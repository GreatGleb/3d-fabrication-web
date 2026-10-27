// Тесты ядра калькулятора W-10 на корпусе tests/fixtures/stl/ (§9.2 плана): node --test, без npm-пакетов.
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, describe, test } from 'node:test';
import {
  MAX_BYTES,
  MAX_TRIANGLES,
  StlError,
  WARNING_EXCEEDS_BUILD_SIZE,
  WARNING_OPEN_MESH,
  estimate,
  measure,
  parseStl,
} from '../../resources/js/calculator/core/index.js';

const fixtures = new URL('../fixtures/stl/', import.meta.url);
const toArrayBuffer = (bytes) => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
const load = (name) => toArrayBuffer(readFileSync(new URL(name, fixtures)));
const parse = (name) => parseStl(load(name));
const mm = { unit: 'mm', scale: 100, maxDimensionMm: 250 };

// Примерные значения D-09 (не подтверждены): 0,10 EUR/г, плата 5 EUR, плата за файл 0.
const settings = { densities: { PLA: 1.24, ABS: 1.04, PETG: 1.27 }, ratePerGram: '0.10', orderFeeCents: 500, fileFeeCents: 0 };

// Объём — сумма слагаемых «/ 6» по треугольникам (как calculator.js): у куба 1000 мм³ это 999,9999999999999.
const close = (actual, expected, message) => assert.ok(Math.abs(actual - expected) <= 1e-12 * Math.abs(expected), message ?? `${actual} ≉ ${expected}`);
const rejects = (buffer, code) => assert.throws(() => parseStl(buffer), (error) => error instanceof StlError && error.code === code);

describe('разбор STL', () => {
  test('ASCII и binary куб 10 мм дают одинаковый объём 1000 мм³ и габарит 10 × 10 × 10', () => {
    assert.equal(parse('cube-10mm-ascii.stl').volume, parse('cube-10mm-binary.stl').volume);
    for (const name of ['cube-10mm-ascii.stl', 'cube-10mm-binary.stl', 'cube-10mm-binary-solid-header.stl']) {
      const model = parse(name);
      assert.equal(model.triangles, 12, name);
      close(model.volume, 1000, name);
      assert.deepEqual(model.size, [10, 10, 10], name);
      assert.equal(model.closed, true, name);
      assert.ok(model.positions instanceof Float32Array);
    }
  });

  test('обратная ориентация граней не меняет объём', () => {
    assert.equal(parse('cube-10mm-reversed-ascii.stl').volume, parse('cube-10mm-ascii.stl').volume);
  });

  test('объём — по формуле §3.1 от первой вершины в float32', () => {
    // Тот же тетраэдр, посчитанный независимо: |(b−a)·((c−a)×(d−a))| / 6 от координат после Math.fround.
    const [a, b, c, d] = [[0.1, 0.2, 0.3], [12.345678, 0.5, 1.5], [0.7, 9.87654321, 0.1], [1.1, 2.2, 7.77777777]]
      .map((p) => p.map(Math.fround));
    const u = b.map((x, i) => x - a[i]), v = c.map((x, i) => x - a[i]), w = d.map((x, i) => x - a[i]);
    const expected = Math.abs(u[0] * (v[1] * w[2] - v[2] * w[1]) + u[1] * (v[2] * w[0] - v[0] * w[2]) + u[2] * (v[0] * w[1] - v[1] * w[0])) / 6;
    const model = parse('tetrahedron-fractional-ascii.stl');
    assert.ok(Math.abs(model.volume - expected) / expected < 1e-12, `${model.volume} ≠ ${expected}`);
    assert.equal(model.positions[6], Math.fround(12.345678));
    assert.notEqual(model.positions[6], 12.345678);
    assert.equal(model.closed, true);
  });

  test('-0 и 0 — одна вершина: тетраэдр с -0 замкнут, -0 в вершинах не остаётся', () => {
    for (const name of ['tetrahedron-negative-zero-ascii.stl', 'tetrahedron-negative-zero-binary.stl']) {
      const model = parse(name);
      assert.equal(model.closed, true, name);
      assert.ok(!model.positions.some((x) => Object.is(x, -0)), name);
      close(model.volume, 1000 / 6, name);
    }
  });

  test('открытый тетраэдр — предупреждение «незамкнутая сетка», а не молчаливый успех', () => {
    const model = parse('tetrahedron-open-ascii.stl');
    assert.equal(model.closed, false);
    assert.deepEqual(measure(model, mm).warnings, [WARNING_OPEN_MESH]);
    assert.deepEqual(measure(parse('cube-10mm-ascii.stl'), mm).warnings, []);
  });

  test('отказы: число не по шаблону, float32-переполнение, NaN/Infinity, усечение, неверный счётчик, 0 треугольников', () => {
    rejects(load('hex-number-ascii.stl'), 'number');
    rejects(load('float32-overflow-ascii.stl'), 'coordinate');
    rejects(load('nan-binary.stl'), 'coordinate');
    rejects(load('infinity-binary.stl'), 'coordinate');
    rejects(load('truncated-binary.stl'), 'structure');
    rejects(load('wrong-count-binary.stl'), 'structure');
    rejects(load('zero-triangles-binary.stl'), 'triangle_count');
    rejects(load('no-facets-ascii.stl'), 'triangle_count');
    assert.throws(() => parseStl(readFileSync(new URL('cube-10mm-binary.stl', fixtures))), StlError);
  });

  test('шаблон числа ASCII: допустимые формы и отказ для остальных', () => {
    const cube = readFileSync(new URL('cube-10mm-ascii.stl', fixtures), 'latin1');
    const withToken = (token) => toArrayBuffer(Buffer.from(cube.replace('vertex 10 0 0', `vertex ${token} 0 0`), 'latin1'));
    for (const token of ['10', '+10', '10.', '10.0', '1e1', '1E+1', '100e-1', '.1e2']) {
      close(parseStl(withToken(token)).volume, 1000, token);
    }
    for (const token of ['0x10', '1_0', '10,0', 'NaN', 'Infinity', '-inf', '1e', 'e1', '.', '+', '1..0', '１０']) {
      rejects(withToken(token), 'number');
    }
  });

  test('ASCII: лишнее после endsolid и нарушение грамматики — отказ', () => {
    const cube = readFileSync(new URL('cube-10mm-ascii.stl', fixtures), 'latin1');
    const ascii = (text) => toArrayBuffer(Buffer.from(text, 'latin1'));
    rejects(ascii(cube + 'solid second\n'), 'structure');
    rejects(ascii(cube.replace('outer loop', 'outer')), 'structure');
    rejects(ascii(cube.replace('endsolid cube', '')), 'structure');
    rejects(ascii(cube.replace('vertex 10 0 0\n', '')), 'structure');
    rejects(ascii('hello'), 'structure');
  });
});

describe('единицы, масштаб и габарит оборудования', () => {
  const cube = () => parse('cube-10mm-binary.stl');

  test('единицы mm/cm/in и масштаб: объём в кубе, габарит линейно', () => {
    close(measure(cube(), mm).volumeCm3, 1);
    assert.deepEqual(measure(cube(), mm).dimensionsMm, [10, 10, 10]);
    close(measure(cube(), { ...mm, unit: 'cm' }).volumeCm3, 1000);
    assert.deepEqual(measure(cube(), { ...mm, unit: 'cm' }).dimensionsMm, [100, 100, 100]);
    const inch = measure(cube(), { ...mm, unit: 'in', maxDimensionMm: 2000 });
    close(inch.volumeCm3, 16387.064);
    assert.deepEqual(inch.dimensionsMm, [254, 254, 254]);
    close(measure(cube(), { ...mm, scale: 50 }).volumeCm3, 0.125);
    close(measure(cube(), { ...mm, scale: 200 }).volumeCm3, 8);
    // Порядок §3.1 (точное совпадение для паритета с PHP): V × k × k × k / 1000, k = мм_на_единицу × масштаб / 100.
    const { volume } = cube();
    for (const [unit, perUnit] of [['mm', 1], ['cm', 10], ['in', 25.4]]) {
      for (const scale of [1, 37, 1000]) {
        const k = perUnit * scale / 100;
        assert.equal(measure(cube(), { unit, scale, maxDimensionMm: 250 }).volumeCm3, volume * k * k * k / 1000, `${unit} ${scale}`);
      }
    }
  });

  test('превышение габарита — по каждому размеру после единиц и масштаба, только предупреждение', () => {
    assert.deepEqual(measure(cube(), { ...mm, scale: 1000 }).warnings, []); // 100 мм
    assert.deepEqual(measure(cube(), { ...mm, unit: 'cm', scale: 250 }).warnings, []); // ровно 250 мм
    assert.deepEqual(measure(cube(), { ...mm, unit: 'cm', scale: 251 }).warnings, [WARNING_EXCEEDS_BUILD_SIZE]);
    assert.deepEqual(measure(cube(), { ...mm, unit: 'in', maxDimensionMm: 254 }).warnings, []);
    assert.deepEqual(measure(cube(), { ...mm, unit: 'in', maxDimensionMm: 253.9 }).warnings, [WARNING_EXCEEDS_BUILD_SIZE]);
    const tall = { size: [1, 1, 300], volume: 300, closed: true };
    assert.deepEqual(measure(tall, mm).warnings, [WARNING_EXCEEDS_BUILD_SIZE]);
    const openAndLarge = measure(parse('tetrahedron-open-ascii.stl'), { ...mm, scale: 1000, maxDimensionMm: 99 });
    assert.deepEqual(openAndLarge.warnings, [WARNING_OPEN_MESH, WARNING_EXCEEDS_BUILD_SIZE]);
  });

  test('неверные единицы, масштаб вне 1–1000 % и габарит — отказ', () => {
    assert.throws(() => measure(cube(), { ...mm, unit: 'm' }), RangeError);
    assert.throws(() => measure(cube(), { ...mm, scale: 0.5 }), RangeError);
    assert.throws(() => measure(cube(), { ...mm, scale: 1001 }), RangeError);
    assert.throws(() => measure(cube(), { ...mm, maxDimensionMm: undefined }), RangeError);
    assert.doesNotThrow(() => measure(cube(), { ...mm, scale: 1 }));
    assert.doesNotThrow(() => measure(cube(), { ...mm, scale: 1000 }));
  });
});

describe('оценка §3.1', () => {
  const onePart = (overrides = {}) => ({ parts: [{ volumeCm3: 1, quantity: 1 }], material: 'PLA', infill: 100, ...overrides });

  test('контрольный пример: куб 10 мм, PLA, 100 %, qty 1, 0,10 EUR/г, 5 EUR → 1,24 г, 512 центов', () => {
    const { volumeCm3 } = measure(parse('cube-10mm-ascii.stl'), mm);
    const result = estimate({ parts: [{ volumeCm3, quantity: 1 }], material: 'PLA', infill: 100 }, settings);
    close(result.lines[0].massGrams, 1.24);
    assert.equal(result.lines[0].cents, 12);
    assert.equal(result.orderFeeCents, 500);
    assert.equal(result.fileFeesCents, 0);
    assert.equal(result.totalCents, 512);
  });

  test('заполнение 20 % → 0,4464 г (0,2 + 0,8 × заполнение/100)', () => {
    const { massGrams } = estimate(onePart({ infill: 20 }), settings).lines[0];
    assert.ok(Math.abs(massGrams - 0.4464) < 1e-12, String(massGrams));
    assert.equal(massGrams, 1 * 1.24 * (0.2 + 0.8 * 20 / 100) * 1);
  });

  test('qty 3 → 3,72 г и строка 37 центов (не 3 × 12)', () => {
    const result = estimate(onePart({ parts: [{ volumeCm3: 1, quantity: 3 }] }), settings);
    assert.ok(Math.abs(result.lines[0].massGrams - 3.72) < 1e-12);
    assert.equal(result.lines[0].cents, 37);
    assert.equal(result.totalCents, 537);
  });

  test('округление half-up на границе половины цента: floor(x + 0,5)', () => {
    const half = { ...settings, densities: { X: 1 }, ratePerGram: '0.5' };
    const exact = { parts: [{ volumeCm3: 0.25, quantity: 1 }], material: 'X', infill: 100 };
    assert.equal(0.25 * 1 * 0.5 * 100, 12.5); // x ровно 12,5
    assert.equal(estimate(exact, half).lines[0].cents, 13);
    assert.equal(estimate(exact, { ...half, ratePerGram: '0.4999' }).lines[0].cents, 12);
    // Не банковское округление: 12,5 → 13, 37,5 → 38, 62,5 → 63.
    for (const [volumeCm3, cents] of [[0.25, 13], [0.75, 38], [1.25, 63]]) {
      assert.equal(estimate({ ...exact, parts: [{ volumeCm3, quantity: 1 }] }, half).lines[0].cents, cents, String(volumeCm3));
    }
  });

  test('несколько файлов: Σ строк + плата за заказ + n × плата за файл', () => {
    const order = { parts: [{ volumeCm3: 1, quantity: 1 }, { volumeCm3: 8, quantity: 2 }], material: 'ABS', infill: 20 };
    const result = estimate(order, { ...settings, fileFeeCents: 150 });
    const expected = [1 * 1.04 * (0.2 + 0.8 * 20 / 100) * 1, 8 * 1.04 * (0.2 + 0.8 * 20 / 100) * 2]
      .map((mass) => Math.floor(mass * 0.1 * 100 + 0.5));
    assert.deepEqual(result.lines.map((line) => line.cents), expected);
    assert.equal(result.fileFeesCents, 300);
    assert.equal(result.totalCents, expected[0] + expected[1] + 500 + 300);
  });

  test('плотность — только из материала в настройках; своя плотность не принимается', () => {
    assert.throws(() => estimate(onePart({ parts: [{ volumeCm3: 1, quantity: 1, density: 5 }] }), settings), RangeError);
    assert.equal(estimate(onePart({ material: 'PETG' }), settings).lines[0].massGrams, 1.27);
    const changed = { ...settings, densities: { ...settings.densities, PLA: 2 } };
    assert.equal(estimate(onePart(), changed).lines[0].massGrams, 2);
    for (const material of ['NYLON', 'toString', '__proto__', undefined]) {
      assert.throws(() => estimate(onePart({ material }), settings), RangeError, String(material));
    }
  });

  test('тариф — десятичная строка или число; неверные параметры — отказ', () => {
    assert.equal(estimate(onePart(), { ...settings, ratePerGram: 0.1 }).totalCents, 512);
    assert.throws(() => estimate(onePart(), { ...settings, ratePerGram: 'abc' }), RangeError);
    assert.throws(() => estimate(onePart(), { ...settings, orderFeeCents: 5.5 }), RangeError);
    for (const quantity of [0, 101, 1.5]) {
      assert.throws(() => estimate(onePart({ parts: [{ volumeCm3: 1, quantity }] }), settings), RangeError, String(quantity));
    }
    for (const infill of [-1, 101, NaN]) assert.throws(() => estimate(onePart({ infill }), settings), RangeError);
    assert.throws(() => estimate(onePart({ parts: [] }), settings), RangeError);
    assert.throws(() => estimate(onePart({ parts: [{ volumeCm3: 0, quantity: 1 }] }), settings), RangeError);
  });
});

describe('границы файла (генерируются во временный каталог)', () => {
  const directory = mkdtempSync(join(tmpdir(), 'aiqme-stl-'));
  after(() => rmSync(directory, { recursive: true, force: true }));
  const roundTrip = (name, bytes) => {
    const path = join(directory, name);
    writeFileSync(path, bytes);
    return toArrayBuffer(readFileSync(path));
  };

  // Замкнутая бипирамида: кольцо из n вершин и две вершины-полюса → 2n треугольников.
  function bipyramid(triangles) {
    const n = Math.floor(triangles / 2);
    const ring = (i) => [100 * Math.cos(2 * Math.PI * (i % n) / n), 100 * Math.sin(2 * Math.PI * (i % n) / n), 0];
    const bytes = Buffer.alloc(84 + triangles * 50);
    bytes.writeUInt32LE(triangles, 80);
    let offset = 84;
    const triangle = (...points) => {
      offset += 12;
      for (const point of points) for (const x of point) { bytes.writeFloatLE(x, offset); offset += 4; }
      offset += 2;
    };
    for (let i = 0; i < n; i++) {
      triangle([0, 0, 50], ring(i), ring(i + 1));
      triangle([0, 0, -50], ring(i + 1), ring(i));
    }
    while (offset < bytes.length) triangle([0, 0, 0], [1, 0, 0], [0, 1, 0]); // лишний треугольник для нечётного числа
    return bytes;
  }

  test(`binary ${MAX_TRIANGLES} треугольников (ровно MAX_BYTES) — принят, ${MAX_TRIANGLES + 1} — отказ по размеру`, () => {
    assert.equal(84 + MAX_TRIANGLES * 50 <= MAX_BYTES && 84 + (MAX_TRIANGLES + 1) * 50 > MAX_BYTES, true);
    const model = parseStl(roundTrip('max.stl', bipyramid(MAX_TRIANGLES)));
    assert.equal(model.triangles, MAX_TRIANGLES);
    assert.equal(model.closed, true);
    assert.ok(model.volume > 0);
    rejects(roundTrip('over.stl', bipyramid(MAX_TRIANGLES + 1)), 'size');
  });

  test(`файл ровно ${MAX_BYTES} байт (ASCII с пробелами) — принят, на 1 байт больше — отказ`, () => {
    const cube = readFileSync(new URL('cube-10mm-ascii.stl', fixtures), 'latin1');
    const padded = (size) => {
      const at = cube.indexOf('endsolid');
      return Buffer.from(cube.slice(0, at) + ' '.repeat(size - cube.length) + cube.slice(at), 'latin1');
    };
    const exact = roundTrip('exact.stl', padded(MAX_BYTES));
    assert.equal(exact.byteLength, 52428800);
    close(parseStl(exact).volume, 1000);
    rejects(roundTrip('plus-one.stl', padded(MAX_BYTES + 1)), 'size');
  });
});
