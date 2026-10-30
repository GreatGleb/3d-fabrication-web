// Капча ALTCHA (правка 28.10 П3): сборка altcha/external — без встроенных <style> и data:-воркеров, которые запретила бы CSP;
// стили — отдельным CSS, воркер PBKDF2 — отдельный файл /build/assets. Тексты LT — из пакета, EN встроен.
import 'altcha/external';
import 'altcha/i18n/lt';
import 'altcha/altcha.css';
import Pbkdf2Worker from 'altcha/workers/pbkdf2?worker';

globalThis.$altcha.algorithms.set('PBKDF2/SHA-256', () => new Pbkdf2Worker());
