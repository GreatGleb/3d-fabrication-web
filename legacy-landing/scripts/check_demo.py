"""Browser checks for the no-backend GitHub Pages build under a repo prefix."""

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import shutil
from tempfile import TemporaryDirectory
from threading import Thread

from playwright.sync_api import expect, sync_playwright

from check_pages import cube_stl


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "build/demo"
PREFIX = "aiqme-preview"
ROUTES = ("", "projektai/", "kontaktai/", "calculator/", "login/")


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def check():
    if not (OUTPUT / "index.html").is_file():
        raise RuntimeError("Run scripts/build_pages.py --mode demo first")
    assert not list(OUTPUT.rglob("*.sqlite3"))
    assert not (OUTPUT / "instance").exists()
    with TemporaryDirectory(prefix="aiqme-pages-demo-") as temp:
        shutil.copytree(OUTPUT, Path(temp) / PREFIX)
        server = ThreadingHTTPServer(("127.0.0.1", 0), partial(QuietHandler, directory=temp))
        Thread(target=server.serve_forever, daemon=True).start()
        base = f"http://127.0.0.1:{server.server_port}/{PREFIX}/"
        try:
            with sync_playwright() as playwright:
                chrome = Path("/usr/bin/google-chrome")
                options = {"args": ["--no-sandbox"]}
                if chrome.exists():
                    options["executable_path"] = str(chrome)
                browser = playwright.chromium.launch(**options)
                for width in (390, 768, 1440):
                    page = browser.new_page(viewport={"width": width, "height": 900})
                    errors, bad_responses, api_requests, external_requests = [], [], [], []
                    page.on("pageerror", lambda error: errors.append(str(error)))
                    page.on("response", lambda response: bad_responses.append(f"{response.status} {response.url}") if response.status >= 400 else None)
                    page.on("request", lambda request: api_requests.append(request.url) if "/api/" in request.url else None)
                    page.on("request", lambda request: external_requests.append(request.url) if not request.url.startswith(base) else None)
                    for route in ROUTES:
                        page.goto(base + route, wait_until="load")
                        expect(page.get_by_role("heading", level=1)).to_be_visible()
                        expect(page.locator('.site-header')).to_have_attribute('data-site-mode', 'demo')
                        assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), (width, route)

                    page.goto(base, wait_until="load")
                    page.locator("#quick-stl-file").set_input_files({"name": "cube.stl", "mimeType": "application/sla", "buffer": cube_stl()[0]})
                    page.locator("#quick-stl-calculator button[type=submit]").click()
                    expect(page.locator("#quick-result-volume")).to_have_text("1")
                    expect(page.locator(".lead-demo-notice")).to_be_visible()
                    page.locator('input[name="service"][value="model"] + span').click()
                    page.locator('.lead-step[data-step="0"] .lead-next').click()
                    page.locator('#lead-message').fill('Демонстрационная модель корпуса по фото')
                    page.locator('.lead-step[data-step="1"] .lead-next').click()
                    page.locator('#lead-name').fill('Демо')
                    page.locator('#lead-email').fill('demo@example.test')
                    page.locator('#lead-form button[type=submit]').click()
                    expect(page.get_by_role('heading', name='Демо пройдено. Заявка не отправлена.')).to_be_visible()
                    expect(page.locator('.lead-demo-mail')).to_be_visible()

                    page.goto(base + "calculator/", wait_until="load")
                    page.locator("#stl-upload").set_input_files({"name": "cube.stl", "mimeType": "application/sla", "buffer": cube_stl()[0]})
                    page.locator("#stl-calculator button[type=submit]").click()
                    expect(page.locator("#result-volume")).to_have_text("1")

                    page.goto(base + "login/", wait_until="load")
                    expect(page.locator('.portal-demo-entry')).to_be_visible()
                    expect(page.locator('#login-form')).to_be_hidden()
                    page.locator('#open-demo-button').click()
                    expect(page.get_by_role('heading', name='Демо: корпус датчика')).to_be_visible()
                    expect(page.get_by_role('heading', name='Демо: тестовая деталь')).to_be_visible()
                    link = page.get_by_role('link', name='Скачать файл: demo-cube.stl')
                    assert link.get_attribute('href').startswith(base + 'assets/demo/')
                    response = page.request.get(link.get_attribute('href'))
                    assert response.ok and response.body().startswith(b'solid cube')
                    page.locator('#logout-button').click()
                    expect(page.locator('.portal-demo-entry')).to_be_visible()
                    assert not errors, errors
                    assert not bad_responses, bad_responses
                    assert not api_requests, api_requests
                    assert not external_requests, external_requests
                    page.close()
                browser.close()
        finally:
            server.shutdown()
            server.server_close()
    print(f"Demo build passed under /{PREFIX}/ at 390, 768, 1440 px; no API requests")


if __name__ == "__main__":
    check()
