"""Browser checks for the built AIQME preview under a nested publication path."""

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import shutil
import struct
from tempfile import TemporaryDirectory
from threading import Thread

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "build/server"
PREFIX = "aiqme-preview"
ROUTES = ("", "projektai/", "kontaktai/", "calculator/")
WIDTHS = (390, 768, 1440)


def cube_stl():
    faces = (
        ((0, 0, 0), (0, 10, 0), (10, 10, 0), (10, 0, 0)),
        ((0, 0, 10), (10, 0, 10), (10, 10, 10), (0, 10, 10)),
        ((0, 0, 0), (10, 0, 0), (10, 0, 10), (0, 0, 10)),
        ((0, 10, 0), (0, 10, 10), (10, 10, 10), (10, 10, 0)),
        ((0, 0, 0), (0, 0, 10), (0, 10, 10), (0, 10, 0)),
        ((10, 0, 0), (10, 10, 0), (10, 10, 10), (10, 0, 10)),
    )
    triangles = [(a, b, c) for a, b, c, d in faces] + [(a, c, d) for a, b, c, d in faces]
    binary = b"AIQME cube".ljust(80, b"\0") + struct.pack("<I", len(triangles))
    for triangle in triangles:
        binary += struct.pack("<12fH", 0, 0, 0, *(value for point in triangle for value in point), 0)
    ascii_data = "solid cube\n" + "".join(
        "facet normal 0 0 0\nouter loop\n" + "".join(f"vertex {x} {y} {z}\n" for x, y, z in triangle) + "endloop\nendfacet\n"
        for triangle in triangles
    ) + "endsolid cube\n"
    return binary, ascii_data.encode()


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def check() -> None:
    if not (OUTPUT / "index.html").exists():
        raise RuntimeError("Run scripts/build_pages.py first")

    with TemporaryDirectory(prefix="aiqme-pages-") as temp:
        shutil.copytree(OUTPUT, Path(temp) / PREFIX)
        server = ThreadingHTTPServer(("127.0.0.1", 0), partial(QuietHandler, directory=temp))
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        base = f"http://127.0.0.1:{server.server_port}/{PREFIX}/"
        try:
            with sync_playwright() as playwright:
                chrome = Path("/usr/bin/google-chrome")
                options = {"args": ["--no-sandbox"]}
                if chrome.exists():
                    options["executable_path"] = str(chrome)
                browser = playwright.chromium.launch(**options)

                no_js = browser.new_context(viewport={"width": 390, "height": 844}, java_script_enabled=False)
                page = no_js.new_page()
                page.goto(base, wait_until="load")
                expect(page.get_by_role("heading", level=1)).to_be_visible()
                expect(page.get_by_role("navigation", name="Основная навигация")).to_be_visible()
                for anchor in ("services", "process", "projects", "about", "information"):
                    expect(page.locator(f"#{anchor}")).to_be_attached()
                no_js.close()

                for width in WIDTHS:
                    context = browser.new_context(viewport={"width": width, "height": 900})
                    page = context.new_page()
                    errors = []
                    failed_requests = []
                    bad_responses = []
                    external_requests = []
                    page.on("pageerror", lambda error: errors.append(str(error)))
                    page.on("requestfailed", lambda request: failed_requests.append(request.url))
                    page.on("response", lambda response: bad_responses.append(f"{response.status} {response.url}") if response.status >= 400 else None)
                    page.on("request", lambda request: external_requests.append(request.url) if not request.url.startswith(base) else None)

                    for route in ROUTES:
                        page.goto(base + route, wait_until="load")
                        expect(page.get_by_role("heading", level=1)).to_be_visible()
                        assert page.locator("html").get_attribute("lang") == "ru"
                        assert "{{" not in page.content(), f"Unrendered template on {route}"
                        assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), f"Horizontal overflow at {width}px on {route}"
                        images = page.locator("img")
                        images.evaluate_all('imgs => Promise.all(imgs.map(img => {img.loading = "eager"; return img.decode()}))')
                        assert images.evaluate_all("imgs => imgs.every(img => img.naturalWidth > 0)"), f"Broken image on {route}"
                        assert page.evaluate('document.fonts.check("16px \\\"Ubuntu Sans\\\"")'), f"Local font missing on {route}"

                        if width <= 980:
                            menu = page.locator(".menu-button")
                            expect(menu).to_be_visible()
                            expect(menu).to_have_attribute("aria-label", "Открыть меню")
                            menu.click()
                            expect(page.get_by_role("navigation", name="Основная навигация")).to_be_visible()
                            expect(menu).to_have_attribute("aria-expanded", "true")
                            expect(menu).to_have_attribute("aria-label", "Закрыть меню")
                            page.keyboard.press("Escape")
                            expect(menu).to_have_attribute("aria-expanded", "false")

                    page.goto(base, wait_until="load")
                    assert page.locator(".service-card").count() == 5
                    page.locator("#quick-stl-file").set_input_files({"name": "cube.stl", "mimeType": "application/sla", "buffer": cube_stl()[0]})
                    page.locator("#quick-stl-calculator button[type=submit]").click()
                    expect(page.locator("#quick-result-dimensions")).to_have_text("10 × 10 × 10")
                    expect(page.locator("#quick-result-volume")).to_have_text("1")
                    assert page.locator("#lead-form .lead-step").count() == 3

                    page.goto(base + "projektai/", wait_until="load")
                    expect(page.locator("#impeller")).to_be_attached()
                    expect(page.locator("#bmw")).to_be_attached()
                    expect(page.locator("#siemens")).to_be_attached()
                    page.goto(base + "kontaktai/", wait_until="load")
                    assert page.locator('a[href^="mailto:info@aiqme.eu"]').count() >= 1
                    assert page.locator('a[href="tel:+37068884084"]').count() >= 1
                    page.goto(base + "calculator/", wait_until="load")
                    for name, contents in zip(("cube-binary.stl", "cube-ascii.stl"), cube_stl()):
                        page.locator("#stl-upload").set_input_files({"name": name, "mimeType": "application/sla", "buffer": contents})
                        page.locator("#stl-density").fill("1.24")
                        page.locator("#stl-quantity").fill("2")
                        page.locator("#stl-calculator button[type=submit]").click()
                        expect(page.locator("#result-dimensions")).to_have_text("10 × 10 × 10")
                        expect(page.locator("#result-volume")).to_have_text("1")
                        expect(page.locator("#result-mass")).to_have_text("0,89")
                    assert not errors, f"JavaScript errors: {errors}"
                    assert not failed_requests, f"Failed requests: {failed_requests}"
                    assert not bad_responses, f"HTTP errors: {bad_responses}"
                    assert not external_requests, f"External runtime requests: {external_requests}"
                    context.close()

                browser.close()
        finally:
            server.shutdown()
            server.server_close()

    report = {"status": "passed", "prefix": f"/{PREFIX}/", "routes": list(ROUTES), "viewports": list(WIDTHS)}
    destination = ROOT.parent / "docs" / "design" / "pages-check.json"
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    check()
