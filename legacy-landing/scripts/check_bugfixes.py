"""Regression checks for the interaction, state and responsive defects from the audit."""
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
from tempfile import TemporaryDirectory
from playwright.sync_api import sync_playwright, expect
from check_pages import cube_stl
import sys

ROOT = Path(__file__).resolve().parents[1]
class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args): pass

def check():
    server = ThreadingHTTPServer(('127.0.0.1', 0), partial(QuietHandler, directory=str(ROOT / 'build/demo')))
    Thread(target=server.serve_forever, daemon=True).start()
    base = f'http://127.0.0.1:{server.server_port}'
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
            page = browser.new_page(viewport={'width':1440,'height':1000})
            errors=[]
            page.on('pageerror', lambda e: errors.append(str(e)))
            page.goto(base, wait_until='networkidle')
            button=page.locator('.hero-actions .button-primary')
            image=button.evaluate('e=>getComputedStyle(e).backgroundImage')
            for direction in ('enter','leave','enter'):
                if direction=='enter': button.hover()
                else: page.mouse.move(0,0)
                for instant in (0,30,100,199):
                    page.evaluate('(t)=>document.getAnimations().forEach(a=>{a.pause();a.currentTime=t})',instant)
                    assert button.evaluate('e=>getComputedStyle(e).backgroundImage') == image
                    assert button.evaluate('e=>getComputedStyle(e).backgroundColor') == 'rgb(7, 89, 220)'
                page.evaluate('document.getAnimations().forEach(a=>a.finish())')
            page.emulate_media(reduced_motion='reduce')
            button.hover()
            assert button.evaluate('e=>getComputedStyle(e).backgroundImage') == image
            page.emulate_media(reduced_motion='no-preference')
            disabled=page.locator('#quick-stl-calculator button[type=submit]')
            disabled.evaluate('e=>e.disabled=true');disabled.hover(force=True)
            assert disabled.evaluate('e=>getComputedStyle(e).filter')=='none'
            disabled.evaluate('e=>e.disabled=false')
            card=page.locator('.service-card').first
            card.hover()
            assert card.evaluate('e=>getComputedStyle(e).transform')=='none'
            assert card.locator('img').evaluate('e=>getComputedStyle(e).transform')=='none'
            pick=page.locator('.file-pick').first
            pick.scroll_into_view_if_needed();page.mouse.move(0,0);page.wait_for_timeout(250)
            normal=pick.evaluate('e=>getComputedStyle(e).backgroundColor')
            pick.hover();page.wait_for_timeout(250)
            assert pick.evaluate('e=>getComputedStyle(e).backgroundColor') != normal
            assert page.locator('.quick-calculator').evaluate("e=>getComputedStyle(e).getPropertyValue('--section-tint')").strip()=='#dceaff'
            for width in (700,701,768,980,1024,1100):
                page.set_viewport_size({'width':width,'height':900});page.goto(base)
                copy=page.locator('.hero-copy').bounding_box();visual=page.locator('.hero-visual').bounding_box()
                assert copy['y']+copy['height'] <= visual['y'],width
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),width
            page.goto(base+'/projektai/')
            photo=page.locator('#bmw .case-image img')
            photo.evaluate('e=>e.decode()')
            assert photo.evaluate('e=>Math.abs(e.clientWidth/e.clientHeight-e.naturalWidth/e.naturalHeight)<.01')
            page.set_viewport_size({'width':390,'height':844});page.goto(base)
            page.locator('.menu-button').click();page.locator('.primary-navigation a').first.focus();page.keyboard.press('Escape')
            expect(page.locator('.menu-button')).to_be_focused()
            page.keyboard.press('Tab');expect(page.locator('.hero-actions a').first).to_be_focused()
            page.locator('.lead-next').first.click();expect(page.locator('#lead-status')).to_be_visible()
            page.locator('input[name=service][value=model] + span').click();expect(page.locator('#lead-status')).to_be_hidden()
            page.locator('.lead-next').first.click();page.locator('.lead-step[data-step="1"] .lead-next').click()
            page.locator('#lead-message').fill('a');expect(page.locator('#lead-message-error')).to_be_visible()
            page.locator('#lead-message').fill('Создать корпус по фотографии');expect(page.locator('#lead-message-error')).to_have_count(0)
            page.locator('#lead-quantity').fill('1e2');page.locator('.lead-step[data-step="1"] .lead-next').click()
            page.locator('#lead-name').fill('Тест');page.locator('#lead-email').fill('test@example.test');page.locator('#lead-form button[type=submit]').click()
            expect(page.locator('#lead-step-label')).to_be_hidden();expect(page.locator('.lead-progress')).to_be_hidden()
            expect(page.locator('#lead-complete-title')).to_be_focused()
            expect(page.locator('#lead-quantity')).to_have_value('100')
            for route,field,submit,volume in [('', '#quick-stl-file','#quick-stl-calculator button[type=submit]','#quick-result-volume'),('calculator/','#stl-upload','#stl-calculator button[type=submit]','#result-volume')]:
                page.goto(f'{base}/{route}')
                page.locator(field).set_input_files({'name':'tiny.stl','mimeType':'application/sla','buffer':cube_stl()[1].replace(b'10',b'0.01')})
                page.locator(submit).click();expect(page.locator(volume)).to_be_visible()
                assert page.locator(volume).inner_text() not in ('0','—')
            page.locator('#stl-quantity').fill('0');expect(page.locator('#calculator-values')).to_be_hidden()
            expect(page.locator('#calculator-empty')).to_contain_text('Исправьте параметры')
            page.locator('#stl-quantity').fill('2');expect(page.locator('#calculator-values')).to_be_visible()
            page.goto(base+'/calculator/');page.locator('#stl-upload').set_input_files({'name':'cube.stl','mimeType':'application/sla','buffer':cube_stl()[0]})
            page.locator('#stl-density').fill('0');page.locator('#stl-calculator button[type=submit]').click()
            page.locator('#stl-density').fill('1.24');expect(page.locator('#calculator-status')).to_be_hidden()
            # Incomplete number input has empty .value but is not an omitted density.
            page.locator('#stl-density').fill('')
            page.locator('#stl-density').press('e')
            assert page.locator('#stl-density').evaluate('e=>e.validity.badInput')
            page.locator('#stl-calculator button[type=submit]').click()
            expect(page.locator('#calculator-status')).to_contain_text('плотность')
            expect(page.locator('#calculator-values')).to_be_hidden()
            page.locator('#stl-density').fill('1.24')
            expect(page.locator('#calculator-status')).to_be_hidden()
            page.locator('#stl-calculator button[type=submit]').click()
            expect(page.locator('#calculator-values')).to_be_visible()
            page.locator('#stl-density').fill('')
            page.locator('#stl-density').press('e')
            expect(page.locator('#calculator-values')).to_be_hidden()
            expect(page.locator('#calculator-empty')).to_contain_text('Исправьте параметры')
            page.locator('#stl-density').fill('1.24')
            expect(page.locator('#calculator-values')).to_be_visible()
            # Late success and late failure must not modify a reopened dashboard.
            for fail in (False,True):
                context=browser.new_context()
                context.add_init_script('''(()=>{const native=fetch;let count=0;window.fetch=async(...args)=>{const response=await native(...args);if(String(args[0]).includes('demo/projects.json')&&++count===1) await new Promise((resolve,reject)=>{window.release=resolve;window.reject=()=>reject(new Error('late'));});return response;};})()''')
                tab=context.new_page();tab.goto(base+'/login/');tab.locator('#open-demo-button').click()
                expect(tab.locator('.portal-dashboard-head h2')).to_be_focused()
                tab.wait_for_function('!!window.release');tab.locator('#logout-button').click();expect(tab.locator('#open-demo-button')).to_be_focused()
                tab.locator('#open-demo-button').click();expect(tab.locator('.portal-project')).to_have_count(2)
                tab.evaluate('window.reject()' if fail else 'window.release()');tab.wait_for_timeout(100)
                expect(tab.locator('.portal-project')).to_have_count(2);expect(tab.locator('#portal-message')).to_be_hidden()
                context.close()
            context=browser.new_context(java_script_enabled=False);tab=context.new_page();tab.goto(base)
            expect(tab.locator('#lead-form')).to_be_hidden();expect(tab.locator('.lead-noscript')).to_be_visible();context.close()
            assert not errors, errors
            browser.close()
    finally:
        server.shutdown();server.server_close()
    print('Bug regressions passed: hover, tablet layout, colours, form state, precision, focus and stale responses.')

def check_quantity():
    sys.path.insert(0,str(ROOT))
    from server.app import create_app
    with TemporaryDirectory() as directory:
        root=Path(directory)
        app=create_app({'TESTING':True,'SECRET_KEY':'regression-only','DATABASE':str(root/'db.sqlite'),'STORAGE':str(root/'files')})
        client=app.test_client();csrf=client.get('/api/session').json['csrf']
        for quantity in ('2.5','-1','0','10001','NaN','Infinity'):
            data={'service':'model','message':'Создать модель корпуса','name':'Тест','email':'test@example.test','quantity':quantity}
            assert client.post('/api/leads',data=data,headers={'X-CSRF-Token':csrf}).status_code==400,quantity
        for quantity in ('','2','2.0','1e2'):
            data={'service':'model','message':'Создать модель корпуса','name':'Тест','email':'test@example.test','quantity':quantity}
            assert client.post('/api/leads',data=data,headers={'X-CSRF-Token':csrf}).status_code==201,quantity
    print('Server quantity normalisation passed.')

if __name__=='__main__':
    check()
    check_quantity()
