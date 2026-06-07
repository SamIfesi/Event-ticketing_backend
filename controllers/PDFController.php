<?php

/**
 * TicketPDFController
 *
 * Handles PDF and PNG ticket generation and download for bookings.
 *
 * Routes:
 *   GET  /api/bookings/:id/ticket              → download()          — attendee downloads PDF ticket
 *   GET  /api/bookings/:id/ticket/png          → downloadPng()       — attendee downloads PNG ticket
 *   GET  /api/bookings/:id/ticket/status       → status()            — check if ticket files exist
 *   POST /api/bookings/:id/ticket/regenerate   → regenerate()        — force regenerate (admin/dev)
 *   GET  /api/admin/tickets/:id/download       → adminDownload()     — admin downloads any PDF ticket
 *   GET  /api/admin/tickets/:id/download/png   → adminDownloadPng()  — admin downloads any PNG ticket
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
  // Attendee downloads their own PDF ticket.
  // Generates the PDF on first request, caches it for subsequent
  // downloads (regenerate endpoint clears the cache).
  // ============================================================
  public function download(array $params): void
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
      Response::forbidden('You do not have access to this ticket.');
    }

    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for confirmed paid bookings.', 400);
    }

    $this->servePdf($bookingId, $booking);
  }

  // ============================================================
  // GET /api/bookings/:id/ticket/png
  //
  // Attendee downloads their own PNG ticket image.
  // Both PDF and PNG are generated together on the first request,
  // so whichever format is requested first warms the cache for both.
  // ============================================================
  public function downloadPng(array $params): void
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
      Response::forbidden('You do not have access to this ticket.');
    }

    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for confirmed paid bookings.', 400);
    }

    $this->servePng($bookingId, $booking);
  }

  // ============================================================
  // POST /api/bookings/:id/ticket/regenerate
  //
  // Force-regenerates both PDF and PNG (clears cached files).
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

    // Delete cached PDF and PNG versions
    PDFService::deleteTicket($bookingId);

    try {
      $filePaths = PDFService::generateTickets($bookingId);
      $filePath  = $filePaths[0] ?? null;

      Response::success([
        'booking_id'  => $bookingId,
        'ticket_url'  => PDFService::getTicketUrl($bookingId),
        'ticket_png_url' => PDFService::getTicketPngUrl($bookingId),
        'file_size'   => $filePath && file_exists($filePath) ? $this->humanFileSize(filesize($filePath)) : null,
      ], 'Ticket regenerated successfully.');
    } catch (Exception $e) {
      error_log("TicketPDFController::regenerate error for booking #{$bookingId}: " . $e->getMessage());
      Response::error('Failed to regenerate ticket. Please try again.', 500);
    }
  }

  // ============================================================
  // GET /api/admin/tickets/:id/download
  //
  // Admin downloads any booking PDF ticket directly.
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

    $this->servePdf($bookingId, $booking);
  }

  // ============================================================
  // GET /api/admin/tickets/:id/download/png
  //
  // Admin downloads any booking PNG ticket directly.
  // ============================================================
  public function adminDownloadPng(array $params): void
  {
    $bookingId = (int) $params['id'];

    $booking = $this->fetchBookingOwnership($bookingId);

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('A ticket is only available for paid bookings.', 400);
    }

    $this->servePng($bookingId, $booking);
  }

  // ============================================================
  // GET /api/bookings/:id/ticket/status
  //
  // Check if ticket files (PDF + PNG) have been generated.
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

    $pdfExists = PDFService::ticketExists($bookingId);
    $pngExists = PDFService::ticketPngExists($bookingId);

    $pdfPath     = null;
    $pdfSize     = null;
    $downloadUrl = null;
    $pngUrl      = null;

    if ($pdfExists) {
      try {
        $pdfPath     = PDFService::getTicketPath($bookingId);
        $pdfSize     = file_exists($pdfPath) ? $this->humanFileSize(filesize($pdfPath)) : null;
        $downloadUrl = PDFService::getTicketUrl($bookingId);
      } catch (Exception $e) {
        $pdfExists = false;
      }
    }

    if ($pngExists) {
      try {
        $pngUrl = PDFService::getTicketPngUrl($bookingId);
      } catch (Exception $e) {
        $pngExists = false;
      }
    }

    Response::success([
      'booking_id'       => $bookingId,
      'ticket_generated' => $pdfExists,
      'png_generated'    => $pngExists,
      'file_size'        => $pdfSize,
      'download_url'     => $downloadUrl,
      'png_url'          => $pngUrl,
    ]);
  }

  // ============================================================
  // PRIVATE HELPERS
  // ============================================================

  /**
   * Core PDF serving logic.
   *
   * For single-ticket bookings: streams the one PDF directly.
   * For multi-ticket bookings:  streams a ZIP containing all ticket PDFs.
   *
   * Generates PDFs on first request; cached on subsequent calls.
   */
  private function servePdf(int $bookingId, array $booking): void
  {
    // Generate PDF (and PNG) if not already cached
    if (!PDFService::ticketExists($bookingId)) {
      try {
        PDFService::generateTickets($bookingId);
      } catch (Exception $e) {
        error_log("TicketPDFController::servePdf generation error for booking #{$bookingId}: " . $e->getMessage());
        Response::error('Could not generate ticket. Please try again shortly.', 500);
        return;
      }
    }

    // Resolve all PDF paths for this booking
    try {
      $filePaths = PDFService::getTicketPaths($bookingId);
    } catch (Exception $e) {
      error_log("TicketPDFController::servePdf path resolution error for booking #{$bookingId}: " . $e->getMessage());
      Response::error('Ticket file not found. Please try regenerating it.', 404);
      return;
    }

    $existingPaths = array_filter($filePaths, fn($p) => file_exists($p));

    if (empty($existingPaths)) {
      Response::error('Ticket file not found. Please try regenerating it.', 404);
      return;
    }

    $bookingIdPadded = str_pad($bookingId, 6, '0', STR_PAD_LEFT);

    // ── Single ticket: stream PDF directly ───────────────────
    if (count($existingPaths) === 1) {
      $filePath = reset($existingPaths);
      $filename = "Ticketer_Ticket_#{$bookingIdPadded}.pdf";

      if (ob_get_level()) {
        ob_end_clean();
      }

      header('Content-Type: application/pdf');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Content-Length: ' . filesize($filePath));
      header('Cache-Control: private, max-age=0, must-revalidate');
      header('Pragma: public');

      readfile($filePath);
      exit;
    }

    // ── Multiple tickets: bundle into a ZIP ──────────────────
    if (!extension_loaded('zip')) {
      // ZIP not available — stream the first ticket only
      error_log("ZIP extension not loaded; serving only ticket 1 of " . count($existingPaths) . " for booking #{$bookingId}");
      $filePath = reset($existingPaths);
      $filename = "Ticketer_Ticket_#{$bookingIdPadded}.pdf";

      if (ob_get_level()) {
        ob_end_clean();
      }

      header('Content-Type: application/pdf');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Content-Length: ' . filesize($filePath));
      header('Cache-Control: private, max-age=0, must-revalidate');

      readfile($filePath);
      exit;
    }

    $zipPath = sys_get_temp_dir() . "/ticketer_booking_{$bookingId}_" . time() . '.zip';
    $zip     = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      Response::error('Could not create ticket archive. Please try again.', 500);
      return;
    }

    $i = 1;
    foreach ($existingPaths as $path) {
      $zip->addFile($path, "Ticket_{$i}_Booking_{$bookingIdPadded}.pdf");
      $i++;
    }
    $zip->close();

    if (!file_exists($zipPath)) {
      Response::error('Could not package tickets. Please try again.', 500);
      return;
    }

    $filename = "Ticketer_Tickets_#{$bookingIdPadded}.zip";

    if (ob_get_level()) {
      ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($zipPath);

    @unlink($zipPath);
    exit;
  }

  /**
   * Core PNG serving logic.
   *
   * For single-ticket bookings: streams the one PNG directly.
   * For multi-ticket bookings:  streams a ZIP containing all ticket PNGs.
   *
   * Triggers generateTickets() (which produces both PDF + PNG) if
   * the PNG is not yet cached.
   */
  private function servePng(int $bookingId, array $booking): void
  {
    // Generate PNG (and PDF) if not already cached
    if (!PDFService::ticketPngExists($bookingId)) {
      try {
        PDFService::generateTickets($bookingId);
      } catch (Exception $e) {
        error_log("TicketPDFController::servePng generation error for booking #{$bookingId}: " . $e->getMessage());
        Response::error('Could not generate ticket image. Please try again shortly.', 500);
        return;
      }
    }

    // Resolve all PNG paths for this booking
    try {
      $filePaths = PDFService::getTicketPngPaths($bookingId);
    } catch (Exception $e) {
      error_log("TicketPDFController::servePng path resolution error for booking #{$bookingId}: " . $e->getMessage());
      Response::error('Ticket image not found. Please try regenerating it.', 404);
      return;
    }

    $existingPaths = array_filter($filePaths, fn($p) => file_exists($p));

    if (empty($existingPaths)) {
      Response::error('Ticket image not found. Please try regenerating it.', 404);
      return;
    }

    $bookingIdPadded = str_pad($bookingId, 6, '0', STR_PAD_LEFT);

    // ── Single ticket: stream PNG directly ───────────────────
    if (count($existingPaths) === 1) {
      $filePath = reset($existingPaths);
      $filename = "Ticketer_Ticket_#{$bookingIdPadded}.png";

      if (ob_get_level()) {
        ob_end_clean();
      }

      header('Content-Type: image/png');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Content-Length: ' . filesize($filePath));
      header('Cache-Control: private, max-age=0, must-revalidate');
      header('Pragma: public');

      readfile($filePath);
      exit;
    }

    // ── Multiple tickets: bundle into a ZIP of PNGs ──────────
    if (!extension_loaded('zip')) {
      // ZIP not available — stream the first PNG only
      error_log("ZIP extension not loaded; serving only PNG 1 of " . count($existingPaths) . " for booking #{$bookingId}");
      $filePath = reset($existingPaths);
      $filename = "Ticketer_Ticket_#{$bookingIdPadded}.png";

      if (ob_get_level()) {
        ob_end_clean();
      }

      header('Content-Type: image/png');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Content-Length: ' . filesize($filePath));
      header('Cache-Control: private, max-age=0, must-revalidate');

      readfile($filePath);
      exit;
    }

    $zipPath = sys_get_temp_dir() . "/ticketer_png_booking_{$bookingId}_" . time() . '.zip';
    $zip     = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      Response::error('Could not create ticket archive. Please try again.', 500);
      return;
    }

    $i = 1;
    foreach ($existingPaths as $path) {
      $zip->addFile($path, "Ticket_{$i}_Booking_{$bookingIdPadded}.png");
      $i++;
    }
    $zip->close();

    if (!file_exists($zipPath)) {
      Response::error('Could not package ticket images. Please try again.', 500);
      return;
    }

    $filename = "Ticketer_Tickets_#{$bookingIdPadded}.zip";

    if (ob_get_level()) {
      ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($zipPath);

    @unlink($zipPath);
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
    if ($bytes < 1024)    return "{$bytes} B";
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
  }
}
