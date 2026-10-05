<?php
// Rend la vue de réception hors-ligne en HTML statique (aucune base de données nécessaire)
define('BASE_PATH', dirname(__DIR__, 2));
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
include BASE_PATH . '/app/Views/offline/reception.php';
