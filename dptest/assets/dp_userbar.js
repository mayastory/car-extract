// assets/dp_userbar.js
(function () {
  if (window.__dpUserbarInit) return;
  window.__dpUserbarInit = true;

  function qs(id){ return document.getElementById(id); }

  function openAdmin() {
    const bd = qs('dpAdminBackdrop');
    const md = qs('dpAdminModal');
    const ifr = qs('dpAdminIframe');
    if (!bd || !md || !ifr) return;

    const base = ifr.getAttribute('src') || 'admin_settings';
    ifr.src = base.split('?')[0] + '?ts=' + Date.now();

    bd.hidden = false;
    md.hidden = false;
  }

  function closeAdmin() {
    const bd = qs('dpAdminBackdrop');
    const md = qs('dpAdminModal');
    if (!bd || !md) return;
    bd.hidden = true;
    md.hidden = true;
  }

  document.addEventListener('click', function (e) {
    const openBtn = e.target.closest('[data-dp-admin-open="1"]');
    if (openBtn) { e.preventDefault(); openAdmin(); return; }

    const closeBtn = e.target.closest('[data-dp-admin-close="1"]');
    if (closeBtn) { e.preventDefault(); closeAdmin(); return; }

    if (e.target && e.target.id === 'dpAdminBackdrop') closeAdmin();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAdmin();
  });
})();