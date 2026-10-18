"""Check contextual label, keyboard activation, hiding and user input preservation."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(390,844),(768,900),(1440,900)]:
  page=b.new_page(viewport={'width':w,'height':h});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(250);cta=page.locator('.floating-cta');assert not cta.is_visible()
  def seek(id):
   page.locator('#'+id).evaluate('(e)=>scrollTo({top:scrollY+e.getBoundingClientRect().top,behavior:"instant"})');page.wait_for_timeout(200)
   if id in ('services','projects'):
    for offset in (120,160,220,280):
     if cta.is_visible():break
     page.evaluate('(y)=>scrollBy({top:y,behavior:"instant"})',offset);page.wait_for_timeout(100)
  seek('services');assert cta.is_visible();assert cta.inner_text()=='Обсудить услугу'
  cta.focus();seek('projects');assert cta.inner_text()=='Обсудить услугу'
  cta.evaluate('(e)=>e.blur()');page.wait_for_timeout(200);assert cta.inner_text()=='Обсудить похожую задачу'
  assert cta.is_visible();page.wait_for_timeout(800);page.screenshot(path=f'/tmp/floating-{w}.png')
  seek('services');assert cta.inner_text()=='Обсудить услугу'
  if w<980:
   page.locator('.menu-button').click();page.wait_for_timeout(100);assert not cta.is_visible();page.keyboard.press('Escape');seek('services');assert cta.is_visible()
  # No clearing of data and no automatic service selection when jumping to the form.
  page.locator('#lead-message').evaluate('(e)=>e.value="Сохранённый черновик"')
  cta.focus();page.keyboard.press('Enter');page.wait_for_timeout(200)
  assert not cta.is_visible();assert page.evaluate('document.activeElement.id')=='lead-title'
  assert page.locator('#lead-message').input_value()=='Сохранённый черновик'
  assert page.locator('input[name=service]:checked').count()==0
  seek('about');assert cta.is_visible()
  page.emulate_media(reduced_motion='reduce');seek('services');assert cta.is_visible();assert cta.evaluate('(e)=>getComputedStyle(e).transitionDuration')=='0s'
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth');assert not errors,errors
  print(w,'PASS',flush=True);page.close()
 page=b.new_page(java_script_enabled=False);page.goto('http://127.0.0.1:8824/v2/');assert page.locator('.floating-cta').count()==0;b.close();print('No JS PASS')
