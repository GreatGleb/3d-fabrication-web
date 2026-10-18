(() => {
 const root=document.querySelector('.gear-case');if(!root)return;
 const panel=root.querySelector('.gear-panel'),header=document.querySelector('.site-header'),nav=root.querySelector('.gear-nav'),hintText=root.querySelector('.gear-hint'),steps=[...root.querySelectorAll('.gear-steps li')],buttons=[...nav.querySelectorAll('button')];
 const cad=root.querySelector('.gear-cad'),result=root.querySelector('.gear-result'),seam=root.querySelector('.gear-seam'),pointer=root.querySelector('.gear-pointer'),line=pointer.querySelector('path'),callout=root.querySelector('.gear-callout'),bar=root.querySelector('.gear-progress span'),count=root.querySelector('.gear-count');
 const heading=root.querySelector('.gear-heading'),intro=document.createElement('div');intro.className='gear-intro shell';intro.hidden=true;root.before(intro);
 const reduced=matchMedia('(prefers-reduced-motion: reduce)'),clamp=n=>Math.max(0,Math.min(1,n));let mode='static',queued=false,headerHeight=78,progress=0,lead=220,run=2400;
 function render(p){progress=p;
  const a=clamp((p-.13)/.25),b=clamp((p-.64)/.22),hint=clamp((p-.4)/.1)*(1-clamp((p-.59)/.05));
  cad.style.clipPath=`inset(0 ${100*(1-a)}% 0 0)`;result.style.clipPath=`inset(0 0 0 ${100*(1-b)}%)`;
  seam.style.left=`${(b>0?1-b:a)*100}%`;seam.style.opacity=(a>0&&a<1)||(b>0&&b<1)?'1':'0';pointer.style.opacity=String(hint);line.style.strokeDashoffset=String(1-hint);callout.style.opacity=String(hint);bar.style.transform=`scaleX(${p})`;
  const active=p<.38?0:p<.75?1:2;steps.forEach((el,i)=>el.classList.toggle('is-current',i===active));buttons.forEach((el,i)=>{if(i===active)el.setAttribute('aria-current','step');else el.removeAttribute('aria-current')});count.textContent=`0${active+1} / 03`;root.dataset.progress=p.toFixed(3);
 }
 function update(){queued=false;if(mode!=='pinned')return;const box=root.getBoundingClientRect();render(clamp((headerHeight+12-box.top-lead)/run))}
 function setup(){
  headerHeight=header.getBoundingClientRect().height;root.style.setProperty('--gear-header',`${headerHeight}px`);
  root.classList.remove('is-pinned','is-compact');root.style.removeProperty('height');panel.prepend(heading);intro.hidden=true;mode='static';
  nav.hidden=hintText.hidden=reduced.matches;hintText.textContent='Прокрутите вниз или выберите этап';
  if(!reduced.matches){
   mode='pinned';root.classList.add('is-pinned');
   if(panel.offsetHeight>innerHeight-headerHeight-20){root.classList.add('is-compact');intro.append(heading);intro.hidden=false;}
   // Even the compact scene may not fit a small phone or enlarged text.
   // Keep all three stages in normal flow instead of clipping the controls.
   if(panel.offsetHeight>innerHeight-headerHeight-24){
    mode='static';root.classList.remove('is-pinned','is-compact');
    panel.prepend(heading);intro.hidden=true;nav.hidden=hintText.hidden=true;
   }
  }
  lead=Math.max(200,Math.round(innerHeight*.3));run=Math.max(2100,Math.round(innerHeight*3.2));
  if(mode==='pinned')root.style.height=`${panel.offsetHeight+lead+run+Math.round(innerHeight*.25)}px`;
  root.dataset.lead=String(lead);root.dataset.run=String(run);render(progress);update();
 }
 buttons.forEach((button,i)=>button.addEventListener('click',()=>{
  if(mode!=='pinned')return;
  const target=[.05,.52,.94][i];
  scrollTo({top:scrollY+root.getBoundingClientRect().top-headerHeight-12+lead+run*target,behavior:'instant'});update();
 }));
 addEventListener('scroll',()=>{if(!queued&&mode==='pinned'){queued=true;requestAnimationFrame(update)}},{passive:true});addEventListener('resize',setup);reduced.addEventListener('change',setup);document.fonts.ready.then(setup);
})();
