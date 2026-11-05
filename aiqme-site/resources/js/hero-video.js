// Видео hero (§7.3; механизм initHeroVideo прототипа и видеоконтроллера V2): muted/loop без autoplay-атрибута; играет само,
// когда вкладка видна, hero на экране, нет prefers-reduced-motion и saveData — иначе пауза и постер. Кнопки паузы нет
// (правка 05.11, H-01). При ошибке — постер. Источник ≤700 px выбирает <source media> разметки.
const video = document.getElementById('hero-video');

if (video && typeof window.matchMedia === 'function') {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const connection = navigator.connection;
    let visible = true;
    let failed = false;
    video.muted = true;

    const eligible = () => !document.hidden && visible && !failed && !reduced.matches && !connection?.saveData;
    const sync = () => {
        if (!eligible()) {
            video.pause();
            return;
        }
        video.play()?.then(() => { if (!eligible()) video.pause(); }).catch(() => {});
    };

    // Источники <source> пробуются по очереди (неподходящий media тоже даёт error): ошибка — только у последнего.
    const onError = () => {
        failed = true;
        video.pause();
    };
    video.addEventListener('error', onError);
    [...video.querySelectorAll('source')].pop()?.addEventListener('error', onError);
    document.addEventListener('visibilitychange', sync);
    reduced.addEventListener('change', sync);
    connection?.addEventListener?.('change', sync);
    if (typeof IntersectionObserver !== 'undefined') {
        new IntersectionObserver((entries) => { visible = entries[0].isIntersecting; sync(); }, { threshold: 0.05 })
            .observe(video.closest('.hero'));
    }
    sync();
}
