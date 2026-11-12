// Выбор языка в шапке (правка 11.11, J-02): кнопка «EN ▾» открывает список остальных языков. Нажатие (и Enter/пробел)
// открывает и закрывает; мышью на ПК список открывается и наведением, уход мыши закрывает. Esc закрывает и возвращает
// фокус на кнопку, нажатие вне списка или уход фокуса — закрывает. aria-expanded всегда совпадает с видом списка.
// Без JS список открывают :hover и :focus-within (shell.css); data-ready отключает этот запасной путь.
const menu = document.querySelector('[data-lang-menu]');

if (menu) {
    const button = menu.querySelector('button');
    let openedByHover = false;
    const isOpen = () => button.getAttribute('aria-expanded') === 'true';
    const setOpen = (open) => {
        button.setAttribute('aria-expanded', String(open));
        menu.classList.toggle('open', open);
        if (!open) openedByHover = false;
    };

    menu.dataset.ready = '';
    button.addEventListener('click', () => {
        // Список, открытый наведением, нажатие не закрывает: иначе щелчок сразу после наведения прятал бы его.
        if (openedByHover) {
            openedByHover = false;
            return;
        }
        setOpen(!isOpen());
    });
    menu.addEventListener('pointerenter', (event) => {
        if (event.pointerType === 'mouse' && !isOpen()) {
            setOpen(true);
            openedByHover = true;
        }
    });
    menu.addEventListener('pointerleave', (event) => {
        if (event.pointerType === 'mouse') setOpen(false);
    });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            setOpen(false);
            button.focus();
        }
    });
    menu.addEventListener('focusout', (event) => {
        if (!menu.contains(event.relatedTarget)) setOpen(false);
    });
    document.addEventListener('click', (event) => {
        if (isOpen() && !menu.contains(event.target)) setOpen(false);
    });
}
