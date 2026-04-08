<?php

class Environment
{
  public static function load(string $filePath): void
  {
    if (!file_exists($filePath)) {
      die('.env file not found. Please create one from .env.example');
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
      // Skip comment lines that start with #
      if (str_starts_with(trim($line), '#')) {
        continue;
      }

      // Split on the first = sign only
      if (!str_contains($line, '=')) {
        continue;
      }

      [$key, $value] = explode('=', $line, 2);

      $key   = trim($key);
      $value = trim($value);

      // Remove quotes if present
      if ((str_contains($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
        (str_contains($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)
      ) {
        $value = substr($value, 1, -1);
      }

      // Put it in $_ENV so we can access it anywhere
      $_ENV[$key] = $value;
      putenv("$key=$value");
    }
  }

  //  Returns $default if the key doesn't exist
  public static function get(string $key, string $default = ''): string
  {
    return $_ENV[$key] ?? $default;
  }
}
