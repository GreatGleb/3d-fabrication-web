// Видео hero (§7.3; механизм initHeroVideo прототипа и видеоконтроллера V2): muted/loop без autoplay-атрибута; играет,
// только когда вкладка видна, hero на экране, нет prefers-reduced-motion и saveData (или пользователь сам нажал «Play»);
// кнопка паузы; при ошибке — постер и сообщение. Источник ≤700 px выбирает <source media> разметки.
const video = document.getElementById('hero-video');
const button = document.getElementById('hero-video-toggle');
const status = document.getElementById('hero-video-status');

if (video && button && typeof window.matchMedia === 'function') {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const connection = navigator.connection;
    let visible = true;
    let paused = false;
    let explicitPlay = false;
    let failed = false;
    video.muted = true;

    const label = () => {
        const playing = !video.paused && !video.ended;
        const icon = Object.assign(document.createElement('span'), { textContent: playing ? 'Ⅱ' : '▶' });
        icon.setAttribute('aria-hidden', 'true');
        button.replaceChildren(icon, ` ${playing ? button.dataset.pause : button.dataset.play}`);
        button.setAttribute('aria-label', playing ? button.dataset.pauseLabel : button.dataset.playLabel);
    };
    const eligible = () => !document.hidden && visible && !paused
        && (!reduced.matches || explicitPlay) && (!connection?.saveData || explicitPlay);
    const sync = () => {
        if (!eligible() || failed) {
            video.pause();
            label();
            return;
        }
        video.play()?.then(() => { if (!eligible()) video.pause(); label(); }).catch(label);
    };

    button.addEventListener('click', () => {
        if (!video.paused) {
            paused = true;
            explicitPlay = false;
            video.pause();
            label();
        } else {
            paused = false;
            explicitPlay = true;
            failed = false;
            sync();
        }
    });
    video.addEventListener('playing', label);
    video.addEventListener('pause', label);
    // Источники <source> пробуются по очереди (неподходящий media тоже даёт error): ошибка — только у последнего.
    const onError = () => {
        failed = true;
        video.pause();
        label();
        status.textContent = status.dataset.error;
    };
    video.addEventListener('error', onError);
    [...video.querySelectorAll('source')].pop()?.addEventListener('error', onError);
    document.addEventListener('visibilitychange', sync);
    reduced.addEventListener('change', () => { explicitPlay = false; sync(); });
    connection?.addEventListener?.('change', () => { explicitPlay = false; sync(); });
    if (typeof IntersectionObserver !== 'undefined') {
        new IntersectionObserver((entries) => { visible = entries[0].isIntersecting; sync(); }, { threshold: 0.05 })
            .observe(video.closest('.hero'));
    }
    sync();
}
