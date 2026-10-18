"""Check visible motion, not just classes, at desktop/tablet/phone sizes."""
import json
import re
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
BASE = 'http://127.0.0.1:8822/animated/'

def check():
    report = []
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
        for width, height in [(1440,900),(768,900),(390,844)]:
            page = browser.new_page(viewport={'width':width,'height':height})
            errors = []
            page.on('pageerror', lambda e: errors.append(str(e)))
            print(f"Checking {width}px", flush=True)
            page.goto(BASE, wait_until='domcontentloaded')
            hero = page.locator('.hero-visual img')
            hero.evaluate('(el)=>scrollTo({top:Math.max(0,scrollY+el.getBoundingClientRect().top-120),behavior:"instant"})')
            page.wait_for_function('document.querySelector(".hero-visual img").getAnimations().length > 0')
            # Sample the actual browser animation at two times with a fixed viewport.
            positions = hero.evaluate('''el => {
                const a=el.getAnimations()[0];a.pause();a.currentTime=0;
                const start=el.getBoundingClientRect().top;
                a.currentTime=2200;const end=el.getBoundingClientRect().top;
                a.finish();return {start,end};
            }''')
            assert positions['start']-positions['end'] > 40, positions
            movements = {}
            selectors = ['#services .section-heading','.service-card','#process .section-heading','#projects .section-heading','.project-card','.about-copy']
            for selector in selectors:
                page.goto(BASE, wait_until='networkidle')
                target = page.locator(selector).first
                expect(target).to_have_class(re.compile(r'.*motion-reveal.*'))
                target.evaluate('(el)=>scrollTo({top:scrollY+el.getBoundingClientRect().top-innerHeight*.68,behavior:"instant"})')
                page.wait_for_function('(s)=>document.querySelector(s).getAnimations().some(a=>a.transitionProperty==="transform")', arg=selector)
                movement = target.evaluate('''el=>{
                    const a=el.getAnimations().find(a=>a.transitionProperty==='transform');
                    a.pause();a.currentTime=0;const start=el.getBoundingClientRect().top;
                    a.currentTime=2100;const end=el.getBoundingClientRect().top;
                    el.getAnimations().forEach(a=>a.finish());return start-end;
                }''')
                assert movement>50,(selector,movement)
                movements[selector]=round(movement)
                assert abs(page.locator('.site-header').bounding_box()['y'])<1
            # Arrow moves on hover on desktop and is also accessible by focus.
            link=page.locator('.quick-calculator .text-link')
            link.scroll_into_view_if_needed()
            if width==1440:link.hover()
            else:link.focus()
            page.wait_for_timeout(400)
            arrow=link.locator('span').evaluate('el=>getComputedStyle(el).transform')
            assert arrow not in ('none','matrix(1, 0, 0, 1, 0, 0)'),arrow
            states=[]
            for index,progress in enumerate([.1,.45,.8]):
                if width>1100:
                    page.evaluate('''p=>{const s=document.querySelector('#motion-case'),panel=s.querySelector('.case-panel'),h=document.querySelector('.site-header');scrollTo({top:scrollY+s.getBoundingClientRect().top-h.offsetHeight-12+(s.clientHeight-panel.offsetHeight-40)*p,behavior:'instant'})}''',progress)
                else:
                    page.locator('[data-case-step]').nth(index).evaluate('''el=>{const stage=document.querySelector('.case-stage'),h=document.querySelector('.site-header');scrollTo({top:scrollY+el.getBoundingClientRect().top-h.offsetHeight-stage.offsetHeight-60,behavior:'instant'})}''')
                expect(page.locator('[data-case-step].is-current')).to_have_attribute('data-case-step',str(index))
                page.wait_for_timeout(1700)
                stage=page.locator('.case-stage').bounding_box()
                header=page.locator('.site-header').bounding_box()
                assert stage['y'] >= header['height'] and stage['y']+stage['height']<=height,(width,stage,header)
                states.append(page.locator('.stage-label').inner_text())
            # The same scene works in reverse on touch-sized layouts.
            if width<1101:
                page.locator('[data-case-step]').first.evaluate('''el=>{const stage=document.querySelector('.case-stage'),h=document.querySelector('.site-header');scrollTo({top:scrollY+el.getBoundingClientRect().top-h.offsetHeight-stage.offsetHeight-60,behavior:'instant'})}''')
                expect(page.locator('[data-case-step].is-current')).to_have_attribute('data-case-step','0')
            page.wait_for_timeout(1700)
            page.screenshot(path=str(ROOT.parent/f'docs/design/animation-v1/fixed-{width}.png'))
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
            assert not errors, errors
            report.append({'width':width,'heroLift':round(positions['start']-positions['end']),'liftPixels':movements,'arrowTransform':arrow,'caseStates':states,'stickyHeader':True})
            page.close()
        browser.close()
    (ROOT.parent/'docs/design/animation-v1/effects-check.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
    print(json.dumps(report,ensure_ascii=False,indent=2))

if __name__=='__main__':check()
