(() => {
  const form = document.getElementById('stl-calculator');

  function formatMeasurement(number, digits = 2) {
    if (!Number.isFinite(number)) return '—';
    const options = number !== 0 && Math.abs(number) < 10 ** -digits
      ? { notation: 'scientific', maximumSignificantDigits: 4 }
      : { maximumFractionDigits: digits };
    return new Intl.NumberFormat('ru-RU', options).format(number);
  }

  const MAX_BYTES = 25 * 1024 * 1024;
  const MAX_TRIANGLES = 500000;
  const fileInput = document.getElementById('stl-file');
  const unitInput = document.getElementById('stl-unit');
  const densityInput = document.getElementById('stl-density');
  const quantityInput = document.getElementById('stl-quantity');
  const status = document.getElementById('calculator-status');
  const empty = document.getElementById('calculator-empty');
  const values = document.getElementById('calculator-values');
  const resultWarning = document.getElementById('result-warning');
  const submit = form?.querySelector('button[type="submit"]');
  let parsed = null;
  let parsedFilename = '';
  let revision = 0;

  function makeAccumulator() {
    const min = [Infinity, Infinity, Infinity];
    const max = [-Infinity, -Infinity, -Infinity];
    let origin = null;
    let signedVolume = 0;
    let triangles = 0;

    function add(a, b, c) {
      for (const point of [a, b, c]) {
        for (let axis = 0; axis < 3; axis += 1) {
          if (!Number.isFinite(point[axis])) throw new Error('Invalid coordinate');
          min[axis] = Math.min(min[axis], point[axis]);
          max[axis] = Math.max(max[axis], point[axis]);
        }
      }
      if (!origin) origin = a;
      const ax = a[0] - origin[0], ay = a[1] - origin[1], az = a[2] - origin[2];
      const bx = b[0] - origin[0], by = b[1] - origin[1], bz = b[2] - origin[2];
      const cx = c[0] - origin[0], cy = c[1] - origin[1], cz = c[2] - origin[2];
      signedVolume += (ax * (by * cz - bz * cy) + ay * (bz * cx - bx * cz) + az * (bx * cy - by * cx)) / 6;
      triangles += 1;
      if (triangles > MAX_TRIANGLES) throw new Error('Too many triangles');
    }

    function finish() {
      if (!triangles) throw new Error('No triangles');
      return { triangles, dimensions: min.map((lower, axis) => max[axis] - lower), volume: Math.abs(signedVolume) };
    }
    return { add, finish };
  }

  function parseBinary(buffer, triangleCount) {
    const view = new DataView(buffer);
    const accumulator = makeAccumulator();
    for (let i = 0; i < triangleCount; i += 1) {
      const offset = 84 + i * 50 + 12;
      const a = [0, 4, 8].map((delta) => view.getFloat32(offset + delta, true));
      const b = [12, 16, 20].map((delta) => view.getFloat32(offset + delta, true));
      const c = [24, 28, 32].map((delta) => view.getFloat32(offset + delta, true));
      accumulator.add(a, b, c);
    }
    return accumulator.finish();
  }

  function parseAscii(buffer) {
    const content = new TextDecoder('utf-8', { fatal: true }).decode(buffer);
    if (!content.trimStart().startsWith('solid') || !/facet\s+normal/i.test(content)) throw new Error('Not ASCII STL');
    const vertexLine = /^\s*vertex\s+(\S+)\s+(\S+)\s+(\S+)\s*$/gim;
    const accumulator = makeAccumulator();
    const vertices = [];
    for (const match of content.matchAll(vertexLine)) {
      const vertex = [Number(match[1]), Number(match[2]), Number(match[3])];
      if (vertex.some((value) => !Number.isFinite(value))) throw new Error('Invalid coordinate');
      vertices.push(vertex);
      if (vertices.length === 3) {
        accumulator.add(vertices[0], vertices[1], vertices[2]);
        vertices.length = 0;
      }
    }
    if (vertices.length) throw new Error('Incomplete triangle');
    return accumulator.finish();
  }

  function parseStl(buffer) {
    if (buffer.byteLength >= 84) {
      const view = new DataView(buffer);
      const count = view.getUint32(80, true);
      const expected = 84 + count * 50;
      if (count > 0 && count <= MAX_TRIANGLES && expected <= buffer.byteLength && buffer.byteLength - expected <= 80) {
        return parseBinary(buffer, count);
      }
    }
    return parseAscii(buffer);
  }

  function initQuickCalculator() {
    const quick = document.getElementById('quick-stl-calculator');
    if (!quick) return;
    const input = document.getElementById('quick-stl-file');
    const unit = document.getElementById('quick-stl-unit');
    const message = document.getElementById('quick-calculator-status');
    const result = document.getElementById('quick-calculator-result');
    const button = quick.querySelector('button[type="submit"]');
    let model = null;
    let revision = 0;
    const format = formatMeasurement;
    function show(messageText, processing = false) {
      message.textContent = messageText;
      message.hidden = !messageText;
      message.classList.toggle('is-processing', processing);
    }
    function render() {
      if (!model) return;
      const scale = { mm: 1, cm: 10, inch: 25.4 }[unit.value];
      const volume = model.volume * scale ** 3 / 1000;
      document.getElementById('quick-result-dimensions').textContent = model.dimensions.map((size) => format(size * scale)).join(' × ');
      document.getElementById('quick-result-volume').textContent = Number.isFinite(volume) && volume > 0 ? format(volume, 4) : '—';
      result.hidden = false;
      show(Number.isFinite(volume) && volume > 0 ? '' : quick.dataset.errorVolume);
    }
    input.addEventListener('change', () => { revision++; model = null; result.hidden = true; button.disabled = false; show(''); });
    unit.addEventListener('change', render);
    quick.addEventListener('submit', async (event) => {
      event.preventDefault();
      const file = input.files[0];
      if (!file) return show(quick.dataset.errorFile);
      if (!file.name.toLowerCase().endsWith('.stl')) return show(quick.dataset.errorFormat);
      if (file.size > MAX_BYTES) return show(quick.dataset.errorSize);
      const currentRevision = ++revision;
      button.disabled = true;
      show(quick.dataset.processing, true);
      try {
        const buffer = await file.arrayBuffer();
        if (currentRevision !== revision) return;
        model = parseStl(buffer);
        render();
      } catch (_) {
        if (currentRevision !== revision) return;
        model = null;
        result.hidden = true;
        show(quick.dataset.errorFormat);
      } finally {
        if (currentRevision === revision) button.disabled = false;
      }
    });
  }

  window.AIQMEStlCalculator = { parseStl };
  initQuickCalculator();
  if (!form) return;

  function showStatus(message, processing = false) {
    status.textContent = message;
    status.classList.toggle('is-processing', processing);
    status.hidden = !message;
  }

  function format(number, digits = 2) {
    return formatMeasurement(number, digits);
  }

  const emptyText = empty.textContent;
  function parameterError() {
    const density = densityInput.value.trim() === '' ? null : Number(densityInput.value);
    const quantity = Number(quantityInput.value);
    if (densityInput.validity.badInput || (density !== null && (!Number.isFinite(density) || density <= 0 || density > 30))) return form.dataset.errorDensity;
    if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 10000) return form.dataset.errorQuantity;
    return '';
  }
  function renderResults() {
    const error = parameterError();
    if (!parsed) {
      if (!status.hidden && [form.dataset.errorDensity, form.dataset.errorQuantity].includes(status.textContent)) showStatus(error);
      return;
    }
    if (error) {
      values.hidden = true;
      empty.hidden = false;
      empty.textContent = empty.dataset.invalid;
      showStatus(error);
      return;
    }
    const scale = { mm: 1, cm: 10, inch: 25.4 }[unitInput.value];
    const density = densityInput.value.trim() === '' ? null : Number(densityInput.value);
    const quantity = Number(quantityInput.value);
    const volume = parsed.volume * scale ** 3 / 1000;
    const validVolume = Number.isFinite(volume) && volume > 0;
    document.getElementById('result-file').textContent = parsedFilename;
    document.getElementById('result-triangles').textContent = format(parsed.triangles, 0);
    document.getElementById('result-dimensions').textContent = parsed.dimensions.map((size) => format(size * scale)).join(' × ');
    document.getElementById('result-volume').textContent = validVolume ? format(volume, 4) : '—';
    document.getElementById('result-mass').textContent = validVolume && density !== null ? format(volume * density * quantity, 2) : '—';
    empty.hidden = true;
    values.hidden = false;
    resultWarning.hidden = false;
    showStatus(validVolume ? '' : form.dataset.errorVolume);
  }

  fileInput.addEventListener('change', () => {
    revision++;
    submit.disabled = false;
    parsed = null;
    parsedFilename = '';
    empty.hidden = false;
    empty.textContent = emptyText;
    values.hidden = true;
    showStatus('');
  });
  [unitInput, densityInput, quantityInput].forEach((input) => input.addEventListener('input', renderResults));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const file = fileInput.files[0];
    if (!file) return showStatus(form.dataset.errorFile);
    if (!file.name.toLowerCase().endsWith('.stl')) return showStatus(form.dataset.errorFormat);
    if (file.size > MAX_BYTES) return showStatus(form.dataset.errorSize);
    const error = parameterError();
    if (error) return showStatus(error);

    const currentRevision = ++revision;
    submit.disabled = true;
    values.hidden = true;
    showStatus(form.dataset.processing, true);
    try {
      const buffer = await file.arrayBuffer();
      if (currentRevision !== revision) return;
      parsed = parseStl(buffer);
      parsedFilename = file.name;
      renderResults();
    } catch (_) {
      if (currentRevision !== revision) return;
      parsed = null;
      values.hidden = true;
      showStatus(form.dataset.errorFormat);
    } finally {
      if (currentRevision === revision) submit.disabled = false;
    }
  });

})();
