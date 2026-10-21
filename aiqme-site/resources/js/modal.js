// Модальные окна (компонент x-modal, §7.2): Escape и щелчок по фону закрывают, Tab остаётся в окне,
// фон недоступен (inert), фокус — на [data-modal-focus] или первый элемент, после закрытия фокус возвращается на кнопку, открывшую окно.
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]';
let opened = null;
let opener = null;

function background(modal) {
    return [...document.body.children].filter((element) => element !== modal && element.tagName !== 'SCRIPT');
}

function open(modal, trigger) {
    if (opened) close();
    opened = modal;
    opener = trigger;
    modal.hidden = false;
    background(modal).forEach((element) => { element.inert = true; });
    (modal.querySelector('[data-modal-focus]') ?? modal.querySelector(FOCUSABLE))?.focus();
}

function close() {
    if (!opened) return;
    background(opened).forEach((element) => { element.inert = false; });
    opened.hidden = true;
    opened = null;
    opener?.focus();
    opener = null;
}

document.querySelectorAll('[data-modal]').forEach((modal) => {
    // Окно — прямой потомок body, иначе inert фона закрыл бы и его самого.
    document.body.append(modal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal || event.target.closest('[data-modal-close]')) close();
    });
});

// Окно, открытое сервером (ответ формы, W-09): после закрытия фокус — на кнопку, открывающую окно data-modal-return.
const autoOpen = document.querySelector('[data-modal][data-modal-autoopen]');
if (autoOpen) {
    open(autoOpen, document.querySelector(`[data-modal-open="${autoOpen.dataset.modalReturn ?? autoOpen.id}"]`));
}

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-modal-open]');
    const modal = trigger && document.getElementById(trigger.dataset.modalOpen);
    if (modal) {
        // Ссылка-открыватель (поиск в шапке) без JS ведёт на страницу; с JS — открывает окно.
        event.preventDefault();
        open(modal, trigger);
    }
});

document.addEventListener('keydown', (event) => {
    if (!opened) return;
    if (event.key === 'Escape') {
        close();
    } else if (event.key === 'Tab') {
        const items = [...opened.querySelectorAll(FOCUSABLE)];
        if (event.shiftKey && document.activeElement === items[0]) {
            event.preventDefault();
            items.at(-1).focus();
        } else if (!event.shiftKey && document.activeElement === items.at(-1)) {
            event.preventDefault();
            items[0].focus();
        }
    }
});
