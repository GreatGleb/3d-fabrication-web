"""Check refreshed controls, file lifecycle, keyboard behavior and responsive layout."""
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
from playwright.sync_api import sync_playwright, expect
from check_pages import cube_stl

ROOT = Path(__file__).resolve().parents[1]

class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass

def check():
    server = ThreadingHTTPServer(('127.0.0.1', 0), partial(QuietHandler, directory=str(ROOT / 'build/demo')))
    Thread(target=server.serve_forever, daemon=True).start()
    base = f'http://127.0.0.1:{server.server_port}'
    file = {'name': 'тестовая-модель-с-длинным-названием.stl', 'mimeType': 'application/sla', 'buffer': cube_stl()[0]}
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
            page = browser.new_page()
            errors = []
            page.on('pageerror', lambda e: errors.append(str(e)))
            for width in (360, 390, 600, 700, 701, 768, 980, 981, 1440):
                page.set_viewport_size({'width': width, 'height': 900})
                for route in ('', 'calculator/', 'kontaktai/', 'projektai/', 'login/'):
                    page.goto(f'{base}/{route}', wait_until='networkidle')
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (width, route)
                if width <= 700:
                    page.goto(base, wait_until='networkidle')
                    note = page.locator('.hero-note').bounding_box()
                    picture = page.locator('.hero-visual').bounding_box()
                    assert note['y'] + note['height'] <= picture['y'], width
            page.set_viewport_size({'width': 390, 'height': 900})
            for route, selector, result in (('', '#quick-stl-file', '#quick-calculator-result'), ('calculator/', '#stl-upload', '#calculator-values')):
                page.goto(f'{base}/{route}')
                input = page.locator(selector)
                form = input.locator('xpath=ancestor::form')
                input.set_input_files(file)
                expect(form.locator('.file-name')).to_have_text(file['name'])
                form.locator('button[type=submit]').click()
                expect(page.locator(result)).to_be_visible()
                form.locator('.file-remove').focus()
                page.keyboard.press('Enter')
                expect(page.locator(result)).to_be_hidden()
                expect(form.locator('.file-name')).to_have_text('Файл пока не выбран')
                expect(input).to_be_focused()
                # Native chooser is still keyboard-accessible after removal.
                with page.expect_file_chooser() as chooser:
                    page.keyboard.press('Space')
                chooser.value.set_files(file)
                # Deterministic pending read: invalidate it before resolving.
                page.evaluate('''() => {
                    window.originalRead = FileReader.prototype.readAsArrayBuffer;
                    FileReader.prototype.readAsArrayBuffer = function(file) {
                        window.finishRead = () => window.originalRead.call(this, file);
                    };
                }''')
                form.locator('button[type=submit]').click()
                page.wait_for_function('typeof window.finishRead === "function"')
                form.locator('.file-remove').click()
                page.evaluate('window.finishRead()')
                page.evaluate('() => { FileReader.prototype.readAsArrayBuffer = window.originalRead; }')
                expect(page.locator(result)).to_be_hidden()
                expect(form.locator('button[type=submit]')).to_be_enabled()
                input.set_input_files(file)
                form.locator('button[type=submit]').click()
                expect(page.locator(result)).to_be_visible()
                if route:
                    page.locator('#stl-quantity').fill('0')
                    expect(page.locator(result)).to_be_hidden()
                    page.locator('#stl-quantity').fill('2')
                    expect(page.locator(result)).to_be_visible()
                    page.locator('#stl-density').fill('31')
                    expect(page.locator(result)).to_be_hidden()
                    page.locator('#stl-density').fill('1.24')
                    expect(page.locator('#result-mass')).to_have_text('0,89')
            page.goto(f'{base}/kontaktai/')
            page.locator('input[name=service][value=model] + span').click()
            page.locator('.lead-step[data-step="0"] .lead-next').click()
            page.locator('.lead-step[data-step="1"] .lead-next').click()
            expect(page.locator('#lead-message')).to_be_focused()
            expect(page.locator('#lead-message')).to_have_attribute('aria-invalid', 'true')
            page.locator('#lead-message').fill('Сделать корпус по фотографии')
            expect(page.locator('#lead-message-error')).to_have_count(0)
            page.locator('#lead-attachment').set_input_files(file)
            page.locator('#lead-form .file-remove').focus()
            page.keyboard.press('Enter')
            expect(page.locator('.lead-step[data-step="1"]')).to_be_visible()
            expect(page.locator('#lead-attachment')).to_be_focused()
            page.locator('.lead-step[data-step="1"] .lead-back').focus()
            page.keyboard.press('Enter')
            expect(page.locator('.lead-step[data-step="0"]')).to_be_visible()
            page.locator('.lead-step[data-step="0"] .lead-next').click()
            expect(page.locator('#lead-message')).to_have_value('Сделать корпус по фотографии')
            # Native controls remain visible without JavaScript.
            context = browser.new_context(java_script_enabled=False)
            fallback = context.new_page()
            fallback.goto(base)
            expect(fallback.locator('#quick-stl-file')).to_be_visible()
            context.close()
            assert not errors, errors
            browser.close()
        print('UI checks passed: responsive layout, file lifecycle, stale reads, invalid results, keyboard, form errors and no-JS fallback.')
    finally:
        server.shutdown()
        server.server_close()

if __name__ == '__main__':
    check()
