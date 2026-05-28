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

  // ============================================================
  // EMAIL JOBS
  // ============================================================

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
      'email'          => $email,
      'name'           => $name,
      'event_title'    => $eventTitle,
      'event_date'     => $eventDate,
      'event_location' => $eventLocation,
      'ticket_type'    => $ticketType,
      'quantity'       => $quantity,
      'total_amount'   => $totalAmount,
      'dashboard_url'  => $dashboardUrl,
    ]);
  }

  public static function sendPasswordChanged(string $email, string $name): void
  {
    self::push('send_password_changed', [
      'email' => $email,
      'name'  => $name,
    ]);
  }

    // ============================================================
    // PDF JOBS
    // ============================================================

  /**
   * Queue a tictet PDF generation job for a paid booking.
   *
   * Called from BookingController::verify() after payment confirmed.
   * Delayed by 5 seconds to let the booking transaction fully commit
   * before Browsershot tries to read it.
   *
   * @param int $bookingId  The confirmed paid booking ID
   * @param int $delaySeconds  Seconds to wait before processing (default 5)
   */
  public static function generateTicket(int $bookingId, int $delaySeconds = 5): void
  {
    self::push('generate_ticket', [
      'booking_id' => $bookingId,
    ], $delaySeconds);
  }

  /**
   * Queue tictet generation for multiple bookings at once.
   * Used by admin when bulk-processing tictets.
   *
   * @param int[] $bookingIds
   */
  public static function generateTicketBulk(array $bookingIds): void
  {
    foreach ($bookingIds as $index => $bookingId) {
      // Stagger by 3 seconds each to avoid hammering Chromium
      self::generateTicket((int) $bookingId, 5 + ($index * 3));
    }
  }
}
