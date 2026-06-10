/* ============================================================
   KAU animation engine
   Drives motion via rAF manual tweens (the preview freezes CSS
   transition/animation timelines, but JS timers + rAF run).
   Works identically in a normal browser / WordPress.

   Reveals are LOOPING: every watched element hides when it
   leaves the viewport and replays its entrance when it comes
   back in — scrolling up and down re-triggers the animations.
   ============================================================ */
(function(){
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // rAF is unreliable in some embeds — drive frames with setTimeout instead
  var tick = (typeof requestAnimationFrame!=='undefined') ? function(cb){ var done=false; var id=requestAnimationFrame(function(){done=true;cb();}); setTimeout(function(){ if(!done) cb(); }, 32); return id; } : function(cb){ return setTimeout(cb,16); };

  function easeOutExpo(p){ return p>=1 ? 1 : 1 - Math.pow(2, -10*p); }
  function easeOutCubic(p){ return 1 - Math.pow(1-p, 3); }

  // generic opacity + translateY (+ optional scale) tween
  // o.guard: function returning false → abort silently (used to cancel on reset)
  function tween(el, o){
    var dur=o.dur||760, delay=o.delay||0, ease=o.ease||easeOutExpo;
    var fO=(o.fromO!=null?o.fromO:1), tO=(o.toO!=null?o.toO:1);
    var fY=o.fromY||0, tY=o.toY||0, fS=(o.fromS!=null?o.fromS:1), tS=(o.toS!=null?o.toS:1);
    var start=performance.now();
    function frame(){
      if(o.guard && !o.guard()) return;
      var t=performance.now()-start-delay;
      if(t<0){ tick(frame); return; }
      var p=Math.min(t/dur,1), e=ease(p);
      el.style.opacity = (fO+(tO-fO)*e);
      var y=fY+(tY-fY)*e, s=fS+(tS-fS)*e;
      el.style.transform = (y?('translateY('+y.toFixed(2)+'px) '):'') + (s!==1?('scale('+s.toFixed(4)+')'):'');
      if(p<1){ tick(frame); }
      else { if(o.clear!==false){ el.style.transform=''; el.style.willChange=''; } if(o.done) o.done(); }
    }
    tick(frame);
  }

  // count-up numbers
  function countUp(el, guard){
    var target=parseFloat(el.getAttribute('data-count'));
    var suffix=el.getAttribute('data-suffix')||'';
    var dur=900, start=performance.now();
    var dec=(target%1!==0)?1:0;
    function frame(){
      if(guard && !guard()) return;
      var p=Math.min((performance.now()-start)/dur,1), e=easeOutCubic(p);
      el.textContent=(target*e).toFixed(dec)+suffix;
      if(p<1) tick(frame); else el.textContent=target.toFixed(dec)+suffix;
    }
    tick(frame);
  }
  function resetCounts(el){
    var list = el.hasAttribute && el.hasAttribute('data-count') ? [el] : el.querySelectorAll('[data-count]');
    Array.prototype.forEach.call(list, function(c){ c.textContent='0'; });
  }
  function runCounts(el, guard){
    var list = el.hasAttribute && el.hasAttribute('data-count') ? [el] : el.querySelectorAll('[data-count]');
    Array.prototype.forEach.call(list, function(c){ countUp(c, guard); });
  }

  // ---------- page transition veil ----------
  var veil=document.createElement('div');
  veil.setAttribute('aria-hidden','true');
  veil.style.cssText='position:fixed;inset:0;z-index:9999;background:#001b3d;pointer-events:none;opacity:1';
  veil.innerHTML='<div style="position:absolute;left:0;top:0;height:100%;width:100%;background:linear-gradient(90deg,#001b3d 0%,#0a2747 100%)"></div>';
  document.documentElement.appendChild(veil);
  function hideVeil(){
    tween(veil,{fromO:1,toO:0,dur:620,clear:false,done:function(){ veil.style.display='none'; }});
  }
  // fail-safe: never leave the veil covering the page
  setTimeout(function(){ if(veil.style.display!=='none'){ veil.style.opacity='0'; veil.style.display='none'; } }, 1400);

  function showVeilThen(href){
    veil.style.display='block'; veil.style.pointerEvents='all';
    tween(veil,{fromO:0,toO:1,dur:420,clear:false,done:function(){ window.location.href=href; }});
  }

  // intercept internal links for smooth page transition
  function isInternal(a){
    var href=a.getAttribute('href')||'';
    if(!href || href.charAt(0)==='#') return false;
    if(a.target==='_blank' || a.hasAttribute('download')) return false;
    if(/^(https?:|mailto:|tel:)/i.test(href)) return false;
    return /\.html(\?|#|$)/.test(href) || href.indexOf('.')===-1;
  }
  document.addEventListener('click', function(e){
    var a=e.target.closest && e.target.closest('a');
    if(!a) return;
    if(isInternal(a)){
      var href=a.getAttribute('href');
      if(href===location.pathname.split('/').pop()) return;
      e.preventDefault();
      if(reduce){ location.href=href; return; }
      showVeilThen(href);
    }
  });

  // ---------- looping viewport watcher ----------
  // items: {el, state:'hidden'|'shown', gen, show(guard,batchDelay), hide()}
  var items=[];
  function guardOf(it){ var g=++it.gen; return function(){ return g===it.gen; }; }

  function watch(el, opts){
    opts=opts||{};
    var it={ el:el, state:'hidden', gen:0 };
    it.show=function(batchDelay){
      it.state='shown';
      var guard=guardOf(it);
      el.style.willChange='opacity,transform';
      tween(el,{
        fromO:0, toO:1,
        fromY:(opts.fromY!=null?opts.fromY:30), toY:0,
        dur:opts.dur||820,
        delay:(batchDelay||0)+(opts.delay||0),
        guard:guard
      });
      runCounts(el, guard);
    };
    it.hide=function(){
      it.gen++; // cancel any running tween/count
      it.state='hidden';
      el.style.opacity='0'; el.style.transform=''; el.style.willChange='';
      resetCounts(el);
    };
    // start hidden
    el.style.opacity='0';
    items.push(it);
    return it;
  }

  // ken-burns zoom items: never fade, only re-zoom on re-enter
  function watchZoom(el){
    var it={ el:el, state:'hidden', gen:0 };
    it.show=function(){
      it.state='shown';
      tween(el,{fromS:1.12,toS:1,dur:1900,clear:false,ease:easeOutCubic,guard:guardOf(it)});
    };
    it.hide=function(){
      it.gen++;
      it.state='hidden';
    };
    items.push(it);
    return it;
  }

  function check(){
    var vh=window.innerHeight, batch=0;
    for(var i=0;i<items.length;i++){
      var it=items[i], r=it.el.getBoundingClientRect();
      if(r.width===0 && r.height===0) continue; // display:none — skip
      var inView   = r.top < vh*0.86 && r.bottom > 0;
      var fullyOut = r.bottom < -10 || r.top > vh+10;
      if(it.state==='hidden' && inView){ it.show(batch*80); batch++; }
      else if(it.state==='shown' && fullyOut){ it.hide(); }
    }
  }

  // ---------- run on load ----------
  function init(){
    if(reduce){
      document.querySelectorAll('[data-reveal],[data-hero]').forEach(function(el){el.style.opacity='1';el.style.transform='';});
      document.querySelectorAll('[data-count]').forEach(function(el){var t=el.getAttribute('data-count');el.textContent=t+(el.getAttribute('data-suffix')||'');});
      veil.style.display='none';
      return;
    }
    hideVeil();

    // hero entrance — staggered rise (replays when scrolled back to top)
    document.querySelectorAll('[data-hero]').forEach(function(el,i){
      watch(el,{fromY:34,dur:1000,delay:120+i*110});
    });

    // hero image ken-burns zoom-in
    document.querySelectorAll('[data-hero-zoom]').forEach(function(el){
      watchZoom(el);
    });

    // scroll reveals
    document.querySelectorAll('[data-reveal]').forEach(function(el){
      watch(el,{fromY:30,dur:820});
    });

    // standalone count-ups not wrapped in reveal/hero — loop them too
    document.querySelectorAll('[data-count]').forEach(function(el){
      if(el.closest('[data-reveal],[data-hero]')) return;
      var it={ el:el, state:'hidden', gen:0 };
      it.show=function(){ it.state='shown'; countUp(el, guardOf(it)); };
      it.hide=function(){ it.gen++; it.state='hidden'; el.textContent='0'; };
      items.push(it);
    });

    window.addEventListener('scroll', check, {passive:true});
    window.addEventListener('resize', check);
    check(); setTimeout(check,60);
    // belt-and-braces: some embeds drop scroll events — poll lightly
    setInterval(check, 400);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();

  window.KAU = { tween: tween };
})();
