// Состояние заявки калькулятора (W-27) без DOM: файлы, разбор STL, параметры у каждой позиции (правка 02.11, Г8), оценка
// ядра W-10 и сообщение для специалиста. Разбор передаётся параметром (в браузере — Web Worker, в тестах — parseStl напрямую).
import { MAX_TRIANGLES, StlError, estimate, measure, parseStl } from './core/index.js';

// Принимаемые форматы (правка 02.11, Г6): STL разбирается и оценивается, остальные — без разбора, цену назначит специалист.
export const THREE_D_FORMATS = ['stl', 'step', 'stp', 'obj'];
export const FORMATS = [...THREE_D_FORMATS, 'dxf', 'dwg'];
export const METHODS = ['printing', 'milling', 'engraving'];
// Пожелания печати по умолчанию — те же значения, что были у общего шага 2 (правка 28.10, E-05).
const PRINTING_DEFAULTS = { color: 'black', purpose: 'visual', service: 'none', surface: 'standard' };
// Предел message в ссылке предзаполнения контактной формы (§1.2).
export const MESSAGE_MAX = 2000;

export const extension = (name) => (name.includes('.') ? name.split('.').pop().toLowerCase() : '');

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
  // settings: { materials, rate_per_gram, order_fee_cents, file_fee_cents, default_infill, max_dimension_mm, milling_materials,
  // engraving_materials, milling_thicknesses_mm, engraving_depths_mm, milling_max_mm, max_file_bytes, max_files } из
  // data-settings страницы (настройки админки W-11, Г6 и лимиты config); onChange — после любого изменения файлов (в том числе
  // позднего разбора).
  constructor(settings, { parse = async (buffer) => parseStl(buffer), onChange = () => {} } = {}) {
    this.settings = settings;
    this.parse = parse;
    this.onChange = onChange;
    this.models = [];
    this.active = -1;
    this.nextId = 1;
  }

  // Выбранный файл: формат и размер проверяются до чтения; затем та же функция, что для ArrayBuffer.
  async addFile(file) {
    if (!FORMATS.includes(extension(file.name))) return { name: file.name, error: 'unsupported' };
    if (file.size > this.settings.max_file_bytes) return { name: file.name, error: 'too_large' };
    return this.addBuffer(file.name, await file.arrayBuffer());
  }

  // Файл из ArrayBuffer — общая точка входа для выбранного файла и повторного заказа (W-15). Позиция получает параметры по
  // умолчанию: 3D-форматы — печать, DXF/DWG — фрезеровка. STL разбирается (новая модель становится активной в просмотре;
  // поздний результат заполняет только свою запись), остальные форматы готовы сразу. Байты файла остаются в записи:
  // заявка (W-12) отправляет их на сервер.
  async addBuffer(name, buffer) {
    if (this.models.length >= this.settings.max_files) return { name, error: 'too_many' };
    const format = extension(name);
    if (!FORMATS.includes(format)) return { name, error: 'unsupported' };
    const { settings } = this;
    const entry = {
      id: this.nextId++, name, format, buffer, bytes: buffer.byteLength, model: null,
      unit: 'mm', scale: 100, quantity: 1,
      method: THREE_D_FORMATS.includes(format) ? 'printing' : 'milling',
      options: {
        printing: { material: Object.keys(settings.materials)[0], infill: String(settings.default_infill), ...PRINTING_DEFAULTS },
        milling: { material: settings.milling_materials[0].lt, thickness: String(settings.milling_thicknesses_mm[0]) },
        engraving: { material: settings.engraving_materials[0].lt, depth: String(settings.engraving_depths_mm[0]) },
      },
      priority: 'standard', neededBy: '', notes: '', collapsed: false,
    };
    this.models.push(entry);
    if (format !== 'stl') {
      this.onChange();
      return { name, error: null };
    }
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
    const stl = this.models.filter((entry) => entry.format === 'stl');
    this.active = activeEntry && this.models.includes(activeEntry) ? this.models.indexOf(activeEntry)
      : stl.length ? this.models.indexOf(stl.at(-1)) : -1;
    if (notify) this.onChange();
  }

  find(id) {
    return this.models.find((entry) => entry.id === Number(id));
  }

  // Способ позиции: печать — только для 3D-форматов (Г6).
  methods(entry) {
    return THREE_D_FORMATS.includes(entry.format) ? METHODS : METHODS.slice(1);
  }

  get busy() {
    return this.models.some((entry) => entry.format === 'stl' && !entry.model);
  }

  get ready() {
    return this.models.filter((entry) => entry.format !== 'stl' || entry.model);
  }

  measure(entry) {
    return measure(entry.model, { unit: entry.unit, scale: entry.scale, maxDimensionMm: this.settings.max_dimension_mm });
  }

  // Автоматическая цена — только у STL + печать (Г6): материал и заполнение позиции («Reikia konsultacijos» — по заполнению
  // по умолчанию); у остальных строка null — «Kainą įvertins specialistas».
  priced(entry) {
    return entry.format === 'stl' && entry.method === 'printing';
  }

  // STL фрезеровки и гравировки: две наибольшие стороны больше предела milling_max_mm (Г8, шаг 3) — предупреждение.
  exceedsMilling(entry) {
    const sides = [...this.measure(entry).dimensionsMm].sort((a, b) => b - a).slice(0, 2);
    const limit = [...this.settings.milling_max_mm].sort((a, b) => b - a);
    return sides[0] > limit[0] || sides[1] > limit[1];
  }

  // Предварительная оценка по §3.1 в центах ядра; без готовых файлов — null. lines[i].cents null — цену назначит специалист.
  estimate() {
    const ready = this.ready;
    if (!ready.length) return null;
    const result = estimate({
      parts: ready.map((entry) => {
        if (!this.priced(entry)) return null;
        const { material, infill } = entry.options.printing;
        return {
          volumeCm3: this.measure(entry).volumeCm3,
          quantity: entry.quantity,
          material,
          infill: infill === 'consult' ? this.settings.default_infill : Number(infill),
        };
      }),
    }, {
      densities: this.settings.materials,
      ratePerGram: this.settings.rate_per_gram,
      orderFeeCents: this.settings.order_fee_cents,
      fileFeeCents: this.settings.file_fee_cents,
    });
    return {
      ...result,
      lines: ready.map((entry, i) => ({ entry, cents: result.lines[i] ? result.lines[i].cents : null })),
      specialist: result.lines.includes(null),
    };
  }

  // Строки боковой панели: позиции «имя × количество», плата за заказ и за файлы (только если > 0), итог. withPrices false
  // (шаг 1, правка 02.11, Г8) — суммы undefined (прочерк); cents null — «Kainą įvertins specialistas»; specialist — под
  // итогом пометка о неоценённых позициях.
  summary(withPrices) {
    const result = this.estimate();
    if (!result) return { rows: [], totalCents: undefined, specialist: false };
    const shown = (cents) => (withPrices ? cents : undefined);
    return {
      rows: [
        ...result.lines.map((line) => ({ entry: line.entry, cents: shown(line.cents) })),
        { fee: 'order_fee', cents: shown(result.orderFeeCents) },
        ...(result.fileFeesCents > 0 ? [{ fee: 'file_fees', cents: shown(result.fileFeesCents) }] : []),
      ],
      totalCents: shown(result.totalCents),
      specialist: withPrices && result.specialist,
    };
  }

  // Поля позиции для заявки (Г6): способ, его параметры, количество, срок, дата при скорой очереди, заметка; у STL — единицы
  // и масштаб.
  fields(entry) {
    return {
      method: entry.method,
      ...entry.options[entry.method],
      ...(entry.format === 'stl' ? { unit: entry.unit, scale: entry.scale } : {}),
      quantity: entry.quantity,
      priority: entry.priority,
      needed_by: entry.priority === 'priority' ? entry.neededBy : '',
      notes: entry.notes,
    };
  }

  // message для контактной формы: вступление и по строке на файл (label — подпись способа позиции); длиннее MESSAGE_MAX —
  // файлы с конца убираются, последней строкой — число неуказанных.
  specialistMessage(texts, label = () => '') {
    const lines = this.ready.map((entry) => `- ${[
      entry.name + (entry.model ? `: ${formatDimensions(this.measure(entry).dimensionsMm)}` : ''),
      label(entry),
      `${texts.quantity}: ${entry.quantity}`,
    ].filter(Boolean).join(' · ')}`);
    const head = [texts.message_intro];
    for (let shown = lines.length; shown >= 0; shown--) {
      const hidden = lines.length - shown;
      const message = [...head, ...lines.slice(0, shown), ...(hidden ? [texts.message_more.replace(':count', hidden)] : [])].join('\n');
      if (message.length <= MESSAGE_MAX) return message;
    }
    return head.join('\n').slice(0, MESSAGE_MAX);
  }
}
