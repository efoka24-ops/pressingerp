<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'layout'): void
    {
        View::render($view, $data, $layout);
    }

    protected function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Retour à la page précédente (chemin local uniquement) */
    protected function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $path = parse_url($ref, PHP_URL_PATH);
        $query = parse_url($ref, PHP_URL_QUERY);
        redirect(is_string($path) && str_starts_with($path, '/') ? $path . ($query ? '?' . $query : '') : $fallback);
    }

    /** Erreur de saisie : message + conservation des champs, puis retour. */
    protected function fail(string $message, ?string $to = null): never
    {
        $old = $_POST;
        unset($old['_csrf']);
        $_SESSION['_old'] = $old;
        flash('err', $message);
        $to !== null ? redirect($to) : $this->back();
    }

    protected function ok(string $message, string $to): never
    {
        unset($_SESSION['_old']);
        flash('ok', $message);
        redirect($to);
    }

    protected function int(string $key, int $default = 0): int
    {
        $v = input($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    protected function str(string $key, string $default = ''): string
    {
        $v = input($key);
        return is_string($v) ? $v : $default;
    }

    /** @return array<string,string> */
    protected function required(array $labels): array
    {
        $out = [];
        foreach ($labels as $key => $label) {
            $v = $this->str($key);
            if ($v === '') {
                $this->fail("Champ obligatoire : $label.");
            }
            $out[$key] = $v;
        }
        return $out;
    }
}
