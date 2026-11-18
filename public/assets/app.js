// Comptes à rebours + rafraîchissement live des salles (polling léger) + confirmations.
(function () {
  'use strict';

  const POLL_MS = parseInt(document.body.dataset.poll || '4000', 10);
  let refreshNow = null;
  let picked = null;      // joueur sélectionné pour un déplacement
  let dragging = false;

  // Pas de rafraîchissement pendant qu'un chef déplace un joueur ou choisit un rôle
  function interacting(live) {
    return dragging || !!picked || !!live.querySelector('details[open]');
  }

  function pad(n) { return String(n).padStart(2, '0'); }
  function fmtLeft(s) {
    if (s <= 0) return '0:00';
    if (s >= 86400) return Math.floor(s / 86400) + ' j ' + Math.floor((s % 86400) / 3600) + ' h';
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
      if (busy || (!force && (document.hidden || interacting(live)))) return;
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

  // Déplacer les joueurs dans une salle (chef de salle et officiers) :
  // glisser-déposer, ou clic sur un joueur puis clic sur une place (marche aussi au doigt).
  const room = document.querySelector('.room[data-action]');
  const toast = document.querySelector('.toast');
  let toastTimer = null;

  function say(msg, ok) {
    if (!toast) return;
    toast.textContent = msg;
    toast.className = 'toast ' + (ok ? 'toast-ok' : 'toast-error');
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.hidden = true; }, 4500);
  }

  function unpick() {
    if (picked) picked.classList.remove('is-picked');
    picked = null;
    if (room) room.classList.remove('picking');
  }

  function pick(el) {
    unpick();
    picked = el;
    el.classList.add('is-picked');
    room.classList.add('picking');
  }

  async function send(fields) {
    const fd = new FormData();
    fd.append('_csrf', room.dataset.csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    unpick();
    try {
      const r = await fetch(room.dataset.action, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' }
      });
      const j = await r.json();
      say(j.message, j.ok);
    } catch (e) {
      say("Le déplacement n'a pas pu être enregistré. Vérifie ta connexion.", false);
    }
    if (refreshNow) refreshNow();
  }

  function moveTo(memberEl, target) {
    const fromReserve = !memberEl.hasAttribute('data-pos');
    if (target.hasAttribute('data-pos')) {
      if (target === memberEl) return unpick();
      return send({ action: 'place', member_id: memberEl.dataset.member, position: target.dataset.pos });
    }
    if (target.hasAttribute('data-reserve-drop') && !fromReserve) {
      return send({ action: 'move', to: 'reserve', member_id: memberEl.dataset.member });
    }
    unpick();
  }

  if (room) {
    room.addEventListener('click', function (ev) {
      if (ev.target.closest('button, a, input, select, textarea, summary, details, form')) return;
      const member = ev.target.closest('[data-member]');
      const target = ev.target.closest('[data-pos], [data-reserve-drop]');
      if (picked) {
        if (member === picked) return unpick();
        const pickedInReserve = !picked.hasAttribute('data-pos');
        if (target && target.hasAttribute('data-pos')) return moveTo(picked, target);
        if (target && target.hasAttribute('data-reserve-drop') && !pickedInReserve) return moveTo(picked, target);
        if (member) return pick(member);
        return unpick();
      }
      if (member) pick(member);
    });

    room.addEventListener('dragstart', function (ev) {
      const m = ev.target.closest && ev.target.closest('[data-member]');
      if (!m) return;
      dragging = true;
      pick(m);
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', m.dataset.member);
    });
    room.addEventListener('dragover', function (ev) {
      if (!dragging) return;
      const t = ev.target.closest('[data-pos], [data-reserve-drop]');
      room.querySelectorAll('.drop-hover').forEach(function (el) { if (el !== t) el.classList.remove('drop-hover'); });
      if (!t) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      t.classList.add('drop-hover');
    });
    room.addEventListener('drop', function (ev) {
      const t = ev.target.closest('[data-pos], [data-reserve-drop]');
      if (!dragging || !t || !picked) return;
      ev.preventDefault();
      dragging = false;
      t.classList.remove('drop-hover');
      moveTo(picked, t);
    });
    room.addEventListener('dragend', function () {
      dragging = false;
      room.querySelectorAll('.drop-hover').forEach(function (el) { el.classList.remove('drop-hover'); });
      if (picked) unpick();
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') unpick(); });
  }

  // Confirmation avant les actions destructrices (attribut data-confirm sur le formulaire)
  document.addEventListener('submit', function (ev) {
    const btn = ev.submitter;
    const msg = (btn && btn.dataset && btn.dataset.confirm) || (ev.target.dataset ? ev.target.dataset.confirm : null);
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });
})();
