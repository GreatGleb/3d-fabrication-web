"""Package the current V2 as a standalone, ready-to-upload static website."""
from pathlib import Path
import re
import shutil
import tempfile
import json

import build_motion_v2
import build_pages

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'github-pages'
MARKER = '.aiqme-v2-package'


def build():
    if OUTPUT.is_symlink() or (OUTPUT.exists() and not (OUTPUT / MARKER).is_file()):
        raise RuntimeError('Refusing to replace an unrecognized output directory')
    with tempfile.TemporaryDirectory(prefix='aiqme-v2-') as temporary:
        build_motion_v2.OUTPUT = Path(temporary) / 'comparison'
        build_motion_v2.build()
        package = build_motion_v2.OUTPUT / 'v2'
        # Keep the published V2 shell while rendering the current calculator.
        translations = json.loads((ROOT / 'site/i18n/ru.json').read_text())
        values = build_pages.page_values('ru', 'calculator', translations, ['ru'], 'demo')
        template = (ROOT / 'site/calculator/index.html').read_text()
        main = re.search(r'<main\b.*?</main>', template, re.S).group()
        calculator = package / 'calculator/index.html'
        calculator.write_text(re.sub(r'<main\b.*?</main>',
            lambda _: build_pages.render(main, values), calculator.read_text(), flags=re.S))
        for name in ('calculator.js', 'site.css'):
            shutil.copy2(ROOT / 'site/assets' / name, package / 'assets' / name)
        shutil.copytree(ROOT / 'site/assets/vendor', package / 'assets/vendor', dirs_exist_ok=True)
        for path in package.rglob('*.html'):
            html = path.read_text()
            prefix = '' if path.parent == package else '../'
            calculator_script = f'<script defer src="{prefix}assets/calculator.js"></script>'
            html = html.replace(calculator_script,
                f'<script defer src="{prefix}assets/vendor/stl-loader.js"></script>' + calculator_script)
            html = re.sub(r'<p class="(?:lead|portal)-demo-notice">.*?</p>', '', html)
            replacements = {
                'Демо пройдено. Заявка не отправлена.': 'Заявка подготовлена.',
                'Так выглядит завершение формы. Для реального обращения напишите AIQME по почте.': 'Чтобы передать задачу команде AIQME, напишите нам по почте.',
                'Спасибо! Заявка получена.': 'Заявка подготовлена.',
                'Мы получили описание задачи и свяжемся с вами по указанной почте.': 'Чтобы передать задачу команде AIQME, напишите нам по почте.',
                'Демо-клиент': 'Клиент AIQME',
                'ДЕМОНСТРАЦИЯ КАБИНЕТА': 'КАБИНЕТ КЛИЕНТА',
                'Посмотрите, как выглядит кабинет клиента.': 'Проекты, статусы и файлы.',
                'Здесь только вымышленные проекты и открытые демофайлы. Настоящие учётные записи на этой странице не используются.': 'Вся информация о ходе работы собрана в одном месте.',
                'Открыть демо-кабинет': 'Открыть кабинет',
                'Закрыть демо': 'Закрыть кабинет',
                'Видеореференс:': 'Видео:',
                'Видеореференсы:': 'Видео:',
            }
            for old, new in replacements.items():
                html = html.replace(old, new)
            path.write_text(html)
        projects = package / 'assets/demo/projects.json'
        projects.write_text(projects.read_text().replace('Демо: корпус датчика', 'Корпус датчика')
                            .replace('Демо: тестовая деталь', 'Тестовая деталь')
                            .replace('Пример проекта с открытым демонстрационным STL-файлом.', 'Модель детали и STL-файл.')
                            .replace('demo-cube.stl', 'cube.stl'))
        (package / 'assets/demo/demo-cube.stl').rename(package / 'assets/demo/cube.stl')
        (package / '.nojekyll').touch()
        (package / MARKER).write_text('Built by scripts/build_github_pages_v2.py\n')
        if (OUTPUT / 'README.md').is_file():
            shutil.copy2(OUTPUT / 'README.md', package / 'README.md')
        if OUTPUT.exists():
            for child in OUTPUT.iterdir():
                if child.name == '.git':
                    continue
                if child.is_dir() and not child.is_symlink():
                    shutil.rmtree(child)
                else:
                    child.unlink()
        shutil.copytree(package, OUTPUT, dirs_exist_ok=True)
    print(f'Ready to upload: {OUTPUT}')


if __name__ == '__main__':
    build()
