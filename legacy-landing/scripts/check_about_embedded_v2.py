from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(1366,700),(1024,768),(1024,650),(1440,900)]:
  page=b.new_page(viewport={'width':w,'height':h});page.goto('http://127.0.0.1:8824/');f=page.frame_locator('#preview');f.locator('#about').wait_for();page.wait_for_timeout(200)
  page.locator('#jump').select_option('about');page.wait_for_timeout(100)
  assert f.locator('.about-reveal').count()==1,(w,h)
  before=f.locator('.about-photo').evaluate('(e)=>getComputedStyle(e).clipPath')
  f.locator('#about').evaluate('''e=>scrollTo({top:scrollY+e.getBoundingClientRect().top-document.querySelector('.site-header').offsetHeight-24+innerHeight*1.5,behavior:'instant'})''');page.wait_for_timeout(150)
  after=f.locator('.about-photo').evaluate('(e)=>getComputedStyle(e).clipPath')
  assert before!=after
  assert f.locator('.about-copy').evaluate('(e)=>getComputedStyle(e).opacity')=='1'
  assert f.locator('.about-copy').evaluate('(e)=>{let r=e.getBoundingClientRect();return r.top>=document.querySelector(".site-header").offsetHeight&&r.bottom<=innerHeight}')
  page.screenshot(path=f'/tmp/about-embedded-{w}-{h}.png')
  print(w,h,'PASS',flush=True);page.close()
 b.close()
