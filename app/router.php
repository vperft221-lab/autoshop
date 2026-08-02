<?php
/**
 * Minimal router supporting {param} placeholders and HTTP verbs.
 * Routes register a handler; params are passed in order to the handler.
 */
class Router
{
    private array $routes = [];

    public function get(string $path, callable $h): void  { $this->add('GET', $path, $h); }
    public function post(string $path, callable $h): void { $this->add('POST', $path, $h); }

    private function add(string $method, string $path, callable $h): void
    {
        $regex = '#^' . preg_replace('#\{[a-z_]+\}#', '([^/]+)', rtrim($path, '/') ?: '/') . '$#';
        $this->routes[] = compact('method', 'regex', 'h');
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = defined('BASE') ? BASE : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = rtrim($path, '/') ?: '/';
        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;
            if (preg_match($r['regex'], $path, $m)) {
                array_shift($m);
                ($r['h'])(...$m);
                return;
            }
        }
        http_response_code(404);
        echo layout('Not found', '<div class="card empty"><h2>404 — Page not found</h2><p>The page you requested does not exist.</p>' . btn('Back to dashboard', '/') . '</div>');
    }
}
