"""Check isolated variants, semantic scroll states and untouched baseline."""
import hashlib,json
from pathlib import Path
from playwright.sync_api import sync_playwright,expect

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT.parent/'docs/design/animation-v1'
BASE='http://127.0.0.1:8822'

def check():
 OUT.mkdir(parents=True,exist_ok=True)
 manifest=json.loads((ROOT/'design-previews/animation-v1/baseline/manifest.json').read_text())
 for name,digest in manifest.items():assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest,name
 report={'baselineFilesUnchanged':len(manifest),'views':[]}
 with sync_playwright() as p:
  b=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
  for width,height,reduced,js in [(1440,900,False,True),(390,844,False,True),(768,900,False,True),(1440,600,False,True),(1440,900,True,True),(1440,900,False,False)]:
   c=b.new_context(viewport={'width':width,'height':height},reduced_motion='reduce' if reduced else 'no-preference',java_script_enabled=js)
   page=c.new_page();errors=[];bad=[]
   page.on('pageerror',lambda e:errors.append(str(e)));page.on('response',lambda r:bad.append(r.url) if r.status>=400 else None)
   page.goto(BASE+'/animated/',wait_until='networkidle')
   page.screenshot(path=str(OUT/f'hero-{width}-{height}-{reduced}-{js}.png'))
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   pinned=page.locator('#motion-case').evaluate("e=>e.classList.contains('case--pinned')")
   assert pinned==(width>1100 and height>=700 and not reduced and js),(width,height,reduced,js,pinned)
   if pinned:
    samples=[]
    for progress,index in [(0,0),(.45,1),(.8,2),(.1,0)]:
     page.evaluate('''p=>{let s=document.querySelector('#motion-case'),panel=s.querySelector('.case-panel');window.scrollTo({top:scrollY+s.getBoundingClientRect().top-document.querySelector('.site-header').offsetHeight-12+(s.clientHeight-panel.offsetHeight-40)*p,behavior:'instant'})}''',progress)
     page.wait_for_timeout(1750)
     expect(page.locator('[data-case-step].is-current')).to_have_attribute('data-case-step',str(index))
     samples.append(page.locator('.stage-label').inner_text())
     if progress in (0,.45,.8):page.screenshot(path=str(OUT/f'scene-{index}.png'))
    report['sceneStates']=samples
    page.emulate_media(reduced_motion='reduce');page.wait_for_timeout(200);assert 'case--pinned' not in page.locator('#motion-case').get_attribute('class')
    page.emulate_media(reduced_motion='no-preference');page.wait_for_timeout(200)
    page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(200);assert 'case--pinned' not in page.locator('#motion-case').get_attribute('class')
    page.set_viewport_size({'width':1440,'height':900});page.wait_for_timeout(200);assert 'case--pinned' in page.locator('#motion-case').get_attribute('class')
   elif 'case--compact' in page.locator('#motion-case').get_attribute('class'):
    page.locator('.case-stage').scroll_into_view_if_needed()
    expect(page.locator('.case-stage')).to_be_visible()
    page.screenshot(path=str(OUT/f'case-{width}-{height}-{reduced}-{js}.png'))
   else:
    page.locator('#motion-case').scroll_into_view_if_needed();page.wait_for_function("[...document.querySelectorAll('.case-steps img')].every(i=>i.complete && i.naturalWidth>0)");page.screenshot(path=str(OUT/f'case-{width}-{height}-{reduced}-{js}.png'))
    for img in page.locator('.case-steps img').all():expect(img).to_be_visible()
   assert not errors,errors
   assert not bad,bad
   report['views'].append({'width':width,'height':height,'reduce':reduced,'javascript':js,'pinned':pinned})
   c.close()
  page=b.new_page(viewport={'width':1440,'height':1000});page.goto(BASE+'/animated/?scene=flow',wait_until='networkidle');assert 'case--pinned' not in page.locator('#motion-case').get_attribute('class')
  page.goto(BASE,wait_until='networkidle');expect(page.locator('iframe')).to_have_attribute('src','animated/')
  page.locator('#jump').select_option('case');page.wait_for_timeout(300)
  page.get_by_role('button',name='Обычная',exact=True).click();page.wait_for_timeout(600)
  expect(page.locator('#scene-label')).to_be_hidden()
  frame=page.frame_locator('#preview');expect(frame.locator('.process-visual-band')).to_be_visible();assert frame.locator('#motion-case').count()==0
  expect(frame.locator('body')).not_to_have_class('motion-page')
  page.get_by_role('button',name='С анимациями',exact=True).click();page.wait_for_timeout(600);expect(frame.locator('#motion-case')).to_be_visible()
  page.screenshot(path=str(OUT/'comparison.png'))
  b.close()
 report['comparison']='passed'
 (OUT/'checks.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
 print(json.dumps(report,ensure_ascii=False,indent=2))
if __name__=='__main__':check()
