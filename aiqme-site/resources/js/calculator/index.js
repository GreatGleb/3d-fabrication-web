// Интерфейс калькулятора (W-27): 5 шагов прототипа поверх Quote (quote.js) и ядра W-10. Разбор STL — в Web Worker;
// без Worker — в основном потоке. Просмотр модели (Canvas 2D, W-31) — в .viewer; расчёт от него не зависит.
// Правка 02.11, Г8 (макет 17.09): шаг 1 принимает STL/STEP/OBJ/DXF/DWG и без сумм; шаг 2 — карточка на каждый файл со своими
// способом и параметрами; шаг 3 — проверка по файлам; шаг 4 и панель — суммы оценённых позиций и «Kainą įvertins specialistas».
import { StlError } from './core/index.js';
import { Quote, formatDimensions, formatMoney } from './quote.js';
import { mountViewer } from './viewer/index.js';
import { loadSavedModels, modelIds } from './saved-models.js';

function parseInWorker(buffer) {
  return new Promise((resolve, reject) => {
    const worker = new Worker(new URL('./parse-worker.js', import.meta.url), { type: 'module' });
    worker.onmessage = ({ data }) => {
      worker.terminate();
      if (data.error) reject(new StlError(data.error));
      else resolve(data.model);
    };
    worker.onerror = (event) => {
      worker.terminate();
      reject(event);
    };
    worker.postMessage(buffer);
  });
}

const html = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const METHOD_ICONS = { printing: 'print_layers', milling: 'milling_cutter_surface', engraving: 'engraving_cutter_surface' };

function start(root) {
  const settings = JSON.parse(root.dataset.settings);
  const texts = JSON.parse(root.dataset.texts);
  const locale = root.dataset.locale;
  const $ = (selector) => root.querySelector(selector);
  const t = (key, values = {}) => Object.entries({ mb: texts.mb, ...values })
    .reduce((text, [name, value]) => text.replaceAll(`:${name}`, value), texts[key]);
  const money = (cents) => formatMoney(cents, locale);
  const number = (value, digits = 1) => new Intl.NumberFormat(locale === 'lt' ? 'lt-LT' : 'en-IE', { maximumFractionDigits: digits }).format(value);
  // Значки заказчика (icons/ui/, правка 02.11) — <img> без изменения рисунка.
  const icon = (name, alt = '') => `<img class="ui-icon" src="${root.dataset.icons}/${name}.svg" alt="${html(alt)}" width="20" height="20">`;
  const size = (bytes) => (bytes < 1024 * 1024 ? `${number(Math.max(1, Math.round(bytes / 1024)), 0)} KB` : `${number(bytes / 1024 / 1024)} MB`);
  // Пункты списков: значение → подпись.
  const lists = (entry) => ({
    material: entry.method === 'printing'
      ? Object.fromEntries(Object.keys(settings.materials).map((name) => [name, name]))
      : Object.fromEntries(settings[`${entry.method}_materials`].map((item) => [item.lt, item[locale === 'lt' ? 'lt' : 'en']])),
    color: texts.settings.colors,
    purpose: texts.settings.purposes,
    service: texts.settings.services,
    surface: texts.settings.surfaces,
    infill: {
      ...Object.fromEntries([...new Set([15, 20, 40, 60, 80, 100, settings.default_infill])].sort((a, b) => a - b)
        .map((value) => [value, texts.settings.infill_option.replace(':value', value) + (value === settings.default_infill ? texts.settings.recommended : '')])),
      consult: texts.settings.infill_consult,
    },
    thickness: Object.fromEntries(settings.milling_thicknesses_mm.map((value) => [value, `${number(value, 2)} mm`])),
    depth: Object.fromEntries(settings.engraving_depths_mm.map((value) => [value, `${number(value, 2)} mm`])),
  });
  const fieldLabels = {
    material: texts.settings.material, color: texts.settings.color, purpose: texts.settings.purpose, service: texts.settings.service,
    surface: texts.settings.surface, infill: texts.settings.infill, thickness: texts.part.thickness, depth: texts.part.depth,
  };
  // Подпись позиции, как StlPart::label на сервере: «3D spausdinimas / PLA», «Frezavimas / Aliuminis, 6 mm».
  const label = (entry) => {
    const options = entry.options[entry.method];
    const list = lists(entry);
    const detail = entry.method === 'printing' ? options.material
      : `${list.material[options.material]}, ${list[entry.method === 'milling' ? 'thickness' : 'depth'][options[entry.method === 'milling' ? 'thickness' : 'depth']]}`;
    return `${texts.methods[entry.method]} / ${detail}`;
  };

  let step = 0;
  const viewer = mountViewer($('[data-viewer]'));
  const quote = new Quote(settings, {
    parse: typeof Worker === 'function' ? parseInWorker : undefined,
    onChange: render,
  });

  function showErrors(messages) {
    $('#quote-error').innerHTML = messages.length ? `<div class="notice error">${messages.map(html).join('<br>')}</div>` : '';
  }

  async function addFiles(files) {
    showErrors([]);
    const results = await Promise.all([...files].map((file) => quote.addFile(file)));
    showErrors(results.filter((r) => r.error).map((r) => t(r.error, { name: r.name, max: settings.max_files })));
    render();
  }

  function specialistUrl() {
    const url = new URL(root.dataset.contactUrl, location.href);
    url.searchParams.set('message', quote.specialistMessage(texts, label));
    return url.toString();
  }

  // Заявка (W-12, §3.1): один multipart-запрос — контакты и получение из формы шага 5 (с CSRF, токеном отправки и
  // honeypot) и по каждой позиции файл и её поля (правка 02.11, Г8). Сумму сервер считает сам. Ошибки проверки — под полями
  // формы, остальные — списком; успех — страница подтверждения.
  const form = $('#checkout-form');

  function showFieldErrors(errors) {
    form.querySelectorAll('small[data-error]').forEach((node) => node.remove());
    form.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
    const general = [];
    Object.entries(errors).forEach(([key, messages]) => {
      const field = form.querySelector(`[name="${key}"]:not([type=hidden])`);
      if (field?.closest('label.field')) {
        field.setAttribute('aria-invalid', 'true');
        field.closest('label.field').insertAdjacentHTML('beforeend', `<small data-error>${html(messages[0])}</small>`);
      } else {
        general.push(...messages);
      }
    });
    showErrors(general);
  }

  async function submitOrder() {
    const data = new FormData(form);
    quote.ready.forEach((entry, i) => {
      data.append(`parts[${i}][file]`, new Blob([entry.buffer]), entry.name);
      Object.entries(quote.fields(entry)).forEach(([name, value]) => data.append(`parts[${i}][${name}]`, value));
    });
    const button = form.querySelector('[type=submit]');
    button.disabled = true;
    try {
      const response = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
      const body = await response.json().catch(() => ({}));
      if (response.ok && body.redirect) {
        location.assign(body.redirect);
        return;
      }
      if (response.status === 422) showFieldErrors(body.errors ?? {});
      else showErrors([response.status === 429 ? texts.throttle : response.status === 413 && body.message ? body.message : texts.submit_failed]);
    } catch {
      showErrors([texts.submit_failed]);
    }
    button.disabled = false;
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    submitOrder();
  });

  function goTo(next) {
    step = next;
    showErrors([]);
    render();
    window.scrollTo({ top: 0 });
  }

  const select = (entry, name, options, value) => `<label class="field" for="part-${entry.id}-${name}">${html(fieldLabels[name])}
    <select id="part-${entry.id}-${name}" data-part="${entry.id}" data-option="${name}">${Object.entries(options).map(([key, text]) =>
      `<option value="${html(key)}"${String(key) === String(value) ? ' selected' : ''}>${html(text)}</option>`).join('')}</select></label>`;

  const tomorrow = () => {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  };

  // Карточка позиции шага 2 (макет 17.09): заголовок, способ, поля способа, приоритет и дата, заметка; свёрнутая — сводка.
  function card(entry) {
    const options = entry.options[entry.method];
    const list = lists(entry);
    const names = entry.method === 'printing' ? ['material', 'color', 'purpose', 'service', 'surface', 'infill']
      : ['material', entry.method === 'milling' ? 'thickness' : 'depth'];
    const urgent = entry.priority === 'priority';
    const summary = [texts.methods[entry.method], ...names.map((name) => list[name][options[name]]), texts.settings.terms[entry.priority],
      ...(urgent && entry.neededBy ? [`${texts.part.needed_by}: ${entry.neededBy}`] : [])].join(' · ');
    const id = `part-${entry.id}`;
    return `<article class="part-card${entry.collapsed ? ' collapsed' : ''}" data-card="${entry.id}">
      <header class="part-head">
        ${icon(entry.format === 'dxf' || entry.format === 'dwg' ? 'file' : 'order_cube')}
        <span class="part-name"><strong>${html(entry.name)}</strong><small>${entry.format.toUpperCase()} • ${size(entry.bytes)}</small></span>
        ${urgent ? `<span class="tag orange part-urgent" data-urgent>${icon('urgent_bolt')}${html(texts.part.urgent)}</span>` : ''}
        <span class="part-quantity"><label for="${id}-quantity">${html(texts.quantity)}</label>
          <button type="button" class="icon-btn" data-step-quantity="-1" data-part="${entry.id}">${icon('minus', '−')}</button>
          <input type="number" id="${id}-quantity" min="1" max="100" value="${entry.quantity}" data-quantity="${entry.id}">
          <button type="button" class="icon-btn" data-step-quantity="1" data-part="${entry.id}">${icon('plus', '+')}</button></span>
        <button type="button" class="text-link part-toggle" id="${id}-toggle" data-toggle="${entry.id}" aria-expanded="${!entry.collapsed}">${html(texts.part.change)} ${icon(entry.collapsed ? 'chevron_down' : 'chevron_up')}</button>
      </header>
      ${entry.collapsed ? `<p class="part-summary" data-summary>${html(summary)}</p>` : `
      <fieldset class="part-methods"><legend>${html(texts.part.method)}</legend>
        ${quote.methods(entry).map((method) => `<label class="part-method${method === entry.method ? ' active' : ''}">
          <input type="radio" class="sr-only" id="${id}-method-${method}" name="${id}-method" value="${method}" data-part="${entry.id}" data-method${method === entry.method ? ' checked' : ''}>
          ${icon(METHOD_ICONS[method])}<span>${html(texts.methods[method])}</span></label>`).join('')}
      </fieldset>
      <div class="part-fields ${entry.method === 'printing' ? 'four' : 'three'}">
        ${names.map((name) => select(entry, name, list[name], options[name])).join('')}
        <label class="field" for="${id}-priority"><span class="field-icon">${icon('clock')}${html(texts.part.priority)}</span>
          <select id="${id}-priority" data-part="${entry.id}" data-priority>${Object.entries(texts.settings.terms).map(([key, text]) =>
            `<option value="${key}"${key === entry.priority ? ' selected' : ''}>${html(text)}</option>`).join('')}</select></label>
        ${urgent ? `<label class="field" for="${id}-needed-by"><span class="field-icon">${icon('calendar')}${html(texts.part.needed_by)}</span>
          <input type="date" id="${id}-needed-by" min="${tomorrow()}" value="${html(entry.neededBy)}" data-part="${entry.id}" data-needed-by></label>` : ''}
      </div>
      ${urgent ? `<p class="part-note">${icon('info')}${html(texts.part.rush_note)}</p>` : ''}
      <label class="field" for="${id}-notes">${html(texts.part.notes)}
        <textarea id="${id}-notes" maxlength="2000" placeholder="${html(texts.part.notes_placeholder)}" data-part="${entry.id}" data-notes>${html(entry.notes)}</textarea></label>`}
    </article>`;
  }

  // Шаг 3 (макет 18.49): блок на файл — размеры и количество, предел оборудования по способу, предупреждения.
  function review(entry, warnings) {
    const lines = [];
    const fdm = settings.max_dimension_mm;
    const [width, length] = settings.milling_max_mm;
    let measured = null;
    if (entry.model) {
      measured = quote.measure(entry);
      if (measured.warnings.includes('open_mesh')) warnings.push(t('open_mesh', { name: entry.name }));
    }
    if (entry.method === 'printing') {
      if (measured) {
        lines.push(`${texts.review.build} · ${texts.review.build_text.replaceAll(':mm', fdm)}`);
        if (measured.warnings.includes('exceeds_build_size')) warnings.push(t('exceeds', { name: entry.name, mm: fdm }));
      }
    } else {
      lines.push(texts.review.cnc.replace(':w', width).replace(':h', length));
      if (measured && quote.exceedsMilling(entry)) warnings.push(t('exceeds', { name: entry.name, mm: `${width} × ${length}` }));
    }
    lines.push(entry.model ? texts.geometry_read : texts.review.specialist_file);
    return `<div class="check-row" data-review-file="${entry.id}">${icon(entry.model ? 'check' : 'file')}<div><h3>${html(entry.name)}</h3>
      <p>${measured ? `${html(formatDimensions(measured.dimensionsMm))} · ` : ''}${html(texts.quantity)}: ${entry.quantity}</p>
      ${lines.map((line) => `<p>${html(line)}</p>`).join('')}</div></div>`;
  }

  function render() {
    const active = quote.models[quote.active];
    const result = quote.estimate();

    // Шаги
    root.querySelectorAll('[data-step]').forEach((button, i) => {
      button.className = `step${i === step ? ' active' : i < step ? ' done' : ''}`;
      button.disabled = i > step;
      button.querySelector('b').textContent = i < step ? '✓' : i + 1;
    });
    root.querySelectorAll('[data-step-panel]').forEach((panel) => { panel.hidden = Number(panel.dataset.stepPanel) !== step; });
    $('[data-actions]').hidden = step === 4;
    $('[data-actions] [data-back]').disabled = step === 0;
    const next = $('[data-next] span');
    next.dataset.label ??= next.textContent;
    next.textContent = step === 3 ? $('[data-next]').dataset.labelLast : next.dataset.label;

    // Шаг 1: файлы; STL — выбор модели для просмотра, остальные — строка со значком без просмотра.
    $('#upload-progress').innerHTML = quote.busy ? `<label class="field gap">${html(texts.reading)}<progress></progress></label>` : '';
    $('[data-files]').innerHTML = quote.models.map((entry, i) => `<div class="file-row${i === quote.active ? ' active' : ''}" data-file="${entry.id}">
      ${entry.format === 'stl' ? `<button type="button" class="icon-btn" data-select="${i}" style="text-align:left;justify-content:start"><span><strong>${html(entry.name)}</strong>
      <small>${html(entry.model ? `${texts.ready} · STL` : texts.reading)}</small></span></button>`
        : `<span class="file-info">${icon('file')}<span><strong>${html(entry.name)}</strong><small>${entry.format.toUpperCase()} • ${size(entry.bytes)}</small></span></span>`}
      <button type="button" class="icon-btn" data-remove="${i}" aria-label="${html(texts.remove)}">×</button></div>`).join('');
    $('[data-model]').hidden = !active?.model;
    if (active?.model) {
      // Поля единиц и масштаба — значения модели только при смене активной модели: иначе render после change
      // стёр бы ещё не подтверждённый ввод.
      if ($('[data-viewer]').dataset.modelId !== String(active.id)) {
        $('[data-viewer]').dataset.modelId = active.id;
        $('#model-unit').value = active.unit;
        $('#model-scale').value = active.scale;
        viewer.show(active.model);
      }
      $('[data-dimensions]').textContent = formatDimensions(quote.measure(active).dimensionsMm);
    }

    // Шаг 2: карточки; разметка заменяется только при изменении, фокус возвращается на то же поле.
    const cards = quote.ready.map(card).join('');
    const container = $('[data-cards]');
    if (container.dataset.html !== cards) {
      const focused = container.contains(document.activeElement) ? document.activeElement.id : null;
      container.innerHTML = cards;
      container.dataset.html = cards;
      if (focused) document.getElementById(focused)?.focus();
    }

    // Шаг 3: по файлам; предупреждения не блокируют.
    const warnings = [];
    $('[data-review]').innerHTML = quote.ready.map((entry) => review(entry, warnings)).join('');
    $('[data-warnings]').hidden = !warnings.length;
    $('[data-warning-list]').innerHTML = warnings.map(html).join('<br>');

    // Шаг 4 и панель: строки — центы ядра или «Kainą įvertins specialistas»; итог = сумма известных строк и сборов. На шаге 1
    // сумм нет — прочерк.
    const price = (cents) => (cents === null ? texts.price.specialist : money(cents));
    $('[data-price-rows]').innerHTML = result ? result.lines.map((line) => `<tr><td>${html(line.entry.name)}<br><small>${html(label(line.entry))}</small></td>
      <td>${line.entry.quantity}</td><td>${html(price(line.cents))}</td></tr>`).join('') : '';
    const summary = quote.summary(step > 0);
    $('[data-summary-rows]').innerHTML = summary.rows.map((row) => `<div class="summary-row"><span>${html(row.entry ? `${row.entry.name} × ${row.entry.quantity}` : texts[row.fee])}</span>${
      row.cents === undefined ? '<b>—</b>' : `<b${row.cents === null ? ' data-specialist-price' : ` data-cents="${row.cents}"`}>${html(price(row.cents))}</b>`}</div>`).join('');
    const total = $('[data-total]');
    total.textContent = summary.totalCents === undefined ? '—' : money(summary.totalCents);
    if (summary.totalCents === undefined) delete total.dataset.cents;
    else total.dataset.cents = summary.totalCents;
    $('[data-specialist-total]').hidden = !summary.specialist;

    const url = specialistUrl();
    root.querySelectorAll('[data-specialist]').forEach((link) => { link.href = url; });
  }

  // События
  const input = $('#model-upload');
  input.addEventListener('change', () => {
    addFiles(input.files);
    input.value = '';
  });
  const drop = $('#drop-zone');
  drop.addEventListener('dragover', (event) => event.preventDefault());
  drop.addEventListener('drop', (event) => {
    event.preventDefault();
    addFiles(event.dataTransfer.files);
  });

  const setQuantity = (entry, value) => { entry.quantity = Math.min(100, Math.max(1, Math.round(Number(value)) || 1)); };

  root.addEventListener('click', (event) => {
    const target = event.target.closest('button');
    if (!target) return;
    if (target.dataset.select !== undefined) {
      quote.active = Number(target.dataset.select);
      render();
    } else if (target.dataset.remove !== undefined) {
      quote.removeAt(Number(target.dataset.remove));
    } else if (target.dataset.stepQuantity !== undefined) {
      const entry = quote.find(target.dataset.part);
      setQuantity(entry, entry.quantity + Number(target.dataset.stepQuantity));
      render();
    } else if (target.dataset.toggle !== undefined) {
      const entry = quote.find(target.dataset.toggle);
      entry.collapsed = !entry.collapsed;
      render();
    } else if (target.dataset.step !== undefined) {
      if (Number(target.dataset.step) <= step) goTo(Number(target.dataset.step));
    } else if (target.matches('[data-back]')) {
      goTo(Math.max(0, step - 1));
    } else if (target.matches('[data-next]')) {
      if (step === 0 && (!quote.ready.length || quote.busy)) {
        if (!quote.busy) showErrors([texts.no_model]);
        return;
      }
      goTo(Math.min(4, step + 1));
    }
  });

  // Единицы и масштаб — с подтверждением новых размеров (окно x-modal); неверный масштаб окно не открывает.
  $('[data-apply-scale]').addEventListener('click', (event) => {
    const active = quote.models[quote.active];
    const unit = $('#model-unit').value;
    const scale = Number($('#model-scale').value);
    if (!Number.isFinite(scale) || scale < 1 || scale > 1000) {
      event.stopPropagation();
      showErrors([texts.scale_range]);
      return;
    }
    showErrors([]);
    const before = formatDimensions(quote.measure(active).dimensionsMm);
    const after = formatDimensions(quote.measure({ ...active, unit, scale }).dimensionsMm);
    const confirm = document.querySelector('[data-scale-confirm]');
    Object.assign(confirm.dataset, { id: active.id, unit, scale });
    document.querySelector('[data-scale-text]').innerHTML = `${html(active.name)}<br>${html(texts.before)}: ${before}<br>${html(texts.after)}: ${after}`;
  });
  document.querySelector('[data-scale-confirm]').addEventListener('click', ({ currentTarget: { dataset } }) => {
    const entry = quote.find(dataset.id);
    if (!entry) return;
    entry.unit = dataset.unit;
    entry.scale = Number(dataset.scale);
    render();
  });

  root.addEventListener('change', (event) => {
    const field = event.target;
    const entry = field.dataset.part ? quote.find(field.dataset.part) : null;
    if (field.dataset.quantity) {
      setQuantity(quote.find(field.dataset.quantity), field.value);
    } else if (entry && field.dataset.method !== undefined) {
      entry.method = field.value;
    } else if (entry && field.dataset.option) {
      entry.options[entry.method][field.dataset.option] = field.value;
    } else if (entry && field.dataset.priority !== undefined) {
      entry.priority = field.value;
    } else if (entry && field.dataset.neededBy !== undefined) {
      entry.neededBy = field.value;
    } else if (entry && field.dataset.notes !== undefined) {
      // Заметка в сумме и сводке не участвует: без перерисовки, иначе кнопка, нажатая сразу после ввода, была бы заменена
      // посреди щелчка.
      entry.notes = field.value;
      return;
    } else if (field.name === 'delivery') {
      $('[data-address]').hidden = field.value !== 'ship';
    }
    render();
  });

  render();

  // ?models=<id,…> из кабинета (W-15): модели заказов клиента загружаются как обычные файлы.
  const ids = modelIds(location.search, settings.max_files);
  if (ids.length) {
    loadSavedModels(ids, (name, buffer) => quote.addBuffer(name, buffer)).then((results) => {
      showErrors(results.filter((r) => r.error).map((r) => t(r.error, { name: r.name, max: settings.max_files })));
      render();
    });
  }
}

const root = document.getElementById('quote');
if (root) start(root);
