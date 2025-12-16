(function(){
  function buildOne(root){
    if(!root) return;

    var mode = root.getAttribute('data-mode') || 'marquee';
    var dir = root.getAttribute('data-direction') || 'left';
    var speed = Math.max(10, parseInt(root.getAttribute('data-speed') || '80', 10));
    var pauseHover = parseInt(root.getAttribute('data-pause-hover') || '1', 10) === 1;
    var pauseTime = Math.max(0, parseInt(root.getAttribute('data-pause-time') || '0', 10));

    var track = root.querySelector('.wpmq-track');
    if(!track) return;
    if(mode === 'static') return;

    function finalize(){
      // remove old clones
      var old = track.querySelectorAll('[data-wpmq-clone="1"]');
      for(var i=0;i<old.length;i++) old[i].parentNode.removeChild(old[i]);

      var containerWidth = root.clientWidth;
      if(containerWidth <= 0) return;

      // duplicate until long enough
      var baseWidth = track.scrollWidth;
      var safety = 0;
      while(baseWidth < containerWidth * 2.2 && safety < 20){
        var kids = Array.prototype.slice.call(track.children);
        for(var k=0;k<kids.length;k++){
          var clone = kids[k].cloneNode(true);
          clone.setAttribute('data-wpmq-clone','1');
          track.appendChild(clone);
        }
        baseWidth = track.scrollWidth;
        safety++;
      }

      var distance = track.scrollWidth / 2;
      if(!distance || distance < 10) distance = track.scrollWidth; // fallback

      var seconds = Math.max(1, distance / speed);
      var fromX = (dir === 'right') ? (-distance) : 0;
      var toX   = (dir === 'right') ? 0 : (-distance);

      // create/update keyframes
      var keyName = 'wpmq_' + Math.random().toString(16).slice(2);
      var style = document.createElement('style');
      style.setAttribute('data-wpmq-style','1');
      style.textContent =
        '@keyframes ' + keyName + '{0%{transform:translateX(' + fromX + 'px)}100%{transform:translateX(' + toX + 'px)}}' +
        '#' + root.id + ' .wpmq-track{animation:' + keyName + ' ' + seconds + 's linear infinite; will-change:transform;}';

      var olds = root.querySelectorAll('style[data-wpmq-style="1"]');
      for(var s=0;s<olds.length;s++) olds[s].parentNode.removeChild(olds[s]);
      root.appendChild(style);

      if(pauseTime > 0){
        track.addEventListener('animationiteration', function(){
          track.classList.add('is-paused');
          setTimeout(function(){ track.classList.remove('is-paused'); }, pauseTime);
        }, { passive:true });
      }

      if(pauseHover){
        root.addEventListener('mouseenter', function(){ track.classList.add('is-paused'); });
        root.addEventListener('mouseleave', function(){ track.classList.remove('is-paused'); });
      }
    }

    // wait for images
    var imgs = track.querySelectorAll('img');
    if(!imgs.length) return finalize();

    var loaded = 0;
    function done(){ loaded++; if(loaded >= imgs.length) finalize(); }
    for(var i=0;i<imgs.length;i++){
      if(imgs[i].complete) done();
      else {
        imgs[i].addEventListener('load', done, {once:true});
        imgs[i].addEventListener('error', done, {once:true});
      }
    }

    var t;
    window.addEventListener('resize', function(){
      clearTimeout(t);
      t = setTimeout(finalize, 150);
    });
  }

  function init(){
    var roots = document.querySelectorAll('.wpmq-wrap[data-mode]');
    for(var i=0;i<roots.length;i++) buildOne(roots[i]);
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
