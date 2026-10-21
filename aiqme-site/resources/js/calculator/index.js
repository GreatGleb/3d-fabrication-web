// Интерфейс калькулятора (W-27): 5 шагов прототипа поверх Quote (quote.js) и ядра W-10. Разбор — в Web Worker;
// без Worker — в основном потоке. Просмотр модели (Canvas 2D, W-31) — в .viewer; расчёт от него не зависит.
import { StlError } from './core/index.js';
import { Quote, formatDimensions, formatMoney } from './quote.js';
import { mountViewer } from './viewer/index.js';

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

function start(root) {
  const settings = JSON.parse(root.dataset.settings);
  const texts = JSON.parse(root.dataset.texts);
  const locale = root.dataset.locale;
  const $ = (selector) => root.querySelector(selector);
  const t = (key, values = {}) => Object.entries({ mb: texts.mb, ...values })
    .reduce((text, [name, value]) => text.replaceAll(`:${name}`, value), texts[key]);
  const money = (cents) => formatMoney(cents, locale);
  const selectedText = (name) => root.querySelector(`[name=${name}]`).selectedOptions[0].text;

  let step = 0;
  const viewer = mountViewer($('[data-viewer]'));
  let specialistFiles = []; // OBJ/STEP: в сообщение специалисту, без разбора
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
    specialistFiles = [...specialistFiles, ...results.filter((r) => r.error === 'specialist_format').map((r) => r.name)];
    const errors = results.filter((r) => r.error).map((r) => t(r.error, { name: r.name, max: settings.max_files }));
    showErrors(errors);
    if (results.some((r) => ['specialist_format', 'unsupported'].includes(r.error))) {
      $('#quote-error .notice').insertAdjacentHTML('beforeend', `<br><a class="text-link" data-specialist>${html(root.querySelector('.help-box [data-specialist]').textContent)}</a>`);
    }
    render();
  }

  function parameters() {
    return [
      `FDM / ${quote.material}`,
      `${texts.infill}: ${selectedText('infill').split(' · ')[0]}`,
      ...['color', 'quality', 'layer', 'finish', 'term'].map((name) => `${root.querySelector(`[name=${name}]`).closest('label').firstChild.textContent.trim()}: ${selectedText(name)}`),
    ].join(', ');
  }

  function specialistUrl() {
    const url = new URL(root.dataset.contactUrl, location.href);
    url.searchParams.set('message', quote.specialistMessage(texts, parameters(), specialistFiles));
    return url.toString();
  }

  function goTo(next) {
    step = next;
    showErrors([]);
    render();
    window.scrollTo({ top: 0 });
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

    // Шаг 1: файлы и активная модель
    $('#upload-progress').innerHTML = quote.busy ? `<label class="field gap">${html(texts.reading)}<progress></progress></label>` : '';
    $('[data-files]').innerHTML = quote.models.map((entry, i) => `<div class="file-row${i === quote.active ? ' active' : ''}">
      <button type="button" class="icon-btn" data-select="${i}" style="text-align:left;justify-content:start"><span><strong>${html(entry.name)}</strong>
      <small>${html(entry.model ? `${texts.ready} · STL` : texts.reading)}</small></span></button>
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

    // Шаг 2: количество по модели
    $('[data-quantities]').innerHTML = quote.ready.map((entry) => `<div class="file-row"><strong>${html(entry.name)}</strong>
      <label class="field">${html(texts.quantity)}<input type="number" min="1" max="100" value="${entry.quantity}" data-quantity="${entry.id}" style="width:90px"></label></div>`).join('');

    // Шаг 3: габарит, количество, предупреждения ядра (не блокируют)
    const warnings = [];
    $('[data-review]').innerHTML = quote.ready.map((entry) => {
      const measured = quote.measure(entry);
      if (measured.warnings.includes('open_mesh')) warnings.push(t('open_mesh', { name: entry.name }));
      if (measured.warnings.includes('exceeds_build_size')) warnings.push(t('exceeds', { name: entry.name, mm: settings.max_dimension_mm }));
      return `<div class="check-row"><div><h3>${html(entry.name)}</h3>
        <p>${html(formatDimensions(measured.dimensionsMm))} · ${html(texts.quantity)}: ${entry.quantity}</p><p>${html(texts.geometry_read)}</p></div></div>`;
    }).join('');
    $('[data-warnings]').hidden = !warnings.length;
    $('[data-warning-list]').innerHTML = warnings.map(html).join('<br>');

    // Шаг 4 и сводка: строки — центы ядра; итог = сумма показанных строк
    $('[data-price-rows]').innerHTML = result ? result.lines.map((line) => `<tr><td>${html(line.entry.name)}<br><small>FDM / ${html(quote.material)}</small></td>
      <td>${line.entry.quantity}</td><td>${money(line.cents)}</td></tr>`).join('') : '';
    const rows = result ? [
      ...result.lines.map((line) => [`${line.entry.name} × ${line.entry.quantity}`, line.cents]),
      [texts.order_fee, result.orderFeeCents],
      ...(result.fileFeesCents > 0 ? [[texts.file_fees, result.fileFeesCents]] : []),
    ] : [];
    $('[data-summary-rows]').innerHTML = rows.map(([label, cents]) => `<div class="summary-row"><span>${html(label)}</span><b data-cents="${cents}">${money(cents)}</b></div>`).join('');
    $('[data-total]').textContent = money(result ? result.totalCents : 0);
    $('[data-total]').dataset.cents = result ? result.totalCents : 0;

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

  root.addEventListener('click', (event) => {
    const target = event.target.closest('button');
    if (!target) return;
    if (target.dataset.select !== undefined) {
      quote.active = Number(target.dataset.select);
      render();
    } else if (target.dataset.remove !== undefined) {
      quote.removeAt(Number(target.dataset.remove));
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
    const entry = quote.models.find((model) => model.id === Number(dataset.id));
    if (!entry) return;
    entry.unit = dataset.unit;
    entry.scale = Number(dataset.scale);
    render();
  });

  root.addEventListener('change', (event) => {
    const field = event.target;
    if (field.dataset.quantity) {
      const entry = quote.models.find((model) => model.id === Number(field.dataset.quantity));
      entry.quantity = Math.min(100, Math.max(1, Math.round(Number(field.value)) || 1));
    } else if (field.name === 'material') {
      quote.material = field.value;
    } else if (field.name === 'infill') {
      quote.infill = Number(field.value);
    } else if (field.name === 'delivery') {
      $('[data-address]').hidden = field.value !== 'ship';
    }
    render();
  });

  render();
}

const root = document.getElementById('quote');
if (root) start(root);
