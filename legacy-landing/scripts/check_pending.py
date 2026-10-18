"""Check a slow submission snapshot, retry and navigation across breakpoints."""
from functools import partial
from http.server import ThreadingHTTPServer
from threading import Thread
from playwright.sync_api import sync_playwright, expect
from check_bugfixes import ROOT, QuietHandler


def check():
    server = ThreadingHTTPServer(('127.0.0.1', 0), partial(QuietHandler, directory=str(ROOT / 'build/server')))
    Thread(target=server.serve_forever, daemon=True).start()
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
            page = browser.new_page(viewport={'width':390,'height':844})
            page.add_init_script('''(() => {
                const native = fetch;
                window.sent = [];
                window.fetch = (url, options) => {
                    if (url === '/api/session') return new Promise(resolve => {
                        window.releaseSession = () => resolve(new Response(JSON.stringify({csrf:'test'}), {status:200}));
                    });
                    if (url === '/api/leads') {
                        window.sent.push(Object.fromEntries(options.body));
                        return Promise.resolve(new Response('{}', {status:window.sent.length === 1 ? 500 : 201}));
                    }
                    return native(url, options);
                };
            })()''')
            page.goto(f'http://127.0.0.1:{server.server_port}/')
            page.locator('.menu-button').click()
            page.set_viewport_size({'width':1440,'height':900})
            expect(page.locator('.menu-button')).to_have_attribute('aria-expanded','false')
            page.set_viewport_size({'width':390,'height':844})
            expect(page.locator('.primary-navigation')).to_be_hidden()
            page.locator('input[name=service][value=model] + span').click()
            page.locator('.lead-next').first.click()
            page.locator('#lead-message').fill('Первоначальное описание задачи')
            page.locator('.lead-step[data-step="1"] .lead-next').click()
            page.locator('#lead-name').fill('Тест')
            page.locator('#lead-email').fill('test@example.test')
            submit = page.locator('#lead-form button[type=submit]')
            submit.click()
            expect(page.locator('#lead-form')).to_have_attribute('aria-busy','true')
            expect(page.locator('.lead-step[data-step="2"] .lead-back')).to_be_disabled()
            expect(page.locator('#lead-name')).to_be_disabled()
            # A second submit cannot start a second pending request.
            page.locator('#lead-form').evaluate('e=>e.requestSubmit()')
            page.wait_for_function('!!window.releaseSession')
            page.evaluate('window.releaseSession()')
            expect(page.locator('#lead-status')).to_be_visible()
            expect(submit).to_be_enabled()
            expect(page.locator('#lead-name')).to_be_enabled()
            assert page.evaluate('window.sent.length') == 1
            assert page.evaluate('window.sent[0].message') == 'Первоначальное описание задачи'
            assert page.evaluate('window.sent[0].email') == 'test@example.test'
            assert page.evaluate('window.sent[0].service') == 'model'
            page.locator('.lead-step[data-step="2"] .lead-back').click()
            page.locator('#lead-message').fill('Изменённое описание для повторной отправки')
            page.locator('.lead-step[data-step="1"] .lead-next').click()
            submit.click()
            page.evaluate('window.releaseSession()')
            expect(page.locator('#lead-complete')).to_be_visible()
            expect(page.locator('#lead-complete-title')).to_be_focused()
            assert page.evaluate('window.sent.length') == 2
            assert page.evaluate('window.sent[1].message') == 'Изменённое описание для повторной отправки'
            browser.close()
    finally:
        server.shutdown()
        server.server_close()
    print('Pending submission, retry, single request and responsive menu checks passed.')

if __name__ == '__main__':
    check()
