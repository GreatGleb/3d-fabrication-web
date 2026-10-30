// Живой поиск (searchAll прототипа): при вводе результаты берутся со страницы поиска сервера (?q=) и подставляются
// под поле — в модальном окне шапки и на странице поиска. Enter — обычная отправка формы на страницу поиска.
document.querySelectorAll('form[data-live-search]').forEach((form) => {
    const input = form.querySelector('input[name="q"]');
    const results = form.nextElementSibling;
    let timer;
    let controller;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            controller?.abort();
            controller = new AbortController();
            const url = `${form.action}?${new URLSearchParams({ q: input.value.trim() })}`;
            try {
                const response = await fetch(url, { signal: controller.signal });
                // template, а не DOMParser: DOMParser разбирает без скриптов, и noscript-стиль шапки (правка 28.10, П2)
                // с nonce чужого ответа стал бы элементом style — нарушение CSP в консоли. Содержимое template инертно.
                const page = document.createElement('template');
                page.innerHTML = await response.text();
                results.innerHTML = page.content.querySelector('main [data-search-results]').innerHTML;
            } catch (error) {
                if (error.name !== 'AbortError') throw error;
            }
        }, 200);
    });
});
