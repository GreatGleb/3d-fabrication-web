(() => {
 const reduced=matchMedia('(prefers-reduced-motion: reduce)'), frame=document.querySelector('.v2-frame'), video=frame.querySelector('video'), button=document.querySelector('.v2-controls button');
 let visible=false, manualPause=false, failed=false;
 const label=()=>button.textContent=video.paused?'Воспроизвести':'Пауза';
 async function play(){if(failed)return;if(!video.getAttribute('src'))video.src='assets/motion-v2/hero.mp4';try{await video.play();}catch{label();}}
 button.hidden=false;
 button.addEventListener('click',()=>{if(video.paused){manualPause=false;play()}else{manualPause=true;video.pause()}});
 video.addEventListener('playing',()=>{frame.classList.add('playing');label()});video.addEventListener('pause',label);
 video.addEventListener('error',()=>{failed=true;frame.classList.remove('playing');button.hidden=true});
 function sync(){if(visible&&!document.hidden&&!manualPause&&!reduced.matches&&!navigator.connection?.saveData)play();else video.pause()}
 new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;sync()},{threshold:0.12}).observe(frame);
 document.addEventListener('visibilitychange',sync);reduced.addEventListener('change',sync);
 const animations=new Set();
 function animate(el,keys,options={}){if(reduced.matches)return;const a=el.animate(keys,{duration:1500,easing:'cubic-bezier(.22,.7,.2,1)',...options});animations.add(a);a.finished.catch(()=>{}).finally(()=>animations.delete(a));}
 reduced.addEventListener('change',()=>{if(reduced.matches)animations.forEach(a=>a.finish())});
 // Register on readiness; initial and no-JS states are always fully readable.
 document.fonts.ready.then(()=>{
  const title=document.querySelector('#services-title'), original=title.textContent;
  function lines(){title.textContent='';const words=original.trim().split(/\s+/);const spans=words.map((word,i)=>{const s=document.createElement('span');s.textContent=word+(i<words.length-1?' ':'');s.style.whiteSpace='pre';title.append(s);return s});const groups=[];let top=-1;for(const s of spans){const y=s.offsetTop;if(y!==top){groups.push([]);top=y}groups.at(-1).push(s.textContent)}title.replaceChildren();title.setAttribute('aria-label',original);for(const group of groups){const outer=document.createElement('span'),inner=document.createElement('span');outer.className='v2-line';inner.className='v2-line-inner';outer.setAttribute('aria-hidden','true');inner.textContent=group.join('').trim();outer.append(inner);title.append(outer)}}
  lines();let resize;addEventListener('resize',()=>{clearTimeout(resize);resize=setTimeout(lines,180)});
  const seen=new WeakSet();
  const observer=new IntersectionObserver(entries=>{for(const entry of entries){if(!entry.isIntersecting||seen.has(entry.target))continue;const el=entry.target;seen.add(el);observer.unobserve(el);
   if(el===frame){frame.querySelectorAll('img,video').forEach(layer=>animate(layer,[{transform:'scale(1.08)'},{transform:'scale(1)'}],{duration:2200}))}
   else if(el===title){title.querySelectorAll('.v2-line-inner').forEach((line,i)=>animate(line,[{transform:'translateY(115%) rotate(2deg)'},{transform:'translateY(0) rotate(0)'}],{delay:i*140,fill:'backwards',duration:1300}))}
   else{const media=el.querySelector('.service-image');animate(media,[{clipPath:'inset(0 100% 0 0)'},{clipPath:'inset(0 0 0 0)'}],{duration:1600});animate(media.querySelector('img'),[{transform:'translateX(-18px)'},{transform:'translateX(0)'}]);el.querySelectorAll('.service-content > *').forEach((part,i)=>animate(part,[{transform:'translateY(24px)'},{transform:'translateY(0)'}],{delay:180+i*90,fill:'backwards',duration:1300}))}
  }},{rootMargin:'0px 0px -22% 0px',threshold:0});
  [frame,title,...document.querySelectorAll('.service-card')].forEach(el=>observer.observe(el));
 });
 let scheduled=false;
 function scroll(){scheduled=false;const r=document.querySelector('.hero').getBoundingClientRect(),p=Math.max(0,Math.min(1,-r.top/Math.max(1,r.height)));frame.style.transform=reduced.matches?'none':`scale(${1+(innerWidth<700?.025:.05)*p})`}

 addEventListener('scroll',()=>{if(!scheduled){scheduled=true;requestAnimationFrame(scroll)}},{passive:true});addEventListener('resize',scroll);scroll();
})();
