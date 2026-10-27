// Ядро калькулятора (W-10): разбор STL, объём, габариты, предупреждения и оценка по §3.1 плана.
// Без DOM и без пакета three; по образцу legacy-landing/site/assets/calculator.js.
// PHP-ядро W-11 повторяет те же шаги и тот же порядок операций (паритет на корпусе tests/fixtures/stl/).

export const MAX_BYTES = 50 * 1024 * 1024;
export const MAX_TRIANGLES = 1048574; // столько помещается в binary MAX_BYTES (84 + 50 × n): предел файла задаёт размер
export const MM_PER_UNIT = { mm: 1, cm: 10, in: 25.4 };
export const WARNING_OPEN_MESH = 'open_mesh';
export const WARNING_EXCEEDS_BUILD_SIZE = 'exceeds_build_size';

// Общий с PHP шаблон числа ASCII STL; иное (0x10, nan, inf, 1,5) — отказ.
const NUMBER = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;
// Пробелы — только ASCII (как \s в PCRE без /u), а не Unicode-пробелы \s в JS.
const SPACE = /[ \t\n\v\f\r]+/;

export class StlError extends Error {
  constructor(code) {
    super(`Invalid STL: ${code}`);
    this.code = code;
  }
}

// Координата как в binary STL: float32, конечная, -0 → 0 (иначе -0 и 0 дали бы разные рёбра).
function coordinate(value) {
  const x = Math.fround(value);
  if (!Number.isFinite(x)) throw new StlError('coordinate');
  return x === 0 ? 0 : x;
}

function parseBinary(view, count) {
  const positions = new Float32Array(count * 9);
  for (let t = 0; t < count; t++) {
    const offset = 84 + t * 50 + 12; // 12 байт нормали пропускаются
    for (let i = 0; i < 9; i++) positions[t * 9 + i] = coordinate(view.getFloat32(offset + i * 4, true));
  }
  return positions;
}

// ASCII: solid <имя> (facet normal n n n outer loop (vertex x y z)×3 endloop endfacet)+ endsolid [<имя>].
// Ключевые слова — строчными, как пишут экспортёры и как проверял calculator.js.
function parseAscii(buffer) {
  const text = new TextDecoder('latin1').decode(buffer);
  const header = /^[ \t\n\v\f\r]*solid(?=[ \t\n\v\f\r]|$)[^\n]*/.exec(text);
  if (!header) throw new StlError('structure');
  const tokens = text.slice(header[0].length).split(SPACE).filter(Boolean);
  const values = [];
  let i = 0;
  const expect = (word) => {
    if (tokens[i++] !== word) throw new StlError('structure');
  };
  const number = () => {
    const token = tokens[i++];
    if (token === undefined || !NUMBER.test(token)) throw new StlError('number');
    return Number(token);
  };
  // Лимит треугольников здесь не нужен: грань ASCII не короче 86 байт, в MAX_BYTES помещается меньше 610 000.
  while (tokens[i] === 'facet') {
    i++;
    expect('normal');
    number(); number(); number();
    expect('outer');
    expect('loop');
    for (let v = 0; v < 3; v++) {
      expect('vertex');
      values.push(coordinate(number()), coordinate(number()), coordinate(number()));
    }
    expect('endloop');
    expect('endfacet');
  }
  expect('endsolid');
  // После endsolid допускается только имя на той же строке.
  const rest = text.slice(text.lastIndexOf('endsolid') + 'endsolid'.length);
  if (/\n[ \t\n\v\f\r]*[^ \t\n\v\f\r]/.test(rest)) throw new StlError('structure');
  if (!values.length) throw new StlError('triangle_count');
  return Float32Array.from(values);
}

// Каждое ребро (пара одинаковых после float32 вершин) должно встречаться ровно дважды.
function isClosed(positions) {
  const vertexIds = new Map();
  const vertexId = (i) => {
    const key = `${positions[i]} ${positions[i + 1]} ${positions[i + 2]}`;
    let id = vertexIds.get(key);
    if (id === undefined) vertexIds.set(key, id = vertexIds.size);
    return id;
  };
  const ids = new Uint32Array(positions.length / 3);
  for (let v = 0; v < ids.length; v++) ids[v] = vertexId(v * 3);
  const base = vertexIds.size;
  const edges = new Map();
  for (let t = 0; t < ids.length; t += 3) {
    for (const [p, q] of [[ids[t], ids[t + 1]], [ids[t + 1], ids[t + 2]], [ids[t + 2], ids[t]]]) {
      const key = Math.min(p, q) * base + Math.max(p, q);
      edges.set(key, (edges.get(key) || 0) + 1);
    }
  }
  for (const count of edges.values()) if (count !== 2) return false;
  return true;
}

// Модель в единицах файла: число треугольников, вершины (float32), габарит, объём, замкнутость.
export function parseStl(buffer) {
  if (!(buffer instanceof ArrayBuffer)) throw new StlError('size');
  if (buffer.byteLength > MAX_BYTES) throw new StlError('size');
  const view = new DataView(buffer);
  const count = buffer.byteLength >= 84 ? view.getUint32(80, true) : -1;
  let positions;
  if (count >= 0 && 84 + count * 50 === buffer.byteLength) {
    if (count === 0 || count > MAX_TRIANGLES) throw new StlError('triangle_count');
    positions = parseBinary(view, count);
  } else {
    positions = parseAscii(buffer);
  }

  const min = [Infinity, Infinity, Infinity];
  const max = [-Infinity, -Infinity, -Infinity];
  for (let i = 0; i < positions.length; i++) {
    const axis = i % 3;
    if (positions[i] < min[axis]) min[axis] = positions[i];
    if (positions[i] > max[axis]) max[axis] = positions[i];
  }

  // §3.1: |Σ (a−o)·((b−o)×(c−o)) / 6| по треугольникам в порядке файла, o — первая вершина.
  const ox = positions[0], oy = positions[1], oz = positions[2];
  let signedVolume = 0;
  for (let i = 0; i < positions.length; i += 9) {
    const ax = positions[i] - ox, ay = positions[i + 1] - oy, az = positions[i + 2] - oz;
    const bx = positions[i + 3] - ox, by = positions[i + 4] - oy, bz = positions[i + 5] - oz;
    const cx = positions[i + 6] - ox, cy = positions[i + 7] - oy, cz = positions[i + 8] - oz;
    signedVolume += (ax * (by * cz - bz * cy) + ay * (bz * cx - bx * cz) + az * (bx * cy - by * cx)) / 6;
  }
  // Обратная ориентация меняет знак, а не объём.
  const volume = Math.abs(signedVolume);
  if (!Number.isFinite(volume) || volume <= 0) throw new StlError('volume');

  return {
    triangles: positions.length / 9,
    positions,
    size: [max[0] - min[0], max[1] - min[1], max[2] - min[2]],
    volume,
    closed: isClosed(positions),
  };
}

// Единицы и масштаб применяются к объёму (в кубе) и габариту, не к координатам.
// maxDimensionMm — габарит оборудования из настроек (D-24); превышение и незамкнутость — только предупреждения.
export function measure(model, { unit, scale, maxDimensionMm }) {
  if (!(unit in MM_PER_UNIT)) throw new RangeError('unit');
  if (!Number.isFinite(scale) || scale < 1 || scale > 1000) throw new RangeError('scale');
  if (!Number.isFinite(maxDimensionMm) || maxDimensionMm <= 0) throw new RangeError('maxDimensionMm');
  const k = MM_PER_UNIT[unit] * scale / 100;
  const dimensionsMm = model.size.map((size) => size * k);
  const warnings = [];
  if (!model.closed) warnings.push(WARNING_OPEN_MESH);
  if (dimensionsMm.some((size) => size > maxDimensionMm)) warnings.push(WARNING_EXCEEDS_BUILD_SIZE);
  return { dimensionsMm, volumeCm3: model.volume * k * k * k / 1000, warnings };
}

// Оценка по §3.1 в целых центах. Плотность — только из настроек материала (своей плотности клиента нет).
// settings: { densities: { PLA: 1.24, … }, ratePerGram: '0.10' | 0.1, orderFeeCents, fileFeeCents }.
export function estimate({ parts, material, infill }, settings) {
  const density = Object.hasOwn(settings.densities, material) ? Number(settings.densities[material]) : NaN;
  const rate = Number(settings.ratePerGram);
  const { orderFeeCents, fileFeeCents } = settings;
  if (!Number.isFinite(density) || density <= 0) throw new RangeError('material');
  if (!Number.isFinite(rate) || rate < 0) throw new RangeError('ratePerGram');
  if (![orderFeeCents, fileFeeCents].every((fee) => Number.isSafeInteger(fee) && fee >= 0)) throw new RangeError('fee');
  if (!Number.isFinite(infill) || infill < 0 || infill > 100) throw new RangeError('infill');
  if (!Array.isArray(parts) || !parts.length) throw new RangeError('parts');

  const lines = parts.map((part) => {
    if ('density' in part) throw new RangeError('density');
    const { volumeCm3, quantity } = part;
    if (!Number.isFinite(volumeCm3) || volumeCm3 <= 0) throw new RangeError('volumeCm3');
    if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 100) throw new RangeError('quantity');
    const massGrams = volumeCm3 * density * (0.2 + 0.8 * infill / 100) * quantity;
    return { massGrams, cents: Math.floor(massGrams * rate * 100 + 0.5) };
  });
  const fileFeesCents = parts.length * fileFeeCents;
  const totalCents = lines.reduce((sum, line) => sum + line.cents, 0) + orderFeeCents + fileFeesCents;
  return { lines, orderFeeCents, fileFeesCents, totalCents };
}
