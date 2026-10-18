"""Both About reveal and incoming Information must animate on small screens."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(390,844),(390,700),(320,568),(768,1024),(768,700),(844,390)]:
  page=b.new_page(viewport={'width':w,'height':h});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('http://127.0.0.1:8824/v2/#about');page.wait_for_timeout(250)
  assert page.locator('.about-reveal.section-overlap-out').count()==1
  states=[]
  for q in [0,.4,1,.4]:
   page.locator('#about').evaluate('''(e,q)=>scrollTo({top:scrollY+e.getBoundingClientRect().top-document.querySelector('.site-header').offsetHeight-24+innerHeight*q,behavior:'instant'})''',q);page.wait_for_timeout(100)
   states.append(page.locator('.about-photo').evaluate('(e)=>getComputedStyle(e).clipPath'))
   if q==1:
    assert page.locator('.about-copy').evaluate('(e)=>getComputedStyle(e).opacity')=='1'
    assert page.locator('.about-copy').bounding_box()['y']>=page.locator('.site-header').bounding_box()['height']
    page.screenshot(path=f'/tmp/about-mobile-{w}-{h}.png')
  assert states[0]!=states[1]!=states[2] and states[1]==states[3]
  # Before the cover enters, the end of even long copy must have scrolled into view.
  page.locator('#information').evaluate('(e)=>scrollTo({top:scrollY+e.getBoundingClientRect().top-innerHeight,behavior:"instant"})');page.wait_for_timeout(100)
  assert page.locator('.about-copy').evaluate('(e)=>e.getBoundingClientRect().bottom<=innerHeight-20')
  values=[]
  for q in [0,.5,1,.5]:
   page.locator('#information').evaluate('''(e,q)=>{const t=document.querySelector('.site-header').offsetHeight+24;scrollTo({top:scrollY+e.getBoundingClientRect().top-(innerHeight-q*(innerHeight-t)),behavior:'instant'})}''',q);page.wait_for_timeout(100)
   values.append(float(page.locator('.about-grid').evaluate('(e)=>getComputedStyle(e).opacity')))
  assert abs(values[0]-1)<.01 and abs(values[1]-.5)<.01 and values[2]<.01 and values[1]==values[3],values
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
  page.emulate_media(reduced_motion='reduce');page.wait_for_timeout(100)
  assert page.locator('.about-reveal,.section-overlap-out').count()==0
  assert page.locator('.about-copy').evaluate('(e)=>getComputedStyle(e).opacity')=='1'
  assert not errors,errors
  print(w,h,'PASS',flush=True);page.close()
 b.close()
