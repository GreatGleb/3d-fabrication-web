// Шапка-шторка (правка заказчика 28.10, П2; приём 3dbonum.com): вверху тёмная (класс header--dark рисует сервер),
// после прокрутки больше высоты шапки + 50 px — белая шапка прототипа §7.1. Переходы (.3s) включает класс header--ready
// только после load: страница, открытая уже прокрученной, получает состояние сразу, без анимации.
// Правка 05.11, H-11: шторка — только у шапки с тёмным верхом (header--overlay, его рисует сервер на главной); на остальных
// страницах шапка белая сразу и без переходов, скрипт её не трогает.
const header = document.querySelector('.header.header--overlay');

if (header) {
    let threshold = header.offsetHeight + 50;
    let pending = false;

    const sync = () => {
        pending = false;
        header.classList.toggle('header--dark', window.scrollY <= threshold);
    };

    window.addEventListener('scroll', () => {
        if (!pending) {
            pending = true;
            requestAnimationFrame(sync);
        }
    }, { passive: true });
    window.addEventListener('resize', () => {
        threshold = header.offsetHeight + 50;
        sync();
    });
    window.addEventListener('pageshow', sync);

    const ready = () => {
        sync();
        void header.offsetHeight; // состояние применяется до включения переходов, иначе смена класса анимировалась бы
        header.classList.add('header--ready');
    };
    if (document.readyState === 'complete') {
        ready();
    } else {
        window.addEventListener('load', ready);
    }
}
