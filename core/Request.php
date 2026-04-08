<?php

class Request
{
  public string $method;
  public string $uri;
  public array  $body;
  public array  $query;
  public array  $headers;

  public ?array $user = null;

  public function __construct()
  {
    $this->method = strtoupper($_SERVER['REQUEST_METHOD']);

    // Strip query string from URI — e.g. /api/events?page=1 becomes /api/events
    $this->uri = strtok($_SERVER['REQUEST_URI'], '?');

    // Parse JSON body (what React sends via fetch)
    $rawBody    = file_get_contents('php://input');
    $this->body = json_decode($rawBody, true) ?? [];

    // Query string params — e.g. ?page=1&category=music
    $this->query = $_GET ?? [];

    // Request headers
    $this->headers = getallheaders() ?: [];
  }

  /**
   * Get a value from the request body
   * Returns $default if the key doesn't exist
   */
  public function input(string $key, mixed $default = null): mixed
  {
    return $this->body[$key] ?? $default;
  }

  /**
   * Get a value from the query string (?key=value)
   */
  public function query(string $key, mixed $default = null): mixed
  {
    return $this->query[$key] ?? $default;
  }

  /**
   * Get a request header value
   */
  public function header(string $key, mixed $default = null): mixed
  {
    return $this->headers[$key] ?? $default;
  }

  /**
   * Get the Bearer token from the Authorization header
   * Authorization: Bearer eyJhbGci...
   */
  public function bearerToken(): ?string
  {
    $authHeader = $this->header('Authorization', '');

    if (str_starts_with($authHeader, 'Bearer ')) {
      return substr($authHeader, 7);
    }

    return null;
  }
}
