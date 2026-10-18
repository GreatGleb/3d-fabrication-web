"""Crop decorative website images from the screenshots embedded in the Excel brief."""

from io import BytesIO
from pathlib import Path
from zipfile import ZipFile

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
WORKBOOK = ROOT.parent / "docs/customer/design_inside_excel.xlsx"
OUTPUT = ROOT / "site" / "assets" / "images"

# The workbook contains only full-page PNG mockups. Crop the clean image areas
# without regenerating or altering the objects shown in the design.
CROPS = {
    "excel-hero.png": ("image3.png", (420, 55, 1022, 363)),
    "excel-scan.png": ("image5.png", (42, 508, 269, 615)),
    "excel-model.png": ("image5.png", (285, 508, 513, 615)),
    "excel-print.png": ("image5.png", (529, 508, 759, 615)),
    "excel-metal.png": ("image5.png", (42, 742, 395, 844)),
    "excel-cnc.png": ("image5.png", (406, 742, 759, 844)),
    "excel-process.png": ("image5.png", (43, 1159, 758, 1250)),
}


def extract() -> None:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    with ZipFile(WORKBOOK) as workbook:
        for filename, (source, box) in CROPS.items():
            with Image.open(BytesIO(workbook.read(f"xl/media/{source}"))) as image:
                crop = image.crop(box)
                crop.save(OUTPUT / filename, optimize=True)
                print(f"{filename}: {crop.width} x {crop.height}")


if __name__ == "__main__":
    extract()
