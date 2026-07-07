document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.tcm-tabs').forEach(function (tabs) {
    const buttons = tabs.querySelectorAll('.tcm-tab');
    // Certains panneaux (Cours/Historique) se retrouvent hors de .tcm-tabs dans le
    // DOM (le HTML de la section inscriptions casse l'imbrication) : on cherche donc
    // les panneaux dans toute la fiche pour pouvoir les activer au clic.
    const panelScope = tabs.closest('.tcm-fiche') || tabs.parentElement || document;
    const panels = panelScope.querySelectorAll('.tcm-tabpanel');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        const target = button.getAttribute('data-tab');
        buttons.forEach(function (btn) { btn.classList.remove('is-active'); });
        button.classList.add('is-active');
        panels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.id === target);
        });
        // Refléter l'onglet actif dans l'URL + les liens de la liste (master-detail),
        // pour qu'il soit conservé au rechargement et en passant d'un adhérent à l'autre.
        var slug = (target || '').replace('tcm-tab-', '');
        try {
          var u = new URL(window.location.href);
          u.searchParams.set('tab', slug);
          history.replaceState(null, '', u.toString());
        } catch (e) {}
        document.querySelectorAll('.tcm-crm-row').forEach(function (a) {
          try { var au = new URL(a.href); au.searchParams.set('tab', slug); a.href = au.toString(); } catch (e) {}
        });
      });
    });
  });
});

// Menu burger (sidebar repliable sur mobile).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.tcm-burger').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var side = btn.closest('.tcm-sidebar');
      if (!side) return;
      var open = side.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
});

// Tri des tableaux (classe .tcm-sortable) : clic sur un en-tête de colonne.
document.addEventListener('DOMContentLoaded', function () {
  function num(s) {
    var n = parseFloat(String(s).replace(/[^\d,.\-]/g, '').replace(/\s/g, '').replace(',', '.'));
    return isNaN(n) ? null : n;
  }
  function dateFr(s) {
    var m = String(s).trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    return m ? (m[3] + m[2] + m[1]) : null;
  }
  function cmp(a, b) {
    var da = dateFr(a), db = dateFr(b);
    if (da && db) return da.localeCompare(db);
    var na = num(a), nb = num(b);
    if (na !== null && nb !== null) return na - nb;
    return a.localeCompare(b, 'fr', { sensitivity: 'base' });
  }
  document.querySelectorAll('table.tcm-sortable').forEach(function (table) {
    var ths = table.querySelectorAll('thead th');
    ths.forEach(function (th, idx) {
      if (th.classList.contains('tcm-no-sort')) return;
      th.classList.add('tcm-th-sort');
      th.addEventListener('click', function () {
        var tbody = table.tBodies[0];
        if (!tbody) return;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var asc = th.getAttribute('data-asc') !== 'true';
        ths.forEach(function (h) { h.removeAttribute('data-asc'); h.classList.remove('is-sorted-asc', 'is-sorted-desc'); });
        th.setAttribute('data-asc', asc ? 'true' : 'false');
        th.classList.add(asc ? 'is-sorted-asc' : 'is-sorted-desc');
        rows.sort(function (r1, r2) {
          var a = (r1.children[idx] ? r1.children[idx].textContent : '').trim();
          var b = (r2.children[idx] ? r2.children[idx].textContent : '').trim();
          return asc ? cmp(a, b) : cmp(b, a);
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
      });
    });
  });
});

// Calculette (section Commandes).
document.addEventListener('DOMContentLoaded', function () {
  var back = document.getElementById('tcm-calc');
  if (!back) return;
  var exprEl = back.querySelector('.tcm-calc-expr');
  var valEl = back.querySelector('.tcm-calc-val');
  var s = '';
  function fmt(x) { if (!isFinite(x)) return 'Erreur'; return (Math.round(x * 1e6) / 1e6).toString().replace('.', ','); }
  function evalExpr(str) { if (!/^[-0-9.+*/% ()]+$/.test(str)) throw 0; return Function('return (' + str + ')')(); }
  function current() { try { if (!s) return '0'; var v = evalExpr(s); return isFinite(v) ? fmt(v) : '0'; } catch (e) { return valEl.textContent || '0'; } }
  function render() {
    exprEl.textContent = s.replace(/\*/g, '×').replace(/\//g, '÷').replace(/-/g, '−').replace(/\./g, ',');
    valEl.textContent = current();
  }
  function press(k) {
    if (k === 'C') { s = ''; }
    else if (k === 'back') { s = s.slice(0, -1); }
    else if (k === '=') {
      try { var v = evalExpr(s); if (!isFinite(v)) throw 0; s = fmt(v).replace(',', '.'); }
      catch (e) { valEl.textContent = 'Erreur'; return; }
    } else { s += k; }
    render();
  }
  back.querySelectorAll('.tcm-calc-keys button').forEach(function (b) {
    b.addEventListener('click', function () { press(b.getAttribute('data-k')); });
  });
  function open() { back.classList.add('is-open'); back.setAttribute('aria-hidden', 'false'); s = ''; render(); }
  function close() { back.classList.remove('is-open'); back.setAttribute('aria-hidden', 'true'); }
  document.addEventListener('click', function (e) {
    if (e.target.closest('.tcm-calc-open')) { open(); }
    else if (e.target === back || e.target.closest('.tcm-calc-close')) { close(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  render();
});
