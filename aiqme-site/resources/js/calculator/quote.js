// Состояние заявки калькулятора (W-27) без DOM: модели, их разбор, оценка ядра W-10 и сообщение для специалиста.
// Разбор передаётся параметром (в браузере — Web Worker, в тестах — parseStl напрямую).
import { MAX_TRIANGLES, StlError, estimate, measure, parseStl } from './core/index.js';

// OBJ и STEP не разбираются: сообщение со ссылкой на контактную форму (§1.2).
export const SPECIALIST_FORMATS = ['obj', 'step', 'stp'];
// Предел message в ссылке предзаполнения контактной формы (§1.2).
export const MESSAGE_MAX = 2000;

const extension = (name) => (name.includes('.') ? name.split('.').pop().toLowerCase() : '');

// Отказ ядра → код текста: превышение лимита треугольников binary — «слишком сложный», иное — «не прочитан».
function errorCode(error, buffer) {
  if (!(error instanceof StlError)) return 'invalid';
  if (error.code === 'size') return 'too_large';
  if (error.code === 'triangle_count' && buffer.byteLength >= 84 && new DataView(buffer).getUint32(80, true) > MAX_TRIANGLES) return 'too_complex';
  return 'invalid';
}

export const formatDimensions = (dimensionsMm) => `${dimensionsMm.map((size) => size.toFixed(1)).join(' × ')} mm`;

export const formatMoney = (cents, locale) =>
  new Intl.NumberFormat(locale === 'lt' ? 'lt-LT' : 'en-IE', { style: 'currency', currency: 'EUR' }).format(cents / 100);

export class Quote {
  // settings: { materials, rate_per_gram, order_fee_cents, file_fee_cents, default_infill, max_dimension_mm, max_file_bytes,
  // max_files } из config('aiqme.calculator'); onChange — после любого изменения моделей (в том числе позднего разбора).
  constructor(settings, { parse = async (buffer) => parseStl(buffer), onChange = () => {} } = {}) {
    this.settings = settings;
    this.parse = parse;
    this.onChange = onChange;
    this.models = [];
    this.active = -1;
    this.material = Object.keys(settings.materials)[0];
    this.infill = settings.default_infill;
    this.nextId = 1;
  }

  // Выбранный файл: формат и размер проверяются до чтения; затем та же функция, что для ArrayBuffer.
  async addFile(file) {
    const format = extension(file.name);
    if (SPECIALIST_FORMATS.includes(format)) return { name: file.name, error: 'specialist_format' };
    if (format !== 'stl') return { name: file.name, error: 'unsupported' };
    if (file.size > this.settings.max_file_bytes) return { name: file.name, error: 'too_large' };
    return this.addBuffer(file.name, await file.arrayBuffer());
  }

  // Модель из ArrayBuffer — общая точка входа для выбранного файла, повторного заказа и «пересчитать» (W-15).
  // Новая модель сразу становится активной; поздний результат разбора заполняет только свою запись и активную не меняет.
  async addBuffer(name, buffer) {
    if (this.models.length >= this.settings.max_files) return { name, error: 'too_many' };
    const entry = { id: this.nextId++, name, unit: 'mm', scale: 100, quantity: 1, model: null };
    this.models.push(entry);
    this.active = this.models.length - 1;
    this.onChange();
    let error = null;
    try {
      entry.model = await this.parse(buffer);
    } catch (exception) {
      error = errorCode(exception, buffer);
    }
    const index = this.models.indexOf(entry);
    if (index === -1) return { name, error: null }; // запись удалили, пока шёл разбор
    if (error) this.removeAt(index, false);
    this.onChange();
    return { name, error };
  }

  removeAt(index, notify = true) {
    const activeEntry = this.models[this.active];
    this.models.splice(index, 1);
    this.active = activeEntry && this.models.includes(activeEntry) ? this.models.indexOf(activeEntry) : this.models.length - 1;
    if (notify) this.onChange();
  }

  get busy() {
    return this.models.some((entry) => !entry.model);
  }

  get ready() {
    return this.models.filter((entry) => entry.model);
  }

  measure(entry) {
    return measure(entry.model, { unit: entry.unit, scale: entry.scale, maxDimensionMm: this.settings.max_dimension_mm });
  }

  // Предварительная оценка по §3.1 в центах ядра; без моделей — null.
  estimate() {
    const ready = this.ready;
    if (!ready.length) return null;
    const result = estimate({
      parts: ready.map((entry) => ({ volumeCm3: this.measure(entry).volumeCm3, quantity: entry.quantity })),
      material: this.material,
      infill: this.infill,
    }, {
      densities: this.settings.materials,
      ratePerGram: this.settings.rate_per_gram,
      orderFeeCents: this.settings.order_fee_cents,
      fileFeeCents: this.settings.file_fee_cents,
    });
    return { ...result, lines: result.lines.map((line, i) => ({ ...line, entry: ready[i] })) };
  }

  // message для контактной формы: вступление, параметры заявки, по строке на модель; длиннее MESSAGE_MAX — модели
  // с конца убираются, последней строкой — число неуказанных. extraNames — файлы OBJ/STEP без разбора.
  specialistMessage(texts, parameters, extraNames = []) {
    const lines = [
      ...this.ready.map((entry) => `- ${entry.name}: ${formatDimensions(this.measure(entry).dimensionsMm)} · ${texts.quantity}: ${entry.quantity}`),
      ...extraNames.map((name) => `- ${name}`),
    ];
    const head = [texts.message_intro, parameters];
    for (let shown = lines.length; shown >= 0; shown--) {
      const hidden = lines.length - shown;
      const message = [...head, ...lines.slice(0, shown), ...(hidden ? [texts.message_more.replace(':count', hidden)] : [])].join('\n');
      if (message.length <= MESSAGE_MAX) return message;
    }
    return head.join('\n').slice(0, MESSAGE_MAX);
  }
}
