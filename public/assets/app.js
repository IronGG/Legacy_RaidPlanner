// Comptes à rebours + rafraîchissement live des salles (polling léger) + confirmations.
(function () {
  'use strict';

  const POLL_MS = parseInt(document.body.dataset.poll || '4000', 10);
  let refreshNow = null;

  function pad(n) { return String(n).padStart(2, '0'); }
  function fmtLeft(s) {
    if (s <= 0) return '0:00';
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return h ? h + ':' + pad(m) + ':' + pad(sec) : m + ':' + pad(sec);
  }

  function tick() {
    const now = Date.now() / 1000;
    document.querySelectorAll('[data-deadline]').forEach(function (el) {
      const end = Number(el.dataset.deadline), start = Number(el.dataset.start || 0);
      const left = Math.max(0, Math.round(end - now));
      const out = el.querySelector('[data-left]');
      if (out) out.textContent = fmtLeft(left);
      const bar = el.querySelector('[data-bar]');
      if (bar && start && end > start) {
        bar.style.width = Math.max(0, Math.min(100, ((end - now) / (end - start)) * 100)) + '%';
      }
      // Fin du délai : on recharge l'affichage pour passer en phase « remplacements »
      if (left === 0 && !el.dataset.expired) {
        el.dataset.expired = '1';
        if (refreshNow) setTimeout(refreshNow, 600);
      }
    });
  }
  tick();
  setInterval(tick, 1000);

  const live = document.querySelector('[data-live]');
  if (live) {
    let version = String(live.dataset.version);
    let busy = false;

    async function check(force) {
      if (busy || (document.hidden && !force)) return;
      busy = true;
      try {
        const r = await fetch(live.dataset.versionUrl, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (r.status === 401) { location.reload(); return; }
        if (!r.ok) return;
        const j = await r.json();
        if (force || String(j.v) !== version) {
          const p = await fetch(live.dataset.partialUrl, { cache: 'no-store', credentials: 'same-origin' });
          if (p.ok) {
            live.innerHTML = await p.text();
            version = String(j.v);
            tick();
          }
        }
      } catch (e) {
        // réseau coupé : on réessaiera au prochain tour
      } finally {
        busy = false;
      }
    }

    refreshNow = function () { check(true); };
    setInterval(check, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(false); });
  }

  // Confirmation avant les actions destructrices (attribut data-confirm sur le formulaire)
  document.addEventListener('submit', function (ev) {
    const btn = ev.submitter;
    const msg = (btn && btn.dataset && btn.dataset.confirm) || (ev.target.dataset ? ev.target.dataset.confirm : null);
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });
})();
