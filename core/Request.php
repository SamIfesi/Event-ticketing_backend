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
    // HTTP method: GET, POST, PUT, DELETE
    $this->method = strtoupper($_SERVER['REQUEST_METHOD']);

    // Strip query string from URI e.g. /event_ticketing/api/events?page=1 → /event_ticketing/api/events
    $fullUri = strtok($_SERVER['REQUEST_URI'], '?');

    // Strip the subfolder prefix if the app is running in a subdirectory
    // e.g. if running at localhost/event_ticketing/, strip /event_ticketing
    // This makes routes work the same locally and on Railway
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']); // e.g. /event_ticketing
    if ($scriptDir !== '/' && str_starts_with($fullUri, $scriptDir)) {
      $fullUri = substr($fullUri, strlen($scriptDir));
    }

    $this->uri = $fullUri ?: '/';

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
