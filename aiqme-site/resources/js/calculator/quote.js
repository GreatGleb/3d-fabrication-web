// Состояние заявки калькулятора (W-27) без DOM: файлы, разбор STL, параметры у каждой позиции (правка 02.11, Г8), измерение
// ядра W-10 и сообщение для специалиста. Разбор передаётся параметром (в браузере — Web Worker, в тестах — parseStl напрямую).
// Правка 05.11, H-04: оценки в браузере нет — цену называет администратор (серверную оценку считает EstimateService).
import { MAX_TRIANGLES, StlError, measure, parseStl } from './core/index.js';

// Принимаемые форматы (правка 02.11, Г6): STL разбирается и измеряется, остальные — без разбора, цену назначит специалист.
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

export class Quote {
  // settings: { materials (названия), default_infill, max_dimension_mm, milling_materials,
  // engraving_materials, milling_max_mm, max_file_bytes, max_files } из
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
        printing: { material: settings.materials[0], infill: String(settings.default_infill), ...PRINTING_DEFAULTS },
        // Толщина и глубина вводятся числом (правка 05.11, H-05): по умолчанию пусто.
        milling: { material: settings.milling_materials[0].lt, thickness: '' },
        engraving: { material: settings.engraving_materials[0].lt, depth: '' },
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

  // Серверная оценка — только у STL + печать (Г6); у остальных карточка шага 2 пишет «Kainą įvertins specialistas»
  // (правка 05.11, H-04: суммы покупателю не показываются).
  priced(entry) {
    return entry.format === 'stl' && entry.method === 'printing';
  }

  // STL фрезеровки и гравировки: две наибольшие стороны больше предела milling_max_mm (Г8; проверка в карточке шага 2) — предупреждение.
  exceedsMilling(entry) {
    const sides = [...this.measure(entry).dimensionsMm].sort((a, b) => b - a).slice(0, 2);
    const limit = [...this.settings.milling_max_mm].sort((a, b) => b - a);
    return sides[0] > limit[0] || sides[1] > limit[1];
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
