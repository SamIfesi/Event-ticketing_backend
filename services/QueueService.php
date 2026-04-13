<?php

class QueueService
{
  private static ?PDO $db = null;

  private static function db(): PDO
  {
    if (self::$db === null) {
      self::$db = Database::connect();
    }
    return self::$db;
  }

  // ============================================================
  // Push a job onto the queue
  // Called instead of sending email directly
  //
  // Usage:
  //   QueueService::push('send_otp', [
  //       'email' => 'user@example.com',
  //       'name'  => 'Sam',
  //       'otp'   => '123456',
  //       'type'  => 'register',
  //   ]);
  // ============================================================
  public static function push(string $type, array $payload, int $delaySeconds = 0): bool
  {
    try {
      self::db()->prepare("
        INSERT INTO jobs (type, payload, status, available_at)
        VALUES (?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? SECOND))
      ")->execute([
        $type,
        json_encode($payload),
        $delaySeconds,
      ]);

      return true;
    } catch (Exception $e) {
      error_log('QueueService::push error: ' . $e->getMessage());
      return false;
    }
  }

  public static function sendOTP(string $email, string $name, string $otp, string $type): void
  {
    self::push('send_otp', [
      'email' => $email,
      'name'  => $name,
      'otp'   => $otp,
      'type'  => $type,
    ]);
  }

  public static function sendTicketConfirmation(
    string $email,
    string $name,
    string $eventTitle,
    string $eventDate,
    string $eventLocation,
    string $ticketType,
    int    $quantity,
    float  $totalAmount,
    string $dashboardUrl
  ): void {
    self::push('send_ticket_confirmation', [
      'email'         => $email,
      'name'          => $name,
      'event_title'   => $eventTitle,
      'event_date'    => $eventDate,
      'event_location' => $eventLocation,
      'ticket_type'   => $ticketType,
      'quantity'      => $quantity,
      'total_amount'  => $totalAmount,
      'dashboard_url' => $dashboardUrl,
    ]);
  }

  public static function sendPasswordChanged(string $email, string $name): void
  {
    self::push('send_password_changed', [
      'email' => $email,
      'name'  => $name,
    ]);
  }
}
