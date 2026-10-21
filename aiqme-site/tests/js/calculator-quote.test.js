// Тесты состояния заявки калькулятора W-27 (quote.js): File = ArrayBuffer, поздний разбор, лимиты, сводка и сообщение
// для специалиста. node --test, без npm-пакетов; разбор — ядро W-10 напрямую или управляемые обещания.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';
import { MESSAGE_MAX, Quote, formatMoney } from '../../resources/js/calculator/quote.js';
import { parseStl } from '../../resources/js/calculator/core/index.js';

const fixtures = new URL('../fixtures/stl/', import.meta.url);
const bytes = (name) => readFileSync(new URL(name, fixtures));
const buffer = (name) => { const b = bytes(name); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const file = (name, content = bytes(name)) => new File([content], name);

// Значения config('aiqme.calculator') по умолчанию (D-09, D-24, §6.4).
const settings = {
  materials: { PLA: 1.24, PETG: 1.27, ABS: 1.04 }, rate_per_gram: '0.10', order_fee_cents: 500, file_fee_cents: 0,
  default_infill: 20, max_dimension_mm: 250, max_file_bytes: 25 * 1024 * 1024, max_files: 10,
};
const texts = { quantity: 'Quantity', message_intro: 'Please help review these files:', message_more: 'Models not listed: :count.' };

// Разбор, который завершается по команде теста: name → { resolve, reject }.
function controlledParse() {
  const pending = new Map();
  const parse = (data) => new Promise((resolve, reject) => pending.set(new TextDecoder().decode(data.slice(0, 40)), { resolve, reject, data }));
  const finish = (prefix) => {
    const [key, job] = [...pending].find(([k]) => k.startsWith(prefix));
    pending.delete(key);
    job.resolve(parseStl(job.data));
  };
  return { parse, finish };
}

describe('добавление моделей', () => {
  test('модель из ArrayBuffer даёт тот же результат, что из File; куб 1 см³ PLA 100 % → 5,12 EUR', async () => {
    const fromBuffer = new Quote(settings);
    const fromFile = new Quote(settings);
    assert.deepEqual(await fromBuffer.addBuffer('cube.stl', buffer('cube-10mm-binary.stl')), { name: 'cube.stl', error: null });
    assert.deepEqual(await fromFile.addFile(file('cube-10mm-binary.stl')), { name: 'cube-10mm-binary.stl', error: null });
    for (const quote of [fromBuffer, fromFile]) quote.infill = 100;
    assert.deepEqual(fromFile.measure(fromFile.models[0]), fromBuffer.measure(fromBuffer.models[0]));
    const a = fromBuffer.estimate();
    const b = fromFile.estimate();
    assert.equal(a.totalCents, 512);
    assert.deepEqual(b.lines.map((l) => l.cents), a.lines.map((l) => l.cents));
    assert.equal(b.totalCents, a.totalCents);
    assert.equal(formatMoney(a.totalCents, 'en'), '€5.12');
    assert.equal(formatMoney(a.totalCents, 'lt'), '5,12 €');
  });

  test('второй файл, выбранный до окончания разбора первого, остаётся на экране после позднего результата первого', async () => {
    const { parse, finish } = controlledParse();
    let changes = 0;
    const quote = new Quote(settings, { parse, onChange: () => changes++ });
    const first = quote.addBuffer('first-cube.stl', buffer('cube-10mm-ascii.stl'));
    const second = quote.addBuffer('second-tetra.stl', buffer('tetrahedron-open-ascii.stl'));
    assert.equal(quote.models[quote.active].name, 'second-tetra.stl');
    assert.equal(quote.busy, true);
    finish('solid open');
    await second;
    finish('solid cube');
    await first;
    assert.equal(quote.busy, false);
    assert.equal(quote.models[quote.active].name, 'second-tetra.stl', 'активной осталась вторая модель');
    assert.deepEqual(quote.measure(quote.models[quote.active]).warnings, ['open_mesh'], 'на экране — результат второго файла');
    assert.deepEqual(quote.measure(quote.models[0]).dimensionsMm, [10, 10, 10], 'первый результат попал в свою запись');
    assert.ok(changes >= 4);
  });

  test('поздний результат удалённой модели не возвращает её и не меняет оставшуюся', async () => {
    const { parse, finish } = controlledParse();
    const quote = new Quote(settings, { parse });
    const first = quote.addBuffer('old.stl', buffer('cube-10mm-ascii.stl'));
    const second = quote.addBuffer('new.stl', buffer('tetrahedron-open-ascii.stl'));
    quote.removeAt(0);
    finish('solid open');
    await second;
    finish('solid cube');
    await first;
    assert.deepEqual(quote.models.map((m) => m.name), ['new.stl']);
    assert.equal(quote.active, 0);
  });

  test('OBJ и STEP — к специалисту без разбора; иные форматы, размер больше лимита и отказ ядра — ошибка, модели нет', async () => {
    let parsed = 0;
    const quote = new Quote(settings, { parse: async (data) => { parsed++; return parseStl(data); } });
    assert.equal((await quote.addFile(new File(['x'], 'part.STEP'))).error, 'specialist_format');
    assert.equal((await quote.addFile(new File(['x'], 'part.stp'))).error, 'specialist_format');
    assert.equal((await quote.addFile(new File(['x'], 'part.obj'))).error, 'specialist_format');
    assert.equal((await quote.addFile(new File(['x'], 'photo.png'))).error, 'unsupported');
    assert.equal((await quote.addFile(new File([new Uint8Array(settings.max_file_bytes + 1)], 'big.stl'))).error, 'too_large');
    assert.equal(parsed, 0, 'до разбора не дошло');
    assert.equal((await quote.addFile(file('truncated-binary.stl'))).error, 'invalid');
    const tooMany = new ArrayBuffer(84);
    new DataView(tooMany).setUint32(80, 500001, true);
    assert.equal((await quote.addBuffer('huge.stl', tooMany)).error, 'invalid', 'размер не сходится со счётчиком — не binary');
    const complex = new ArrayBuffer(84 + 500001 * 50);
    new DataView(complex).setUint32(80, 500001, true);
    assert.equal((await quote.addBuffer('complex.stl', complex)).error, 'too_complex', 'binary больше 500 000 треугольников');
    assert.deepEqual(quote.models, []);
    assert.equal(quote.active, -1);
  });

  test('не больше max_files моделей в заявке', async () => {
    const quote = new Quote({ ...settings, max_files: 2 });
    await quote.addBuffer('a.stl', buffer('cube-10mm-ascii.stl'));
    await quote.addBuffer('b.stl', buffer('cube-10mm-ascii.stl'));
    assert.deepEqual(await quote.addBuffer('c.stl', buffer('cube-10mm-ascii.stl')), { name: 'c.stl', error: 'too_many' });
    assert.equal(quote.models.length, 2);
  });
});

describe('оценка и сводка', () => {
  test('масштаб и единицы по модели, материал и заполнение общие; итог = строки + плата за заказ и за файлы', async () => {
    const quote = new Quote({ ...settings, file_fee_cents: 150 });
    await quote.addBuffer('a.stl', buffer('cube-10mm-ascii.stl'));
    await quote.addBuffer('b.stl', buffer('cube-10mm-binary.stl'));
    Object.assign(quote.models[1], { unit: 'cm', scale: 50, quantity: 3 });
    quote.infill = 100;
    const result = quote.estimate();
    // b: 10 мм × 10 мм/см × 0,5 = 50 мм → 125 см³ × 1,24 г × 3 = 465 г → 4650 центов.
    assert.deepEqual(result.lines.map((l) => [l.entry.name, l.cents]), [['a.stl', 12], ['b.stl', 4650]]);
    assert.equal(result.fileFeesCents, 300);
    assert.equal(result.totalCents, 12 + 4650 + 500 + 300);
    assert.deepEqual(quote.measure(quote.models[1]).warnings, []);
    quote.models[1].scale = 600; // 600 мм > 250
    assert.deepEqual(quote.measure(quote.models[1]).warnings, ['exceeds_build_size']);
    quote.material = 'ABS';
    assert.equal(quote.estimate().lines[0].cents, 10); // 1,04 г
  });

  test('без моделей оценки нет', () => {
    assert.equal(new Quote(settings).estimate(), null);
  });
});

describe('сообщение для специалиста', () => {
  test('10 моделей с длинными именами: не длиннее 2000 символов, указано число неуказанных моделей', async () => {
    const quote = new Quote(settings);
    for (let i = 1; i <= 10; i++) await quote.addBuffer(`${'detail-'.repeat(40)}${i}.stl`, buffer('cube-10mm-ascii.stl'));
    const message = quote.specialistMessage(texts, 'FDM / PLA, Infill: 20%', ['bracket.step']);
    assert.ok(message.length <= MESSAGE_MAX, `длина ${message.length}`);
    const lines = message.split('\n');
    assert.equal(lines[0], 'Please help review these files:');
    assert.equal(lines[1], 'FDM / PLA, Infill: 20%');
    const listed = lines.filter((line) => line.startsWith('- '));
    assert.ok(listed.length > 0 && listed.length < 11);
    assert.equal(lines.at(-1), `Models not listed: ${11 - listed.length}.`);
    assert.match(listed[0], /^- (detail-){40}1\.stl: 10\.0 × 10\.0 × 10\.0 mm · Quantity: 1$/);
  });

  test('10 моделей с короткими именами перечислены все, без пометки', async () => {
    const quote = new Quote(settings);
    for (let i = 1; i <= 10; i++) await quote.addBuffer(`part-${i}.stl`, buffer('cube-10mm-ascii.stl'));
    const message = quote.specialistMessage(texts, 'FDM / PLA');
    assert.equal(message.split('\n').filter((line) => line.startsWith('- ')).length, 10);
    assert.doesNotMatch(message, /not listed/);
  });
});
