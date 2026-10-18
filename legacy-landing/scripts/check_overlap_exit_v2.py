"""The outgoing dark scene must never paint above the following lead form."""
from io import BytesIO
from PIL import Image
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(390,844),(768,900),(1440,900)]:
  page=b.new_page(viewport={'width':w,'height':h});page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(200)
  for fraction in (.25,.5,.75):
   page.locator('#information').evaluate('(e,f)=>scrollTo({top:scrollY+e.getBoundingClientRect().bottom-innerHeight*f,behavior:"instant"})',fraction);page.wait_for_timeout(100)
   y=page.locator('#contact-form').bounding_box()['y']+12
   assert page.evaluate('(y)=>!!document.elementFromPoint(5,y)?.closest("#contact-form")',y)
   shot=Image.open(BytesIO(page.screenshot())).convert('RGB');assert min(shot.getpixel((5,round(y))))>180,(w,h,y)
  print(w,'exit seam PASS',flush=True);page.close()
 b.close()
