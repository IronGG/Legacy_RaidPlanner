// Comptes à rebours + rafraîchissement live des salles et des wars (polling léger) + déplacements + confirmations.
(function () {
  'use strict';

  const POLL_MS = parseInt(document.body.dataset.poll || '4000', 10);
  let refreshNow = null;
  let picked = null;      // joueur sélectionné pour un déplacement
  let dragging = false;

  // Pas de rafraîchissement pendant qu'on déplace un joueur, qu'un menu est ouvert ou qu'on tape dans un champ
  function interacting(live) {
    const f = document.activeElement;
    return dragging || !!picked || !!live.querySelector('details[open] form')
      || !!(f && live.contains(f) && /^(INPUT|TEXTAREA|SELECT)$/.test(f.tagName));
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
            // Les listes dépliées (data-keep) restent dépliées après la mise à jour
            const keep = Array.from(live.querySelectorAll('details[open][data-keep]')).map(function (d) { return d.dataset.keep; });
            live.innerHTML = await p.text();
            keep.forEach(function (k) { const d = live.querySelector('details[data-keep="' + k + '"]'); if (d) d.open = true; });
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
    setInterval(check, parseInt(live.dataset.poll || '', 10) || POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(false); });
  }

  // Déplacer les joueurs : dans une salle (chef de salle et officiers) ou dans les troupes d'une war (officiers).
  // Glisser-déposer, ou clic sur un joueur puis clic sur une place (marche aussi au doigt).
  const board = document.querySelector('.room[data-action], .war[data-action]');
  const isWar = !!board && board.classList.contains('war');
  const SLOT = isWar ? '[data-slot]' : '[data-pos]';            // une place
  const POOL = isWar ? '[data-pool-drop]' : '[data-reserve-drop]'; // « À placer » (war) ou la réserve (salle)
  const TARGET = SLOT + ', ' + POOL;
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
    if (board) board.classList.remove('picking');
  }

  function pick(el) {
    unpick();
    picked = el;
    el.classList.add('is-picked');
    board.classList.add('picking');
  }

  async function post(fd) {
    try {
      const r = await fetch(board.dataset.action, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' }
      });
      const j = await r.json();
      say(j.message, j.ok);
    } catch (e) {
      say("Le changement n'a pas pu être enregistré. Vérifie ta connexion.", false);
    }
    if (refreshNow) refreshNow();
  }

  function send(fields) {
    const fd = new FormData();
    fd.append('_csrf', board.dataset.csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    unpick();
    return post(fd);
  }

  function isPlaced(el) { return el.matches(SLOT); }

  function moveTo(memberEl, target) {
    const id = memberEl.dataset.member;
    if (target.matches(SLOT)) {
      if (target === memberEl) return unpick();
      return send(isWar ? { action: 'place', member_id: id, group_id: target.dataset.group, slot: target.dataset.slot }
                        : { action: 'place', member_id: id, position: target.dataset.pos });
    }
    if (target.matches(POOL) && isPlaced(memberEl)) {
      return send(isWar ? { action: 'unplace', member_id: id } : { action: 'move', to: 'reserve', member_id: id });
    }
    unpick();
  }

  if (board) {
    board.addEventListener('click', function (ev) {
      if (ev.target.closest('button, a, input, select, textarea, summary, details, form')) return;
      const member = ev.target.closest('[data-member]');
      const target = ev.target.closest(TARGET);
      if (picked) {
        if (member === picked) return unpick();
        if (target && target.matches(SLOT)) return moveTo(picked, target);
        if (target && target.matches(POOL) && isPlaced(picked)) return moveTo(picked, target);
        if (member) return pick(member);
        return unpick();
      }
      if (member) pick(member);
    });

    board.addEventListener('dragstart', function (ev) {
      const m = ev.target.closest && ev.target.closest('[data-member]');
      if (!m) return;
      dragging = true;
      pick(m);
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', m.dataset.member);
    });
    board.addEventListener('dragover', function (ev) {
      if (!dragging) return;
      const t = ev.target.closest(TARGET);
      board.querySelectorAll('.drop-hover').forEach(function (el) { if (el !== t) el.classList.remove('drop-hover'); });
      if (!t) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      t.classList.add('drop-hover');
    });
    board.addEventListener('drop', function (ev) {
      const t = ev.target.closest(TARGET);
      if (!dragging || !t || !picked) return;
      ev.preventDefault();
      dragging = false;
      t.classList.remove('drop-hover');
      moveTo(picked, t);
    });
    board.addEventListener('dragend', function () {
      dragging = false;
      board.classList.remove('drop-out');
      board.querySelectorAll('.drop-hover').forEach(function (el) { el.classList.remove('drop-hover'); });
      if (picked) unpick();
    });

    // War : un joueur placé lâché n'importe où hors des places (marges, en-têtes, autre partie de la page)
    // sort de sa troupe et repasse dans « À placer ».
    if (isWar) {
      const outside = function (ev) {
        const el = ev.target instanceof Element ? ev.target : null;
        return dragging && picked && isPlaced(picked) && !(el && el.closest(TARGET));
      };
      document.addEventListener('dragover', function (ev) {
        if (!outside(ev)) { board.classList.remove('drop-out'); return; }
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'move';
        board.classList.add('drop-out');
      });
      document.addEventListener('drop', function (ev) {
        if (!outside(ev)) return;
        ev.preventDefault();
        dragging = false;
        board.classList.remove('drop-out');
        send({ action: 'unplace', member_id: picked.dataset.member });
      });
    }
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') unpick(); });
  }

  // Formulaire de salle : les champs propres au raid disparaissent pour un donjon
  function syncKind(form) {
    const k = form.querySelector('input[name="kind"]:checked');
    const donjon = !!k && k.value === 'donjon';
    form.querySelectorAll('[data-raid-only]').forEach(function (el) { el.hidden = donjon; });
  }
  document.querySelectorAll('form').forEach(function (f) { if (f.querySelector('input[name="kind"]')) syncKind(f); });
  document.addEventListener('change', function (ev) {
    if (ev.target.name === 'kind' && ev.target.form) syncKind(ev.target.form);
  });

  // Confirmation avant les actions destructrices (attribut data-confirm sur le formulaire)
  document.addEventListener('submit', function (ev) {
    const btn = ev.submitter;
    const msg = (btn && btn.dataset && btn.dataset.confirm) || (ev.target.dataset ? ev.target.dataset.confirm : null);
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  // Formulaires marqués data-ajax : envoyés sans recharger la page (après la confirmation ci-dessus)
  document.addEventListener('submit', function (ev) {
    const f = ev.target;
    if (ev.defaultPrevented || !board || !f.matches('form[data-ajax]') || !board.contains(f)) return;
    ev.preventDefault();
    const fd = new FormData(f);
    if (ev.submitter && ev.submitter.name) fd.append(ev.submitter.name, ev.submitter.value);
    const d = f.closest('details');
    if (d) d.open = false;
    unpick();
    post(fd);
  });
})();
