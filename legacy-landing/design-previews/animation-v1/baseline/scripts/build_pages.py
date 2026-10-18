"""Build the AIQME static preview from HTML templates and locale dictionaries."""

import argparse
from html import escape
import json
from pathlib import Path
import re
import shutil
from urllib.parse import quote


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "site"
LOCALES = SOURCE / "i18n"
PAGES = ("", "projektai", "kontaktai", "calculator", "login")
PLACEHOLDER = re.compile(r"\{\{([a-z][a-z0-9_]*)\}\}")


def render(template: str, values: dict[str, str], raw: set[str] | None = None) -> str:
    raw = raw or set()

    def replace(match: re.Match[str]) -> str:
        key = match.group(1)
        if key not in values:
            raise KeyError(f"Missing template value: {key}")
        value = str(values[key])
        return value if key in raw else escape(value, quote=True)

    return PLACEHOLDER.sub(replace, template)


def page_values(locale: str, page: str, translations: dict, available: list[str], mode: str) -> dict:
    locale_depth = 0 if locale == "ru" else 1
    page_depth = 0 if not page else 1
    site_root = "../" * (locale_depth + page_depth)
    locale_root = "../" * page_depth or "./"
    suffix = f"{page}/" if page else ""

    switch = []
    for candidate in available:
        label = candidate.upper()
        if candidate == locale:
            switch.append(f'<span aria-current="page">{label}</span>')
        else:
            destination = site_root + ("" if candidate == "ru" else f"{candidate}/") + suffix
            switch.append(f'<a href="{escape(destination, quote=True)}" lang="{candidate}">{label}</a>')

    values = dict(translations)
    values.update({
        "asset_root": site_root + "assets/",
        "site_mode": mode,
        "home_href": locale_root,
        "services_href": locale_root + "#services",
        "process_href": locale_root + "#process",
        "projects_href": locale_root + "projektai/",
        "about_href": locale_root + "#about",
        "information_href": locale_root + "#information",
        "contact_href": locale_root + "kontaktai/",
        "calculator_href": locale_root + "calculator/",
        "login_href": locale_root + "login/",
        "locale_switch": " ".join(switch),
        "mailto_href": "mailto:info@aiqme.eu?subject=" + quote(translations["contact_email_subject"]),
    })
    return values


def build(mode: str = "server") -> None:
    if mode not in ("server", "demo"):
        raise ValueError("Mode must be server or demo")
    output = ROOT / ("_site" if mode == "server" else "_site_pages")
    if output.is_symlink():
        raise RuntimeError(f"Refusing to replace symlink at {output.name}")

    dictionaries = {}
    for path in sorted(LOCALES.glob("*.json")):
        locale = path.stem
        translations = json.loads(path.read_text(encoding="utf-8"))
        if translations.get("lang") != locale:
            raise ValueError(f"Locale mismatch in {path}")
        dictionaries[locale] = translations
    if "ru" not in dictionaries:
        raise ValueError("Russian base dictionary is required")

    base_keys = set(dictionaries["ru"])
    for locale, translations in dictionaries.items():
        missing = base_keys - set(translations)
        if missing:
            raise ValueError(f"Missing {locale} translations: {sorted(missing)}")

    staging = ROOT / ("_site_staging" if mode == "server" else "_site_pages_staging")
    if staging.is_symlink():
        raise RuntimeError("Refusing to replace symlink at _site_staging")
    if staging.exists():
        shutil.rmtree(staging)
    staging.mkdir()
    shutil.copytree(SOURCE / "assets", staging / "assets")

    header = (SOURCE / "partials/header.html").read_text(encoding="utf-8")
    footer = (SOURCE / "partials/footer.html").read_text(encoding="utf-8")
    lead_form = (SOURCE / "partials/lead_form.html").read_text(encoding="utf-8")
    available = list(dictionaries)
    for locale, translations in dictionaries.items():
        locale_output = staging if locale == "ru" else staging / locale
        for page in PAGES:
            source = SOURCE / page / "index.html" if page else SOURCE / "index.html"
            destination = locale_output / page / "index.html" if page else locale_output / "index.html"
            values = page_values(locale, page, translations, available, mode)
            values["header"] = render(header, values, {"locale_switch"})
            values["footer"] = render(footer, values)
            values["lead_form"] = render(lead_form, values)
            html = render(source.read_text(encoding="utf-8"), values, {"header", "footer", "lead_form"})
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_text(html, encoding="utf-8")

    (staging / ".nojekyll").touch()
    if output.exists():
        shutil.rmtree(output)
    staging.rename(output)
    print(f"Built {len(dictionaries)} locale(s), {len(PAGES) * len(dictionaries)} pages in {output} ({mode})")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Build the AIQME site")
    parser.add_argument("--mode", choices=("server", "demo"), default="server")
    build(parser.parse_args().mode)
