(() => {
 const form=document.querySelector('#contact-form');if(!form)return;
 const contexts=[['services','Обсудить услугу'],['projects','Обсудить похожую задачу']].map(([id,label])=>({section:document.getElementById(id),label})).filter(x=>x.section);
 const link=document.createElement('a');link.className='floating-cta';link.href='#contact-form';link.hidden=true;
 const label=document.createElement('span');label.className='floating-cta-label';link.append(label);document.body.append(link);
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');let current='',hovered=false,queued=false,animation;
 function setLabel(text){
  if(current===text)return;current=text;animation?.cancel();label.textContent=text;
  link.style.width=`${Math.ceil(label.getBoundingClientRect().width)+54}px`;
  if(!reduced.matches)animation=label.animate([{opacity:0,transform:'scale(.94)'},{opacity:1,transform:'scale(1)'}],{duration:400,easing:'ease-out'});
 }
 let inRange=false,projectContext=false;
 function measure(){link.style.width=`${Math.ceil(label.offsetWidth)+54}px`}
 function update(){
  queued=false;const focused=document.activeElement===link;
  const box=form.getBoundingClientRect(),menu=document.querySelector('.menu-button');
  const line=innerHeight*.5;
  const start=contexts[0]?.section.getBoundingClientRect().top??Infinity;
  // A small dead band prevents toggling at a boundary on touch scroll/resize.
  if(inRange){if(start>line+32||box.top<innerHeight*.75-32)inRange=false}
  else if(start<line-32&&box.top>innerHeight*.75+32)inRange=true;
  const blocked=menu?.getAttribute('aria-expanded')==='true'||document.activeElement?.matches('input,textarea,select');
  if((blocked||!inRange)&&!focused){link.hidden=true;return}
  link.hidden=false;
  // Freeze only the wording during interaction, never restart it on resize.
  if(hovered||focused)return;
  const projectTop=contexts[1]?.section.getBoundingClientRect().top??Infinity;
  if(projectTop<line-32)projectContext=true;
  else if(projectTop>line+32)projectContext=false;
  setLabel(contexts[projectContext?1:0].label);
  // On phones the fixed CTA must not cover another action (or its focus ring).
  const rect=link.getBoundingClientRect();
  const overlaps=[...document.querySelectorAll('main a,main button')].some(control=>{
   const box=control.getBoundingClientRect();
   return box.width&&box.height&&getComputedStyle(control).visibility!=='hidden'&&
    box.left<rect.right+8&&box.right>rect.left-8&&box.top<rect.bottom+8&&box.bottom>rect.top-8;
  });
  if(overlaps&&!focused)link.hidden=true;
 }
 function schedule(){if(!queued){queued=true;requestAnimationFrame(update)}}
 link.addEventListener('pointerenter',e=>{if(e.pointerType!=='touch')hovered=true});link.addEventListener('pointerleave',()=>{hovered=false;schedule()});
 link.addEventListener('blur',()=>{hovered=false;schedule()});
 link.addEventListener('click',event=>{
  if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
  event.preventDefault();hovered=false;
  const title=form.querySelector('#lead-title');title.setAttribute('tabindex','-1');title.focus({preventScroll:true});
  scrollTo({top:scrollY+form.getBoundingClientRect().top-document.querySelector('.site-header').offsetHeight,behavior:'instant'});
  link.hidden=true;
 });
 addEventListener('scroll',schedule,{passive:true});addEventListener('resize',()=>{if(!link.hidden)measure();schedule()});document.addEventListener('focusin',schedule);document.addEventListener('focusout',schedule);
 const menu=document.querySelector('.menu-button');if(menu)new MutationObserver(schedule).observe(menu,{attributes:true,attributeFilter:['aria-expanded']});
 reduced.addEventListener('change',()=>{animation?.cancel();schedule()});document.fonts.ready.then(()=>{if(!link.hidden)measure();schedule()});schedule();
 const hash=location.hash,target=contexts.find(x=>'#'+x.section.id===hash)?.section;
 if(target){const loaded=document.readyState==='complete'?Promise.resolve():new Promise(r=>addEventListener('load',r,{once:true}));Promise.all([loaded,document.fonts.ready]).then(()=>requestAnimationFrame(()=>{if(location.hash===hash)scrollTo({top:scrollY+target.getBoundingClientRect().top-document.querySelector('.site-header').offsetHeight,behavior:'instant'})}))}

})();
