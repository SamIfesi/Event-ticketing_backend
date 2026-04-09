<?php

class Router
{
  // Stores all registered routes
  private array $routes = [];

  /**
   * Register a GET route
   */
  public function get(string $path, array $handler, array $middlewares = []): void
  {
    $this->addRoute('GET', $path, $handler, $middlewares);
  }

  /**
   * Register a POST route
   */
  public function post(string $path, array $handler, array $middlewares = []): void
  {
    $this->addRoute('POST', $path, $handler, $middlewares);
  }

  /**
   * Register a PUT route
   */
  public function put(string $path, array $handler, array $middlewares = []): void
  {
    $this->addRoute('PUT', $path, $handler, $middlewares);
  }

  /**
   * Register a DELETE route
   */
  public function delete(string $path, array $handler, array $middlewares = []): void
  {
    $this->addRoute('DELETE', $path, $handler, $middlewares);
  }

  /**
   * Store the route internally
   */
  private function addRoute(string $method, string $path, array $handler, array $middlewares): void
  {
    $this->routes[] = [
      'method'      => $method,
      'path'        => $path,
      'handler'     => $handler,   // e.g. [EventController::class, 'index']
      'middlewares' => $middlewares,
      // Convert :id style params to a regex — /api/events/:id becomes a pattern
      'pattern'     => $this->pathToPattern($path),
    ];
  }

  /**
   * Convert /api/events/:id into a regex pattern
   * so we can match it against the incoming URI
   */
  private function pathToPattern(string $path): string
  {
    // Escape forward slashes, replace :param with a capture group
    $pattern = preg_replace('/:[a-zA-Z_]+/', '([^/]+)', $path);
    return '#^' . $pattern . '$#';
  }

  /**
   * Extract param names from a path like /api/events/:id/:slug
   * Returns ['id', 'slug']
   */
  private function extractParamNames(string $path): array
  {
    preg_match_all('/:([a-zA-Z_]+)/', $path, $matches);
    return $matches[1];
  }

  /**
   * Main dispatch method — called from index.php
   * Finds the matching route and runs it
   */
  public function dispatch(Request $request): void
  {
    foreach ($this->routes as $route) {
      // Check method matches and URI matches the pattern
      if (
        $route['method'] === $request->method &&
        preg_match($route['pattern'], $request->uri, $matches)
      ) {
        // Remove the full match, keep only captured groups
        array_shift($matches);

        // Map captured values to param names — e.g. ['id' => '42']
        $paramNames = $this->extractParamNames($route['path']);
        $params     = array_combine($paramNames, $matches) ?: [];

        // Run middlewares first
        // Middlewares can be plain class names or [ClassName => $args] arrays
        foreach ($route['middlewares'] as $key => $value) {
          if (is_string($value)) {
            // Plain middleware e.g. AuthMiddleware::class
            (new $value())->handle($request);
          } else {
            // Middleware with arguments e.g. RoleMiddleware::class => ['organizer']
            (new $key($value))->handle($request);
          }
        }

        // Call the controller method
        [$controllerClass, $method] = $route['handler'];
        $controller = new $controllerClass($request);
        $controller->$method($params);
        return;
      }
    }

    // No route matched
    Response::notFound('Route not found');
  }
}
