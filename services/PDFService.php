<?php
/**
 * PDFService
 *
 * Generates PDF tickets for paid bookings using
 * Spatie Browsershot (which wraps Puppeteer/Chromium).
 *
 * Flow:
 *   1. Fetch booking + event + attendee data from DB
 *   2. Render HTML ticket template with that data
 *   3. Browsershot converts HTML → PDF
 *   4. Store in storage/tickets/ticket_booking_{id}.pdf
 *   5. Return the file path
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

  // ============================================================
  // Generate a ticket PDF for a booking.
  // Returns the absolute file path on success.
  // Throws on failure.
  // ============================================================
  public static function generateTicket(int $bookingId): string
  {
    $db = Database::connect();

    // ── Fetch all data needed for the ticket ─────────────
    $stmt = $db->prepare("
            SELECT
                b.id                    AS booking_id,
                b.quantity,
                b.unit_price,
                b.total_amount,
                b.payment_status,
                b.paystack_reference,
                b.paid_at,
                b.created_at            AS booked_at,

                e.id                    AS event_id,
                e.title                 AS event_title,
                e.location              AS event_location,
                e.start_date            AS event_start_date,
                e.end_date              AS event_end_date,
                e.banner_image          AS event_banner,

                tt.name                 AS ticket_type,
                tt.price                AS ticket_price,

                u.id                    AS user_id,
                u.name                  AS attendee_name,
                u.email                 AS attendee_email,

                org.name                AS organizer_name,
                org.email               AS organizer_email,

                c.name                  AS category_name
            FROM bookings b
            JOIN events       e   ON e.id  = b.event_id
            JOIN ticket_types tt  ON tt.id = b.ticket_type_id
            JOIN users        u   ON u.id  = b.user_id
            JOIN users        org ON org.id = e.organizer_id
            LEFT JOIN categories c ON c.id = e.category_id
            WHERE b.id = ?
              AND b.payment_status = 'paid'
              AND b.deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
      throw new Exception("Booking #{$bookingId} not found or not paid.");
    }

    // ── Fetch the individual tickets for this booking ──────
    $stmt = $db->prepare("
            SELECT id, qr_token, is_used, used_at, created_at
            FROM tickets
            WHERE booking_id = ?
              AND deleted_at IS NULL
            ORDER BY id ASC
        ");
    $stmt->execute([$bookingId]);
    $tickets = $stmt->fetchAll();

    // ── Ensure storage directory exists ───────────────────
    if (!is_dir(self::$storageDir)) {
      mkdir(self::$storageDir, 0755, true);
    }

    $filePath = self::$storageDir . "ticket_booking_{$bookingId}.pdf";

    // ── Render HTML template ───────────────────────────────
    $html = self::renderTemplate($booking, $tickets);

    // ── Generate PDF via Browsershot ───────────────────────
    $chromiumPath = Environment::get('CHROMIUM_PATH', '/usr/bin/chromium');
    $nodePath     = Environment::get('NODE_PATH', '/usr/bin/node');
    $npmPath      = Environment::get('NPM_PATH', '/usr/bin/npm');

    Browsershot::html($html)
      ->setChromePath($chromiumPath)
      ->setNodeBinary($nodePath)
      ->setNpmBinary($npmPath)
      ->noSandbox()                   // Required in Docker
      ->format('A4')
      ->margins(15, 15, 15, 15)       // top, right, bottom, left in mm
      ->showBackground()
      ->waitUntilNetworkIdle()
      ->save($filePath);

    return $filePath;
  }

  // ============================================================
  // Check if a ticket already exists for a booking.
  // Used to avoid regenerating on every download request.
  // ============================================================
  public static function ticketExists(int $bookingId): bool
  {
    $filePath = self::$storageDir . "ticket_booking_{$bookingId}.pdf";
    return file_exists($filePath);
  }

  // ============================================================
  // Get the file path for an existing ticket.
  // Does NOT check if the file exists — call ticketExists() first.
  // ============================================================
  public static function getTicketPath(int $bookingId): string
  {
    return self::$storageDir . "ticket_booking_{$bookingId}.pdf";
  }

  // ============================================================
  // Delete a cached ticket (e.g. after refund).
  // ============================================================
  public static function deleteTicket(int $bookingId): void
  {
    $filePath = self::$storageDir . "ticket_booking_{$bookingId}.pdf";
    if (file_exists($filePath)) {
      unlink($filePath);
    }
  }

  // ============================================================
  // Get the public URL for a ticket.
  // ============================================================
  public static function getTicketUrl(int $bookingId): string
  {
    $appUrl = Environment::get('APP_URL', 'http://localhost');
    return "{$appUrl}/storage/tickets/ticket_booking_{$bookingId}.pdf";
  }

  // ============================================================
  // Render the HTML ticket template.
  // Kept in-class for portability — no external template engine needed.
  // ============================================================
  private static function renderTemplate(array $booking, array $tickets): string
  {
    $appName      = Environment::get('APP_NAME', 'Ticketer');
    $appUrl       = Environment::get('APP_URL', 'http://localhost');

    // Format values
    $totalFormatted   = '₦' . number_format((float) $booking['total_amount'], 2);
    $unitFormatted    = '₦' . number_format((float) $booking['unit_price'], 2);
    $paidAt           = $booking['paid_at']
      ? date('d M Y \a\t g:ia', strtotime($booking['paid_at']))
      : 'N/A';
    $eventDate        = $booking['event_start_date']
      ? date('D, d M Y', strtotime($booking['event_start_date']))
      : 'TBC';
    $eventTime        = $booking['event_start_date']
      ? date('g:ia', strtotime($booking['event_start_date']))
      : '';
    $eventEndTime     = $booking['event_end_date']
      ? date('g:ia', strtotime($booking['event_end_date']))
      : '';
    $bookingRef       = strtoupper(substr($booking['paystack_reference'] ?? "BK{$booking['booking_id']}", 0, 20));
    $bookingIdPadded  = str_pad($booking['booking_id'], 6, '0', STR_PAD_LEFT);

    // Ticket rows
    $ticketRowsHtml = '';
    foreach ($tickets as $index => $ticket) {
      $ticketNum    = $index + 1;
      $ticketId     = str_pad($ticket['id'], 8, '0', STR_PAD_LEFT);
      $status       = $ticket['is_used'] ? 'Used' : 'Valid';
      $statusColor  = $ticket['is_used'] ? '#94a3b8' : '#22c55e';
      $ticketRowsHtml .= "
                <tr>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #1e293b;'>#{$ticketNum}</td>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; font-family: monospace; color: #475569;'>#{$ticketId}</td>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #1e293b;'>{$booking['ticket_type']}</td>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0;'>
                        <span style='
                            display: inline-block;
                            padding: 3px 10px;
                            border-radius: 999px;
                            font-size: 11px;
                            font-weight: 700;
                            background: {$statusColor}18;
                            color: {$statusColor};
                        '>{$status}</span>
                    </td>
                    <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #475569;'>{$unitFormatted}</td>
                </tr>
            ";
    }

    // QR code URL for ticket (server-side public path)
    $qrCodeSectionHtml = '';
    if (count($tickets) === 1) {
      $qrToken = $tickets[0]['qr_token'] ?? null;
      if ($qrToken) {
        $qrUrl = "{$appUrl}/storage/qrcodes/{$qrToken}.svg";
        $qrCodeSectionHtml = "
                    <div style='text-align: center; margin: 24px 0;'>
                        <p style='font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;'>Your Entry QR Code</p>
                        <img src='{$qrUrl}' alt='QR Code' style='width: 140px; height: 140px;' />
                        <p style='font-size: 10px; font-family: monospace; color: #94a3b8; margin-top: 6px;'>{$qrToken}</p>
                    </div>
                ";
      }
    }

    return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Ticket — Booking #{$bookingIdPadded}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: 'Segoe UI', Arial, sans-serif;
                    background: #ffffff;
                    color: #1e293b;
                    font-size: 14px;
                    line-height: 1.5;
                }
                .page {
                    max-width: 700px;
                    margin: 0 auto;
                    padding: 40px 40px 60px;
                }
                .header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    margin-bottom: 36px;
                    padding-bottom: 24px;
                    border-bottom: 2px solid #2563eb;
                }
                .brand-name {
                    font-size: 26px;
                    font-weight: 900;
                    color: #2563eb;
                    letter-spacing: -0.02em;
                }
                .brand-tagline {
                    font-size: 11px;
                    color: #94a3b8;
                    margin-top: 2px;
                }
                .ticket-meta {
                    text-align: right;
                }
                .ticket-title {
                    font-size: 18px;
                    font-weight: 800;
                    color: #1e293b;
                }
                .ticket-subtitle {
                    font-size: 12px;
                    color: #94a3b8;
                    margin-top: 2px;
                }
                .status-badge {
                    display: inline-block;
                    padding: 4px 12px;
                    border-radius: 999px;
                    font-size: 11px;
                    font-weight: 700;
                    background: #dcfce7;
                    color: #16a34a;
                    margin-top: 6px;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }
                .section {
                    margin-bottom: 28px;
                }
                .section-title {
                    font-size: 10px;
                    font-weight: 700;
                    color: #94a3b8;
                    text-transform: uppercase;
                    letter-spacing: 0.12em;
                    margin-bottom: 12px;
                }
                .info-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 0;
                    border: 1px solid #e2e8f0;
                    border-radius: 10px;
                    overflow: hidden;
                }
                .info-cell {
                    padding: 14px 16px;
                    border-bottom: 1px solid #e2e8f0;
                    border-right: 1px solid #e2e8f0;
                }
                .info-cell:nth-child(even) { border-right: none; }
                .info-cell:nth-last-child(-n+2) { border-bottom: none; }
                .info-label {
                    font-size: 10px;
                    font-weight: 700;
                    color: #94a3b8;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                    margin-bottom: 3px;
                }
                .info-value {
                    font-size: 13px;
                    font-weight: 600;
                    color: #1e293b;
                }
                .event-card {
                    background: #eff6ff;
                    border: 1px solid #bfdbfe;
                    border-radius: 10px;
                    padding: 18px 20px;
                }
                .event-title {
                    font-size: 17px;
                    font-weight: 800;
                    color: #1e3a8a;
                    margin-bottom: 10px;
                }
                .event-detail {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-size: 12px;
                    color: #3b82f6;
                    margin-bottom: 5px;
                }
                .tickets-table {
                    width: 100%;
                    border-collapse: collapse;
                    border: 1px solid #e2e8f0;
                    border-radius: 10px;
                    overflow: hidden;
                }
                .tickets-table thead tr {
                    background: #f8fafc;
                }
                .tickets-table thead th {
                    padding: 10px 12px;
                    text-align: left;
                    font-size: 10px;
                    font-weight: 700;
                    color: #94a3b8;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                    border-bottom: 1px solid #e2e8f0;
                }
                .total-row {
                    background: #1e293b;
                    border-radius: 10px;
                    padding: 16px 20px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-top: 12px;
                }
                .total-label {
                    font-size: 13px;
                    font-weight: 600;
                    color: #94a3b8;
                }
                .total-amount {
                    font-size: 22px;
                    font-weight: 900;
                    color: #ffffff;
                }
                .footer {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #e2e8f0;
                    text-align: center;
                }
                .footer-text {
                    font-size: 11px;
                    color: #94a3b8;
                    line-height: 1.6;
                }
                .divider {
                    border: none;
                    border-top: 1px dashed #e2e8f0;
                    margin: 24px 0;
                }
                @media print {
                    body { -webkit-print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
        <div class='page'>

            <!-- ── Header ── -->
            <div class='header'>
                <div>
                    <div class='brand-name'>{$appName}</div>
                    <div class='brand-tagline'>Nigeria's Event Ticketing Platform</div>
                </div>
                <div class='ticket-meta'>
                    <div class='ticket-title'>Payment ticket</div>
                    <div class='ticket-subtitle'>Booking #{$bookingIdPadded}</div>
                    <div class='status-badge'>✓ Paid</div>
                </div>
            </div>

            <!-- ── Booking Summary ── -->
            <div class='section'>
                <div class='section-title'>Booking Summary</div>
                <div class='info-grid'>
                    <div class='info-cell'>
                        <div class='info-label'>Booking Reference</div>
                        <div class='info-value' style='font-family: monospace; font-size: 12px;'>{$bookingRef}</div>
                    </div>
                    <div class='info-cell'>
                        <div class='info-label'>Payment Date</div>
                        <div class='info-value'>{$paidAt}</div>
                    </div>
                    <div class='info-cell'>
                        <div class='info-label'>Attendee</div>
                        <div class='info-value'>{$booking['attendee_name']}</div>
                    </div>
                    <div class='info-cell'>
                        <div class='info-label'>Email</div>
                        <div class='info-value' style='font-size: 12px;'>{$booking['attendee_email']}</div>
                    </div>
                </div>
            </div>

            <!-- ── Event Details ── -->
            <div class='section'>
                <div class='section-title'>Event Details</div>
                <div class='event-card'>
                    <div class='event-title'>{$booking['event_title']}</div>
                    <div class='event-detail'>
                        <span>📅</span>
                        <span>{$eventDate} · {$eventTime}" . ($eventEndTime ? " – {$eventEndTime}" : "") . "</span>
                    </div>
                    " . ($booking['event_location'] ? "
                    <div class='event-detail'>
                        <span>📍</span>
                        <span>{$booking['event_location']}</span>
                    </div>" : "") . "
                    <div class='event-detail'>
                        <span>🎫</span>
                        <span>{$booking['ticket_type']} · {$booking['quantity']} ticket(s)</span>
                    </div>
                    <div class='event-detail'>
                        <span>🎤</span>
                        <span>Organised by {$booking['organizer_name']}</span>
                    </div>
                </div>
            </div>

            <!-- ── Ticket Breakdown ── -->
            <div class='section'>
                <div class='section-title'>Ticket Breakdown</div>
                <table class='tickets-table'>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ticket ID</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$ticketRowsHtml}
                    </tbody>
                </table>

                <!-- Total -->
                <div class='total-row'>
                    <div>
                        <div class='total-label'>Total Paid</div>
                        <div style='font-size: 11px; color: #475569; margin-top: 2px;'>{$booking['quantity']} × {$unitFormatted}</div>
                    </div>
                    <div class='total-amount'>{$totalFormatted}</div>
                </div>
            </div>

            <hr class='divider'>

            <!-- ── QR Code (single ticket only) ── -->
            {$qrCodeSectionHtml}

            <!-- ── Footer ── -->
            <div class='footer'>
                <div class='footer-text'>
                    This is an official payment ticket from {$appName}.<br>
                    Keep this ticket for your records. Show your QR code at the event entrance.<br>
                    Questions? Contact us at support@ticketer.ng<br><br>
                    <strong>{$appName}</strong> · Nigeria's Event Ticketing Platform · {$appUrl}
                </div>
            </div>

        </div>
        </body>
        </html>
        ";
  }
}
