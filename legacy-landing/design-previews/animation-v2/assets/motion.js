(() => {
 const reduced=matchMedia('(prefers-reduced-motion: reduce)'), frame=document.querySelector('.v2-frame'), video=frame.querySelector('video'), button=document.querySelector('.v2-controls button');
 let visible=false, manualPause=false, failed=false;
 const label=()=>button.textContent=video.paused?'Воспроизвести':'Пауза';
 async function play(){if(failed)return;if(!video.getAttribute('src'))video.src='assets/motion-v2/bleu-full.mp4';try{await video.play();}catch{label();}}
 button.hidden=false;
 button.addEventListener('click',()=>{if(video.paused){manualPause=false;play()}else{manualPause=true;video.pause()}});
 video.addEventListener('playing',()=>{frame.classList.add('playing');label()});video.addEventListener('pause',label);
 video.addEventListener('error',()=>{failed=true;frame.classList.remove('playing');button.hidden=true});
 function sync(){if(visible&&!document.hidden&&!manualPause&&!reduced.matches&&!navigator.connection?.saveData)play();else video.pause()}
 new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;sync()},{threshold:0.12}).observe(frame);
 document.addEventListener('visibilitychange',sync);reduced.addEventListener('change',sync);
 const animations=new Map();
 const seen=new WeakSet();
 // The incoming information surface has its own scroll transition; its text
 // stays readable immediately instead of waiting for a second entrance effect.
 const targets=[...document.querySelectorAll('.section-heading,.service-card,.process-step,.process-visual-band,.project-card')].filter(el=>!el.closest('#information,#services'));
 const delays=new Map(targets.map((el,i)=>[el,(i%3)*160]));
 let observer;
 function settle(el){
  seen.add(el);observer?.unobserve(el);
  el.classList.remove('reveal-pending');
  const animation=animations.get(el);animations.delete(el);animation?.cancel();
 }
 // Prepare only content below the initial viewport. Never hide content already
 // visible on load, a restored scroll position, or a direct section link.
 for(const el of targets){
  if(reduced.matches||el.getBoundingClientRect().top<innerHeight)seen.add(el);
  else el.classList.add('reveal-pending');
 }
 function observe(){
  observer?.disconnect();
  observer=new IntersectionObserver(entries=>{
   for(const entry of entries){
    if(!entry.isIntersecting||seen.has(entry.target))continue;
    const el=entry.target;seen.add(el);observer.unobserve(el);
    if(reduced.matches){settle(el);continue}
    try{
     const animation=el.animate([{opacity:0,transform:'translateY(64px)'},{opacity:1,transform:'translateY(0)'}],{duration:1800,delay:delays.get(el),fill:'both',easing:'cubic-bezier(.4,0,.2,1)'});
     animations.set(el,animation);
     animation.finished.then(()=>settle(el),()=>{el.classList.remove('reveal-pending');animations.delete(el)});
    }catch{settle(el)}
   }
  },{rootMargin:`0px 0px -${Math.round(innerHeight*.22)}px 0px`,threshold:0});
  targets.filter(el=>!seen.has(el)).forEach(el=>observer.observe(el));
 }
 observe();addEventListener('resize',observe);
 document.addEventListener('focusin',event=>{for(const el of targets)if(el.contains(event.target))settle(el)});
 reduced.addEventListener('change',()=>{if(reduced.matches)targets.forEach(settle)});
})();
