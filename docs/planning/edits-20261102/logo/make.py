"""Логотипы правки 02.11 из PNG заказчика (LOGO.zip, 31.10): общая рамка и чистый фон, буквы не меняются.

Запуск: python3 -I make.py <каталог с «Black AIQME logo.png» и «White AIQME logo.png»> <каталог вывода>

1. Пиксели с прозрачностью alpha < 8 (≈3 %) обнуляются — рваная дымка вокруг букв, не видна глазом.
2. Белый файл сдвинут относительно чёрного на несколько пикселей: сдвиг подбирается по наибольшему
   совпадению масок букв (alpha ≥ 128) в пределах ±16 px.
3. Оба файла обрезаются одной рамкой: рамка букв чёрного файла плюс поле PAD px со всех сторон.
Цвет и форма пикселей букв не меняются, масштаб тоже.
"""
import sys
from pathlib import Path

from PIL import Image, ImageChops

PAD = 8
FAINT = 8
SEARCH = 16


def clean(im):
    r, g, b, a = im.split()
    return Image.merge("RGBA", (r, g, b, a.point(lambda v: 0 if v < FAINT else v)))


def mask(im):
    return im.getchannel("A").point(lambda v: 255 if v >= 128 else 0)


def overlap(ref, mov, dx, dy):
    shifted = ImageChops.offset(mov, dx, dy)
    both = ImageChops.multiply(ref, shifted)
    return sum(both.histogram()[255:])


def main():
    src, out = Path(sys.argv[1]), Path(sys.argv[2])
    black = clean(Image.open(src / "Black AIQME logo.png").convert("RGBA"))
    white = clean(Image.open(src / "White AIQME logo.png").convert("RGBA"))
    # холсты почти одинаковые (2172 и 2171 px) — выравниваем белый на холст чёрного
    canvas = Image.new("RGBA", black.size, (0, 0, 0, 0))
    canvas.paste(white, (0, 0))
    white = canvas
    ref, mov = mask(black), mask(white)
    # грубо по рамкам, затем перебор вокруг
    bb, wb = ref.getbbox(), mov.getbbox()
    gx, gy = bb[0] - wb[0], bb[1] - wb[1]
    best = max(((overlap(ref, mov, gx + dx, gy + dy), gx + dx, gy + dy)
                for dx in range(-SEARCH, SEARCH + 1, 2) for dy in range(-SEARCH, SEARCH + 1, 2)))
    _, sx, sy = best
    best = max(((overlap(ref, mov, sx + dx, sy + dy), sx + dx, sy + dy)
                for dx in (-1, 0, 1) for dy in (-1, 0, 1)))
    _, sx, sy = best
    shifted = Image.new("RGBA", white.size, (0, 0, 0, 0))
    shifted.paste(white, (sx, sy))
    frame = black.getchannel("A").getbbox()
    box = (frame[0] - PAD, frame[1] - PAD, frame[2] + PAD, frame[3] + PAD)
    out.mkdir(parents=True, exist_ok=True)
    black.crop(box).save(out / "logo.png", optimize=True)
    shifted.crop(box).save(out / "logo-white.png", optimize=True)
    print(f"сдвиг белого: {sx:+d}, {sy:+d}; рамка {box}; размер {box[2] - box[0]}×{box[3] - box[1]}")


if __name__ == "__main__":
    main()
