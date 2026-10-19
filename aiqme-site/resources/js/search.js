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
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                results.innerHTML = page.querySelector('main [data-search-results]').innerHTML;
            } catch (error) {
                if (error.name !== 'AbortError') throw error;
            }
        }, 200);
    });
});
