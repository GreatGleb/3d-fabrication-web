(() => {
 const nav=document.querySelector('.primary-navigation');if(!nav)return;
 const links=[...nav.querySelectorAll(':scope > a:not(.button)')];
 const pill=document.createElement('span');pill.className='nav-highlight';pill.setAttribute('aria-hidden','true');nav.prepend(pill);
 let hovered=null,current=null,queued=false,positioned=false;
 const sectionLinks=links.map(link=>{
  const url=new URL(link.href),route=url.pathname.split('/').filter(Boolean).at(-1);
  const id=url.hash.slice(1)||({projektai:'projects',calculator:'quick-calculator',kontaktai:'contact-form'})[route];
  const section=id&&document.getElementById(id);return section?{link,section}:null;
 }).filter(Boolean);
 function draw(){
  const focused=links.includes(document.activeElement)?document.activeElement:null,target=hovered||focused||current;
  if(!target||!nav.getClientRects().length){pill.style.opacity='0';return}
  const outer=nav.getBoundingClientRect(),rect=target.getBoundingClientRect(),mobile=matchMedia('(max-width:980px)').matches,pad=mobile?0:6;
  if(!rect.width||!rect.height){pill.style.opacity='0';return}
  if(!positioned)pill.style.transition='none';
  pill.style.width=`${rect.width+pad*2}px`;pill.style.height=`${rect.height+(mobile?0:12)}px`;
  pill.style.transform=`translate(${rect.left-outer.left-nav.clientLeft+nav.scrollLeft-pad}px,${rect.top-outer.top-nav.clientTop+nav.scrollTop-(mobile?0:6)}px)`;
  pill.style.opacity='1';
  if(!positioned){pill.getBoundingClientRect();pill.style.removeProperty('transition');positioned=true}
 }
 function update(){queued=false;
  const line=document.querySelector('.site-header').getBoundingClientRect().bottom+100;
  const active=sectionLinks.filter(({section})=>section.getBoundingClientRect().top<=line).sort((a,b)=>b.section.getBoundingClientRect().top-a.section.getBoundingClientRect().top)[0];
  current=active?.link||null;
  for(const link of links){if(link===current)link.setAttribute('aria-current','location');else link.removeAttribute('aria-current')}
  draw();
 }
 function schedule(){if(!queued){queued=true;requestAnimationFrame(update)}}
 for(const link of links){
  link.addEventListener('pointerenter',event=>{if(event.pointerType!=='touch'){hovered=link;draw()}});
  link.addEventListener('pointerdown',event=>{if(event.pointerType==='touch'){current=link;draw()}});
  link.addEventListener('focus',()=>{hovered=null;draw()});
 }
 nav.addEventListener('focusout',()=>requestAnimationFrame(draw));
 nav.addEventListener('pointerleave',()=>{hovered=null;draw()});
 new MutationObserver(()=>{hovered=null;positioned=false;schedule()}).observe(nav,{attributes:true,attributeFilter:['class']});
 new ResizeObserver(()=>{positioned=false;schedule()}).observe(nav);
 addEventListener('scroll',schedule,{passive:true});addEventListener('resize',()=>{hovered=null;positioned=false;schedule()});
 document.fonts.ready.then(schedule);schedule();
})();
