(() => {
  'use strict';
  const scene = document.querySelector('.motion-case');
  if (!scene) return;
  const panel = scene.querySelector('.case-panel');
  const stage = scene.querySelector('.case-stage');
  const header = document.querySelector('.site-header');
  const hero = document.querySelector('.hero-visual img');
  let heroObserver;
  const copy = scene.querySelector('.case-copy');
  const steps = [...scene.querySelectorAll('[data-case-step]')];
  const frames = [...scene.querySelectorAll('.stage-media .case-frame')];
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');
  const wide = matchMedia('(min-width:1101px) and (min-height:700px)');
  const flow = new URLSearchParams(location.search).get('scene') === 'flow';
  const labels = ['Сканирование', 'CAD-модель', 'Готовая деталь'];
  let raf = 0, resizing = true, active = -1, inRange = false, observer;
  function clearReveals() {
    document.querySelectorAll('.motion-reveal').forEach(el => {
      el.classList.add('is-visible');
      el.style.removeProperty('--motion-delay');
    });
    observer?.disconnect();
  }
  function update() {
    raf = 0;
    try {
      if (resizing) {
        resizing = false;
        const headerHeight = header.offsetHeight;
        document.body.style.setProperty('--motion-header', `${headerHeight}px`);
        panel.append(stage);
        scene.classList.remove('case--compact');
        const wantsPin = wide.matches && !reduce.matches && !flow;
        scene.classList.toggle('case--pinned', wantsPin);
        // Measure the actual text after the proposed two-column layout.
        if (wantsPin && copy.scrollHeight > panel.clientHeight - 64) scene.classList.remove('case--pinned');
        if (!scene.classList.contains('case--pinned') && !reduce.matches && !flow && innerHeight >= 600) {
          scene.classList.add('case--compact');
          copy.insertBefore(stage, copy.querySelector('.case-steps'));
        }
      }
      const compact = scene.classList.contains('case--compact');
      if (!scene.classList.contains('case--pinned') && !compact) {
        steps.forEach(el => el.classList.add('is-current'));
        active = -1;
        return;
      }
      const rect = scene.getBoundingClientRect();
      const travel = scene.clientHeight - panel.offsetHeight - 40;
      if (!compact && travel <= 0) { scene.classList.remove('case--pinned'); return; }
      let progress = compact ? 0 : Math.max(0, Math.min(1, (header.offsetHeight + 12 - rect.top) / travel));
      let current = progress < .28 ? 0 : progress < .6 ? 1 : 2;
      if (compact) {
        const line = header.offsetHeight + 12 + stage.offsetHeight + 100;
        current = 0;
        steps.forEach((step, i) => { if (step.getBoundingClientRect().top <= line) current = i; });
        progress = current / 2;
      }
      scene.style.setProperty('--case-progress', progress.toFixed(4));
      if (active !== current) {
        active = current;
        steps.forEach((el, i) => el.classList.toggle('is-current', i === current));
        frames.forEach((el, i) => el.classList.toggle('is-current', i === current));
        scene.querySelector('.stage-count').textContent = `0${current + 1} / 03`;
        scene.querySelector('.stage-label').textContent = labels[current];
      }
    } catch (error) {
      scene.classList.remove('case--pinned', 'case--compact');
      clearReveals();
      console.warn('Motion enhancement disabled:', error);
    }
  }
  function schedule(resize = false) {
    resizing ||= resize;
    if (!raf) raf = requestAnimationFrame(update);
  }
  function observeReveals() {
    observer?.disconnect();
    if (reduce.matches || !('IntersectionObserver' in window)) return;
    // Use viewport-height pixels: percentage rootMargin is based on width.
    const bottomInset = Math.round(innerHeight * .22);
    observer = new IntersectionObserver(entries => entries.forEach(entry => {
      if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); }
    }), { threshold: 0, rootMargin: `0px 0px -${bottomInset}px 0px` });
    document.querySelectorAll('.motion-reveal:not(.is-visible)').forEach(el => observer.observe(el));
  }
  if (!reduce.matches && 'IntersectionObserver' in window) {
    document.querySelectorAll('.services-section .section-heading,.projects-section .section-heading,.process-section .section-heading,.about-copy,.service-card,.project-card').forEach((el, i) => {
      if (el.getBoundingClientRect().top < innerHeight) return;
      el.classList.add('motion-reveal');
      el.style.setProperty('--motion-delay', `${i % 3 * .16}s`);
    });
    observeReveals();
    // Wait for the bitmap, then start only when the image is actually in view.
    hero?.decode().catch(() => {}).then(() => {
      if (reduce.matches) return;
      heroObserver = new IntersectionObserver(entries => {
        if (!entries.some(entry => entry.isIntersecting)) return;
        heroObserver.disconnect();
        hero.animate([{ transform: 'translateY(64px) scale(.96)', opacity: .15 }, { transform: 'none', opacity: 1 }], { duration: 2200, easing: 'cubic-bezier(.4,0,.2,1)' });
      }, { threshold: .2 });
      heroObserver.observe(hero);
    });
  }
  document.addEventListener('transitionend', event => {
    if (event.propertyName === 'transform' && event.target.matches('.motion-reveal.is-visible')) event.target.classList.add('motion-settled');
  });
  document.addEventListener('focusin', event => {
    const el = event.target.closest('.motion-reveal');
    if (el) { el.style.setProperty('--motion-delay', '0s'); el.classList.add('is-visible'); }
  });
  if ('IntersectionObserver' in window) new IntersectionObserver(entries => {
    inRange = entries[0].isIntersecting;
    if (inRange) schedule();
  }, { rootMargin: '100% 0px' }).observe(scene);
  else inRange = true;
  addEventListener('scroll', () => { if (inRange) schedule(); }, { passive: true });
  addEventListener('resize', () => { schedule(true); observeReveals(); }, { passive: true });
  addEventListener('pageshow', () => schedule(true));
  addEventListener('hashchange', () => schedule());
  reduce.addEventListener('change', () => {
    if (reduce.matches) { heroObserver?.disconnect(); clearReveals(); document.querySelector('.hero-visual img')?.getAnimations().forEach(a => a.cancel()); }
    schedule(true);
  });
  document.fonts?.ready.then(() => schedule(true));
  schedule(true);
})();
