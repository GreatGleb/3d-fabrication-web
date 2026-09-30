(() => {
 const root=document.querySelector('.mosaic');if(!root)return;
 const stage=root.querySelector('.mosaic-stage'),grid=root.querySelector('.mosaic-grid'),center=root.querySelector('.mosaic-center'),button=root.querySelector('button'),videos=[...root.querySelectorAll('video')];
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 let visible=false,manualPause=false,active=false,queued=false,top=0,run=1,startScale=1;
 function draw(){queued=false;if(!active)return;const p=Math.max(0,Math.min(1,(top-root.getBoundingClientRect().top)/run));const eased=p*p*(3-2*p);grid.style.transform=`scale(${startScale+(1-startScale)*eased})`;root.dataset.progress=p.toFixed(3)}
 function schedule(){if(!queued){queued=true;requestAnimationFrame(draw)}}
 function setup(){
  top=document.querySelector('.site-header').offsetHeight;
  root.classList.remove('is-scroll');root.style.removeProperty('height');grid.style.removeProperty('transform');
  active=!reduced.matches&&innerHeight-top>=440;
  if(active){const height=innerHeight-top;run=height*(innerWidth<=700?1:1.5);root.style.setProperty('--mosaic-top',`${top}px`);root.style.setProperty('--mosaic-height',`${height}px`);root.style.height=`${height+run}px`;root.classList.add('is-scroll');startScale=Math.max(stage.clientWidth/center.offsetWidth,stage.clientHeight/center.offsetHeight)*1.03;draw()}
 }
 function label(){button.textContent=videos.some(v=>!v.paused)?'Пауза видео':'Воспроизвести видео'}
 function sync(){
  const play=visible&&!document.hidden&&!manualPause&&!reduced.matches&&!navigator.connection?.saveData;
  for(const video of videos){if(play&&!video.dataset.failed){if(!video.getAttribute('src'))video.src=video.dataset.src;video.play().then(()=>{if(!visible||document.hidden||manualPause||reduced.matches)video.pause();label()}).catch(label)}else video.pause()}
  label();
 }
 for(const video of videos){video.addEventListener('playing',()=>{video.parentElement.classList.add('is-playing');label()});video.addEventListener('pause',label);video.addEventListener('error',()=>{video.dataset.failed='true';video.parentElement.classList.remove('is-playing');label()})}
 button.hidden=false;button.addEventListener('click',()=>{manualPause=videos.some(v=>!v.paused);if(!manualPause&&visible){for(const video of videos){if(video.dataset.failed)continue;if(!video.src)video.src=video.dataset.src;video.play().catch(label)}}else sync()});
 new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;sync()},{threshold:0.05}).observe(stage);
 addEventListener('scroll',schedule,{passive:true});addEventListener('resize',setup);addEventListener('pageshow',setup);document.addEventListener('visibilitychange',sync);reduced.addEventListener('change',()=>{setup();sync()});
 document.fonts.ready.then(setup);setup();
 const hash=location.hash;if(hash==='#possibilities'){const loaded=document.readyState==='complete'?Promise.resolve():new Promise(r=>addEventListener('load',r,{once:true}));Promise.all([loaded,document.fonts.ready]).then(()=>requestAnimationFrame(()=>{if(location.hash===hash)scrollTo({top:scrollY+root.getBoundingClientRect().top-top,behavior:'instant'})}))}
})();
