// Корзина товаров (правка 05.11, H-08): изменение количества сразу отправляет форму строки; выбор «Atsiėmimas» скрывает
// адрес и строку доставки в суммах и меняет итог (обе суммы считает сервер — data-ship / data-pickup).
document.querySelectorAll('form[data-cart-quantity]').forEach((form) => {
    form.addEventListener('change', () => form.requestSubmit());
});

const delivery = document.querySelector('[data-cart-delivery]');
if (delivery) {
    const update = () => {
        const ship = delivery.value === 'ship';
        document.querySelector('#checkout-form [data-address]').hidden = !ship;
        document.querySelector('[data-delivery-row]').hidden = !ship;
        const total = document.querySelector('[data-cart-total]');
        total.textContent = ship ? total.dataset.ship : total.dataset.pickup;
    };
    delivery.addEventListener('change', update);
    update();
}
