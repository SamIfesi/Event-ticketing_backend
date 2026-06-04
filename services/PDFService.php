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
    $taildwindcss =  file_get_contents(__DIR__ . '/../resources/pdf.css');
    $template_ticket =  file_get_contents(__DIR__ . '/../templates/ticket.css');
    
    $appName   = Environment::get('APP_NAME', 'Ticketer');
    $appUrl    = Environment::get('APP_URL',  'http://localhost');

    // ── Format values ─────────────────────────────────────
    $ticketIdPadded = str_pad($ticket['id'], 6, '0', STR_PAD_LEFT);
    $amount         = (float) $booking['unit_price'] === 0.0
      ? 'Free'
      : '₦' . number_format((float) $booking['unit_price'], 0);

    $eventDate = $booking['event_start_date']
      ? date('d M Y', strtotime($booking['event_start_date']))
      : 'TBC';
    $eventTime = $booking['event_start_date']
      ? date('g:i a', strtotime($booking['event_start_date']))
      : '';
    $dateTime  = $eventDate . ' · ' . $eventTime;

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
      ? "<img src='{$qrUrl}' alt='QR Code' style='width:200px;height:200px;display:block;margin:0 auto;' />"
      : "<div style='width:200px;height:200px;margin:0 auto;background:#f1f5f9;border-radius:8px;display:flex;align-items:center;justify-content:center;'>
                 <svg width='60' height='60' viewBox='0 0 24 24' fill='none' stroke='#94a3b8' stroke-width='1.5'>
                   <rect x='3' y='3' width='7' height='7'/><rect x='14' y='3' width='7' height='7'/>
                   <rect x='3' y='14' width='7' height='7'/><rect x='14' y='14' width='3' height='3'/>
                   <rect x='18' y='14' width='3' height='3'/><rect x='14' y='18' width='3' height='3'/>
                   <rect x='18' y='18' width='3' height='3'/>
                 </svg>
               </div>";

    return "<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>Ticket #{$ticketIdPadded}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      background: #e8eaf2;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      min-height: 100vh;
      padding: 24px 16px;
    }

    .ticket {
      width: 420px;
      background: #ffffff;
      border-radius: 24px;
      overflow: visible;
      position: relative;
    }

    /* ── Hero header ── */
    .hero {
      background: #eef0f7;
      border-radius: 24px 24px 0 0;
      padding: 36px 24px 28px;
      text-align: center;
    }
    .hero-emoji {
      font-size: 48px;
      line-height: 1;
      margin-bottom: 14px;
    }
    .hero-title {
      font-size: 21px;
      font-weight: 700;
      color: #1a1f36;
      margin-bottom: 6px;
      letter-spacing: -0.01em;
    }
    .hero-subtitle {
      font-size: 13px;
      color: #8c93a8;
    }

    /* ── Perforation ── */
    .perf {
      position: relative;
      height: 0;
      display: flex;
      align-items: center;
      overflow: visible;
      z-index: 2;
      margin: 0;
    }
    .perf-circle {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: #e8eaf2;
      flex-shrink: 0;
    }
    .perf-circle-left  { margin-left: -12px; }
    .perf-circle-right { margin-right: -12px; }
    .perf-line {
      flex: 1;
      border-top: 2px dashed #cdd0db;
      margin: 0 6px;
    }

    /* ── Ticket body ── */
    .body {
      padding: 28px 24px 0;
    }

    .field-label {
      font-size: 10px;
      font-weight: 600;
      color: #8c93a8;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      margin-bottom: 5px;
    }
    .field-value {
      font-size: 17px;
      font-weight: 700;
      color: #1a1f36;
      line-height: 1.3;
    }

    .row-2col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      margin-bottom: 22px;
    }
    .row-1col {
      margin-bottom: 22px;
    }

    /* ── QR section ── */
    .qr-section {
      padding: 4px 24px 28px;
      text-align: center;
    }
    .qr-label {
      font-size: 10px;
      font-weight: 600;
      color: #8c93a8;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      margin-bottom: 16px;
    }
    .qr-box {
      width: 200px;
      height: 200px;
      margin: 0 auto 20px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      background: #f8fafc;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      border-radius: 999px;
      padding: 7px 20px;
      margin-bottom: 20px;
    }
    .status-dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: {$statusColor};
      flex-shrink: 0;
    }
    .status-text {
      font-size: 13px;
      color: #64748b;
    }
    .issued-by {
      font-size: 12px;
      color: #b0b6c8;
    }

    @media print {
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
<div class='ticket'>

  <!-- Hero -->
  <div class='hero'>
    <div class='hero-emoji'>🎉</div>
    <div class='hero-title'>Your ticket is ready!</div>
    <div class='hero-subtitle'>{$eventTitle}</div>
  </div>

  <!-- Top perforation -->
  <div class='perf' style='margin-top: 0;'>
    <div class='perf-circle perf-circle-left'></div>
    <div class='perf-line'></div>
    <div class='perf-circle perf-circle-right'></div>
  </div>

  <!-- Body fields -->
  <div class='body'>

    <!-- Ticket ID + Amount -->
    <div class='row-2col'>
      <div>
        <div class='field-label'>Ticket ID</div>
        <div class='field-value'>#{$ticketIdPadded}</div>
      </div>
      <div style='text-align: right;'>
        <div class='field-label'>Amount</div>
        <div class='field-value'>{$amount}</div>
      </div>
    </div>

    <!-- Date & Time -->
    <div class='row-1col'>
      <div class='field-label'>Date &amp; Time</div>
      <div class='field-value'>{$dateTime}</div>
    </div>

    <!-- Venue -->
    <div class='row-1col'>
      <div class='field-label'>Venue</div>
      <div class='field-value'>{$venue}</div>
    </div>

    <!-- Ticket Type + Holder -->
    <div class='row-2col' style='margin-bottom: 0;'>
      <div>
        <div class='field-label'>Ticket Type</div>
        <div class='field-value'>{$ticketType}</div>
      </div>
      <div>
        <div class='field-label'>Holder</div>
        <div class='field-value'>{$holderName}</div>
      </div>
    </div>

  </div>

  <!-- Bottom perforation -->
  <div class='perf' style='margin-top: 24px;'>
    <div class='perf-circle perf-circle-left'></div>
    <div class='perf-line'></div>
    <div class='perf-circle perf-circle-right'></div>
  </div>

  <!-- QR Section -->
  <div class='qr-section'>
    <div class='qr-label'>Scan at the gate</div>

    <div class='qr-box'>
      {$qrHtml}
    </div>

    <div class='status-pill'>
      <div class='status-dot'></div>
      <span class='status-text'>{$statusLabel}</span>
    </div>

    <div class='issued-by'>Issued via {$appName}</div>
  </div>

</div>
</body>
</html>";
  }
}
