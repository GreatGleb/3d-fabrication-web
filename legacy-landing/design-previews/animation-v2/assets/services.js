(() => {
 const section=document.querySelector('#services'),panels=section?.querySelector('.service-grid');if(!panels)return;
 const cards=[...panels.children],reduced=matchMedia('(prefers-reduced-motion: reduce)');
 const root=document.createElement('div'),stage=document.createElement('div'),nav=document.createElement('div'),windowEl=document.createElement('div'),list=document.createElement('div'),controls=document.createElement('div');
 root.className='services-showcase';stage.className='services-stage';nav.className='services-selector';windowEl.className='services-window';list.className='services-list';controls.className='services-controls';
 list.setAttribute('role','tablist');list.setAttribute('aria-label','Технологии AIQME');list.setAttribute('aria-orientation','vertical');
 const prev=document.createElement('button'),next=document.createElement('button'),count=document.createElement('span');prev.type=next.type='button';prev.textContent='↑';next.textContent='↓';prev.setAttribute('aria-label','Предыдущая услуга');next.setAttribute('aria-label','Следующая услуга');controls.append(prev,count,next);
 const tabs=cards.map((card,i)=>{const tab=document.createElement('button');tab.type='button';tab.textContent=card.querySelector('h3').textContent;tab.id=`service-tab-${i}`;tab.setAttribute('role','tab');tab.setAttribute('aria-controls',`service-panel-${i}`);card.id=`service-panel-${i}`;card.setAttribute('role','tabpanel');card.setAttribute('aria-labelledby',tab.id);list.append(tab);return tab});
 panels.before(root);windowEl.append(list);nav.append(windowEl,controls);stage.append(nav,panels);root.append(stage);section.classList.add('services-interactive');
 let index=-1,pinned=false,top=0,run=1,queued=false,animation;
 function select(i,animate=true){
  i=Math.max(0,Math.min(cards.length-1,i));if(i===index)return;
  const hadFocus=tabs.includes(document.activeElement);index=i;root.dataset.index=String(i);animation?.cancel();
  cards.forEach((card,n)=>card.hidden=n!==i);tabs.forEach((tab,n)=>{tab.setAttribute('aria-selected',String(n===i));tab.tabIndex=n===i?0:-1});
  list.style.transform=`translateY(${(1-i)*tabs[0].offsetHeight}px)`;count.textContent=`0${i+1} / 0${cards.length}`;prev.disabled=i===0;next.disabled=i===cards.length-1;
  if(hadFocus)tabs[i].focus({preventScroll:true});
  if(animate&&!reduced.matches)animation=cards[i].animate([{opacity:0,transform:'translateY(18px)',clipPath:'inset(0 0 10% round 18px)'},{opacity:1,transform:'translateY(0)',clipPath:'inset(0 round 18px)'}],{duration:650,easing:'cubic-bezier(.16,1,.3,1)'});
 }
 function draw(){queued=false;if(!pinned)return;const p=Math.max(0,Math.min(.9999,(top-root.getBoundingClientRect().top)/run));select(Math.floor(p*cards.length))}
 function schedule(){if(!queued){queued=true;requestAnimationFrame(draw)}}
 function go(i){i=Math.max(0,Math.min(cards.length-1,i));if(pinned)scrollTo({top:scrollY+root.getBoundingClientRect().top-top+run*(i+.5)/cards.length,behavior:'instant'});select(i)}
 tabs.forEach((tab,i)=>tab.addEventListener('click',()=>go(i)));prev.addEventListener('click',()=>go(index-1));next.addEventListener('click',()=>go(index+1));
 list.addEventListener('keydown',e=>{const n={ArrowDown:index+1,ArrowUp:index-1,Home:0,End:cards.length-1}[e.key];if(n!==undefined){e.preventDefault();go(n)}});
 function setup(){
  pinned=false;root.classList.remove('is-pinned');root.style.removeProperty('height');panels.style.removeProperty('min-height');animation?.cancel();
  cards.forEach(card=>card.hidden=false);const height=Math.max(...cards.map(card=>card.offsetHeight));panels.style.minHeight=`${height}px`;cards.forEach((card,i)=>card.hidden=i!==Math.max(0,index));
  top=document.querySelector('.site-header').offsetHeight+12;run=Math.max(1600,innerHeight*3);
  // The complete description must fit; short landscapes retain the same selector by touch.
  pinned=!reduced.matches&&stage.offsetHeight<=innerHeight-top-80;
  root.style.setProperty('--services-top',`${top}px`);
  if(pinned){root.classList.add('is-pinned');root.style.height=`${stage.offsetHeight+run+80}px`}
  list.style.transform=`translateY(${(1-Math.max(0,index))*tabs[0].offsetHeight}px)`;draw();
 }
 select(0,false);setup();addEventListener('scroll',schedule,{passive:true});addEventListener('resize',setup);reduced.addEventListener('change',setup);document.fonts.ready.then(setup);
})();
