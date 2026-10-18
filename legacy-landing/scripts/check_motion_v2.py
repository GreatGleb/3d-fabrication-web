"""Verify actual motion, media lifecycle and preserved snapshots against localhost:8824."""
import hashlib
import json
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
def check():
 for name, digest in json.loads((ROOT/'design-previews/animation-v1/baseline/manifest.json').read_text()).items():
  assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest, name
 for name, old in [('original','original'),('v1','animated')]:
  for path in (ROOT/'build/motion-v1'/old).rglob('*'):
   if path.is_file():assert path.read_bytes()==(ROOT/'build/motion-v2'/name/path.relative_to(ROOT/'build/motion-v1'/old)).read_bytes()
 with sync_playwright() as p:
  b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
  for width in (390,768,1440):
   page=b.new_page(viewport={'width':width,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
   page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(2100)
   page.wait_for_function('document.querySelector("video").currentTime>0')
   assert not page.evaluate('document.documentElement.scrollWidth>innerWidth')
   assert page.evaluate('''() => {const hero=document.querySelector('.hero').getBoundingClientRect(), bg=document.querySelector('.v2-frame').getBoundingClientRect(), copy=document.querySelector('.hero-copy').getBoundingClientRect();return bg.left<=hero.left && bg.right>=hero.right && bg.top<=hero.top && bg.bottom>=hero.bottom && copy.top>=hero.top && copy.bottom<hero.bottom}''')
   page.locator('.v2-controls button').click();page.wait_for_timeout(100)
   assert page.locator('video').evaluate('(v)=>v.paused')
   before=page.locator('.v2-frame').evaluate('(e)=>getComputedStyle(e).transform')
   page.evaluate('scrollBy(0,260)');page.wait_for_timeout(100)
   after=page.locator('.v2-frame').evaluate('(e)=>getComputedStyle(e).transform');assert before!=after
   page.evaluate('scrollTo(0,0)');page.wait_for_timeout(100);assert page.locator('video').evaluate('(v)=>v.paused')
   page.locator('.v2-controls button').click();page.wait_for_timeout(150)
   # Position heading inside the trigger band; sample a real intermediate transform.
   page.locator('#services-title').evaluate('(e)=>scrollTo(0,scrollY+e.getBoundingClientRect().top-innerHeight*.62)')
   page.wait_for_timeout(200)
   values=page.locator('.v2-line-inner').evaluate_all('(es)=>es.map(e=>getComputedStyle(e).transform)')
   assert any(v not in ('none','matrix(1, 0, 0, 1, 0, 0)') for v in values), (width,values)
   page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(300)
   page.locator('.service-image').first.evaluate('(e)=>scrollTo(0,scrollY+e.getBoundingClientRect().top-innerHeight*.65)')
   page.wait_for_timeout(200)
   clip=page.locator('.service-image').first.evaluate('(e)=>getComputedStyle(e).clipPath')
   assert clip!='none', (width,clip)
   assert page.locator('video').evaluate('(v)=>v.paused')
   page.wait_for_timeout(1800)
   assert page.locator('.service-image').first.evaluate('(e)=>getComputedStyle(e).clipPath')=='none'
   if width<980:
    page.locator('.menu-button').click();assert page.locator('.menu-button').get_attribute('aria-expanded')=='true'
   assert not errors, errors
   page.close();print('Motion and video passed:',width)
  page=b.new_page(reduced_motion='reduce');page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(500)
  assert page.locator('video').get_attribute('src') is None
  assert page.evaluate('document.getAnimations().length')==0
  page.close()
  page=b.new_page(java_script_enabled=False);page.goto('http://127.0.0.1:8824/v2/');assert page.locator('.v2-frame img').is_visible();assert page.locator('#services-title').is_visible();page.close()
  page=b.new_page();page.route('**/hero.mp4',lambda route:route.abort());page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(500);assert page.locator('.v2-frame img').is_visible();assert not page.locator('.v2-controls button').is_visible();page.close()
  page=b.new_page();page.goto('http://127.0.0.1:8824/');
  for name in ('original','v1','v2'):
   page.locator(f'[data-version={name}]').click();page.wait_for_timeout(300);assert f'/{name}/' in page.locator('iframe').get_attribute('src') or page.locator('iframe').get_attribute('src')==name+'/'
  page.close();b.close()
 print('Snapshots, reduced motion, no JS, video failure and comparison passed')
if __name__=='__main__':check()
