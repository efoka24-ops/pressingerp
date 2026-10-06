/* Outils communs chargés dans <head>, avant le contenu : les scripts des pages s'en servent dès leur exécution.
   (app.js, lui, est chargé en fin de page et ne doit rien définir dont une page a besoin au chargement.) */
(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf"]');
  window.CSRF = csrf ? csrf.content : '';
  window.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  window.debounce = function (fn, ms) {
    var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); };
  };
})();
