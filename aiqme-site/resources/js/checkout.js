// Страница оплаты (W-16, §3.3): Stripe Payment Element по client_secret запроса. Stripe.js подключает шаблон
// (js.stripe.com разрешён CSP, §6.6). При успехе или 3D Secure Stripe переадресует на return_url — ту же страницу,
// которая оплату не записывает; сюда возвращается только ошибка ввода или отказ банка.
const form = document.querySelector('#payment-form');
if (form && window.Stripe) {
    const stripe = window.Stripe(form.dataset.stripeKey, { locale: document.documentElement.lang });
    const elements = stripe.elements({ clientSecret: form.dataset.clientSecret });
    elements.create('payment').mount('#payment-element');
    const error = form.querySelector('[data-checkout-error]');
    const button = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;
        error.hidden = true;
        const result = await stripe.confirmPayment({ elements, confirmParams: { return_url: form.dataset.returnUrl } });
        error.textContent = result.error?.message ?? '';
        error.hidden = !result.error;
        button.disabled = false;
    });
}
