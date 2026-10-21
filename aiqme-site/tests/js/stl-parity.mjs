// Выдача ядра JS для теста паритета PHP ↔ JS (W-11, tests/Feature/StlParityTest.php): не тест node --test, а скрипт.
// stdin — JSON { files: [путь], settings: настройки калькулятора W-11, matrix: { units, scales, infills, quantities } };
// stdout — JSON по каждому файлу: код отказа или модель, а для каждой комбинации матрицы и материала — измерения и оценка;
// в конце — оценка всех принятых файлов одной заявкой (несколько строк, плата за заказ и за файлы).
import { readFileSync } from 'node:fs';
import { StlError, estimate, measure, parseStl } from '../../resources/js/calculator/core/index.js';

const input = JSON.parse(readFileSync(0, 'utf8'));
const { settings, matrix } = input;
const core = {
  densities: settings.materials,
  ratePerGram: settings.rate_per_gram,
  orderFeeCents: settings.order_fee_cents,
  fileFeeCents: settings.file_fee_cents,
};

const files = [];
const parts = [];
for (const path of input.files) {
  const bytes = readFileSync(path);
  let model;
  try {
    model = parseStl(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength));
  } catch (error) {
    if (!(error instanceof StlError)) throw error;
    files.push({ path, error: error.code });
    continue;
  }
  const cases = [];
  for (const unit of matrix.units) {
    for (const scale of matrix.scales) {
      const measured = measure(model, { unit, scale, maxDimensionMm: settings.max_dimension_mm });
      for (const material of Object.keys(settings.materials)) {
        for (const infill of matrix.infills) {
          for (const quantity of matrix.quantities) {
            const result = estimate({ parts: [{ volumeCm3: measured.volumeCm3, quantity }], material, infill }, core);
            cases.push({ unit, scale, material, infill, quantity, ...measured, estimate: result });
          }
        }
      }
    }
  }
  parts.push({ volumeCm3: measure(model, { unit: 'mm', scale: 100, maxDimensionMm: settings.max_dimension_mm }).volumeCm3, quantity: 2 });
  files.push({ path, triangles: model.triangles, size: model.size, volume: model.volume, closed: model.closed, cases });
}
const material = Object.keys(settings.materials)[0];
const order = estimate({ parts, material, infill: settings.default_infill }, core);
process.stdout.write(JSON.stringify({ files, order }));
