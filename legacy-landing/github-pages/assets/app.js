document.documentElement.classList.add('js-enabled');

const menuButton = document.querySelector('.menu-button');
const navigation = document.querySelector('.primary-navigation');
navigation?.querySelectorAll('a[href]').forEach(link => {
  const url = new URL(link.href);
  if (!url.hash && url.pathname === location.pathname && !link.classList.contains('button')) {
    link.setAttribute('aria-current', 'page');
  }
});

function setMenuOpen(open) {
  if (!menuButton || !navigation) return;
  menuButton.setAttribute('aria-expanded', String(open));
  menuButton.setAttribute('aria-label', open ? menuButton.dataset.closeLabel : menuButton.dataset.openLabel);
  navigation.classList.toggle('open', open);
  document.documentElement.classList.toggle('menu-open', open);
}

if (menuButton && navigation) {
  menuButton.addEventListener('click', () => setMenuOpen(menuButton.getAttribute('aria-expanded') !== 'true'));
  navigation.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setMenuOpen(false)));
  window.matchMedia('(max-width:980px)').addEventListener('change', event => {
    const focused = document.activeElement;
    setMenuOpen(false);
    if (event.matches && navigation.contains(focused)) menuButton.focus();
    else if (!event.matches && focused === menuButton) navigation.querySelector('a')?.focus();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Tab' && menuButton.getAttribute('aria-expanded') === 'true') {
      const items = [menuButton, ...navigation.querySelectorAll('a[href]')];
      if (event.shiftKey && document.activeElement === items[0]) {
        event.preventDefault();
        items.at(-1).focus();
      } else if (!event.shiftKey && document.activeElement === items.at(-1)) {
        event.preventDefault();
        menuButton.focus();
      }
    }
    if (event.key === 'Escape' && menuButton.getAttribute('aria-expanded') === 'true') {
      setMenuOpen(false);
      menuButton.focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (!navigation.contains(event.target) && !menuButton.contains(event.target)) setMenuOpen(false);
  });
}

// Enhance the native chooser without changing its form value or keyboard behavior.
document.querySelectorAll('input[type="file"][data-file-choose]').forEach((input) => {
  const wrapper = document.createElement('div');
  wrapper.className = 'file-upload';
  input.before(wrapper);
  wrapper.append(input);
  input.classList.add('file-native');
  input.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      input.click();
    }
  });
  const pick = document.createElement('label');
  pick.className = 'file-pick';
  pick.htmlFor = input.id;
  pick.textContent = input.dataset.fileChoose;
  const name = document.createElement('span');
  name.className = 'file-name';
  name.id = `${input.id}-filename`;
  name.setAttribute('aria-live', 'polite');
  input.setAttribute('aria-describedby', name.id);
  const remove = document.createElement('button');
  remove.type = 'button';
  remove.className = 'file-remove';
  remove.textContent = input.dataset.fileRemove;
  function update() {
    name.textContent = input.files[0]?.name || input.dataset.fileEmpty;
    remove.hidden = !input.files.length;
  }
  remove.addEventListener('click', () => {
    input.value = '';
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.focus();
  });
  input.addEventListener('change', update);
  wrapper.append(pick, name, remove);
  update();
});
