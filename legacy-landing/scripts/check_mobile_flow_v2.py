"""Touch navigation, CTA and form on narrow/rotated mobile viewports."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(320,568),(375,667),(390,844),(768,1024),(844,390)]:
  page=b.new_page(viewport={'width':w,'height':h},is_mobile=True,has_touch=True,device_scale_factor=2);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(400)
  menu=page.locator('.menu-button');menu.tap();assert menu.get_attribute('aria-expanded')=='true';assert not page.locator('.floating-cta').is_visible()
  assert page.locator('.primary-navigation').evaluate('(e)=>e.getBoundingClientRect().bottom<=innerHeight+1')
  page.locator('.primary-navigation > a').last.scroll_into_view_if_needed()
  page.locator('.primary-navigation a[href$="#services"]').tap();page.wait_for_timeout(400);assert menu.get_attribute('aria-expanded')=='false'
  page.locator('#services').evaluate('(e)=>scrollTo({top:scrollY+e.getBoundingClientRect().top,behavior:"instant"})');page.wait_for_timeout(150)
  cta=page.locator('.floating-cta')
  for i in range(12):
   if cta.is_visible():break
   page.evaluate('scrollBy({top:100,behavior:"instant"})');page.wait_for_timeout(80)
  assert cta.is_visible(),(w,h)
  page.wait_for_timeout(850)
  box=cta.bounding_box();assert box['height']>=44 and box['x']>=0 and box['x']+box['width']<=w and box['y']+box['height']<=h
  assert cta.evaluate('(e)=>e.scrollWidth<=e.clientWidth')
  cta.tap();page.wait_for_timeout(150);assert not cta.is_visible();assert page.evaluate('document.activeElement.id')=='lead-title'
  page.locator('label:has(input[value="scan"])').tap();page.locator('.lead-next').first.tap()
  page.locator('#lead-message').fill('Тестовый черновик мобильной формы')
  assert not cta.is_visible()
  page.set_viewport_size({'width':h,'height':w});page.wait_for_timeout(150)
  assert page.locator('#lead-message').input_value()=='Тестовый черновик мобильной формы'
  assert not cta.is_visible()
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),(w,h)
  assert not errors,errors
  print(w,h,'touch/menu/CTA/form/rotation PASS',flush=True);page.close()
 b.close()
