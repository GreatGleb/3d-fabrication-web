// Девиз подвала по ширине логотипа (правка 11.11, J-01): одна строка, левый и правый края совпадают с картинкой логотипа
// над ним. Ширина текста пропорциональна размеру шрифта — подбираем font-size (два прохода из-за округления метрик);
// пересчёт при смене ширины логотипа (ступени 200/164/128/120 px) и после загрузки шрифтов.
const slogan = document.querySelector('.footer-slogan');
const logo = document.querySelector('.footer .logo img');

if (slogan && logo) {
    const fit = () => {
        const target = logo.getBoundingClientRect().width;
        if (!target) {
            return;
        }
        slogan.style.fontSize = '';
        for (let pass = 0; pass < 2; pass++) {
            const width = slogan.getBoundingClientRect().width;
            if (!width) {
                return;
            }
            slogan.style.fontSize = `${(parseFloat(getComputedStyle(slogan).fontSize) * target) / width}px`;
        }
    };
    new ResizeObserver(fit).observe(logo);
    document.fonts?.ready.then(fit);
}
