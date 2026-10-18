"""Build an offline visual index from the captured study evidence."""
import html,json
from pathlib import Path
from PIL import Image,ImageDraw
OUT=Path(__file__).resolve().parent
report=json.loads((OUT/'browser-report.json').read_text())
captions={
'hero':('Hero','Вход видео: масштаб 1.2 → 1, затем текст по символам. Фоновое видео играет самостоятельно.'),
'intro':('Intro','Сетка видео уменьшается до .51; текст и окружение раскрываются через прокрутку.'),
'revitex':('Revitex','Время busbar.mp4 связано с прокруткой; во второй части переключаются наборы характеристик.'),
'industries':('Industries','Ролик материала, пять вкладок применения, точка и связанная карточка.'),
'about':('About','Маска раскрывает фотографию, затем число растёт от 100 до 150 млн.'),
'periflex':('Periflex','Видео раскрывает продукт; после характеристик глава исчезает под следующей.'),
'other-industries':('Other industries','Список отраслей, маски изображений и текст меняются синхронно.'),
'truck':('Truck','Предварительно записанное раскрытие грузовика; текст и указатели находятся поверх видео.'),
'combo':('Combo','Обычный поток страницы: видео и карточка нового продукта после длинных закреплённых сцен.'),
'worldwide':('Worldwide','Статическая SVG-карта и факты; заголовки появляются общим механизмом.'),
'animation':('Join the ride','Финальная перемотка cable.mp4 и посимвольное появление призыва.'),
'footer':('Footer','Контакты в конце последней сцены.')}
figures=[]
for mode,data in report['scenarios'].items():
 for sample in data['samples']:
  if 'screenshot' not in sample:continue
  section=next((k for k in captions if 'component--'+k in sample['section']),'hero')
  title,description=captions[section];file=sample['screenshot']
  figures.append(f'<figure data-mode="{mode}"><a href="screenshots/{file}" target="_blank"><img loading="lazy" src="screenshots/{file}" alt="{html.escape(title)} — {mode}"></a><figcaption><h2>{html.escape(title)}</h2><p>{html.escape(description)}</p><small>{mode} · scroll {sample["scroll"]:.0f}px · выборка {sample["fraction"]:.0%}</small></figcaption></figure>')
for file,title in [('desktop-popup.png','Popup: Escape не закрывает окно'),('no-javascript.png','Без JS загрузчик остаётся поверх страницы')]:
 if (OUT/'screenshots'/file).exists():figures.append(f'<figure data-mode="checks" hidden><a href="screenshots/{file}" target="_blank"><img loading="lazy" src="screenshots/{file}" alt="{title}"></a><figcaption><h2>{title}</h2><p>Результат отдельной проверки взаимодействий.</p></figcaption></figure>')
text='''<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Relats — исследование анимаций</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f0ec;color:#202020;font:16px/1.6 system-ui,sans-serif}main{max-width:1380px;margin:auto;padding:40px 24px}h1{font-size:clamp(30px,4vw,54px);line-height:1.1;margin:0 0 18px}h2{font-size:21px;margin:0 0 8px}a{color:#274d8c}header p{max-width:950px}.note{padding:16px 20px;background:#fff5db;border-radius:12px;max-width:1000px}nav{display:flex;flex-wrap:wrap;gap:12px;margin:24px 0}button{font:inherit;padding:10px 18px;border:1px solid #aaa;border-radius:10px;background:white;cursor:pointer}button[aria-pressed=true]{background:#202020;color:white}button:focus-visible,a:focus-visible{outline:3px solid #245be6;outline-offset:4px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}figure{margin:0;min-width:0;background:white;border-radius:16px;overflow:hidden}figure[hidden]{display:none}img{display:block;width:100%;height:auto;background:#ddd}figure[data-mode=mobile] img{height:520px;object-fit:contain;background:#deddd8}figcaption{padding:20px}figcaption p{margin:0 0 10px}small{color:#666}footer{margin-top:32px;color:#555}@media(max-width:760px){main{padding:24px 16px}.grid{grid-template-columns:1fr}} 
</style><main><header><h1>Как устроено движение Relats</h1><p>Исследование локального архива · 29 сентября 2026. Основные объёмные сцены — MP4, управляемые прокруткой; типографика, маски, указатели и интерфейс остаются отдельными HTML-слоями.</p><p><a href="README.md">Подробный разбор</a> · <a href="motion-recipes.md">Применение к AIQME</a> · <a href="interaction-report.json">Проверки перемотки и управления</a> · <a href="asset-inventory.json">Ресурсы и параметры видео</a></p><p class="note" id="note">Кадры сделаны после исправления HTTP Range в локальном сервере. Проверена перемотка пяти роликов вперёд и назад. Отдельные кадры не показывают всю длительность эффекта: полный механизм и тайминги описаны в разборе.</p></header><nav aria-label="Режим просмотра"><button data-filter="desktop" aria-pressed="true">Компьютер · 1440×900</button><button data-filter="mobile" aria-pressed="false">Телефон · 390×844</button><button data-filter="reduced-motion" aria-pressed="false">Reduced motion</button><button data-filter="checks" aria-pressed="false">Взаимодействия</button></nav><p id="count" aria-live="polite"></p><div class="grid">FIGURES</div><footer>Скриншоты открываются в полном размере по нажатию. Галерея работает локально без сторонних библиотек и сетевых ресурсов. Это материалы исследования, а не изменения дизайна AIQME.</footer></main><script>
const notes={desktop:'Кадры сделаны после исправления HTTP Range в локальном сервере. Проверена перемотка пяти роликов вперёд и назад. Отдельные кадры не показывают всю длительность эффекта: полный механизм и тайминги описаны в разборе.',mobile:'В архиве отсутствуют 18 мобильных MP4: кадры подтверждают вёрстку, постеры и текст, но не плавность оригинальных мобильных видео. Свежая мобильная загрузка использует другую, существенно более короткую композицию.','reduced-motion':'Эмуляция prefers-reduced-motion: reduce. В этой сборке не обнаружено адаптации сценария к настройке: видео и анимации остаются активны. Здесь сняты первые три секции; вывод не основан только на статичных кадрах.',checks:'Popup открывается и закрывается крестиком, но не Escape; фокус не переносится в диалог. При отключённом JavaScript загрузчик продолжает перекрывать страницу.'};
function select(mode){let count=0;document.querySelectorAll('figure').forEach(f=>{f.hidden=f.dataset.mode!==mode;if(!f.hidden)count++});document.querySelectorAll('button[data-filter]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.filter===mode)));document.getElementById('note').textContent=notes[mode];document.getElementById('count').textContent=`Кадров: ${count}`}
document.querySelectorAll('button[data-filter]').forEach(b=>b.addEventListener('click',()=>select(b.dataset.filter)));select('desktop');
</script></html>'''.replace('FIGURES','\n'.join(figures))
(OUT/'index.html').write_text(text)
# Printable desktop overview.
files=[OUT/'screenshots'/s['screenshot'] for s in report['scenarios']['desktop']['samples'] if 'screenshot'in s]
sheet=Image.new('RGB',(960,330*((len(files)+1)//2)),'#eee');draw=ImageDraw.Draw(sheet)
for i,f in enumerate(files):
 image=Image.open(f);image.thumbnail((480,300));x=i%2*480;y=i//2*330;sheet.paste(image,(x,y+25));draw.text((x+5,y+5),f.stem,fill='black')
sheet.save(OUT/'desktop-contact-sheet.jpg',quality=88)
print('Built gallery:',len(figures),'images')
