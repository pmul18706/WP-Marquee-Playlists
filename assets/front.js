(function(){
  function rafMarquee(windowEl, direction, speed, pauseOnHover){
    const marquee = windowEl.querySelector('.wmp-marquee');
    const track = windowEl.querySelector('.wmp-track');
    const seq = windowEl.querySelector('.wmp-seq');
    if (!marquee || !track || !seq) return;

    let x = 0;
    let last = performance.now();
    let paused = false;

    let seqWidth = 0;
    let lastSeqWidth = 0;

    function recalc(){
      const w = seq.scrollWidth;
      if (w && w !== lastSeqWidth){
        lastSeqWidth = w;
        seqWidth = w;

        // Keep x bounded after resize/load
        if (seqWidth > 0){
          x = x % seqWidth;
        }
      }
    }

    // Recalc when media loads (images/video)
    track.querySelectorAll('img').forEach(img => {
      if (!img.complete) img.addEventListener('load', recalc, { once:true });
    });
    track.querySelectorAll('video').forEach(v => {
      v.addEventListener('loadedmetadata', recalc, { once:true });
      v.addEventListener('loadeddata', recalc, { once:true });
    });

    window.addEventListener('resize', recalc);

    if (pauseOnHover) {
      marquee.addEventListener('mouseenter', () => { paused = true; });
      marquee.addEventListener('mouseleave', () => { paused = false; });
    }

    function step(t){
      const dt = (t - last) / 1000;
      last = t;

      recalc();

      if (!paused && seqWidth > 0){
        const dir = (direction === 'right') ? 1 : -1;
        x += dir * speed * dt;

        // Wrap perfectly at seqWidth
        if (dir < 0 && (-x) >= seqWidth) x += seqWidth;
        if (dir > 0 && x >= seqWidth) x -= seqWidth;

        track.style.transform = `translate3d(${x}px,0,0)`;
      }

      requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function initStaticRotation(windowEl){
    const staticWrap = windowEl.querySelector('.wmp-static');
    if (!staticWrap) return;

    const items = Array.from(staticWrap.querySelectorAll('.wmp-static-item'));
    if (items.length <= 1) return;

    const anyDur = items.some(it => parseInt(it.getAttribute('data-duration') || '0', 10) > 0);
    if (!anyDur) return;

    let idx = 0;

    function show(i){
      items.forEach((it, k) => {
        it.style.display = (k === i) ? '' : 'none';
      });
    }

    function next(){
      const dur = parseInt(items[idx].getAttribute('data-duration') || '0', 10);
      const wait = (dur > 0 ? dur : 5) * 1000;
      setTimeout(() => {
        idx = (idx + 1) % items.length;
        show(idx);
        next();
      }, wait);
    }

    show(idx);
    next();
  }

  function init(){
    document.querySelectorAll('.wmp-wrap-front').forEach(root => {
      const mode = root.getAttribute('data-mode') || 'marquee';
      const direction = root.getAttribute('data-direction') || 'left';
      const speed = parseFloat(root.getAttribute('data-speed') || '60');
      const pause = (root.getAttribute('data-pause') === '1');

      root.querySelectorAll('.wmp-window').forEach(win => {
        if (mode === 'static') {
          initStaticRotation(win);
        } else {
          rafMarquee(win, direction, speed, pause);
        }

        // Try to autoplay videos
        win.querySelectorAll('video.wmp-video').forEach(v => {
          try { v.play().catch(()=>{}); } catch(e){}
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
