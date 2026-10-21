// Загрузка моделей по ?models= (W-15, saved-models.js): id из query, имя из Content-Disposition, чужой id (404) и сбой
// сети пропускаются без сообщения, файлы проходят разбор ядра через Quote.addBuffer. node --test, без npm-пакетов.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileName, loadSavedModels, modelIds } from '../../resources/js/calculator/saved-models.js';
import { Quote } from '../../resources/js/calculator/quote.js';

const cube = readFileSync(new URL('../fixtures/stl/cube-10mm-binary.stl', import.meta.url));
const settings = {
  materials: { PLA: 1.24 }, rate_per_gram: '0.10', order_fee_cents: 500, file_fee_cents: 0,
  default_infill: 20, max_dimension_mm: 250, max_file_bytes: 25 * 1024 * 1024, max_files: 10,
};

const response = (status, name) => new Response(status === 200 ? cube : 'Not Found', {
  status,
  headers: status === 200 ? { 'Content-Disposition': `attachment; filename=part.stl; filename*=utf-8''${encodeURIComponent(name)}` } : {},
});

test('id из query: только числа, без повторов, не больше лимита', () => {
  assert.deepEqual(modelIds('?models=3,%204,x,3,-1,5.5,7', 10), ['3', '4', '7']);
  assert.deepEqual(modelIds('?models=1,2,3', 2), ['1', '2']);
  assert.deepEqual(modelIds('?other=1', 10), []);
});

test('имя файла — filename* UTF-8, иначе filename, иначе запасное', () => {
  assert.equal(fileName("attachment; filename=detal_.stl; filename*=utf-8''detal%C4%97.stl", 'x'), 'detalė.stl');
  assert.equal(fileName('attachment; filename="cube.stl"', 'x'), 'cube.stl');
  assert.equal(fileName(null, 'model-5.stl'), 'model-5.stl');
});

test('свои модели загружаются в Quote и разбираются ядром; чужой id и сбой сети пропускаются без ошибки', async () => {
  const requested = [];
  const fetchFile = async (id) => {
    requested.push(id);
    if (id === '9') return response(404);
    if (id === '8') throw new TypeError('network');
    return response(200, `kubas-${id}.stl`);
  };
  const quote = new Quote(settings);
  const results = await loadSavedModels(['5', '9', '8', '6'], (name, buffer) => quote.addBuffer(name, buffer), fetchFile);

  assert.deepEqual(requested, ['5', '9', '8', '6']);
  assert.deepEqual(results, [{ name: 'kubas-5.stl', error: null }, { name: 'kubas-6.stl', error: null }]);
  assert.deepEqual(quote.models.map((entry) => entry.name), ['kubas-5.stl', 'kubas-6.stl']);
  assert.ok(quote.models.every((entry) => entry.model && entry.model.triangles === 12 && entry.buffer.byteLength === cube.length));
});
