<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array,perm:?string,csrf:bool}> */
    private array $routes = [];

    /** $perm : null = public, 'auth' = connecté, sinon code module (voir App\Domain\Module) */
    public function get(string $path, array $handler, ?string $perm = null): self
    {
        return $this->add('GET', $path, $handler, $perm);
    }

    public function post(string $path, array $handler, ?string $perm = null): self
    {
        return $this->add('POST', $path, $handler, $perm);
    }

    /** Webhook entrant : pas de session ni de CSRF, l'authenticité est vérifiée par signature dans le contrôleur. */
    public function webhook(string $path, array $handler): self
    {
        return $this->add('POST', $path, $handler, null, false);
    }

    private function add(string $method, string $path, array $handler, ?string $perm, bool $csrf = true): self
    {
        $path = rtrim($path, '/') ?: '/';
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'perm' => $perm, 'csrf' => $csrf];
        return $this;
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $uri = rtrim($uri, '/') ?: '/';
        $allowed = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $uri, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed = true;
                continue;
            }
            if ($method === 'POST' && $route['csrf']) {
                Csrf::verify();
            }
            if ($route['perm'] !== null) {
                Auth::authorize($route['perm']);
            }
            $params = array_map('urldecode', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            [$class, $action] = $route['handler'];
            (new $class())->$action(...$params);
            return;
        }

        throw new HttpException($allowed ? 405 : 404, $allowed ? 'Méthode non autorisée' : 'Page introuvable');
    }
}
