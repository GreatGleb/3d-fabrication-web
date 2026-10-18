"""Service selector: scroll reversal, tabs, keyboard and static fallback."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(1440,900),(768,1024),(390,844),(390,700),(844,390)]:
  page=b.new_page(viewport={'width':w,'height':h});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('http://127.0.0.1:8824/v2/#services');page.wait_for_timeout(400)
  root=page.locator('.services-showcase');print(w,h,root.get_attribute('class'),page.locator('.services-stage').bounding_box()['height'],flush=True)
  for i in [1,4,0,3]:
   page.locator('.services-list button').nth(i).evaluate('(e)=>e.click()');page.wait_for_timeout(100)
   assert root.get_attribute('data-index')==str(i)
   assert page.locator('.services-interactive .service-card:visible').count()==1
   assert page.locator('.service-card:visible').get_attribute('id')==f'service-panel-{i}'
  page.wait_for_timeout(850);page.screenshot(path=f'/tmp/services-{w}-{h}.png')
  page.locator('.services-list button').nth(3).focus();page.keyboard.press('ArrowUp');page.wait_for_timeout(100)
  assert root.get_attribute('data-index')=='2'
  if 'is-pinned' in root.get_attribute('class'):
   for q,i in [(.05,0),(.55,2),(.95,4),(.05,0)]:
    root.evaluate('''(e,p)=>{const top=document.querySelector('.site-header').offsetHeight+12;scrollTo({top:scrollY+e.getBoundingClientRect().top-top+Math.max(1600,innerHeight*3)*p,behavior:'instant'})}''',q);page.wait_for_timeout(100)
    assert root.get_attribute('data-index')==str(i)
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
  page.emulate_media(reduced_motion='reduce');page.wait_for_timeout(100);assert 'is-pinned' not in root.get_attribute('class')
  page.locator('.services-list button').nth(4).evaluate('(e)=>e.click()');assert root.get_attribute('data-index')=='4'
  assert not errors,errors
  print(w,'PASS',flush=True);page.close()
 page=b.new_page(java_script_enabled=False);page.goto('http://127.0.0.1:8824/v2/');assert page.locator('.service-card:visible').count()==5;print('No JS PASS');b.close()
