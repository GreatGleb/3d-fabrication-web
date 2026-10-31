"""Убирает серый фон с логотипов заказчика 29.10: чёрный — снятие фона по цвету, белый — по форме чёрного."""
import sys
from PIL import Image, ImageFilter

src, out = sys.argv[1], sys.argv[2]
black = Image.open(src + '/WhatsApp Image 2026-10-29 at 23.37.33.jpeg').convert('RGB')
white = Image.open(src + '/WhatsApp Image 2026-10-29 at 23.37.33 (1).jpeg').convert('RGB')
BG = 247

def unblend(px):
    """Цвет C и прозрачность a, при которых a*C + (1-a)*фон = px (как «цвет в прозрачность» в GIMP)."""
    # светлее фона в чёрном файле — только шум JPEG вокруг краёв
    px = tuple(min(v, BG) for v in px)
    a = 0.0
    for v in px:
        if v < BG:
            a = max(a, (BG - v) / BG)
        elif v > BG:
            a = max(a, (v - BG) / (255 - BG))
    a = min(1.0, a)
    if a < 0.08:
        return (0, 0, 0), 0
    c = tuple(max(0, min(255, round((v - (1 - a) * BG) / a))) for v in px)
    return c, round(a * 255)

w, h = black.size
bpx, wpx = black.load(), white.load()
ob, ow = Image.new('RGBA', (w, h)), Image.new('RGBA', (w, h))
pb, pw = ob.load(), ow.load()
mismatch = shape = 0
for y in range(h):
    for x in range(w):
        c, a = unblend(bpx[x, y])
        pb[x, y] = (*c, a)
        # сверка формы: в белом файле буквы светлее фона, в чёрном темнее
        in_white = sum(wpx[x, y]) / 3 > 250 or (max(wpx[x, y]) - min(wpx[x, y])) > 60
        if a > 200:
            shape += 1
            mismatch += not in_white
BLUE = (3, 99, 246)
blue = Image.new('L', (w, h))
bl = blue.load()
for y in range(h):
    for x in range(w):
        r, g, b, a = pb[x, y]
        bl[x, y] = 255 if a > 120 and b - r > 120 else 0
near = blue.filter(ImageFilter.MaxFilter(9)).load()
for y in range(h):
    for x in range(w):
        a = pb[x, y][3]
        pw[x, y] = (*(BLUE if near[x, y] else (255, 255, 255)), a)
box = ob.getbbox()
pad = 8
box = (max(0, box[0] - pad), max(0, box[1] - pad), min(w, box[2] + pad), min(h, box[3] + pad))
ob.crop(box).save(out + '/logo-black.png', optimize=True)
ow.crop(box).save(out + '/logo-white.png', optimize=True)
print('bbox', box, 'size', (box[2] - box[0], box[3] - box[1]), f'shape mismatch {mismatch}/{shape}')
for name, bg in [('logo-black.png', (255, 255, 255)), ('logo-white.png', (11, 15, 22))]:
    im = Image.open(out + '/' + name)
    prev = Image.new('RGB', (im.width + 80, im.height + 80), bg)
    prev.paste(im, (40, 40), im)
    prev.save(out + '/preview-' + name)
