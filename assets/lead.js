(() => {
  const form = document.getElementById('lead-form');
  if (!form) return;
  const mode = document.querySelector('.site-header')?.dataset.siteMode || 'server';
  const steps = [...form.querySelectorAll('.lead-step')];
  const progress = [...document.querySelectorAll('.lead-progress span')];
  const status = document.getElementById('lead-status');
  const complete = document.getElementById('lead-complete');
  const attachment = document.getElementById('lead-attachment');
  const MAX_BYTES = 25 * 1024 * 1024;
  const allowedExtensions = new Set(['jpg', 'jpeg', 'png', 'webp', 'pdf', 'stl', 'obj', 'step', 'stp']);
  let current = 0;
  let sending = false;
  const stepLabel = document.getElementById('lead-step-label');
  const errors = new Map();
  function clearError(field) {
    errors.get(field)?.remove();
    errors.delete(field);
    field.removeAttribute('aria-invalid');
    const ids = (field.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== `${field.id}-error`);
    if (ids.length) field.setAttribute('aria-describedby', ids.join(' '));
    else field.removeAttribute('aria-describedby');
  }
  function invalid(field, text) {
    clearError(field);
    const error = document.createElement('p');
    error.className = 'field-error';
    error.id = `${field.id}-error`;
    error.textContent = text;
    (field.closest('.field') || field.parentElement).append(error);
    errors.set(field, error);
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', `${field.getAttribute('aria-describedby') || ''} ${error.id}`.trim());
    field.focus();
    return false;
  }
  function fieldError(field) {
    const value = field.value.trim();
    if (field.id === 'lead-message' && (value.length < 10 || value.length > 3000)) return form.dataset.errorDescription;
    if (field.id === 'lead-quantity' && (field.validity.badInput || (value && (!Number.isSafeInteger(Number(value)) || Number(value) < 1 || Number(value) > 10000)))) return form.dataset.errorQuantity;
    if (field.id === 'lead-attachment' && !validFile()) return form.dataset.errorFile;
    if (field.id === 'lead-name' && !value) return form.dataset.errorRequired;
    if (field.id === 'lead-email' && (!value || !field.checkValidity() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value))) return form.dataset.errorEmail;
    return '';
  }
  function refreshError(event) {
    const field = event.target;
    if (errors.has(field)) {
      const text = fieldError(field);
      if (text) errors.get(field).textContent = text;
      else clearError(field);
    }
    if (field.name === 'service' && field.checked) message('');
  }
  form.addEventListener('input', refreshError);
  form.addEventListener('change', refreshError);

  function message(value, success = false) {
    status.textContent = value;
    status.hidden = !value;
    status.classList.toggle('is-success', success);
  }

  function showStep(index) {
    current = index;
    stepLabel.textContent = `${form.dataset.stepLabel.replace('{step}', index + 1)} · ${steps[index].querySelector('h3').textContent}`;
    steps.forEach((step, stepIndex) => { step.hidden = stepIndex !== index; });
    progress.forEach((segment, segmentIndex) => {
      segment.classList.toggle('active', segmentIndex <= index);
      if (segmentIndex === index) segment.setAttribute('aria-current', 'step');
      else segment.removeAttribute('aria-current');
    });
    for (const field of errors.keys()) {
      if (steps[index].contains(field)) refreshError({ target: field });
    }
    message('');
    const heading = steps[index].querySelector('h3');
    heading.focus({ preventScroll: true });
    // The previous step's button can be several screens below this heading.
    // Align the progress label too, so the new step has visible context.
    const top = stepLabel.getBoundingClientRect().top;
    const header = document.querySelector('.site-header');
    const inset = getComputedStyle(header).position === 'sticky' ? header.offsetHeight + 20 : 20;
    if (top < inset || heading.getBoundingClientRect().bottom > innerHeight - 40) {
      window.scrollTo({ top: window.scrollY + top - inset, behavior: 'instant' });
    }
  }

  function validFile() {
    const file = attachment.files[0];
    if (!file) return true;
    return file.size <= MAX_BYTES && allowedExtensions.has(file.name.split('.').pop().toLowerCase());
  }

  function validate(index) {
    if (index === 0 && !form.querySelector('input[name="service"]:checked')) {
      message(form.dataset.errorRequired);
      form.querySelector('input[name=service]').focus();
      return false;
    }
    const fields = index === 1 ? ['lead-message', 'lead-quantity', 'lead-attachment'] : index === 2 ? ['lead-name', 'lead-email'] : [];
    for (const id of fields) {
      const field = document.getElementById(id);
      const error = fieldError(field);
      if (error) return invalid(field, error);
      clearError(field);
    }
    message('');
    return true;
  }

  stepLabel.textContent = `${form.dataset.stepLabel.replace('{step}', 1)} · ${steps[0].querySelector('h3').textContent}`;

  form.querySelectorAll('.lead-next').forEach((button) => button.addEventListener('click', () => {
    if (validate(current)) showStep(Math.min(2, current + 1));
  }));
  form.querySelectorAll('.lead-back').forEach((button) => button.addEventListener('click', () => showStep(Math.max(0, current - 1))));
  form.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && current < 2 && event.target.matches('input:not([type=file]):not([type=radio]):not([type=checkbox])')) {
      event.preventDefault();
      if (validate(current)) showStep(current + 1);
    }
  });

  function finish() {
    form.hidden = true;
    complete.hidden = false;
    document.querySelector('.lead-progress').hidden = true;
    stepLabel.hidden = true;
    document.getElementById('lead-complete-title').focus();
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (sending || !validate(2)) return;
    const quantity = document.getElementById('lead-quantity');
    if (quantity.value.trim()) quantity.value = String(Number(quantity.value));
    if (mode === 'demo') {
      document.getElementById('lead-complete-title').textContent = form.dataset.demoCompleteTitle;
      document.getElementById('lead-complete-text').textContent = form.dataset.demoCompleteText;
      finish();
      return;
    }
    const payload = new FormData(form);
    const controls = [...form.elements].filter(control => 'disabled' in control);
    const disabledBefore = controls.map(control => control.disabled);
    sending = true;
    form.setAttribute('aria-busy', 'true');
    controls.forEach(control => { control.disabled = true; });
    try {
      const sessionResponse = await fetch('/api/session', { credentials: 'same-origin', cache: 'no-store' });
      if (!sessionResponse.ok) throw new Error('session_unavailable');
      const { csrf } = await sessionResponse.json();
      const response = await fetch('/api/leads', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf },
        body: payload,
      });
      if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        message(response.status === 429 ? form.dataset.errorLimit : payload.error === 'invalid_file' || response.status === 413 ? form.dataset.errorFile : payload.error === 'invalid_fields' ? form.dataset.errorFields : form.dataset.errorServer);
        return;
      }
      finish();
    } catch (_) {
      message(form.dataset.errorServer);
    } finally {
      sending = false;
      form.removeAttribute('aria-busy');
      controls.forEach((control, index) => { control.disabled = disabledBefore[index]; });
    }
  });
})();
