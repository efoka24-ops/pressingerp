<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layout'): void
    {
        $content = self::capture($view, $data);
        echo $layout === null ? $content : self::capture($layout, $data + ['content' => $content]);
    }

    public static function capture(string $view, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Vue introuvable : $view");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
