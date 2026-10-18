"""Check the actual publishable package, including phone and landscape regressions."""
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
ROUTES = ('', 'calculator/', 'kontaktai/', 'projektai/', 'login/')
SIZES = ((320, 568), (360, 640), (390, 667), (390, 844), (600, 800),
         (601, 800), (650, 800), (700, 800), (701, 800),
         (768, 1024), (844, 390), (981, 800), (1024, 768), (1440, 900))

class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass

def check():
    server = ThreadingHTTPServer(('127.0.0.1', 0), partial(QuietHandler, directory=str(ROOT)))
    Thread(target=server.serve_forever, daemon=True).start()
    base = f'http://127.0.0.1:{server.server_port}/github-pages/'
    errors, responses = [], []
    try:
        with sync_playwright() as p:
            options = {'args': ['--no-sandbox']}
            if Path('/usr/bin/google-chrome').exists():
                options['executable_path'] = '/usr/bin/google-chrome'
            browser = p.chromium.launch(**options)
            page = browser.new_page()
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.on('response', lambda response: responses.append(response.url) if response.status >= 400 else None)

            def visit(route=''):
                page.goto(base + route, wait_until='domcontentloaded')
                page.evaluate('document.fonts.ready')
                page.wait_for_timeout(100)

            def fits():
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), page.url

            def step_visible():
                box = page.locator('.lead-step:not([hidden]) h3').bounding_box()
                assert box['y'] >= 68 and box['y'] + box['height'] <= page.viewport_size['height'], box

            for width, height in SIZES:
                page.set_viewport_size({'width': width, 'height': height})
                for route in ROUTES:
                    visit(route)
                    fits()
                    if width <= 980:
                        menu = page.locator('.menu-button')
                        menu.click()
                        nav = page.locator('.primary-navigation')
                        box = nav.bounding_box()
                        assert box['y'] + box['height'] <= height + 1, (width, height, route, box)
                        assert nav.evaluate('(e)=>getComputedStyle(e).flexWrap') == 'nowrap'
                        if height < 500:
                            assert nav.evaluate('(e)=>e.scrollHeight>e.clientHeight')
                        assert page.evaluate('getComputedStyle(document.documentElement).overflowY') == 'hidden'
                        # Last action is reachable even when the menu needs its own scrolling.
                        page.locator('.header-cta').focus()
                        assert page.locator('.header-cta').bounding_box()['y'] >= 68
                        page.keyboard.press('Tab')
                        expect(menu).to_be_focused()
                        page.keyboard.press('Escape')
                        expect(nav).to_be_hidden()
                        assert page.evaluate('getComputedStyle(document.documentElement).overflowY') != 'hidden'
                    if route == '':
                        controls = page.locator('.quick-calculator-controls')
                        button = controls.locator('button').bounding_box()
                        unit = controls.locator('select').bounding_box()
                        assert 44 <= button['height'] <= 56, (width, button)
                        assert unit['width'] >= 150, (width, unit)
                        if width <= 700:
                            assert button['y'] >= unit['y'] + unit['height'] + 10, (width, button, unit)
                            assert abs(button['width'] - unit['width']) <= 1, (width, button, unit)
                        if page.locator('.gear-case.is-pinned').count():
                            panel = page.locator('.gear-panel').bounding_box()
                            header = page.locator('.site-header').bounding_box()
                            assert panel['height'] <= height - header['height'] - 24, (width, height, panel)
                    if route == 'login/':
                        page.locator('#open-demo-button').click()
                        expect(page.locator('.portal-project')).to_have_count(2)
                        fits()
                        page.locator('#logout-button').click()
                        expect(page.locator('#portal-login')).to_be_visible()
                print(f'Layout and menu: {width}x{height}, five pages', flush=True)

            for width, height in ((320, 568), (390, 844), (844, 390)):
                page.set_viewport_size({'width': width, 'height': height})
                for route in ('', 'kontaktai/'):
                    visit(route)
                    page.locator('.lead-service-grid label').first.click()
                    page.locator('.lead-step[data-step="0"] .lead-next').click()
                    step_visible()
                    page.locator('#lead-message').fill('Изготовить корпус по чертежу')
                    page.locator('.lead-step[data-step="1"] .lead-next').click()
                    step_visible()
                    page.locator('.lead-step[data-step="2"] .lead-back').click()
                    step_visible()
                    expect(page.locator('#lead-message')).to_have_value('Изготовить корпус по чертежу')
                    page.locator('.lead-step[data-step="1"] .lead-next').click()
                    page.locator('#lead-name').fill('Тест')
                    page.locator('#lead-email').fill('test@example.com')
                    page.locator('#lead-form button[type=submit]').click()
                    expect(page.locator('#lead-complete-title')).to_have_text('Заявка подготовлена.')
                    fits()
                print(f'Form steps and completion: {width}x{height}', flush=True)

            page.set_viewport_size({'width': 320, 'height': 568})
            visit('calculator/')
            page.locator('#stl-upload').set_input_files(ROOT / 'github-pages/assets/demo/cube.stl')
            page.locator('#stl-calculator button[type=submit]').click()
            expect(page.locator('#calculator-values')).to_be_visible()
            fits()
            assert page.locator('#stl-density').evaluate('(e)=>parseFloat(getComputedStyle(e).fontSize)') >= 16

            visit()
            # A fixed CTA must not sit on top of the service's own link.
            page.locator('#service-tab-0').focus()
            page.keyboard.press('End')
            page.keyboard.press('ArrowUp')
            expect(page.locator('#service-panel-3')).to_be_visible()
            page.wait_for_timeout(700)
            action = page.locator('.service-card:not([hidden]) .text-link')
            action.evaluate('(e)=>scrollTo({top:scrollY+e.getBoundingClientRect().top-innerHeight+50,behavior:"instant"})')
            page.wait_for_timeout(150)
            expect(page.locator('.floating-cta')).to_be_hidden()
            # All selector states remain usable, then fit again after rotation.
            page.locator('#service-tab-3').focus()
            page.keyboard.press('Home')
            for i in range(5):
                if i:
                    page.keyboard.press('ArrowDown')
                expect(page.locator(f'#service-panel-{i}')).to_be_visible()
            page.set_viewport_size({'width': 844, 'height': 390})
            fits()

            for reduced, js in (('reduce', True), ('no-preference', False)):
                context = browser.new_context(viewport={'width': 320, 'height': 568},
                                              reduced_motion=reduced, java_script_enabled=js)
                fallback = context.new_page()
                fallback.goto(base, wait_until='domcontentloaded')
                assert fallback.evaluate('document.documentElement.scrollWidth <= innerWidth')
                assert fallback.locator('.gear-case.is-pinned').count() == 0
                expect(fallback.locator('.gear-steps li')).to_have_count(3)
                if not js:
                    expect(fallback.locator('.menu-button')).to_be_hidden()
                    expect(fallback.locator('.primary-navigation')).to_be_visible()
                    assert fallback.locator('.site-header').evaluate('(e)=>getComputedStyle(e).position') == 'relative'
                context.close()
            assert not errors, errors
            assert not responses, responses
            browser.close()
        print('PASS: package layouts, menu, forms, calculator, portal, scene fit, CTA, fallbacks; no JS/HTTP errors.')
    finally:
        server.shutdown()
        server.server_close()

if __name__ == '__main__':
    check()
