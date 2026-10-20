// Галерея товара (F-06, действие gallery прототипа): миниатюра показывает своё фото в основной рамке.
const thumbs = [...document.querySelectorAll('[data-gallery-thumb]')];
const views = [...document.querySelectorAll('[data-gallery-view]')];
thumbs.forEach((thumb, index) => thumb.addEventListener('click', () => {
    views.forEach((view, viewIndex) => { view.hidden = viewIndex !== index; });
    thumbs.forEach((other) => {
        other.classList.toggle('active', other === thumb);
        other.setAttribute('aria-pressed', String(other === thumb));
    });
}));
