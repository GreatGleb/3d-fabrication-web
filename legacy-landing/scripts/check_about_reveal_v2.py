"""Verify image reveal precedes text and reverses without changing fallback content."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(1440,900),(1366,768)]:
  page=b.new_page(viewport={'width':w,'height':h});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('http://127.0.0.1:8824/v2/#about');page.wait_for_timeout(500)
  assert page.locator('.about-reveal').count()==1
  states=[]
  for q in [0,.4,.7,1,.4,0]:
   page.locator('#about').evaluate('''(e,p)=>scrollTo({top:scrollY+e.getBoundingClientRect().top-document.querySelector('.site-header').offsetHeight-24+innerHeight*1.5*p,behavior:'instant'})''',q)
   page.wait_for_timeout(100)
   states.append(page.locator('.about-photo').evaluate('(e)=>getComputedStyle(e).clipPath'))
   opacity=float(page.locator('.about-copy').evaluate('(e)=>getComputedStyle(e).opacity'))
   if q<=.7:assert opacity==0
   if q==1:
    assert opacity==1
    box=page.locator('.about-copy').bounding_box();assert box['y']>=78 and box['y']+box['height']<=h
    page.screenshot(path=f'/tmp/about-open-{w}.png')
  assert states[0]==states[-1] and states[1]==states[-2] and states[0]!=states[1]!=states[2]
  page.emulate_media(reduced_motion='reduce');page.wait_for_timeout(150)
  assert page.locator('.about-reveal').count()==0
  assert page.locator('.about-copy').evaluate('(e)=>getComputedStyle(e).opacity')=='1'
  page.emulate_media(reduced_motion='no-preference');page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(150)
  assert page.locator('.about-reveal').count()==1
  page.set_viewport_size({'width':w,'height':h});page.wait_for_timeout(150);assert page.locator('.about-reveal').count()==1
  assert not errors,errors
  print(w,'PASS',flush=True);page.close()
 page=b.new_page(java_script_enabled=False);page.goto('http://127.0.0.1:8824/v2/#about');assert page.locator('.about-reveal').count()==0;assert page.locator('.about-copy').evaluate('(e)=>getComputedStyle(e).opacity')=='1';b.close()
 print('No JS PASS')
