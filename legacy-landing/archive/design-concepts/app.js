const nav = document.querySelector('.nav');
const menuButton = document.querySelector('.menu-toggle');
document.documentElement.classList.add('js-enabled');
if (nav) nav.id = 'primary-nav';

function setMenuOpen(open) {
  if (!nav || !menuButton) return;
  nav.classList.toggle('open', open);
  menuButton.setAttribute('aria-expanded', String(open));
}

if (nav && menuButton) {
  menuButton.addEventListener('click', () => {
    setMenuOpen(menuButton.getAttribute('aria-expanded') !== 'true');
  });
  nav.querySelectorAll('a[href^="#"]').forEach((link) => {
    link.addEventListener('click', () => setMenuOpen(false));
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setMenuOpen(false);
  });
}

document.querySelectorAll('[data-modal]').forEach((button) => {
  button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.modal);
    if (!dialog || typeof dialog.showModal !== 'function') return;
    if (dialog.dataset.submitted === 'true') resetDialog(dialog);
    dialog.showModal();
    setMenuOpen(false);
  });
});

function resetDialog(dialog) {
  const form = dialog.querySelector('form');
  const status = dialog.querySelector('[data-form-status]');
  if (form) {
    form.reset();
    form.hidden = false;
  }
  status?.remove();
  delete dialog.dataset.submitted;
}

function showStatus(dialog, message) {
  let status = dialog.querySelector('[data-form-status]');
  if (!status) {
    status = document.createElement('p');
    status.className = 'form-status';
    status.dataset.formStatus = 'true';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    dialog.querySelector('.modal').append(status);
  }
  status.textContent = message;
  dialog.dataset.submitted = 'true';
}

document.querySelectorAll('dialog').forEach((dialog) => {
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
  dialog.addEventListener('close', () => resetDialog(dialog));
});

document.querySelectorAll('form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const dialog = form.closest('dialog');
    if (!dialog) return;

    if (dialog.id === 'calculator') {
      const volume = Number(form.elements.volume.value);
      const material = form.elements.material.value;
      const quantity = Number(form.elements.quantity.value);
      const density = { PLA: 1.24, PETG: 1.27, TPU: 1.21 }[material];
      if (!Number.isFinite(volume) || !Number.isFinite(quantity) || !density) return;
      const grams = volume * density * quantity;
      showStatus(dialog, `Teorinė masė: ${grams.toFixed(1)} g (${material}, ${quantity} vnt.). Skaičiuojama darant prielaidą, kad modelis yra pilnaviduris. Tai nėra modelio analizė ar kainos pasiūlymas.`);
    } else if (dialog.id === 'login') {
      showStatus(dialog, 'Klientų zona yra prototipas. Prisijungimas dar neprijungtas prie paskyrų sistemos.');
    } else {
      showStatus(dialog, 'Tai demonstracinė forma. Užklausa nebuvo išsiųsta ir duomenys neišsaugoti.');
    }
  });
});
