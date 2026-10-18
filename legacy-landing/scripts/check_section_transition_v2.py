"""Check section overlap, reverse scroll, viewport changes and readable fallbacks."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(1440,900),(768,900),(390,844),(390,700)]:
  page=b.new_page(viewport={'width':w,'height':h}); errors=[]
  page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(500)
  active=page.locator('#about').evaluate('(e)=>e.classList.contains("section-overlap-out")')
  if active:
   vals=[]
   for q in [0,.5,1,.5,0]:
    page.evaluate('''q=>{let e=document.querySelector('#information'),t=document.querySelector('.site-header').offsetHeight+24;scrollTo({top:scrollY+e.getBoundingClientRect().top-(innerHeight-q*(innerHeight-t)),behavior:'instant'})}''',q)
    page.wait_for_timeout(2200 if q==0 else 100)
    vals.append(float(page.locator('.about-grid').evaluate('(e)=>getComputedStyle(e).opacity')))
    if q==.5:
     page.screenshot(path=f'/tmp/overlap-{w}.png')
     assert page.locator('.about-grid').bounding_box()['y']>=90
     assert page.locator('#information .section-heading').evaluate('(e)=>getComputedStyle(e).opacity')=='1'
   assert abs(vals[0]-1)<.01 and abs(vals[1]-.5)<.01 and vals[2]<.01 and vals[1]==vals[3],vals
  else:
   assert page.locator('#information').bounding_box()['y']>=page.locator('#about').bounding_box()['y']+page.locator('#about').bounding_box()['height']-1
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
  page.emulate_media(reduced_motion='reduce');page.wait_for_timeout(100)
  assert page.locator('.section-overlap-out').count()==0
  assert page.locator('.about-grid').evaluate('(e)=>getComputedStyle(e).opacity')=='1'
  page.emulate_media(reduced_motion='no-preference');page.set_viewport_size({'width':390,'height':700});page.wait_for_timeout(150)
  assert page.locator(".section-overlap-out").count()==1
  page.set_viewport_size({'width':1440,'height':900});page.wait_for_timeout(100)
  assert page.locator('.section-overlap-out').count()==1
  assert not errors,errors
  print(w,h,'overlap' if active else 'readable fallback','PASS')
  page.close()
 page=b.new_page(java_script_enabled=False);page.goto('http://127.0.0.1:8824/v2/#about')
 assert page.locator('.section-overlap-out').count()==0
 assert page.locator('.about-grid').evaluate('(e)=>getComputedStyle(e).opacity')=='1'
 print('No JS PASS');b.close()
