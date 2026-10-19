// Ленты брендов и новостей главной (§7.3): движение — CSS прототипа (38 s / 48 s, пауза при наведении и фокусе,
// статичный вид без дублей при prefers-reduced-motion); кнопка паузы ставит data-paused секции, как в прототипе.
for (const button of document.querySelectorAll('.partner-motion-toggle')) {
    const section = button.closest('section');
    const attribute = section.classList.contains('news-section') ? 'newsPaused' : 'paused';
    button.addEventListener('click', () => {
        const paused = section.dataset[attribute] !== 'true';
        section.dataset[attribute] = String(paused);
        button.setAttribute('aria-pressed', String(paused));
        const icon = Object.assign(document.createElement('span'), { textContent: paused ? '▶' : 'Ⅱ' });
        icon.setAttribute('aria-hidden', 'true');
        button.replaceChildren(icon, ` ${paused ? button.dataset.resume : button.dataset.pause}`);
    });
}
