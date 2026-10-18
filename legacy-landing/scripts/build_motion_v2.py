"""Build isolated original / V1 / V2 comparison from frozen demo snapshots."""
from pathlib import Path
import shutil
import re
import hashlib
ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'design-previews/animation-v2'
OUTPUT = ROOT / 'build/motion-v2'
def build():
    if OUTPUT.is_symlink() or (OUTPUT.exists() and not (OUTPUT / '.motion-v2').exists()):
        raise RuntimeError('Refusing to replace unrecognized output')
    if OUTPUT.exists():
        shutil.rmtree(OUTPUT)
    OUTPUT.mkdir()
    (OUTPUT / '.motion-v2').touch()
    for name in ('original', 'v1', 'v2'):
        shutil.copytree(SOURCE / 'baseline' / ('original' if name == 'v2' else name), OUTPUT / name)
    shutil.copytree(SOURCE / 'assets', OUTPUT / 'v2/assets/motion-v2')
    # Shared fixes belong to the editable variant, never the frozen snapshots.
    shutil.copytree(SOURCE / 'shared', OUTPUT / 'v2/assets', dirs_exist_ok=True)
    path = OUTPUT / 'v2/index.html'
    html = path.read_text().replace('</head>', '<link rel="stylesheet" href="assets/motion-v2/motion.css"><script defer src="assets/motion-v2/motion.js"></script><script defer src="assets/motion-v2/gear.js"></script><script defer src="assets/motion-v2/navigation.js"></script></head>')
    media = '''<figure class="v2-media"><div class="v2-frame"><img src="assets/motion-v2/poster.jpg" width="1280" height="720" alt=""><video muted playsinline loop preload="none" aria-hidden="true"></video></div><figcaption class="v2-controls"><span>Видеореференс: <a href="https://vimeo.com/1012463800" target="_blank" rel="noopener">Bleu · Daria Serova ↗</a></span><button type="button" hidden>Воспроизвести</button></figcaption></figure>'''
    html, n = re.subn(r'<div class="hero-visual" aria-hidden="true">.*?</div>', lambda _: media, html, count=1)
    assert n == 1
    html, n = re.subn(r'    <div class="process-visual-band">.*?  </section>', lambda _: (SOURCE / 'scene.html').read_text() + '\n  </section>', html, count=1, flags=re.S)
    assert n == 1, 'Process scene adapter no longer matches baseline'
    html = html.replace('</head>', '<script defer src="assets/motion-v2/section-transition.js"></script></head>')
    html = html.replace('<section class="section services-section"', (SOURCE / 'mosaic.html').read_text() + '\n<section class="section services-section"', 1)
    html = html.replace('</head>', '<link rel="stylesheet" href="assets/motion-v2/mosaic.css"><script defer src="assets/motion-v2/mosaic.js"></script></head>')
    html = html.replace('</head>', '<link rel="stylesheet" href="assets/motion-v2/floating-cta.css"><script defer src="assets/motion-v2/floating-cta.js"></script></head>')
    html = html.replace('</head>', '<link rel="stylesheet" href="assets/motion-v2/services.css"><script defer src="assets/motion-v2/services.js"></script></head>')
    revision = hashlib.sha256(b''.join(p.read_bytes() for p in sorted((SOURCE / 'assets').glob('*')) if p.suffix in ('.js', '.css'))).hexdigest()[:12]
    for name in ('motion.css', 'motion.js', 'gear.js', 'navigation.js', 'section-transition.js', 'mosaic.css', 'mosaic.js', 'floating-cta.css', 'floating-cta.js', 'services.css', 'services.js'):
        html = html.replace(f'assets/motion-v2/{name}"', f'assets/motion-v2/{name}?v={revision}"')
    path.write_text(html)
    for page in (OUTPUT / 'v2').rglob('*.html'):
        prefix = '' if page.parent == OUTPUT / 'v2' else '../'
        page.write_text(page.read_text().replace('</head>',
            f'<link rel="stylesheet" href="{prefix}assets/responsive.css"></head>'))
    shutil.copy2(SOURCE / 'compare.html', OUTPUT / 'index.html')
    print(OUTPUT)
if __name__ == '__main__':
    build()
