"""Local interaction evidence: scroll reversal, navigation, popup, hover, responsive branch."""
import importlib.util,json,threading
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
OUT=Path(__file__).resolve().parent;ROOT=OUT.parents[2]
spec=importlib.util.spec_from_file_location('archive',ROOT/'legacy-landing/references/relats/serve.py')
archive=importlib.util.module_from_spec(spec);spec.loader.exec_module(archive)
server=archive.Server(('127.0.0.1',0),archive.Handler);server.site=dict(archive.SITES[0],port=server.server_port);server.fetch=False
threading.Thread(target=server.serve_forever,daemon=True).start();base=f'http://127.0.0.1:{server.server_port}'
report={'date':'2026-09-29','externalBlocked':[],'errors':[]}
try:
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path='/usr/bin/google-chrome',args=['--no-sandbox'])
  context=browser.new_context(viewport={'width':1440,'height':900})
  def route(r):
   if urlsplit(r.request.url).hostname=='127.0.0.1':r.continue_()
   else:report['externalBlocked'].append(r.request.url);r.abort()
  context.route('**/*',route);page=context.new_page();page.on('pageerror',lambda e:report['errors'].append(str(e)))
  page.goto(base,wait_until='load');page.wait_for_timeout(6500)
  report['libraries']=page.evaluate('''() => ({scrollTrigger:app.appCore.scrollTrigger.version,lenis:window.lenisVersion,options:Object.fromEntries(Object.entries(app.appCore.superScroller.options).filter(([k,v])=>['string','number','boolean'].includes(typeof v)))})''')
  report['scrub']={}
  for name in ['revitex','industries','periflex','truck','animation']:
   page.evaluate('''name=>{window.studyComponent=app.appStore.currentSection.components.find(c=>c.type===name).instance;window.studyVideo=studyComponent.$videoEl||studyComponent.$sectionsBaseVideo;studyVideo.querySelectorAll('source').forEach(s=>{if(!s.src)s.src=s.dataset.src});studyVideo.load()}''',name)
   page.wait_for_function('studyVideo.readyState>=3')
   values=[]
   for fraction in [.2,.6,.2]:
    target=page.evaluate('''p=>{let t=studyComponent.videotrigger||studyComponent.baseVideotrigger||app.appCore.scrollTrigger.getAll().find(t=>t.trigger===studyComponent.$el&&String(t.vars.onUpdate).includes('currentTime'));window.studyTrigger=t;return t.start+(t.end-t.start)*p}''',fraction)
    page.evaluate('(y)=>app.appCore.superScroller.scrollTo(y,{immediate:true,force:true})',target);page.wait_for_timeout(500)
    page.evaluate('(y)=>app.appCore.superScroller.scrollTo(y+3,{immediate:true,force:true})',target);page.wait_for_timeout(500)
    values.append(page.evaluate('''() => ({scroll:scrollY,progress:studyTrigger.progress,time:studyVideo.currentTime,duration:studyVideo.duration,expected:studyVideo.duration*studyTrigger.progress,readyState:studyVideo.readyState,paused:studyVideo.paused,seeking:studyVideo.seeking,seekable:Array.from({length:studyVideo.seekable.length},(_,i)=>[studyVideo.seekable.start(i),studyVideo.seekable.end(i)]),buffered:Array.from({length:studyVideo.buffered.length},(_,i)=>[studyVideo.buffered.start(i),studyVideo.buffered.end(i)])})'''))
   report['scrub'][name]=values
  # Intro composition and number interpolation.
  report['composition']={}
  for name,selector in [('intro','.rows'),('about','.title.--jumbo')]:
   samples=[]
   for fraction in [.15,.65]:
    page.evaluate('''([name,p])=>{const e=document.querySelector('.component--'+name);app.appCore.superScroller.scrollTo(e.getBoundingClientRect().top+scrollY+(e.offsetHeight-innerHeight)*p,{immediate:true,force:true})}''',[name,fraction]);page.wait_for_timeout(700)
    samples.append(page.locator('.component--'+name+' '+selector).first.evaluate('(e)=>({text:e.innerText,transform:getComputedStyle(e).transform,clip:getComputedStyle(e).clipPath})'))
   report['composition'][name]=samples
  # Click an actual industry navigation control after moving to its visible chapter.
  page.evaluate("app.appCore.superScroller.scrollTo(document.querySelector('.component--industries .markers .item'),{immediate:true,force:true})");page.wait_for_timeout(900)
  report['tabBefore']=page.evaluate('scrollY')
  page.locator('.component--industries .tabs .tab').nth(2).click();page.wait_for_timeout(1600)
  report['tabAfter']=page.evaluate("({scroll:scrollY,marker:document.querySelectorAll('.component--industries .markers .item')[2].getBoundingClientRect().top+scrollY})")
  # Popup and hover use the visible CTA on the first screen, without submitting forms.
  page.evaluate('app.appCore.superScroller.scrollTo(0,{immediate:true,force:true})');page.wait_for_timeout(1000)
  trigger=page.locator('.--open-popup.--periflex-emi');trigger.click();page.wait_for_timeout(600)
  report['popupOpen']=page.locator('[data-component="popup"]').evaluate("e=>({display:getComputedStyle(e).display,role:e.getAttribute('role'),ariaModal:e.getAttribute('aria-modal'),active:document.activeElement.tagName})")
  page.keyboard.press('Escape');page.wait_for_timeout(500)
  report['popupAfterEscape']=page.locator('[data-component="popup"]').evaluate('e=>getComputedStyle(e).display')
  page.screenshot(path=str(OUT/'screenshots/desktop-popup.png'))
  page.locator('[data-component="popup"] .closer').click();page.wait_for_timeout(600)
  report['popupAfterClose']=page.locator('[data-component="popup"]').evaluate('e=>getComputedStyle(e).display')
  page.locator('#header .menu a').nth(1).hover();page.wait_for_timeout(600)
  report['headerHover']=page.locator('#header .mask').evaluate('e=>({transform:getComputedStyle(e).transform,width:getComputedStyle(e).width})')
  report['beforeResize']=page.evaluate("({desktop:app.appStore.isDesktop,desktopNodes:document.querySelectorAll('[data-desktop]').length,mobileNodes:document.querySelectorAll('[data-mobile]').length})")
  page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(1000)
  report['afterResize']=page.evaluate("({width:innerWidth,desktop:app.appStore.isDesktop,desktopNodes:document.querySelectorAll('[data-desktop]').length,mobileNodes:document.querySelectorAll('[data-mobile]').length,overflow:document.documentElement.scrollWidth>innerWidth})")
  context.close()
  nojs=browser.new_context(viewport={'width':1440,'height':900},java_script_enabled=False);nojs.route('**/*',route);pg=nojs.new_page();pg.goto(base,wait_until='load');report['noJS']=pg.locator('#loader').evaluate('e=>({display:getComputedStyle(e).display,position:getComputedStyle(e).position,zIndex:getComputedStyle(e).zIndex})');pg.screenshot(path=str(OUT/'screenshots/no-javascript.png'));nojs.close()
  browser.close()
finally:
 (OUT/'interaction-report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
 server.shutdown();server.server_close()
print(json.dumps(report,ensure_ascii=False,indent=2))
