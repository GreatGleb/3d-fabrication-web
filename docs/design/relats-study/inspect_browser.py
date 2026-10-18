"""Reproduce the local Relats study without contacting the original website."""
import importlib.util
import json
import threading
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright

OUT = Path(__file__).resolve().parent
ROOT = OUT.parents[2]
spec = importlib.util.spec_from_file_location('relats_server', ROOT / 'legacy-landing/references/relats/serve.py')
archive = importlib.util.module_from_spec(spec)
spec.loader.exec_module(archive)
server = archive.Server(('127.0.0.1', 0), archive.Handler)
server.site = dict(archive.SITES[0], port=server.server_port)
server.fetch = False
threading.Thread(target=server.serve_forever, daemon=True).start()
BASE = f'http://127.0.0.1:{server.server_port}'
(OUT / 'screenshots').mkdir(exist_ok=True)

SNAPSHOT = '''() => ({
 scroll:scrollY, width:innerWidth, height:innerHeight,
 documentHeight:document.documentElement.scrollHeight,
 overflow:document.documentElement.scrollWidth > innerWidth,
 desktop:app.appStore.isDesktop,
 canvas:document.querySelectorAll('canvas').length,
 triggers:app.appCore.scrollTrigger.getAll().length,
 videos:[...document.querySelectorAll('video')].map(v=>({
 section:v.closest('section')?.className, src:v.currentSrc.replace(location.origin,''),
 time:v.currentTime,duration:v.duration,paused:v.paused,readyState:v.readyState,
 rate:v.playbackRate,auto:v.autoplay,loop:v.loop,muted:v.muted,
 rect:{top:v.getBoundingClientRect().top,height:v.getBoundingClientRect().height}
 })),
 sticky:[...document.querySelectorAll('.sticky')].map(e=>({
 section:e.closest('section')?.className, position:getComputedStyle(e).position,
 top:e.getBoundingClientRect().top, opacity:getComputedStyle(e).opacity
 }))
})'''
report = {'date':'2026-09-29','source':'local archive; external requests blocked','scenarios':{}}
try:
 with sync_playwright() as p:
  browser = p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
  for name,width,height,mobile,reduced in [('desktop',1440,900,False,False),('mobile',390,844,True,False),('reduced-motion',1440,900,False,True)]:
   context = browser.new_context(viewport={'width':width,'height':height},is_mobile=mobile,has_touch=mobile,reduced_motion='reduce' if reduced else 'no-preference')
   errors=[];bad=[];blocked=[]
   def route(req):
    if urlsplit(req.request.url).hostname in ('127.0.0.1','localhost') or req.request.url.startswith(('data:','blob:')):req.continue_()
    else:blocked.append(req.request.url);req.abort()
   context.route('**/*',route)
   page=context.new_page()
   page.on('pageerror',lambda e:errors.append(str(e)))
   page.on('response',lambda r:bad.append({'url':r.url.replace(BASE,''),'status':r.status}) if r.status>=400 else None)
   page.goto(BASE,wait_until='load')
   page.wait_for_function('window.app && app.appStore.currentSection && app.appCore.scrollTrigger.getAll().length>10')
   page.wait_for_timeout(6500)
   data={'initial':page.evaluate(SNAPSHOT),'errors':errors,'badResponses':bad,'externalBlocked':blocked}
   data['components']=page.evaluate('''() => app.appStore.currentSection.components.map(c=>({type:c.type,instance:!!c.instance,methods:c.instance?Object.getOwnPropertyNames(Object.getPrototypeOf(c.instance)):[]}))''')
   data['sections']=page.evaluate('''() => [...document.querySelectorAll('section,footer')].map(e=>({class:e.className,component:e.dataset.component,top:e.getBoundingClientRect().top+scrollY,height:e.getBoundingClientRect().height,text:e.innerText.replace(/\\s+/g,' ').slice(0,350)}))''')
   data['triggerMap']=page.evaluate('''() => app.appCore.scrollTrigger.getAll().map(t=>({trigger:t.trigger?.className,start:t.start,end:t.end,scrub:t.vars.scrub,pin:!!t.vars.pin,once:!!t.vars.once,section:t.trigger?.closest('section')?.className,hasAnimation:!!t.animation}))''')
   page.screenshot(path=str(OUT/'screenshots'/f'{name}-hero.png'))
   data['samples']=[]
   sections=data['sections'] if not reduced else data['sections'][:3]
   for idx,s in enumerate(sections):
    # Sample the middle of each section's sticky scroll interval, then later in long scenes.
    for fraction in ([.25,.75] if s['height']>height*2 else [.3]):
     target=max(0,s['top']+max(0,s['height']-height)*fraction)
     page.evaluate('(y)=>app.appCore.superScroller.scrollTo(y,{immediate:true,force:true})',target)
     page.wait_for_timeout(700)
     # Loading can reset currentTime after a jump; a small real continuation updates scrub.
     page.evaluate('(y)=>app.appCore.superScroller.scrollTo(y+2,{immediate:true,force:true})',target)
     page.wait_for_timeout(350)
     snap=page.evaluate(SNAPSHOT);snap.update({'section':s['class'],'fraction':fraction})
     data['samples'].append(snap)
     if fraction==.25 or s['height']<=height*2:
      path=f'{name}-{idx:02}-{s.get("component") or "section"}.png'
      page.screenshot(path=str(OUT/'screenshots'/path));snap['screenshot']=path
   data['final']=page.evaluate(SNAPSHOT)
   data['resources']=page.evaluate('''() => performance.getEntriesByType('resource').map(r=>({url:r.name.replace(location.origin,''),type:r.initiatorType,bytes:r.encodedBodySize,duration:r.duration}))''')
   data['interactives']=page.locator('.trigger,.closer,.--open-popup,.navigation .title,.slider .arrow').evaluate_all("es=>es.map(e=>({tag:e.tagName,class:e.className,text:e.innerText.slice(0,80),role:e.getAttribute('role'),tabindex:e.getAttribute('tabindex'),aria:e.getAttribute('aria-label')}))")
   report['scenarios'][name]=data
   (OUT/'browser-report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
   print(name, 'height',data['initial']['documentHeight'],'triggers',data['final']['triggers'],'errors',len(errors),'failed responses',len(bad),flush=True)
   context.close()
  browser.close()
finally:
 server.shutdown();server.server_close()
