// Своё поле выбора файла (правка 02.11, Г3): системное поле скрыто как sr-only (фокус и клавиатура — на нём), видна кнопка
// .btn сайта и строка выбранных имён; без JS остаётся обычное поле (кнопка в разметке hidden).
document.querySelectorAll('[data-file-pick]').forEach((pick) => {
    const input = pick.closest('label').querySelector('input[type=file]');
    const names = pick.querySelector('[data-file-names]');
    const update = () => {
        names.textContent = [...input.files].map((file) => file.name).join(', ') || names.dataset.empty;
    };
    input.classList.add('sr-only');
    pick.hidden = false;
    input.addEventListener('change', update);
    update();
});
