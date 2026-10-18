"""Build a separate demo comparison without modifying site/, build/server/ or build/demo/."""
import importlib.util
import hashlib
from pathlib import Path
import re
import shutil
from tempfile import TemporaryDirectory

ROOT = Path(__file__).resolve().parents[1]
VARIANT = ROOT / 'design-previews/animation-v1'
BASELINE = VARIANT / 'baseline'
OUTPUT = ROOT / 'build/motion-v1'

def build():
    if OUTPUT.is_symlink() or (OUTPUT.exists() and not (OUTPUT / '.motion-preview').exists()):
        raise RuntimeError('Refusing to replace an unrecognized preview directory')
    with TemporaryDirectory(prefix='aiqme-motion-') as temp:
        temp = Path(temp)
        spec = importlib.util.spec_from_file_location('motion_baseline_builder', BASELINE / 'scripts/build_pages.py')
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        builder.ROOT = temp
        builder.SOURCE = BASELINE / 'site'
        builder.LOCALES = builder.SOURCE / 'i18n'
        builder.build('demo')
        result = temp / 'comparison'
        result.mkdir()
        for name in ('original', 'animated'):
            shutil.copytree(temp / '_site_pages', result / name)
        animated = result / 'animated'
        shutil.copytree(VARIANT / 'assets', animated / 'assets/motion')
        path = animated / 'index.html'
        html = path.read_text()
        revision = hashlib.sha256(b''.join(p.read_bytes() for p in sorted((VARIANT / 'assets').glob('*')))).hexdigest()[:12]
        html = html.replace('</head>', '<link rel="stylesheet" href="assets/motion/motion.css">\n<script defer src="assets/motion/motion.js"></script>\n</head>')
        html = html.replace('assets/motion/motion.css"', f'assets/motion/motion.css?v={revision}"').replace('assets/motion/motion.js"', f'assets/motion/motion.js?v={revision}"')
        html = html.replace('class="page-home"', 'class="page-home motion-page"')
        scene = (VARIANT / 'scene.html').read_text()
        html, replacements = re.subn(r'    <div class="process-visual-band">.*?  </section>', lambda m: scene + '\n  </section>', html, count=1, flags=re.S)
        if replacements != 1:
            raise RuntimeError('Baseline process block not found; review the variant adapter')
        path.write_text(html)
        shutil.copy2(VARIANT / 'compare.html', result / 'index.html')
        (result / '.motion-preview').write_text('AIQME isolated comparison, demo mode\n')
        (result / '.nojekyll').touch()
        if OUTPUT.exists():
            shutil.rmtree(OUTPUT)
        shutil.copytree(result, OUTPUT)
    print(f'Comparison built in {OUTPUT}; ordinary site untouched')

if __name__ == '__main__':
    build()
