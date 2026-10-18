"""The CTA stays visible across cards/sections and does not restart on resize."""
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
 for w,h in [(320,568),(390,844),(768,900),(1440,900)]:
  page=b.new_page(viewport={'width':w,'height':h});page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(250)
  result=page.evaluate('''async()=>{
   const c=document.querySelector('.floating-cta'),s=document.querySelector('#services'),f=document.querySelector('#contact-form');
   const start=scrollY+s.getBoundingClientRect().top,end=scrollY+f.getBoundingClientRect().top-innerHeight;
   const failures=[],labels=[];const frame=()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));
   for(const direction of [1,-1])for(let y=direction===1?start:end;direction===1?y<=end:y>=start;y+=direction*95){
    scrollTo({top:y,behavior:'instant'});await frame();
    if(c.hidden)failures.push(y);if(labels.at(-1)!==c.textContent)labels.push(c.textContent);
   }
   return {failures,labels};
  }''')
  assert not result['failures'],(w,result)
  assert result['labels']==['Обсудить услугу','Обсудить похожую задачу','Обсудить услугу'],result
  page.wait_for_timeout(500);page.set_viewport_size({'width':w,'height':h-30});page.wait_for_timeout(100)
  assert page.locator('.floating-cta-label').evaluate('(e)=>e.getAnimations().filter(a=>a.playState==="running").length')==0
  page.locator('.floating-cta').click();page.wait_for_timeout(100);assert not page.locator('.floating-cta').is_visible()
  print(w,'continuous forward/back + resize PASS',flush=True);page.close()
 b.close()
