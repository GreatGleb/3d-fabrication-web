"""Check opacity does not fall from visible to hidden at reveal start/end."""
from playwright.sync_api import sync_playwright

def check():
 with sync_playwright() as p:
  b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
  for width in (390,768,1440):
   page=b.new_page(viewport={'width':width,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
   page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(200)
   card=page.locator('.project-card').first
   card.evaluate('(e)=>scrollTo({top:scrollY+e.getBoundingClientRect().top-innerHeight*.9,behavior:"instant"})');page.wait_for_timeout(100)
   assert card.evaluate('(e)=>getComputedStyle(e).opacity')=='0'
   # Sample every browser frame, including the delay and cleanup after finish.
   values=card.evaluate('''async e=>{const samples=[];scrollBy({top:innerHeight*.2,behavior:'instant'});const start=performance.now();await new Promise(resolve=>{function sample(now){samples.push(Number(getComputedStyle(e).opacity));if(now-start<2400)requestAnimationFrame(sample);else resolve()}requestAnimationFrame(sample)});return samples}''')
   assert values[0]<.1 and values[-1]==1,values
   assert all(y>=x-.0001 for x,y in zip(values,values[1:])),values
   assert not card.evaluate('(e)=>e.classList.contains("reveal-pending")')
   page.evaluate('scrollTo({top:0,behavior:"instant"})');page.wait_for_timeout(100)
   card.evaluate('(e)=>e.scrollIntoView({behavior:"instant"})');page.wait_for_timeout(100)
   assert card.evaluate('(e)=>getComputedStyle(e).opacity')=='1'
   # Unseen elements must become accessible immediately on keyboard focus.
   about=page.locator('.info-grid article').first
   page.emulate_media(reduced_motion='reduce');page.wait_for_function('document.querySelectorAll(".reveal-pending").length===0');page.evaluate('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))');assert about.evaluate('(e)=>getComputedStyle(e).opacity')=='1',about.evaluate('(e)=>({opacity:getComputedStyle(e).opacity,cls:e.className,animations:e.getAnimations().map(a=>({state:a.playState,time:a.currentTime}))})')
   assert not errors,errors
   page.close();print('No start/end/re-entry flicker:',width)
  page=b.new_page(java_script_enabled=False);page.goto('http://127.0.0.1:8824/v2/');assert page.locator('.project-card').first.evaluate('(e)=>getComputedStyle(e).opacity')=='1';page.close()
  b.close()
if __name__=='__main__':check()
