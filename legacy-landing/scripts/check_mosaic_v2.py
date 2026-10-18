"""Check reversible mosaic, video lifecycle and accessible static fallbacks."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(1440,900),(768,900),(390,844)]:
  page=b.new_page(viewport={'width':w,'height':h});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('http://127.0.0.1:8824/v2/#possibilities');page.wait_for_timeout(800)
  vals=[]
  for q in [0,.5,1,.5]:
   page.locator('.mosaic').evaluate('''(e,q)=>{let top=document.querySelector('.site-header').offsetHeight;scrollTo({top:scrollY+e.getBoundingClientRect().top-top+(e.offsetHeight-(innerHeight-top))*q,behavior:'instant'})}''',q);page.wait_for_timeout(150)
   vals.append(page.locator('.mosaic-grid').evaluate('(e)=>getComputedStyle(e).transform'))
   if q==1:page.screenshot(path=f'/tmp/mosaic-{w}.png')
  assert vals[0]!=vals[1]!=vals[2] and vals[1]==vals[3],vals
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
  page.wait_for_function("[...document.querySelectorAll('.mosaic video')].every(v=>!v.paused&&v.currentTime>0)")
  page.locator('.mosaic button').click();page.wait_for_timeout(100)
  assert page.locator('.mosaic video').evaluate_all('(vs)=>vs.every(v=>v.paused)')
  page.locator('.mosaic button').click();page.wait_for_timeout(100)
  page.evaluate('scrollTo({top:0,behavior:"instant"})');page.wait_for_timeout(200)
  assert page.locator('.mosaic video').evaluate_all('(vs)=>vs.every(v=>v.paused)')
  page.emulate_media(reduced_motion='reduce');page.wait_for_timeout(100)
  assert page.locator('.mosaic.is-scroll').count()==0
  assert page.locator('.mosaic-grid').evaluate('(e)=>getComputedStyle(e).transform')=='none'
  page.emulate_media(reduced_motion='no-preference');page.set_viewport_size({'width':844,'height':420});page.wait_for_timeout(150)
  assert page.locator('.mosaic.is-scroll').count()==0
  page.set_viewport_size({'width':w,'height':h});page.wait_for_timeout(150)
  assert page.locator('.mosaic.is-scroll').count()==1
  assert not errors,errors
  print(w,'PASS',flush=True);page.close()
 page=b.new_page(java_script_enabled=False);page.goto('http://127.0.0.1:8824/v2/');assert page.locator('.mosaic.is-scroll').count()==0;assert page.locator('.mosaic img').count()==5;print('No JS PASS');b.close()
