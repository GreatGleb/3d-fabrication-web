"""Check reversible masks, sticky geometry and readable fallback of V2 gear case."""
from pathlib import Path
from playwright.sync_api import sync_playwright
OUT=Path(__file__).resolve().parents[2]/'docs/design/animation-v2'
def seek(page,p):
 page.locator('.gear-case').evaluate('''(e,p)=>{const panel=e.querySelector('.gear-panel'),h=document.querySelector('.site-header').offsetHeight;scrollTo({top:scrollY+e.getBoundingClientRect().top-h-12+Number(e.dataset.lead)+Number(e.dataset.run)*p,behavior:"instant"})}''',p)
 page.wait_for_timeout(80)
def check():
 with sync_playwright() as p:
  b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
  for width,height in ((390,844),(768,900),(1440,900)):
   page=b.new_page(viewport={'width':width,'height':height});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
   page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(300)
   assert 'is-pinned' in page.locator('.gear-case').get_attribute('class')
   values=[]
   for value in (.05,.25,.52,.75,.94,.25):
    seek(page,value)
    assert abs(float(page.locator('.gear-case').get_attribute('data-progress'))-value)<.005
    assert page.locator('.gear-panel').bounding_box()['y']>=page.locator('.site-header').bounding_box()['height']
    assert page.locator('.gear-panel').bounding_box()['y']+page.locator('.gear-panel').bounding_box()['height']<=height
    values.append(page.locator('.gear-cad').evaluate('(e)=>getComputedStyle(e).clipPath'))
    if value==.52:
     assert float(page.locator('.gear-pointer').evaluate('(e)=>getComputedStyle(e).opacity'))==1
     page.screenshot(path=str(OUT/f'gear-cad-{width}.png'))
    if value==.94:
     assert page.locator('.gear-result').evaluate('(e)=>getComputedStyle(e).clipPath')=='inset(0px 0px 0px 0%)'
   assert values[1]==values[-1] and values[0]!=values[1]!=values[2]
   for i in (2,0,1):
    page.locator(f'[data-gear-go="{i}"]').focus();page.keyboard.press('Enter');page.wait_for_timeout(100)
    assert page.locator(f'[data-gear-go="{i}"]').get_attribute('aria-current')=='step'
   page.locator('.gear-actions a[href="#projects"]').click();page.wait_for_timeout(800)
   assert page.locator('#projects').bounding_box()['y']<height
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   assert not errors,errors
   page.close();print('Gear sequence passed:',width)
  for options in ({'reduced_motion':'reduce'},{'java_script_enabled':False}):
   page=b.new_page(**options);page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(300)
   assert 'is-pinned' not in page.locator('.gear-case').get_attribute('class')
   for li in page.locator('.gear-steps li').all():assert li.is_visible() and li.locator('img').is_visible()
   page.close()
  for width,height in ((1366,768),(390,844),(390,700),(844,530)):
   page=b.new_page(viewport={'width':width,'height':height});page.goto('http://127.0.0.1:8824/');page.wait_for_timeout(500);frame=page.frames[1]
   root=frame.locator('.gear-case')
   root.evaluate('(e)=>e.scrollIntoView({behavior:"instant"})');page.wait_for_timeout(100)
   assert 'is-pinned' in root.get_attribute('class')
   if 'is-pinned' in root.get_attribute('class'):
    # Start at sticky arrival, then use actual wheel events through the iframe.
    root.evaluate('(e)=>scrollTo({top:scrollY+e.getBoundingClientRect().top-document.querySelector(".site-header").offsetHeight-12,behavior:"instant"})')
    page.wait_for_timeout(80)
    bounds=frame.locator('.gear-panel').bounding_box();iframe=page.locator('iframe').bounding_box()
    assert bounds['y']+bounds['height']<=iframe['y']+iframe['height']+1,(width,bounds,iframe)
    page.mouse.move(width/2,height/2)
    page.mouse.wheel(0,100);page.wait_for_timeout(200)
    assert float(root.get_attribute('data-progress'))==0
    values=[]
    for _ in range(30):
     page.mouse.wheel(0,80);page.wait_for_timeout(70);values.append(float(root.get_attribute('data-progress')))
    assert all(0<=b-a<.06 for a,b in zip(values,values[1:])),values
    assert values[-1]>.8,values
    stopped=frame.locator('.gear-result').evaluate('(e)=>getComputedStyle(e).clipPath')
    page.wait_for_timeout(1000)
    assert frame.locator('.gear-result').evaluate('(e)=>getComputedStyle(e).clipPath')==stopped
    page.mouse.wheel(0,-700);page.wait_for_timeout(200)
    assert float(root.get_attribute('data-progress'))<values[-1]-.15
   page.close();print('Embedded viewport and real wheel passed:',width,height)
  b.close()
 print('Low-height, landscape, reduced-motion and no-JS fallbacks passed')
if __name__=='__main__':check()
