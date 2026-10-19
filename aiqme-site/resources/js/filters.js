// Фильтры списков (проекты, информация): форма GET; выбор в select сразу применяет фильтр, как в прототипе.
// Поле поиска отправляется клавишей Enter (обычная отправка формы).
document.querySelectorAll('form[data-auto-submit]').forEach((form) => {
    form.addEventListener('change', (event) => {
        if (event.target.matches('select')) {
            form.requestSubmit();
        }
    });
});
