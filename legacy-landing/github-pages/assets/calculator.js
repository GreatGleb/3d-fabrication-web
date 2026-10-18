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
  const fileInput = document.getElementById('stl-upload');
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

  // STL coordinates have no intrinsic units; the UI defaults to millimetres.
  function parseStl(buffer) {
    if (!(buffer instanceof ArrayBuffer) || buffer.byteLength > MAX_BYTES) throw new Error('Invalid file size');
    // Validate structure before STLLoader allocates geometry or skips malformed facets.
    let expectedVertices;
    const count = buffer.byteLength >= 84 ? new DataView(buffer).getUint32(80, true) : 0;
    if (buffer.byteLength >= 84 && 84 + count * 50 === buffer.byteLength) {
      if (!count || count > MAX_TRIANGLES) throw new Error('Invalid triangle count');
      expectedVertices = count * 3;
    } else {
      const content = new TextDecoder('utf-8', { fatal: true }).decode(buffer);
      if (!/^\s*solid\b/.test(content) || !/endsolid\b/.test(content)) throw new Error('Invalid STL structure');
      const facets = [...content.matchAll(/facet\s+normal\b([\s\S]*?)endfacet/g)];
      if (!facets.length || facets.length > MAX_TRIANGLES || (content.match(/\bfacet\b/g) || []).length !== facets.length) throw new Error('Invalid facets');
      let vertexCount = 0;
      for (const facet of facets) {
        const vertices = [...facet[1].matchAll(/\bvertex\s+(\S+)\s+(\S+)\s+(\S+)/g)];
        if (vertices.length !== 3 || vertices.some(v => v.slice(1).some(n => !Number.isFinite(Number(n))))) throw new Error('Invalid vertices');
        vertexCount += vertices.length;
      }
      expectedVertices = vertexCount;
      if ((content.match(/\bvertex\b/g) || []).length !== vertexCount) throw new Error('Unexpected vertices');
    }
    const geometry = new AIQMEThree.STLLoader().parse(buffer);
    try {
      const positions = geometry.getAttribute('position');
      if (!positions || positions.count !== expectedVertices || positions.count % 3 || positions.count / 3 > MAX_TRIANGLES) throw new Error('Invalid triangles');
      for (const coordinate of positions.array) {
        if (!Number.isFinite(coordinate)) throw new Error('Invalid coordinate');
      }
      geometry.computeBoundingBox();
      const size = geometry.boundingBox.getSize(new AIQMEThree.Vector3());
      const origin = new AIQMEThree.Vector3().fromBufferAttribute(positions, 0);
      const a = new AIQMEThree.Vector3(), b = new AIQMEThree.Vector3(), c = new AIQMEThree.Vector3();
      let signedVolume = 0;
      for (let i = 0; i < positions.count; i += 3) {
        a.fromBufferAttribute(positions, i).sub(origin);
        b.fromBufferAttribute(positions, i + 1).sub(origin);
        c.fromBufferAttribute(positions, i + 2).sub(origin);
        signedVolume += a.dot(b.cross(c)) / 6;
      }
      // Reversed winding changes the sign, not the physical volume.
      const volume = Math.abs(signedVolume);
      if (!Number.isFinite(volume) || volume <= 0) throw new Error('Invalid mesh volume');
      return { triangles: positions.count / 3, dimensions: size.toArray(), volume };
    } finally {
      geometry.dispose();
    }
  }

  function readFile(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(reader.error || new Error('File read failed'));
      reader.onabort = () => reject(new Error('File read cancelled'));
      reader.readAsArrayBuffer(file);
    });
  }

  const DENSITIES = { PLA: 1.24, ABS: 1.04, PETG: 1.27 };
  function estimate(volume, density, infill, rate, fee, quantity = 1) {
    if (![volume, density, infill, rate, fee, quantity].every(Number.isFinite) || volume <= 0 || density <= 0 || infill < 0 || infill > 100 || rate < 0 || fee < 0 || !Number.isSafeInteger(quantity) || quantity < 1) throw new Error('Invalid pricing parameters');
    const mass = volume * density * (0.2 + 0.8 * infill / 100) * quantity;
    return { mass, price: mass * rate + fee };
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
        const buffer = await readFile(file);
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

  window.AIQMEStlCalculator = { parseStl, estimate, DENSITIES };
  initQuickCalculator();
  if (!form) return;

  function showStatus(message, processing = false) {
    loading.hidden = !processing;
    form.setAttribute('aria-busy', String(processing));
    status.textContent = message;
    status.classList.toggle('is-processing', processing);
    status.hidden = !message;
  }

  function format(number, digits = 2) {
    return formatMeasurement(number, digits);
  }

  const material = document.getElementById('material-select');
  const infill = document.getElementById('infill-slider');
  const dropZone = document.getElementById('drop-zone');
  const loading = document.getElementById('calculator-loading');
  const emptyText = empty.textContent;
  function parameterError() {
    const density = densityInput.value.trim() === '' ? DENSITIES[material.value] : Number(densityInput.value);
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
    const density = densityInput.value.trim() === '' ? DENSITIES[material.value] : Number(densityInput.value);
    const quantity = Number(quantityInput.value);
    const volume = parsed.volume * scale ** 3 / 1000;
    const validVolume = Number.isFinite(volume) && volume > 0;
    document.getElementById('result-file').textContent = parsedFilename;
    document.getElementById('result-triangles').textContent = format(parsed.triangles, 0);
    ['x', 'y', 'z'].forEach((axis, i) => { document.getElementById(`dim-${axis}`).textContent = format(parsed.dimensions[i] * scale); });
    document.getElementById('volume-val').textContent = validVolume ? format(volume, 4) : '—';
    const quote = estimate(volume, density, Number(infill.value), Number(form.dataset.pricePerGram), Number(form.dataset.baseSetupFee), quantity);
    document.getElementById('weight-val').textContent = format(quote.mass, 2);
    document.getElementById('price-val').textContent = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'USD' }).format(quote.price);
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
    if (fileInput.files.length) analyze(fileInput.files[0]);
  });
  [unitInput, densityInput, quantityInput].forEach((input) => input.addEventListener('input', renderResults));

  async function analyze(file) {
    if (!file) return showStatus(form.dataset.errorFile);
    if (!file.name.toLowerCase().endsWith('.stl')) return showStatus(form.dataset.errorFormat);
    if (file.size > MAX_BYTES) return showStatus(form.dataset.errorSize);
    const currentRevision = ++revision;
    parsed = null;
    submit.disabled = true;
    values.hidden = true;
    showStatus(form.dataset.processing, true);
    try {
      const buffer = await readFile(file);
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
      if (currentRevision === revision) {
        submit.disabled = false;
        loading.hidden = true;
        form.setAttribute('aria-busy', 'false');
      }
    }
  }
  form.addEventListener('submit', (event) => { event.preventDefault(); analyze(fileInput.files[0]); });
  material.addEventListener('change', () => {
    densityInput.value = '';
    densityInput.placeholder = DENSITIES[material.value];
    renderResults();
  });
  infill.addEventListener('input', () => {
    document.getElementById('infill-value').textContent = `${infill.value}%`;
    renderResults();
  });
  infill.addEventListener('change', renderResults);
  dropZone.addEventListener('dragover', (event) => { event.preventDefault(); dropZone.classList.add('is-dragging'); });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('is-dragging'));
  dropZone.addEventListener('drop', (event) => {
    event.preventDefault();
    dropZone.classList.remove('is-dragging');
    const files = event.dataTransfer.files;
    if (!files.length) return;
    const transfer = new DataTransfer();
    transfer.items.add(files[0]);
    fileInput.files = transfer.files;
    fileInput.dispatchEvent(new Event('change', { bubbles: true }));
  });
})();
