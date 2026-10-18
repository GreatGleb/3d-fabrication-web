"""Geometry and pricing integration checks against a temporary static build."""
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
from pathlib import Path
import math
import struct
import os
from playwright.sync_api import sync_playwright, expect
from check_pages import cube_stl

ROOT = Path(__file__).resolve().parents[1]
class Quiet(SimpleHTTPRequestHandler):
    def log_message(self, *args): pass

server = ThreadingHTTPServer(('127.0.0.1', 0), partial(Quiet, directory=str(ROOT)))
Thread(target=server.serve_forever, daemon=True).start()
try:
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
        page = browser.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        base = os.environ.get('STL_TEST_BASE_URL', f'http://127.0.0.1:{server.server_port}/' + os.environ.get('STL_TEST_DIRECTORY', 'build/demo')).rstrip('/')
        page.goto(base + '/calculator/')
        # Invalid parameters must not prevent model ingestion and later recovery.
        page.locator('#stl-quantity').fill('0')
        page.locator('#stl-upload').set_input_files({'name':'cube.stl','mimeType':'model/stl','buffer':cube_stl()[0]})
        expect(page.locator('#calculator-status')).to_contain_text('Количество')
        page.locator('#stl-quantity').fill('1')
        expect(page.locator('#calculator-values')).to_be_visible()
        for data in cube_stl():
            page.locator('#stl-upload').set_input_files({'name':'cube.STL','mimeType':'model/stl','buffer':data})
            expect(page.locator('#volume-val')).to_have_text('1')
            expect(page.locator('#calculator-values')).to_be_visible()
            for axis in 'xyz': expect(page.locator('#dim-' + axis)).to_have_text('10')
        # Translation and consistently reversed winding preserve physical volume.
        translated = bytearray(cube_stl()[0])
        for offset in range(84, len(translated), 50):
            vertices = [struct.unpack_from('<3f', translated, offset + 12 + i * 12) for i in range(3)]
            for i, vertex in enumerate(reversed(vertices)):
                struct.pack_into('<3f', translated, offset + 12 + i * 12, *(v + 10000 for v in vertex))
        model = page.evaluate('data => AIQMEStlCalculator.parseStl(new Uint8Array(data).buffer)', list(translated))
        assert model['dimensions'] == [10,10,10] and math.isclose(model['volume'],1000)
        for material, density in [('PLA',1.24),('ABS',1.04),('PETG',1.27)]:
            page.select_option('#material-select', material)
            for infill in (0,20,100):
                page.locator('#infill-slider').fill(str(infill))
                mass = density * (0.2 + 0.8 * infill / 100)
                expect(page.locator('#weight-val')).to_have_text(str(round(mass,2)).replace('.',','))
                quote = page.evaluate('([v,d,i]) => AIQMEStlCalculator.estimate(v,d,i,0.1,5)', [1,density,infill])
                assert math.isclose(quote['price'],mass*0.1+5)
        # Verify the displayed currency as well as the unrounded engine value.
        expect(page.locator('#price-val')).to_have_text('5,13 $')
        page.locator('#stl-quantity').fill('3')
        expect(page.locator('#weight-val')).to_have_text('3,81')
        page.select_option('#stl-unit','cm')
        expect(page.locator('#dim-x')).to_have_text('100')
        page.select_option('#stl-unit','mm')
        for data in [b'garbage', cube_stl()[0][:-1], cube_stl()[1].replace(b'10',b'0'), cube_stl()[1].replace(b'vertex 0 0 0',b'vertex NaN 0 0',1)]:
            page.locator('#stl-upload').set_input_files({'name':'bad.stl','mimeType':'model/stl','buffer':data})
            expect(page.locator('#calculator-status')).to_contain_text('Не удалось')
            expect(page.locator('#calculator-values')).to_be_hidden()
            expect(page.locator('#calculator-loading')).to_be_hidden()
        page.evaluate('''data => {
            const transfer = new DataTransfer();
            transfer.items.add(new File([new Uint8Array(data)], 'dropped.stl'));
            document.querySelector('#drop-zone').dispatchEvent(new DragEvent('drop', {bubbles:true, dataTransfer:transfer}));
        }''', list(cube_stl()[0]))
        expect(page.locator('#result-file')).to_have_text('dropped.stl')
        expect(page.locator('#calculator-values')).to_be_visible()
        page.locator('.file-remove').click()
        expect(page.locator('#calculator-values')).to_be_hidden()
        for width in [390,768,1440]:
            page.set_viewport_size({'width':width,'height':900})
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        # Hold FileReader calls to verify loading, replacement and late completion.
        page.evaluate("""() => {
            window.nativeRead = FileReader.prototype.readAsArrayBuffer;
            window.pendingReads = [];
            FileReader.prototype.readAsArrayBuffer = function(file) {
                pendingReads.push(() => nativeRead.call(this, file));
            };
        }""")
        upload = page.locator('#stl-upload')
        upload.set_input_files({'name':'old.stl','mimeType':'model/stl','buffer':cube_stl()[0]})
        expect(page.locator('#calculator-loading')).to_be_visible()
        expect(page.locator('#stl-calculator')).to_have_attribute('aria-busy','true')
        upload.set_input_files({'name':'new.stl','mimeType':'model/stl','buffer':cube_stl()[1]})
        page.evaluate('pendingReads[1]()')
        expect(page.locator('#result-file')).to_have_text('new.stl')
        page.evaluate('pendingReads[0]()')
        page.wait_for_timeout(100)
        expect(page.locator('#result-file')).to_have_text('new.stl')
        page.locator('#stl-calculator button[type=submit]').click()
        page.select_option('#material-select','ABS')
        expect(page.locator('#calculator-values')).to_be_hidden()
        expect(page.locator('#calculator-loading')).to_be_visible()
        page.locator('.file-remove').click()
        page.evaluate('pendingReads[2]()')
        page.wait_for_timeout(100)
        expect(page.locator('#calculator-values')).to_be_hidden()
        expect(page.locator('#calculator-loading')).to_be_hidden()
        page.evaluate('() => { FileReader.prototype.readAsArrayBuffer = nativeRead; }')
        # Read failure, wrong extension and oversized files leave no stale price.
        page.evaluate("() => { FileReader.prototype.readAsArrayBuffer = function() { this.onerror(); }; }")
        upload.set_input_files({'name':'unreadable.stl','mimeType':'model/stl','buffer':cube_stl()[0]})
        expect(page.locator('#calculator-status')).to_contain_text('Не удалось')
        expect(page.locator('#calculator-loading')).to_be_hidden()
        page.evaluate('() => { FileReader.prototype.readAsArrayBuffer = nativeRead; }')
        upload.set_input_files({'name':'cube.obj','mimeType':'text/plain','buffer':cube_stl()[0]})
        expect(page.locator('#calculator-status')).to_contain_text('Не удалось')
        page.evaluate("""() => {
            const transfer = new DataTransfer();
            transfer.items.add(new File([new Uint8Array(25 * 1024 * 1024 + 1)], 'large.stl'));
            const input = document.querySelector('#stl-upload');
            input.files = transfer.files; input.dispatchEvent(new Event('change'));
        }""")
        expect(page.locator('#calculator-status')).to_contain_text('больше 25')
        expect(page.locator('#calculator-values')).to_be_hidden()
        page.goto(base + '/')
        page.locator('#quick-stl-file').set_input_files({'name':'cube.stl','mimeType':'model/stl','buffer':cube_stl()[0]})
        page.locator('#quick-stl-calculator button[type=submit]').click()
        expect(page.locator('#quick-result-volume')).to_have_text('1')
        assert not errors, errors
        browser.close()
        print('STL geometry, invalid files, pricing, drop, clear, responsive layout and quick calculator passed.')
finally:
    server.shutdown()
