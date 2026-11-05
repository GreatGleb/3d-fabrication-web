// Тесты состояния заявки калькулятора W-27 (quote.js): File = ArrayBuffer, поздний разбор, лимиты, параметры у каждой
// позиции (правка 02.11, Г8) и сообщение для специалиста. Правка 05.11, H-04: оценки и сводки сумм в браузере нет. node --test, без npm-пакетов; разбор — ядро W-10 напрямую
// или управляемые обещания.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';
import * as quoteModule from '../../resources/js/calculator/quote.js';
import { parseStl } from '../../resources/js/calculator/core/index.js';

const { MESSAGE_MAX, Quote } = quoteModule;

const fixtures = new URL('../fixtures/stl/', import.meta.url);
const bytes = (name) => readFileSync(new URL(name, fixtures));
const buffer = (name) => { const b = bytes(name); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const file = (name, content = bytes(name)) => new File([content], name);

// data-settings страницы (правка 05.11, H-04: названия материалов без плотностей, тарифов нет), списки фрезеровки и гравировки
// (Г6) и лимиты config('aiqme.calculator') (§6.4).
const settings = {
  materials: ['PLA', 'PETG', 'ABS'],
  default_infill: 20, max_dimension_mm: 250, max_file_bytes: 50 * 1024 * 1024, max_files: 10,
  milling_materials: [{ lt: 'Aliuminis', en: 'Aluminium' }, { lt: 'Plienas', en: 'Steel' }],
  engraving_materials: [{ lt: 'Mediena', en: 'Wood' }],
  milling_thicknesses_mm: [1, 6], engraving_depths_mm: [0.1, 0.5], milling_max_mm: [2000, 4000],
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
  test('модель из ArrayBuffer даёт тот же результат, что из File; куб 10 мм — 1 см³', async () => {
    const fromBuffer = new Quote(settings);
    const fromFile = new Quote(settings);
    assert.deepEqual(await fromBuffer.addBuffer('cube.stl', buffer('cube-10mm-binary.stl')), { name: 'cube.stl', error: null });
    assert.deepEqual(await fromFile.addFile(file('cube-10mm-binary.stl')), { name: 'cube-10mm-binary.stl', error: null });
    assert.deepEqual(fromFile.measure(fromFile.models[0]), fromBuffer.measure(fromBuffer.models[0]));
    assert.deepEqual(fromBuffer.measure(fromBuffer.models[0]).dimensionsMm, [10, 10, 10]);
    assert.ok(Math.abs(fromBuffer.measure(fromBuffer.models[0]).volumeCm3 - 1) < 1e-9);
    // Материал по умолчанию — первое название из data-settings (правка 05.11, H-04: список названий, а не плотности).
    assert.equal(fromBuffer.models[0].options.printing.material, 'PLA');
    // Байты файла остаются в записи модели — их отправляет заявка (W-12).
    const bytes = new Uint8Array(buffer('cube-10mm-binary.stl'));
    for (const quote of [fromBuffer, fromFile]) assert.deepEqual(new Uint8Array(quote.models[0].buffer), bytes);
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

  // Правка 02.11, Г8: STEP/STP/OBJ/DXF/DWG принимаются без разбора (прежде OBJ/STEP — specialist_format и уход на контакты).
  test('STEP, OBJ, DXF, DWG — позиции без разбора; иные форматы, размер больше лимита и отказ ядра — ошибка, модели нет', async () => {
    let parsed = 0;
    const quote = new Quote(settings, { parse: async (data) => { parsed++; return parseStl(data); } });
    assert.equal((await quote.addFile(new File(['x'], 'photo.png'))).error, 'unsupported');
    assert.equal((await quote.addFile(new File(['x'], 'plate.dxf.exe'))).error, 'unsupported');
    assert.equal((await quote.addFile(new File([new Uint8Array(settings.max_file_bytes + 1)], 'big.stl'))).error, 'too_large');
    assert.equal((await quote.addFile(new File([new Uint8Array(settings.max_file_bytes + 1)], 'big.dwg'))).error, 'too_large');
    assert.equal(parsed, 0, 'до разбора не дошло');
    assert.equal((await quote.addFile(file('truncated-binary.stl'))).error, 'invalid');
    const tooMany = new ArrayBuffer(84);
    new DataView(tooMany).setUint32(80, 1000001, true);
    assert.equal((await quote.addBuffer('huge.stl', tooMany)).error, 'invalid', 'размер не сходится со счётчиком — не binary');
    assert.deepEqual(quote.models, []);
    assert.equal(quote.active, -1);

    for (const name of ['part.STEP', 'part.stp', 'part.obj', 'plate.DXF', 'sign.dwg']) {
      assert.deepEqual(await quote.addFile(new File(['abc'], name)), { name, error: null });
    }
    assert.equal(parsed, 2, 'не-STL не разбираются (разобраны только два STL выше)');
    assert.deepEqual(quote.models.map((m) => [m.format, m.bytes, m.method, m.model]), [
      ['step', 3, 'printing', null], ['stp', 3, 'printing', null], ['obj', 3, 'printing', null], ['dxf', 3, 'milling', null], ['dwg', 3, 'milling', null],
    ]);
    assert.equal(quote.busy, false);
    assert.equal(quote.ready.length, 5);
    assert.equal(quote.active, -1, 'в просмотре только STL');
    assert.deepEqual(quote.methods(quote.models[0]), ['printing', 'milling', 'engraving']);
    assert.deepEqual(quote.methods(quote.models[3]), ['milling', 'engraving'], 'печать DXF/DWG недоступна');
  });

  test('не больше max_files моделей в заявке', async () => {
    const quote = new Quote({ ...settings, max_files: 2 });
    await quote.addBuffer('a.stl', buffer('cube-10mm-ascii.stl'));
    await quote.addBuffer('b.stl', buffer('cube-10mm-ascii.stl'));
    assert.deepEqual(await quote.addBuffer('c.stl', buffer('cube-10mm-ascii.stl')), { name: 'c.stl', error: 'too_many' });
    assert.equal(quote.models.length, 2);
  });
});

describe('позиции без оценки (правка 05.11, H-04)', () => {
  test('в браузере нет расчёта суммы: ни оценки, ни сводки сумм, ни форматирования денег', async () => {
    const quote = new Quote(settings);
    await quote.addBuffer('cube.stl', buffer('cube-10mm-binary.stl'));
    assert.equal(quote.estimate, undefined);
    assert.equal(quote.summary, undefined);
    assert.equal(quoteModule.formatMoney, undefined);
  });

  test('масштаб и единицы — свои у каждой позиции; предупреждение габарита печати', async () => {
    const quote = new Quote(settings);
    await quote.addBuffer('a.stl', buffer('cube-10mm-ascii.stl'));
    await quote.addBuffer('b.stl', buffer('cube-10mm-binary.stl'));
    Object.assign(quote.models[1], { unit: 'cm', scale: 50 });
    // b: 10 мм × 10 мм/см × 0,5 = 50 мм; a не меняется.
    assert.deepEqual(quote.measure(quote.models[1]).dimensionsMm, [50, 50, 50]);
    assert.deepEqual(quote.measure(quote.models[0]).dimensionsMm, [10, 10, 10]);
    assert.deepEqual(quote.measure(quote.models[1]).warnings, []);
    quote.models[1].scale = 600; // 600 мм > 250
    assert.deepEqual(quote.measure(quote.models[1]).warnings, ['exceeds_build_size']);
  });

  test('«Kainą įvertins specialistas» в карточке — у всего, кроме STL с печатью', async () => {
    const quote = new Quote(settings);
    await quote.addBuffer('cube.stl', buffer('cube-10mm-binary.stl'));
    await quote.addBuffer('milled.stl', buffer('cube-10mm-ascii.stl'));
    for (const name of ['housing.step', 'plate.dxf']) await quote.addBuffer(name, new TextEncoder().encode('x').buffer);
    quote.models[1].method = 'milling';
    assert.deepEqual(quote.models.map((entry) => quote.priced(entry)), [true, false, false, false]);
  });

  test('поля заявки позиции: способ и его параметры, количество, срок, дата только при скорой очереди, заметка; unit и scale — у STL', async () => {
    const quote = new Quote(settings);
    await quote.addBuffer('cube.stl', buffer('cube-10mm-binary.stl'));
    await quote.addBuffer('plate.dxf', new TextEncoder().encode('x').buffer);
    await quote.addBuffer('sign.dwg', new TextEncoder().encode('x').buffer);
    const [cube, plate, sign] = quote.models;
    assert.deepEqual(quote.fields(cube), { method: 'printing', material: 'PLA', infill: '20', color: 'black', purpose: 'visual', service: 'none',
      surface: 'standard', unit: 'mm', scale: 100, quantity: 1, priority: 'standard', needed_by: '', notes: '' });
    Object.assign(plate, { quantity: 3, neededBy: '2030-01-02', notes: 'Be šerpetų' });
    assert.equal(quote.fields(plate).needed_by, '', 'дата без скорой очереди не отправляется');
    plate.priority = 'priority';
    plate.options.milling.thickness = '6';
    assert.deepEqual(quote.fields(plate), { method: 'milling', material: 'Aliuminis', thickness: '6', quantity: 3, priority: 'priority', needed_by: '2030-01-02', notes: 'Be šerpetų' });
    sign.method = 'engraving';
    assert.deepEqual(quote.fields(sign), { method: 'engraving', material: 'Mediena', depth: '0.1', quantity: 1, priority: 'standard', needed_by: '', notes: '' });
    // Параметры способа хранятся отдельно: возврат к фрезеровке возвращает её выбор.
    plate.method = 'engraving';
    plate.method = 'milling';
    assert.equal(quote.fields(plate).thickness, '6');
  });

  test('предел фрезеровки и гравировки для STL — по двум наибольшим сторонам', async () => {
    const quote = new Quote(settings);
    await quote.addBuffer('cube.stl', buffer('cube-10mm-binary.stl'));
    const [cube] = quote.models;
    cube.scale = 1000; // 100 мм
    assert.equal(quote.exceedsMilling(cube), false);
    assert.equal(new Quote({ ...settings, milling_max_mm: [99, 4000] }).exceedsMilling(cube), true);
    assert.equal(new Quote({ ...settings, milling_max_mm: [4000, 99] }).exceedsMilling(cube), true);
  });

});

describe('сообщение для специалиста', () => {
  test('10 моделей с длинными именами: не длиннее 2000 символов, указано число неуказанных моделей', async () => {
    const quote = new Quote(settings);
    for (let i = 1; i <= 10; i++) await quote.addBuffer(`${'detail-'.repeat(40)}${i}.stl`, buffer('cube-10mm-ascii.stl'));
    const message = quote.specialistMessage(texts, () => '3D printing / PLA');
    assert.ok(message.length <= MESSAGE_MAX, `длина ${message.length}`);
    const lines = message.split('\n');
    assert.equal(lines[0], 'Please help review these files:');
    const listed = lines.filter((line) => line.startsWith('- '));
    assert.ok(listed.length > 0 && listed.length < 10);
    assert.equal(lines.at(-1), `Models not listed: ${10 - listed.length}.`);
    assert.match(listed[0], /^- (detail-){40}1\.stl: 10\.0 × 10\.0 × 10\.0 mm · 3D printing \/ PLA · Quantity: 1$/);
  });

  test('10 моделей с короткими именами перечислены все, без пометки', async () => {
    const quote = new Quote(settings);
    for (let i = 1; i <= 10; i++) await quote.addBuffer(`part-${i}.stl`, buffer('cube-10mm-ascii.stl'));
    const message = quote.specialistMessage(texts);
    assert.equal(message.split('\n').filter((line) => line.startsWith('- ')).length, 10);
    assert.equal(message.split('\n')[1], '- part-1.stl: 10.0 × 10.0 × 10.0 mm · Quantity: 1');
    assert.doesNotMatch(message, /not listed/);
  });
});
