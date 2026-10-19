// Мобильное меню ≤900 px (§7.2, F-46): механизм V2 shared/app.js на разметке прототипа — aria-expanded всегда
// совпадает с видом меню (в том числе после Escape), фокус возвращается на кнопку, Tab не уходит из меню, фон не прокручивается.
const button = document.querySelector('.menu-btn');
const nav = document.getElementById('main-nav');

function setOpen(open) {
    button.setAttribute('aria-expanded', String(open));
    nav.classList.toggle('open', open);
    document.documentElement.classList.toggle('menu-open', open);
}

const isOpen = () => button.getAttribute('aria-expanded') === 'true';

if (button && nav) {
    button.addEventListener('click', () => setOpen(!isOpen()));
    nav.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setOpen(false)));
    window.matchMedia('(max-width: 900px)').addEventListener('change', () => {
        const hadFocus = nav.contains(document.activeElement);
        setOpen(false);
        if (hadFocus) button.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (!isOpen()) return;
        if (event.key === 'Escape') {
            setOpen(false);
            button.focus();
        } else if (event.key === 'Tab') {
            // Кнопка стоит в DOM после ссылок меню, поэтому порядок кнопка → ссылки → кнопка задаётся явно.
            event.preventDefault();
            const items = [button, ...nav.querySelectorAll('a[href]')];
            const index = Math.max(items.indexOf(document.activeElement), 0);
            items[(index + (event.shiftKey ? -1 : 1) + items.length) % items.length].focus();
        }
    });
    document.addEventListener('click', (event) => {
        if (isOpen() && !nav.contains(event.target) && !button.contains(event.target)) setOpen(false);
    });
}
