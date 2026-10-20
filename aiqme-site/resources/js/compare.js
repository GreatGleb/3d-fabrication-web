// Выбор товаров для сравнения (F-04, updateCompare прототипа): отметки «Compare» на странице товаров хранятся в localStorage
// своей локали; панель внизу показывает «N/4» и ведёт на страницу сравнения с выбором в адресе (?products=<slug,…>).
// Пятый товар не выбирается — сообщение о пределе. Сервер сам берёт не больше 4 опубликованных товаров.
const bar = document.querySelector('[data-compare-bar]');
if (bar) {
    const key = bar.dataset.compareKey;
    const limit = Number(bar.dataset.compareLimit);
    const link = bar.querySelector('[data-compare-link]');
    const baseUrl = link.getAttribute('href');
    const message = bar.querySelector('[data-compare-message]');
    const boxes = [...document.querySelectorAll('input[data-compare]')];
    let selected = [];
    try {
        selected = JSON.parse(localStorage.getItem(key) || '[]').filter((slug) => typeof slug === 'string').slice(0, limit);
    } catch {
        selected = [];
    }

    const update = () => {
        localStorage.setItem(key, JSON.stringify(selected));
        boxes.forEach((box) => { box.checked = selected.includes(box.dataset.compare); });
        bar.hidden = selected.length === 0;
        bar.querySelector('[data-compare-count]').textContent = String(selected.length);
        link.href = `${baseUrl}?products=${selected.map(encodeURIComponent).join(',')}`;
    };

    boxes.forEach((box) => box.addEventListener('change', () => {
        const slug = box.dataset.compare;
        message.hidden = true;
        if (box.checked && !selected.includes(slug)) {
            if (selected.length >= limit) {
                box.checked = false;
                message.hidden = false;
                return;
            }
            selected.push(slug);
        } else if (!box.checked) {
            selected = selected.filter((value) => value !== slug);
        }
        update();
    }));

    bar.querySelector('[data-compare-clear]').addEventListener('click', () => {
        selected = [];
        message.hidden = true;
        update();
    });

    update();
}
