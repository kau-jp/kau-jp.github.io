/* KAU shared scripts */
(function(){
  // reveal markers are static now (preview freezes CSS timelines); nothing to animate

  // nav shadow + dark/light swap on scroll
  var nav = document.getElementById('nav');
  if(nav){
    var dark = nav.classList.contains('on-dark');
    var logoImg = nav.querySelector('.nav-logo img');
    var onScroll = function(){
      if(dark){
        var solid = window.scrollY > window.innerHeight*0.7;
        nav.classList.toggle('on-dark', !solid);
        if(logoImg) logoImg.src = solid ? ((window.__resources && window.__resources.logoDark) || 'assets/logo-dark.svg') : ((window.__resources && window.__resources.logoLight) || 'assets/logo-light.svg');
      }
      nav.style.boxShadow = window.scrollY>10 ? '0 1px 0 rgba(0,0,0,.04)' : 'none';
    };
    window.addEventListener('scroll', onScroll, {passive:true}); onScroll();
  }

  // online shop dropdown — click/tap toggle (hover works via CSS on desktop)
  var shop = document.getElementById('navShop');
  if(shop){
    var sbtn = shop.querySelector('.nav-shop-btn');
    sbtn.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      shop.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
      if(!shop.contains(e.target)) shop.classList.remove('open');
    });
  }

  // mobile burger -> simple anchor reveal (links already exist; toggle a panel)
  var burger = document.querySelector('.nav-burger');
  if(burger){
    burger.addEventListener('click', function(){
      var links = document.querySelector('.nav-links');
      if(!links) return;
      var open = links.style.display === 'flex';
      links.style.cssText = open ? '' : 'display:flex;position:absolute;top:var(--nav-h);left:0;right:0;flex-direction:column;background:var(--white);padding:24px var(--pad);gap:18px;border-bottom:1px solid var(--line)';
    });
  }

  // design-direction switcher (review aid — remove before WordPress migration)
  var sw = document.getElementById('switcher');
  if(sw){
    var here = location.pathname.split('/').pop() || 'home-a.html';
    var defs = [['home-a.html','A'],['home-b.html','B'],['home-c.html','C']];
    sw.innerHTML = '<div class="sw-label">首頁デザイン案</div>' + defs.map(function(d){
      var on = here===d[0] ? ' on':'';
      return '<a class="sw-btn'+on+'" href="'+d[0]+'">'+d[1]+'</a>';
    }).join('');
    var st = document.createElement('style');
    st.textContent = '#switcher{position:fixed;right:18px;bottom:18px;z-index:200;background:rgba(26,24,21,.92);backdrop-filter:blur(8px);border-radius:40px;padding:8px 10px;display:flex;align-items:center;gap:7px;box-shadow:0 8px 30px rgba(0,0,0,.18)}'+
      '#switcher .sw-label{font-family:var(--mincho);font-size:11px;color:rgba(245,243,239,.6);padding:0 8px 0 6px;letter-spacing:.08em}'+
      '#switcher .sw-btn{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-family:var(--latin);font-size:12px;font-weight:600;color:rgba(245,243,239,.7);transition:.25s}'+
      '#switcher .sw-btn:hover{background:rgba(255,255,255,.14);color:#fff}'+
      '#switcher .sw-btn.on{background:#f5f3ef;color:#1a1815}'+
      '@media(max-width:560px){#switcher .sw-label{display:none}}';
    document.head.appendChild(st);
  }
})();
