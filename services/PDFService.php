<?php

/**
 * PDFService
 *
 * Generates PDF tickets for paid bookings using
 * Spatie Browsershot (which wraps Puppeteer/Chromium).
 *
 * One PDF is generated per ticket (not per booking).
 * So if a user bought 3 tickets, 3 PDFs are generated.
 *
 * Flow:
 *   1. Fetch booking + event + ticket data from DB
 *   2. Render HTML ticket template for each ticket
 *   3. Browsershot converts HTML → PDF
 *   4. Store in storage/tickets/ticket_{ticketId}.pdf
 *   5. Return array of file paths
 *
 * Requirements (handled by Dockerfile):
 *   - composer require spatie/browsershot
 *   - Node.js + Puppeteer installed
 *   - Chromium installed
 */

use Spatie\Browsershot\Browsershot;

class PDFService
{
  private static string $storageDir = __DIR__ . '/../storage/tickets/';

  /**
   * Backwards compatibility wrapper for old controller calls.
   * If something calls generateTicket, we process the ticket array 
   * and return the first ticket path.
   */
  public static function generateTicket(int $bookingId): string
  {
    $paths = self::generateTickets($bookingId);
    return $paths[0] ?? '';
  }

  // ============================================================
  // Generate one PDF ticket per ticket row under a booking.
  // Returns an array of absolute file paths.
  // Throws on failure.
  // ============================================================
  public static function generateTickets(int $bookingId): array
  {
    $db = Database::connect();

    // ── Fetch booking + event + attendee data ─────────────
    $stmt = $db->prepare("
            SELECT
                b.id                AS booking_id,
                b.quantity,
                b.unit_price,
                b.total_amount,
                b.payment_status,
                b.paystack_reference,
                b.paid_at,
                b.created_at        AS booked_at,

                e.id                AS event_id,
                e.title             AS event_title,
                e.location          AS event_location,
                e.start_date        AS event_start_date,
                e.end_date          AS event_end_date,
                e.banner_image      AS event_banner,

                tt.name             AS ticket_type,
                tt.price            AS ticket_price,

                u.id                AS user_id,
                u.name              AS attendee_name,
                u.email             AS attendee_email,

                org.name            AS organizer_name,

                c.name              AS category_name
            FROM bookings b
            JOIN events       e   ON e.id   = b.event_id
            JOIN ticket_types tt  ON tt.id  = b.ticket_type_id
            JOIN users        u   ON u.id   = b.user_id
            JOIN users        org ON org.id = e.organizer_id
            LEFT JOIN categories c ON c.id  = e.category_id
            WHERE b.id             = ?
              AND b.payment_status = 'paid'
              AND b.deleted_at     IS NULL
        ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
      throw new Exception("Booking #{$bookingId} not found or not paid.");
    }

    // ── Fetch individual tickets ──────────────────────────
    $stmt = $db->prepare("
            SELECT id, qr_token, is_used, used_at
            FROM tickets
            WHERE booking_id = ?
              AND deleted_at IS NULL
            ORDER BY id ASC
        ");
    $stmt->execute([$bookingId]);
    $tickets = $stmt->fetchAll();

    if (empty($tickets)) {
      throw new Exception("No tickets found for booking #{$bookingId}.");
    }

    // ── Ensure storage directory exists ───────────────────
    if (!is_dir(self::$storageDir)) {
      mkdir(self::$storageDir, 0755, true);
    }

    // ── Browsershot config ────────────────────────────────
    $chromiumPath = Environment::get('CHROMIUM_PATH', '/usr/bin/chromium');
    $nodePath     = Environment::get('NODE_PATH',     '/usr/bin/node');
    $npmPath      = Environment::get('NPM_PATH',      '/usr/bin/npm');

    $filePaths = [];

    // ── Generate one PDF per ticket ───────────────────────
    foreach ($tickets as $ticket) {
      $ticketId = (int) $ticket['id'];
      $filePath = self::$storageDir . "ticket_{$ticketId}.pdf";

      // Skip if already generated
      if (file_exists($filePath)) {
        $filePaths[] = $filePath;
        continue;
      }

      $html = self::renderTemplate($booking, $ticket);

      Browsershot::html($html)
        ->setChromePath($chromiumPath)
        ->setNodeBinary($nodePath)
        ->setNpmBinary($npmPath)
        ->noSandbox()
        ->addChromiumArguments(['--disable-gpu', '--disable-dev-shm-usage']) 
        ->paperSize(420, 760, 'px') 
        ->showBackground()
        ->waitUntilNetworkIdle()
        ->save($filePath);

      $filePaths[] = $filePath;
    }

    return $filePaths;
  }

  // ============================================================
  // Check if ALL tickets under a booking have been generated.
  // ============================================================
  public static function ticketExists(int $bookingId): bool
  {
    $db   = Database::connect();
    $stmt = $db->prepare("
            SELECT id FROM tickets
            WHERE booking_id = ? AND deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    $tickets = $stmt->fetchAll();

    if (empty($tickets)) return false;

    foreach ($tickets as $ticket) {
      $path = self::$storageDir . "ticket_{$ticket['id']}.pdf";
      if (!file_exists($path)) return false;
    }

    return true;
  }

  // ============================================================
  // Get path for a single ticket PDF.
  // ============================================================
  public static function getTicket(int $ticketId): string
  {
    return self::$storageDir . "ticket_{$ticketId}.pdf";
  }

  // ============================================================
  // Get all ticket PDF paths for a booking.
  // ============================================================
  public static function getTicketPath(int $bookingId): string
  {
    // For single-ticket bookings — returns the first ticket path.
    // For multi-ticket, use getTicketPaths().
    $db   = Database::connect();
    $stmt = $db->prepare("
            SELECT id FROM tickets
            WHERE booking_id = ? AND deleted_at IS NULL
            ORDER BY id ASC LIMIT 1
        ");
    $stmt->execute([$bookingId]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
      throw new Exception("No tickets found for booking #{$bookingId}.");
    }

    return self::$storageDir . "ticket_{$ticket['id']}.pdf";
  }

  // ============================================================
  // Get all PDF paths for a booking (multi-ticket).
  // ============================================================
  public static function getTicketPaths(int $bookingId): array
  {
    $db   = Database::connect();
    $stmt = $db->prepare("
            SELECT id FROM tickets
            WHERE booking_id = ? AND deleted_at IS NULL
            ORDER BY id ASC
        ");
    $stmt->execute([$bookingId]);
    $tickets = $stmt->fetchAll();

    return array_map(
      fn($t) => self::$storageDir . "ticket_{$t['id']}.pdf",
      $tickets
    );
  }

  // ============================================================
  // Delete all cached ticket PDFs for a booking.
  // ============================================================
  public static function deleteTicket(int $bookingId): void
  {
    $db   = Database::connect();
    $stmt = $db->prepare("
            SELECT id FROM tickets WHERE booking_id = ? AND deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    $tickets = $stmt->fetchAll();

    foreach ($tickets as $ticket) {
      $path = self::$storageDir . "ticket_{$ticket['id']}.pdf";
      if (file_exists($path)) {
        unlink($path);
      }
    }
  }

  // ============================================================
  // Public URL for a single ticket PDF.
  // ============================================================
  public static function getTicketUrl(int $bookingId): string
  {
    $db   = Database::connect();
    $stmt = $db->prepare("
            SELECT id FROM tickets
            WHERE booking_id = ? AND deleted_at IS NULL
            ORDER BY id ASC LIMIT 1
        ");
    $stmt->execute([$bookingId]);
    $ticket = $stmt->fetch();

    if (!$ticket) return '';

    $appUrl = Environment::get('APP_URL', 'http://localhost');
    return "{$appUrl}/storage/tickets/ticket_{$ticket['id']}.pdf";
  }

  // ============================================================
  // Render the ticket HTML — matches the signature design exactly.
  // One call per ticket row.
  // ============================================================
  private static function renderTemplate(array $booking, array $ticket): string
  {
    $appName   = Environment::get('APP_NAME', 'Ticketer');
    $appUrl    = Environment::get('APP_URL',  'http://localhost');

    $taildwindcss =  file_get_contents(__DIR__ . '/../resources/pdf.css');
    $template_ticket =  file_get_contents(__DIR__ . '/../templates/ticket.html');

    // ── Format values ─────────────────────────────────────
    $ticketId = (int) $ticket['id'];
    $ticketIdPadded = str_pad($ticketId, 6, '0', STR_PAD_LEFT);
    $amount         = (float) $booking['unit_price'] === 0.0
      ? 'Free'
      : '₦' . number_format((float) $booking['unit_price'], 0);

    $dateTime     = $booking['event_start_date'] ? date('D d M y - g:ia', strtotime($booking['event_start_date'])) : 'TBC';

    $venue        = htmlspecialchars($booking['event_location'] ?? 'TBC');
    $ticketType   = htmlspecialchars($booking['ticket_type']);
    $holderName   = htmlspecialchars($booking['attendee_name']);
    $eventTitle   = htmlspecialchars($booking['event_title']);

    // ── Ticket status ─────────────────────────────────────
    $isUsed      = (bool) $ticket['is_used'];
    $statusLabel = $isUsed ? 'Used'  : 'Valid';
    $statusColor = $isUsed ? '#94a3b8' : '#22c55e';

    // ── QR code image ─────────────────────────────────────
    $qrToken = $ticket['qr_token'] ?? '';
    $qrUrl   = $qrToken
      ? "{$appUrl}/storage/qrcodes/{$qrToken}.svg"
      : '';

    $qrHtml = $qrUrl
      ? "<img src='{$qrUrl}' alt='QR Code' style='width:100px;height:100px;display:block;margin:0 auto;' />"
      : "<div style='width:100px;height:100px;margin:0 auto;background:#f1f5f9;border-radius:8px;display:flex;align-items:center;justify-content:center;'>
                 <svg width='60' height='60' viewBox='0 0 24 24' fill='none' stroke='#94a3b8' stroke-width='1.5'>
                   <rect x='3' y='3' width='7' height='7'/><rect x='14' y='3' width='7' height='7'/>
                   <rect x='3' y='14' width='7' height='7'/><rect x='14' y='14' width='3' height='3'/>
                   <rect x='18' y='14' width='3' height='3'/><rect x='14' y='18' width='3' height='3'/>
                   <rect x='18' y='18' width='3' height='3'/>
                 </svg>
               </div>";

    $statusDot = $isUsed 
      ? "<div class=w-2.5 h-2.5 rounded-full shrink-0 bg-primary></div>"
      : "<div class=w-2.5 h-2.5 rounded-full shrink-0 bg-success></div>";
    $logo = "<img src='{$appUrl}/public/logo.svg' alt='Ticketer Logo' style='width:70px;margin:0 auto;display:block;' />";

    return str_replace([
      '{{TAILWIND_CSS}}',
      '{{TICKET_ID}}',
      '{{AMOUNT}}',
      '{{EVENT_TITLE}}',
      '{{DATE_TIME}}',
      '{{VENUE}}',
      '{{TICKET_TYPE}}',
      '{{HOLDER_NAME}}',
      '{{STATUS_LABEL}}',
      '{{STATUS_COLOR}}',
      '{{STATUS_DOT}}',
      '{{QR_HTML}}',
      '{{LOGO}}',
    ], [
      $taildwindcss,
      $ticketIdPadded,
      $amount,
      $eventTitle,
      $dateTime,
      $venue,
      $ticketType,
      $holderName,
      $statusLabel,
      $statusColor,
      $statusDot,
      $qrHtml,
      $logo
    ], $template_ticket);
  }
}
