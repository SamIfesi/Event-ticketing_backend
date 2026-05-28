<?php

/**
 * TicketController
 *
 * Handles PDF ticket generation and download for bookings.
 *
 * Routes:
 *   GET  /api/bookings/:id/ticket           → download()   — attendee downloads their ticket
 *   POST /api/bookings/:id/ticket/regenerate → regenerate() — force regenerate (admin/dev)
 *   GET  /api/admin/tickets/:id/download    → adminDownload() — admin downloads any ticket
 *
 * Access rules:
 *   - Attendee can only download their own tickets
 *   - Organizer can download tickets for their own events
 *   - Admin / Dev can download any ticket
 */
class TicketPDFController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // GET /api/bookings/:id/ticket
  //
  // Attendee downloads their own ticket.
  // Generates the PDF on first request, caches it for subsequent
  // downloads (regenerate endpoint clears the cache).
  // ============================================================
  public function download(array $params): void
  {
    $bookingId = (int) $params['id'];
    $userId    = $this->request->user['id'];
    $role      = $this->request->user['role'];

    // Fetch booking ownership info
    $booking = $this->fetchBookingOwnership($bookingId);

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    // Access check
    $isOwner     = (int) $booking['user_id']      === $userId;
    $isOrganizer = (int) $booking['organizer_id'] === $userId;
    $isAdmin     = in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_DEV], true);

    if (!$isOwner && !$isOrganizer && !$isAdmin) {
      Response::forbidden('You do not have access to this ticket.');
    }

    // Only paid bookings get tickets
    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for confirmed paid bookings.', 400);
    }

    $this->serveTicket($bookingId, $booking);
  }

  // ============================================================
  // POST /api/bookings/:id/ticket/regenerate
  //
  // Force-regenerates the ticket (clears cached PDF).
  // Useful after admin edits or when the cached version is stale.
  // Admin / Dev only.
  // ============================================================
  public function regenerate(array $params): void
  {
    $bookingId = (int) $params['id'];

    $booking = $this->fetchBookingOwnership($bookingId);

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('Can only regenerate tickets for paid bookings.', 400);
    }

    // Delete cached version
    PDFService::deleteTicket($bookingId);

    try {
      $filePath = PDFService::generateTicket($bookingId);
      Response::success([
        'booking_id'  => $bookingId,
        'ticket_url' => PDFService::getTicketUrl($bookingId),
        'file_size'   => $this->humanFileSize(filesize($filePath)),
      ], 'Ticket regenerated successfully.');
    } catch (Exception $e) {
      error_log("TicketController::regenerate error for booking #{$bookingId}: " . $e->getMessage());
      Response::error('Failed to regenerate ticket. Please try again.', 500);
    }
  }

  // ============================================================
  // GET /api/admin/tickets/:id/download
  //
  // Admin downloads any booking ticket directly.
  // Same logic as download() but no ownership check.
  // ============================================================
  public function adminDownload(array $params): void
  {
    $bookingId = (int) $params['id'];

    $booking = $this->fetchBookingOwnership($bookingId);

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for paid bookings.', 400);
    }

    $this->serveTicket($bookingId, $booking);
  }

  // ============================================================
  // GET /api/bookings/:id/ticket/status
  //
  // Check if a ticket PDF has been generated (for UI indicators).
  // Accessible to booking owner, event organizer, admin, dev.
  // ============================================================
  public function status(array $params): void
  {
    $bookingId = (int) $params['id'];
    $userId    = $this->request->user['id'];
    $role      = $this->request->user['role'];

    $booking = $this->fetchBookingOwnership($bookingId);

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    $isOwner     = (int) $booking['user_id']      === $userId;
    $isOrganizer = (int) $booking['organizer_id'] === $userId;
    $isAdmin     = in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_DEV], true);

    if (!$isOwner && !$isOrganizer && !$isAdmin) {
      Response::forbidden('You do not have access to this booking.');
    }

    $exists   = PDFService::ticketExists($bookingId);
    $filePath = PDFService::getTicketPath($bookingId);

    Response::success([
      'booking_id'       => $bookingId,
      'ticket_generated' => $exists,
      'file_size'        => $exists ? $this->humanFileSize(filesize($filePath)) : null,
      'download_url'     => $exists ? PDFService::getTicketUrl($bookingId) : null,
    ]);
  }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

  /**
   * Core ticket serving logic.
   * Generates the PDF if it doesn't already exist,
   * then streams it as a download.
   */
  private function serveTicket(int $bookingId, array $booking): void
  {
    // Generate if not already cached
    if (!PDFService::ticketExists($bookingId)) {
      try {
        PDFService::generateTicket($bookingId);
      } catch (Exception $e) {
        error_log("TicketController::serveTicket generation error for booking #{$bookingId}: " . $e->getMessage());
        Response::error('Could not generate ticket. Please try again shortly.', 500);
        return;
      }
    }

    $filePath = PDFService::getTicketPath($bookingId);

    if (!file_exists($filePath)) {
      Response::error('Ticket file not found. Please try regenerating it.', 404);
      return;
    }

    // Stream the PDF to the browser
    $bookingIdPadded = str_pad($bookingId, 6, '0', STR_PAD_LEFT);
    $filename        = "Ticketer_Ticket_#{$bookingIdPadded}.pdf";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // Stop any JSON output buffering
    if (ob_get_level()) {
      ob_end_clean();
    }

    readfile($filePath);
    exit;
  }

  /**
   * Fetch the minimal booking data needed for access checks.
   */
  private function fetchBookingOwnership(int $bookingId): array|false
  {
    $stmt = $this->db->prepare("
            SELECT
                b.id,
                b.user_id,
                b.payment_status,
                e.organizer_id
            FROM bookings b
            JOIN events e ON e.id = b.event_id
            WHERE b.id = ?
              AND b.deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    return $stmt->fetch();
  }

  /**
   * Format bytes into a human-readable string.
   */
  private function humanFileSize(int $bytes): string
  {
    if ($bytes < 1024)       return "{$bytes} B";
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
  }
}
