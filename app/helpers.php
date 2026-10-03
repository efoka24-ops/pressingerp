<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;

function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function money(int|float|string|null $v, bool $unit = false): string
{
    $s = number_format((float)$v, 0, ',', "\u{202F}");
    return $unit ? $s . "\u{00A0}FCFA" : $s;
}

function short_money(int|float $v): string
{
    return abs($v) >= 1_000_000 ? str_replace('.', ',', (string)round($v / 1_000_000, 2)) . ' M' : money($v);
}

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(?string $type = null, ?string $msg = null): ?array
{
    if ($type !== null) {
        $_SESSION['_flash'] = ['type' => $type, 'msg' => $msg];
        return null;
    }
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function can(string $module): bool
{
    return Auth::can($module);
}

function partial(string $view, array $data = []): string
{
    return View::capture($view, $data);
}

function dt(?string $v, string $format = 'd/m/Y H:i'): string
{
    return $v ? date($format, strtotime($v)) : '—';
}

/** Date courte en français : "auj. 14:00", "demain 10:00", "lun. 05/10 17:00" */
function fdate(?string $v, bool $time = true): string
{
    if (!$v) {
        return '—';
    }
    $t = strtotime($v);
    $days = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    $label = match (date('Y-m-d', $t)) {
        date('Y-m-d') => 'auj.',
        date('Y-m-d', strtotime('+1 day')) => 'demain',
        date('Y-m-d', strtotime('-1 day')) => 'hier',
        default => $days[(int)date('w', $t)] . ' ' . date('d/m', $t),
    };
    return $time ? $label . ' ' . date('H:i', $t) : $label;
}

function day_name(?int $t = null): string
{
    return ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w', $t ?? time())];
}

function month_name(string $ym): string
{
    $m = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $m[(int)substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);
}

function duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h ? sprintf('%d h %02d', $h, $m) : $m . ' min';
}

function since(?string $v): string
{
    return $v ? duration(time() - strtotime($v)) : '—';
}

/** Feu tricolore d'une commande selon sa date promise : red | orange | green | none */
function risk(?string $promisedAt, string $status = 'en_atelier'): string
{
    if (!$promisedAt || in_array($status, ['retire', 'livre', 'annule'], true)) {
        return 'none';
    }
    if ($status === 'pret') {
        return 'green';
    }
    $t = strtotime($promisedAt);
    if ($t < time()) {
        return 'red';
    }
    return $t < time() + 3600 * (int)Config::get('risk_orange_hours', 3) ? 'orange' : 'green';
}

function dot(string $tone): string
{
    return '<span class="dot ' . e($tone) . '"></span>';
}

function delta(float $value, float $ref): string
{
    if ($ref <= 0) {
        return '<span class="small muted">pas d\'historique</span>';
    }
    $d = (int)round(($value - $ref) * 100 / $ref);
    return $d >= 0
        ? '<span class="small green">▲ ' . $d . ' % vs moy.</span>'
        : '<span class="small red">▼ ' . abs($d) . ' % vs moy.</span>';
}

function pct(float $a, float $b, int $precision = 0): float
{
    return $b > 0 ? round($a * 100 / $b, $precision) : 0.0;
}

function initials(string $name): string
{
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out;
}

function tracking_url(string $token): string
{
    return rtrim((string)Config::get('app.url'), '/') . '/suivi/' . $token;
}

function client_tags(array $c): string
{
    $h = '';
    if (!empty($c['is_vip'])) {
        $h .= '<span class="tag vip">VIP</span>';
    }
    if (($c['type'] ?? $c['client_type'] ?? '') === 'pro') {
        $h .= '<span class="tag pro">PRO</span>';
    }
    return $h;
}

/** @return array{0:string,1:string} [libellé, ton] */
function payment_label(array $o): array
{
    if ((int)$o['on_account'] === 1) {
        return ['En compte', ''];
    }
    if ((int)$o['paid'] >= (int)$o['total']) {
        return ['Payé', 'green'];
    }
    return (int)$o['paid'] > 0 ? ['Acompte', 'orange'] : ['À payer', ''];
}

function event_label(string $action): string
{
    return match ($action) {
        'reception'       => 'Réception',
        'entree'          => 'Arrivée à l\'étape',
        'prise_en_charge' => 'Prise en charge',
        'termine'         => 'Terminé',
        'non_applicable'  => 'Étape non nécessaire',
        'incident'        => 'Incident',
        'incident_leve'   => 'Incident levé',
        'controle_ok'     => 'Contrôle conforme',
        'reprise'         => 'Renvoyé en reprise',
        'retrait'         => 'Retrait client',
        default           => ucfirst(str_replace('_', ' ', $action)),
    };
}

function selected(mixed $a, mixed $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(bool $v): string
{
    return $v ? ' checked' : '';
}
