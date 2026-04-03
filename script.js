(function () {
  'use strict';

  // Navbar: scroll class + initial slide-down animation
  var navbar = document.getElementById('navbar');
  if (navbar) {
    function onScroll() {
      if (window.scrollY > 20) navbar.classList.add('scrolled');
      else navbar.classList.remove('scrolled');
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    requestAnimationFrame(function () {
      navbar.classList.remove('navbar-initial');
      navbar.classList.add('navbar-visible');
    });
  }

  // Mobile menu toggle
  var menuBtn = document.getElementById('mobile-menu-btn');
  var mobileMenu = document.getElementById('mobile-menu');
  var iconUse = document.getElementById('icon-menu-use');
  if (menuBtn && mobileMenu) {
    menuBtn.addEventListener('click', function () {
      var open = mobileMenu.classList.toggle('open');
      if (iconUse) iconUse.setAttribute('href', open ? '#icon-x' : '#icon-menu');
    });
    document.querySelectorAll('.nav-mobile-link').forEach(function (a) {
      a.addEventListener('click', function () { mobileMenu.classList.remove('open'); if (iconUse) iconUse.setAttribute('href', '#icon-menu'); });
    });
  }

  // Scroll-triggered fade-up / fade-left (Intersection Observer)
  var observerOpts = { root: null, rootMargin: '0px 0px -60px 0px', threshold: 0.1 };
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        var parent = entry.target.closest('[data-fade]');
        if (parent && !parent.classList.contains('visible')) parent.classList.add('visible');
      }
    });
  }, observerOpts);

  document.querySelectorAll('[data-fade]').forEach(function (el) { observer.observe(el); });
  document.querySelectorAll('[data-fade-left]').forEach(function (el) { observer.observe(el); });

  // Hero form submit
  var heroForm = document.getElementById('hero-form');
  var heroEmail = document.getElementById('hero-email');
  var heroSubmit = document.getElementById('hero-submit');
  var heroStatus = document.getElementById('hero-status');
  var apiBase = window.REAMAI_API_URL || '';

  function setStatus(el, type, text) {
    if (!el) return;
    el.textContent = text;
    el.className = 'hero-status ' + type;
    el.style.display = 'block';
  }

  function submitEmail(form, emailEl, submitBtn, statusEl, source) {
    var email = (emailEl && emailEl.value) ? emailEl.value.trim() : '';
    if (!email) return;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';
    fetch(apiBase + '/api/subscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email, source: source })
    })
      .then(function (res) {
        if (res.status === 409) {
          setStatus(statusEl, 'exists', "This email is already subscribed.");
        } else if (res.ok) {
          setStatus(statusEl, 'success', "You're on the list! We'll be in touch soon.");
          if (emailEl) emailEl.value = '';
        } else {
          setStatus(statusEl, 'error', 'Something went wrong. Please try again.');
        }
      })
      .catch(function () {
        setStatus(statusEl, 'error', 'Something went wrong. Please try again.');
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Get Early Access <svg width="16" height="16"><use href="#icon-arrow-right"/></svg>';
        if (statusEl) setTimeout(function () { statusEl.style.display = 'none'; statusEl.textContent = ''; }, 6000);
      });
  }

  if (heroForm && heroEmail && heroSubmit && heroStatus) {
    heroForm.addEventListener('submit', function (e) {
      e.preventDefault();
      submitEmail(heroForm, heroEmail, heroSubmit, heroStatus, 'hero');
    });
  }

  // CTA form submit
  var ctaForm = document.getElementById('cta-form');
  var ctaEmail = document.getElementById('cta-email');
  var ctaSubmit = document.getElementById('cta-submit');
  var ctaStatus = document.getElementById('cta-status');
  if (ctaForm && ctaEmail && ctaSubmit && ctaStatus) {
    ctaForm.addEventListener('submit', function (e) {
      e.preventDefault();
      submitEmail(ctaForm, ctaEmail, ctaSubmit, ctaStatus, 'cta');
    });
  }

  // Footer year
  var yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = new Date().getFullYear();
})();
