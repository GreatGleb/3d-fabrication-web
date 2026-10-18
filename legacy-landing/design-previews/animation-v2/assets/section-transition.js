(() => {
  const about = document.querySelector('#about');
  const info = document.querySelector('#information');
  const grid = about?.querySelector('.about-grid');
  const photo = grid?.querySelector('.about-photo');
  const picture = photo?.querySelector('img');
  const copy = grid?.querySelector('.about-copy');
  let reveal = 0, textTravel = 0, readRun = 0;
  const clamp = value => Math.max(0, Math.min(1, value));
  const header = document.querySelector('.site-header');
  if (!grid || !info || !header) return;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  let active = false, queued = false, top = 0;
  function draw() {
    queued = false;
    if (!active) return;
    const remaining = info.getBoundingClientRect().top - top;
    const progress = Math.max(0, Math.min(1, 1 - remaining / Math.max(1, innerHeight - top)));
    grid.style.opacity = String(1 - progress);
    if (reveal) {
      const p = clamp((top - about.getBoundingClientRect().top) / reveal);
      const open = clamp(p / .65);
      photo.style.clipPath = `inset(${20*(1-open)}% ${40*(1-open)}% round ${24*(1-open)}px)`;
      photo.style.transform = `translateY(${18*(1-open)}%)`;
      picture.style.objectPosition = `${innerWidth < 900 ? 50 : 50-40*open}% center`;
      const text = clamp((p-.72)/.23);
      copy.style.opacity = String(text);
      const reading = clamp((top - about.getBoundingClientRect().top - reveal) / Math.max(1, readRun * .75));
      copy.style.transform = `translateY(${24*(1-text)-textTravel*reading}px)`;
      photo.style.setProperty('--about-shade', String(text * .88));
      about.dataset.reveal = p.toFixed(3);
    }
  }
  function schedule() {
    if (!queued) { queued = true; requestAnimationFrame(draw); }
  }
  function setup() {
    active = false;
    reveal = 0;
    about.classList.remove('about-reveal');
    for (const el of [photo,picture,copy]) el.removeAttribute('style');
    about.style.removeProperty('--about-stage-height');
    delete about.dataset.reveal;
    about.classList.remove('section-overlap-out');
    info.classList.remove('section-overlap-in');
    about.style.removeProperty('height');
    about.style.removeProperty('--overlap-top');
    info.style.removeProperty('--overlap-distance');
    grid.style.removeProperty('opacity');
    top = header.getBoundingClientRect().height + 24;
    if (reduced.matches) return;
    const stageHeight = Math.max(200, innerHeight-top-24);
    about.style.setProperty('--about-stage-height', `${stageHeight}px`);
    about.classList.add('about-reveal');
    reveal = innerHeight * (innerWidth < 900 ? 1 : 1.5);
    textTravel = innerWidth < 900 ? Math.max(0, copy.scrollHeight + 48 - stageHeight) : 0;
    readRun = innerHeight * .6 + textTravel;
    const naturalHeight = about.offsetHeight;
    const distance = innerHeight - top;
    about.style.height = `${Math.max(naturalHeight, distance) + distance + readRun + reveal}px`;
    about.style.setProperty('--overlap-top', `${top}px`);
    info.style.setProperty('--overlap-distance', `${distance}px`);
    about.classList.add('section-overlap-out');
    info.classList.add('section-overlap-in');
    active = true;
    draw();
  }
  addEventListener('scroll', schedule, { passive: true });
  addEventListener('resize', setup);
  addEventListener('pageshow', setup);
  reduced.addEventListener('change', setup);
  // Observe viewport/font changes, not the stage whose layout setup changes.
  document.fonts.ready.then(setup);
  setup();
  // Upstream gear layout grows after initial anchor resolution. Resolve these
  // section links once the page and fonts have finished establishing geometry.
  const initialHash = location.hash;
  if (initialHash === '#about' || initialHash === '#information') {
    const loaded = document.readyState === 'complete' ? Promise.resolve() :
      new Promise(resolve => addEventListener('load', resolve, { once: true }));
    Promise.all([loaded, document.fonts.ready]).then(() => requestAnimationFrame(() => {
      if (location.hash !== initialHash) return;
      const target = initialHash === '#about' ? about : info;
      scrollTo({ top: scrollY + target.getBoundingClientRect().top - header.offsetHeight, behavior: 'instant' });
    }));
  }
})();
