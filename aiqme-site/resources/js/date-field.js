// Поле даты dd.mm.yyyy (правка 11.11, J-04): вид не зависит от локали браузера. Ввод с клавиатуры и телефона (цифровая
// клавиатура) — маска «12122026» → «12.12.2026»; кнопка календаря открывает системный календарь скрытого поля type=date
// (showPicker), выбранная дата переносится в поле как dd.mm.yyyy. Делегирование — поля калькулятора создаются позже.
// Маска и перенос даты — в фазе перехвата, раньше обработчиков страниц.
// Разметка: span.date-field > input[data-date] + button[data-date-pick] + input[type=date][data-date-native].

const iso = (text) => {
    const match = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(text);
    return match ? `${match[3]}-${match[2]}-${match[1]}` : '';
};

const mask = (text) => {
    const digits = text.replace(/\D/g, '').slice(0, 8);
    return [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4)].filter(Boolean).join('.');
};

document.addEventListener('input', (event) => {
    const field = event.target;
    if (!field.matches?.('input[data-date]')) return;
    const value = mask(field.value);
    if (value === field.value) return;
    // Курсор — после того же числа цифр, что и до форматирования.
    const before = field.value.slice(0, field.selectionStart ?? field.value.length).replace(/\D/g, '').length;
    field.value = value;
    let caret = 0;
    for (let digits = 0; caret < value.length && digits < before; caret += 1) {
        if (/\d/.test(value[caret])) digits += 1;
    }
    field.setSelectionRange(caret, caret);
}, true);

document.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-date-pick]');
    if (!button) return;
    event.preventDefault();
    const box = button.closest('.date-field');
    const native = box.querySelector('[data-date-native]');
    native.value = iso(box.querySelector('[data-date]').value);
    try {
        native.showPicker();
    } catch {
        box.querySelector('[data-date]').focus();
    }
});

document.addEventListener('change', (event) => {
    const native = event.target;
    if (!native.matches?.('[data-date-native]')) return;
    // Перехват до обработчиков страницы (калькулятор перерисовывает карточку по change): им — change текстового поля.
    event.stopPropagation();
    if (!native.value) return;
    const [year, month, day] = native.value.split('-');
    const field = native.closest('.date-field').querySelector('[data-date]');
    field.value = `${day}.${month}.${year}`;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
}, true);
