<?php

class LogService
{
  public static function log(Request $request, int $responseCode): void
  {
    // Only log in development
    if (Environment::get('APP_ENV') !== 'development') {
      return;
    }

    try {
      $db = Database::connect();

      $stmt = $db->prepare("
                INSERT INTO dev_logs
                    (method, endpoint, user_id, ip_address, payload, response_code)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ");

      // Scrub sensitive fields from payload before storing
      $payload = $request->body;
      foreach (['password', 'password_hash', 'otp', 'token'] as $sensitive) {
        if (isset($payload[$sensitive])) {
          $payload[$sensitive] = '[REDACTED]';
        }
      }

      $stmt->execute([
        $request->method,
        $request->uri,
        $request->user['id'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        !empty($payload) ? json_encode($payload) : null,
        $responseCode,
      ]);
    } catch (Exception $e) {
      // Never let logging crash the app
      error_log('LogService error: ' . $e->getMessage());
    }
  }
}
