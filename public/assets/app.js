(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf"]');
  window.CSRF = csrf ? csrf.content : '';

  // Confirmation avant action sensible
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Soumission automatique des filtres
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  // Raccourcis comptoir : F1 nouvelle commande, F2 recherche/retrait
  document.addEventListener('keydown', function (e) {
    if (e.key === 'F1') { e.preventDefault(); location.href = '/commandes/nouvelle'; }
    if (e.key === 'F2') { e.preventDefault(); var s = document.querySelector('.top input[name=q]'); if (s) s.focus(); }
  });

  // Affiche / masque un bloc selon une case à cocher : data-toggle="#id"
  document.querySelectorAll('[data-toggle]').forEach(function (el) {
    var target = document.querySelector(el.getAttribute('data-toggle'));
    if (!target) return;
    var sync = function () { target.hidden = !el.checked; };
    el.addEventListener('change', sync); sync();
  });

  window.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  window.debounce = function (fn, ms) {
    var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); };
  };
})();

// Commande enregistrée : le brouillon local n'a plus lieu d'être
try { if (/[?&]etiquettes=1/.test(location.search)) localStorage.removeItem('order-draft'); } catch (e) {}
