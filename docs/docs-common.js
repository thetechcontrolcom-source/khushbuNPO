/**
 * GMS Documentation — Shared Interactive Features
 * Mobile navigation, scroll effects, animations, keyboard shortcuts
 */
(function(){
  'use strict';

  // ── Mobile Navigation ──────────────────────────────────────
  const hamburger = document.querySelector('.nav-hamburger');
  const mobileNav = document.querySelector('.mobile-nav');
  const mobileOverlay = document.querySelector('.mobile-nav-overlay');
  const mobileClose = document.querySelector('.mobile-nav-close');

  function openMobileNav(){
    if(!mobileNav) return;
    mobileNav.classList.add('active');
    mobileOverlay.classList.add('active');
    hamburger.classList.add('active');
    document.body.classList.add('nav-open');
    // Trap focus inside mobile nav
    const firstFocusable = mobileNav.querySelector('a, button');
    if(firstFocusable) setTimeout(function(){ firstFocusable.focus(); }, 100);
  }

  function closeMobileNav(){
    if(!mobileNav) return;
    mobileNav.classList.remove('active');
    mobileOverlay.classList.remove('active');
    hamburger.classList.remove('active');
    document.body.classList.remove('nav-open');
    if(hamburger) hamburger.focus();
  }

  if(hamburger) hamburger.addEventListener('click', function(){
    mobileNav.classList.contains('active') ? closeMobileNav() : openMobileNav();
  });
  if(mobileOverlay) mobileOverlay.addEventListener('click', closeMobileNav);
  if(mobileClose) mobileClose.addEventListener('click', closeMobileNav);

  // Close mobile nav on Escape
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && mobileNav && mobileNav.classList.contains('active')){
      closeMobileNav();
    }
  });

  // Close mobile nav on resize to desktop
  var resizeTimer;
  window.addEventListener('resize', function(){
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function(){
      if(window.innerWidth > 768 && mobileNav && mobileNav.classList.contains('active')){
        closeMobileNav();
      }
    }, 150);
  });


  // ── Scroll Progress Bar ────────────────────────────────────
  var progressBar = document.querySelector('.scroll-progress');
  if(!progressBar){
    progressBar = document.createElement('div');
    progressBar.className = 'scroll-progress';
    document.body.prepend(progressBar);
  }


  // ── Nav Scroll Shadow ──────────────────────────────────────
  var topNav = document.querySelector('.top-nav');
  var lastScrollY = 0;
  var ticking = false;

  function onScroll(){
    lastScrollY = window.scrollY;
    if(!ticking){
      requestAnimationFrame(updateOnScroll);
      ticking = true;
    }
  }

  function updateOnScroll(){
    ticking = false;
    // Progress bar
    var scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
    if(scrollHeight > 0 && progressBar){
      var pct = (lastScrollY / scrollHeight) * 100;
      progressBar.style.width = pct + '%';
    }

    // Nav shadow
    if(topNav){
      if(lastScrollY > 10){
        topNav.classList.add('scrolled');
      } else {
        topNav.classList.remove('scrolled');
      }
    }

    // Back to top visibility
    var btt = document.querySelector('.back-to-top') || document.querySelector('.scroll-top');
    if(btt){
      if(lastScrollY > 400){
        btt.classList.add('visible');
      } else {
        btt.classList.remove('visible');
      }
    }
  }

  window.addEventListener('scroll', onScroll, {passive: true});
  // Initial state
  updateOnScroll();


  // ── Scroll-Triggered Animations (Intersection Observer) ────
  function setupScrollAnimations(){
    var animatedEls = document.querySelectorAll('.fade-in, .slide-in-left, .scale-in, .stagger-in, .section-card, .stat-card, .highlight-box');
    if(!animatedEls.length) return;

    // Add animation classes to section cards and stat cards that don't have one
    animatedEls.forEach(function(el){
      if((el.classList.contains('section-card') || el.classList.contains('stat-card') || el.classList.contains('highlight-box')) &&
         !el.classList.contains('fade-in') && !el.classList.contains('slide-in-left') && !el.classList.contains('scale-in')){
        el.classList.add('fade-in');
      }
    });

    // Re-query after adding classes
    animatedEls = document.querySelectorAll('.fade-in, .slide-in-left, .scale-in, .stagger-in');

    if('IntersectionObserver' in window){
      var observer = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
          if(entry.isIntersecting){
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      }, {threshold: 0.1, rootMargin: '0px 0px -40px 0px'});

      animatedEls.forEach(function(el){
        observer.observe(el);
      });
    } else {
      // Fallback: show everything
      animatedEls.forEach(function(el){ el.classList.add('visible'); });
    }
  }

  // Run after DOM is ready
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', setupScrollAnimations);
  } else {
    setupScrollAnimations();
  }


  // ── Back-to-Top Button ─────────────────────────────────────
  var backToTop = document.querySelector('.back-to-top');
  if(backToTop && !backToTop.hasAttribute('data-bound')){
    backToTop.setAttribute('data-bound', '1');
    backToTop.addEventListener('click', function(){
      window.scrollTo({top: 0, behavior: 'smooth'});
    });
  }


  // ── Active Sidebar Link Tracking ───────────────────────────
  function setupSidebarTracking(){
    var sidebar = document.querySelector('.sidebar');
    if(!sidebar) return;

    var sidebarLinks = sidebar.querySelectorAll('.sidebar-nav a[href^="#"]');
    if(!sidebarLinks.length) return;

    var sections = [];
    sidebarLinks.forEach(function(link){
      var id = link.getAttribute('href').replace('#','');
      var section = document.getElementById(id);
      if(section) sections.push({el: section, link: link});
    });

    if(!sections.length) return;

    var sidebarObserver = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if(entry.isIntersecting){
          sidebarLinks.forEach(function(l){ l.classList.remove('active'); });
          var match = sections.find(function(s){ return s.el === entry.target; });
          if(match) match.link.classList.add('active');
        }
      });
    }, {threshold: 0.2, rootMargin: '-80px 0px -60% 0px'});

    sections.forEach(function(s){ sidebarObserver.observe(s.el); });
  }

  setupSidebarTracking();


  // ── Keyboard Shortcuts ─────────────────────────────────────
  document.addEventListener('keydown', function(e){
    // Only trigger if no input/textarea is focused
    var tag = document.activeElement.tagName.toLowerCase();
    if(tag === 'input' || tag === 'textarea' || tag === 'select' || document.activeElement.isContentEditable) return;

    // ? — Show keyboard shortcuts (alt+/)
    if(e.key === '/' && e.altKey){
      e.preventDefault();
      showKeyboardHelp();
      return;
    }

    // Home — scroll to top
    if(e.key === 'Home' && !e.ctrlKey && !e.metaKey){
      e.preventDefault();
      window.scrollTo({top: 0, behavior: 'smooth'});
    }

    // End — scroll to bottom
    if(e.key === 'End' && !e.ctrlKey && !e.metaKey){
      e.preventDefault();
      window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});
    }
  });

  function showKeyboardHelp(){
    var existing = document.getElementById('kbd-help-modal');
    if(existing){ existing.remove(); return; }

    var modal = document.createElement('div');
    modal.id = 'kbd-help-modal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);animation:fadeIn .2s ease';
    modal.innerHTML = '<div style="background:#fff;border-radius:16px;padding:28px;max-width:360px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative">' +
      '<button onclick="this.closest(\'#kbd-help-modal\').remove()" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center" aria-label="Close">&times;</button>' +
      '<h3 style="margin:0 0 16px;font-size:16px;display:flex;align-items:center;gap:8px"><i class="fa-solid fa-keyboard" style="color:#3b82f6"></i> Keyboard Shortcuts</h3>' +
      '<div style="display:grid;gap:8px;font-size:13px">' +
      shortcutRow('Home', 'Scroll to top') +
      shortcutRow('End', 'Scroll to bottom') +
      shortcutRow('Alt + /', 'Toggle this help') +
      shortcutRow('Esc', 'Close menu / modal') +
      '</div></div>';

    document.body.appendChild(modal);
    modal.addEventListener('click', function(e){
      if(e.target === modal) modal.remove();
    });
    document.addEventListener('keydown', function closeKbd(e){
      if(e.key === 'Escape'){ modal.remove(); document.removeEventListener('keydown', closeKbd); }
    });
  }

  function shortcutRow(key, desc){
    return '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9">' +
      '<span style="color:#64748b">' + desc + '</span>' +
      '<kbd style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:700;color:#1e293b;font-family:inherit;min-width:32px;text-align:center">' + key + '</kbd>' +
      '</div>';
  }


  // ── Touch-Friendly: Prevent 300ms Delay ────────────────────
  // Modern browsers handle this, but set viewport meta as fallback
  var vpMeta = document.querySelector('meta[name="viewport"]');
  if(vpMeta && vpMeta.content.indexOf('user-scalable') === -1){
    // Don't add user-scalable=no — it's bad for accessibility
    // The width=device-width already removes the delay in modern browsers
  }


  // ── Smooth Sidebar Toggle on Tablet ────────────────────────
  // If a page has a sidebar toggle button
  var sidebarToggle = document.querySelector('.sidebar-toggle');
  var sidebar = document.querySelector('.sidebar');
  if(sidebarToggle && sidebar){
    sidebarToggle.addEventListener('click', function(){
      sidebar.classList.toggle('sidebar-open');
    });
  }


  // ── Copy code blocks on click (if any exist) ──────────────
  document.addEventListener('click', function(e){
    var code = e.target.closest('pre > code, .code-block');
    if(!code) return;
    var text = code.textContent;
    if(navigator.clipboard){
      navigator.clipboard.writeText(text).then(function(){
        var tip = document.createElement('span');
        tip.textContent = 'Copied!';
        tip.style.cssText = 'position:fixed;top:80px;right:20px;background:#10b981;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;animation:fadeIn .2s ease';
        document.body.appendChild(tip);
        setTimeout(function(){ tip.remove(); }, 1500);
      });
    }
  });


  // ── Performance: Lazy-load off-screen images ───────────────
  if('IntersectionObserver' in window){
    var imgObserver = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if(entry.isIntersecting){
          var img = entry.target;
          if(img.dataset.src){
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
          }
          imgObserver.unobserve(img);
        }
      });
    });
    document.querySelectorAll('img[data-src]').forEach(function(img){ imgObserver.observe(img); });
  }

})();
