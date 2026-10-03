<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array,perm:?string}> */
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

    private function add(string $method, string $path, array $handler, ?string $perm): self
    {
        $path = rtrim($path, '/') ?: '/';
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'perm' => $perm];
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
            if ($method === 'POST') {
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
