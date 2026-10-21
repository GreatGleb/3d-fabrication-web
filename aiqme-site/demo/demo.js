// Демо-сборка GitHub Pages (W-24, §8.7): отправка форм и действия кабинета и админки (data-demo-form) показывают
// «демо: данные не сохраняются» без сетевых запросов. Обработчики — в фазе перехвата, раньше обработчиков сайта
// (отправка заявки калькулятора, W-12). Форма входа с data-demo-redirect ведёт в демо-кабинет.
(() => {
    const banner = document.querySelector('[data-demo-banner]');

    function show() {
        let toast = document.querySelector('[data-demo-message]');
        if (!toast) {
            toast = document.createElement('div');
            toast.setAttribute('data-demo-message', '');
            toast.setAttribute('role', 'status');
            toast.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:1001;max-width:calc(100% - 32px);'
                + 'padding:12px 18px;border-radius:8px;background:#0a1119;color:#fff;font:600 15px/1.4 system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3)';
            document.body.append(toast);
        }
        toast.textContent = banner.dataset.message;
        clearTimeout(show.timer);
        show.timer = setTimeout(() => toast.remove(), 4000);
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.closest('[data-demo-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (form.dataset.demoRedirect) {
            location.assign(form.dataset.demoRedirect);
            return;
        }
        show();
    }, true);

    document.addEventListener('click', (event) => {
        const target = event.target.closest('a[data-demo-form], button[data-demo-form]');
        if (!target) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        show();
    }, true);
})();
