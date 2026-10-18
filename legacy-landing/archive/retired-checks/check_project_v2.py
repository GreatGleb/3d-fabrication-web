"""Exercise the project scene through the actual comparison iframe."""
from pathlib import Path
from playwright.sync_api import sync_playwright
OUT=Path(__file__).resolve().parents[3]/'docs/design/animation-v2'
def check():
 with sync_playwright() as p:
  b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
  for width,height in ((1440,900),(1366,768),(768,900),(390,844),(390,700),(844,530)):
   page=b.new_page(viewport={'width':width,'height':height});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
   page.goto('http://127.0.0.1:8824/');page.wait_for_timeout(400);f=page.frames[1];scene=f.locator('.project-feature')
   def seek(value):
    scene.evaluate('''(e,p)=>scrollTo({top:scrollY+e.getBoundingClientRect().top-document.querySelector('.site-header').offsetHeight-12+Number(e.dataset.lead)+Number(e.dataset.run)*p,behavior:'instant'})''',value)
    page.wait_for_timeout(100)
   def state():
    return f.evaluate('''()=>Object.fromEntries(['.project-scene-image','.project-scene-image img','.project-caption'].map(s=>{const e=document.querySelector(s),c=getComputedStyle(e);return [s,[c.clipPath,c.transform]]}))''')
   states=[]
   for value in (.15,.65,.95,.15):
    seek(value);states.append(state());assert abs(float(scene.get_attribute('data-progress'))-value)<.003
    panel=f.locator('.project-panel').bounding_box();frame=page.locator('iframe').bounding_box();header=f.locator('.site-header').bounding_box()
    assert panel['y']>=header['y']+header['height'],(width,panel)
    assert panel['y']+panel['height']<=frame['y']+frame['height']+2,(width,panel,frame)
   assert states[0]==states[3] and states[0]!=states[1]!=states[2]
   seek(.6);s=state();page.wait_for_timeout(650);assert state()==s
   page.mouse.move(width*.5,height*.5);page.mouse.wheel(0,90);page.wait_for_timeout(180);assert float(scene.get_attribute('data-progress'))>.6
   page.mouse.wheel(0,-90);page.wait_for_timeout(180);assert abs(float(scene.get_attribute('data-progress'))-.6)<.004
   seek(.95);assert f.locator('.project-scene-image img').evaluate('(e)=>getComputedStyle(e).objectFit')=='contain'
   page.screenshot(path=str(OUT/f'project-{width}-{height}.png'))
   seek(.1);f.locator('.project-scene-copy a').focus();assert f.locator('.project-caption').evaluate('(e)=>getComputedStyle(e).transform')in ('none','matrix(1, 0, 0, 1, 0, 0)')
   assert not errors,errors
   assert f.evaluate('document.documentElement.scrollWidth<=innerWidth')
   page.close();print('Project scroll / stop / reverse / focus passed:',width,height)
  for options in ({'reduced_motion':'reduce'},{'java_script_enabled':False}):
   page=b.new_page(**options);page.goto('http://127.0.0.1:8824/v2/');page.wait_for_timeout(300)
   assert 'is-scroll-scene' not in page.locator('.project-feature').get_attribute('class')
   assert page.locator('.project-caption').evaluate('(e)=>getComputedStyle(e).transform')=='none'
   assert page.locator('.project-scene-image').evaluate('(e)=>getComputedStyle(e).clipPath')=='none';page.close()
  b.close()
 print('Reduced motion and no-JS passed')
if __name__=='__main__':check()
