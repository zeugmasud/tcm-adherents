document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.tcm-tabs').forEach(function (tabs) {
    const buttons = tabs.querySelectorAll('.tcm-tab');
    const panels = tabs.querySelectorAll('.tcm-tabpanel');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        const target = button.getAttribute('data-tab');
        buttons.forEach(function (btn) { btn.classList.remove('is-active'); });
        button.classList.add('is-active');
        panels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.id === target);
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
