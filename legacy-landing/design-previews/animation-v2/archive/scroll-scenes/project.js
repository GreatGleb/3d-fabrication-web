(() => {
 const scene=document.querySelector('.project-feature');if(!scene)return;
 const panel=scene.querySelector('.project-panel'),media=scene.querySelector('.project-scene-image'),image=media.querySelector('img'),caption=scene.querySelector('.project-caption'),bar=scene.querySelector('.project-scene-progress span'),header=document.querySelector('.site-header'),reduced=matchMedia('(prefers-reduced-motion: reduce)');
 const clamp=n=>Math.max(0,Math.min(1,n));let enabled=false,queued=false,offset=90,lead=100,run=1000;
 function render(p){
  // No time-based tween: every property is a pure function of scroll position.
  const reveal=clamp(p/.5),scale=clamp((p-.22)/.58),text=clamp((p-.52)/.25);
  media.style.clipPath=`inset(0 0 ${70*(1-reveal)}% 0 round 12px)`;
  image.style.transform=`scale(${.88+.12*scale})`;
  caption.style.transform=`translateY(${110*(1-text)}%)`;bar.style.transform=`scaleX(${p})`;scene.dataset.progress=p.toFixed(4);
 }
 function update(){queued=false;if(enabled)render(clamp((offset-scene.getBoundingClientRect().top-lead)/run))}
 function setup(){
  enabled=!reduced.matches;scene.classList.toggle('is-scroll-scene',enabled);scene.style.removeProperty('height');
  if(!enabled){[media,image,caption,bar].forEach(el=>el.removeAttribute('style'));return}
  const h=header.getBoundingClientRect().height;offset=h+12;scene.style.setProperty('--project-header',`${h}px`);
  let mediaHeight=innerWidth<=700?Math.max(100,Math.min(350,innerHeight-h-350)):Math.max(140,Math.min(480,innerHeight-h-135));
  scene.style.setProperty('--project-image-height',`${mediaHeight}px`);
  const overflow=panel.offsetHeight-(innerHeight-offset-12);
  if(overflow>0)scene.style.setProperty('--project-image-height',`${Math.max(80,mediaHeight-overflow)}px`);
  lead=Math.max(100,innerHeight*.16);run=Math.max(1000,innerHeight*1.65);
  scene.style.height=`${panel.offsetHeight+lead+run+innerHeight*.25}px`;scene.dataset.lead=String(lead);scene.dataset.run=String(run);update();
 }
 addEventListener('scroll',()=>{if(enabled&&!queued){queued=true;requestAnimationFrame(update)}},{passive:true});addEventListener('resize',setup);reduced.addEventListener('change',setup);document.fonts.ready.then(setup);
})();
