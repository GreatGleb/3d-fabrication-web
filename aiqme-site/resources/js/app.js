import './menu';
import './modal';
import './hero-video';
import './tickers';
import './filters';
import './search';
import './compare';
import './gallery';

// Cookie-плашка (D-11): информационная; после «Tik būtini» · «Essential only» больше не показывается.
const cookie = document.querySelector('.cookie');
const COOKIE_KEY = 'aiqme-cookie-notice';
if (cookie && localStorage.getItem(COOKIE_KEY) === null) {
    cookie.hidden = false;
    cookie.querySelector('[data-cookie-accept]').addEventListener('click', () => {
        localStorage.setItem(COOKIE_KEY, 'essential');
        cookie.remove();
    });
}
