<?php

// ============================================================
// QUEUE WORKER
// Runs as a background process or cron job.
// Picks up pending jobs and processes them one by one.
//
// HOW TO RUN LOCALLY (in a separate terminal):
//   php worker.php
//
// HOW TO RUN ON RAILWAY / DOCKER:
//   Add a cron job: * * * * * php /var/www/html/worker.php
//   Or keep it running: while true; do php worker.php; sleep 30; done
// ============================================================

declare(strict_types=1);

// Load everything the worker needs
require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/services/PDFService.php';

$db = Database::connect();

echo "[" . date('Y-m-d H:i:s') . "] Worker started.\n";

// ============================================================
// Fetch up to 10 pending jobs that are ready to run
// ============================================================
$stmt = $db->prepare("
    SELECT * FROM jobs
    WHERE status       = 'pending'
      AND available_at <= NOW()
      AND attempts      < max_attempts
    ORDER BY created_at ASC
    LIMIT 10
");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) {
  echo "[" . date('Y-m-d H:i:s') . "] No pending jobs. Exiting.\n";
  exit;
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($jobs) . " job(s) to process.\n";

foreach ($jobs as $job) {
  $jobId   = $job['id'];
  $type    = $job['type'];
  $payload = json_decode($job['payload'], true);

  echo "[" . date('Y-m-d H:i:s') . "] Processing job #{$jobId} — type: {$type}\n";

  // Mark as processing so another worker doesn't pick it up
  $db->prepare("
        UPDATE jobs
        SET status = 'processing', attempts = attempts + 1
        WHERE id = ?
    ")->execute([$jobId]);

  try {
    $success = false;

    switch ($type) {

      // ── EMAIL JOBS ────────────────────────────────────

      case 'send_otp':
        $mailer  = new MailService();
        $success = $mailer->sendOTP(
          $payload['email'],
          $payload['name'],
          $payload['otp'],
          $payload['type']
        );
        break;

      case 'send_ticket_confirmation':
        $mailer  = new MailService();
        $success = $mailer->sendTicketConfirmation(
          $payload['email'],
          $payload['name'],
          $payload['event_title'],
          $payload['event_date'],
          $payload['event_location'],
          $payload['ticket_type'],
          $payload['quantity'],
          $payload['total_amount'],
          $payload['dashboard_url']
        );
        break;

      case 'send_password_changed':
        $mailer  = new MailService();
        $success = $mailer->sendPasswordChanged(
          $payload['email'],
          $payload['name']
        );
        break;

      // ── PDF JOBS ──────────────────────────────────────

      case 'generate_ticket':
        $bookingId = (int) ($payload['booking_id'] ?? 0);

        if ($bookingId === 0) {
          throw new Exception('generate_ticket job missing booking_id in payload.');
        }

        echo "[" . date('Y-m-d H:i:s') . "] Generating ticket PDF for booking #{$bookingId}\n";

        // Skip if already generated (idempotent)
        if (PDFService::ticketExists($bookingId)) {
          echo "[" . date('Y-m-d H:i:s') . "] Ticket for booking #{$bookingId} already exists. Skipping.\n";
          $success = true;
          break;
        }

        $filePaths = PDFService::generateTicket($bookingId);
        $filePath = $filePaths[0];
        $fileSize = filesize($filePath);

        echo "[" . date('Y-m-d H:i:s') . "] Ticket generated: {$filePath} (" . round($fileSize / 1024, 1) . " KB)\n";
        $success = true;
        break;

      // ── UNKNOWN JOB TYPE — skip gracefully ────────────

      default:
        echo "[" . date('Y-m-d H:i:s') . "] Unknown job type '{$type}' — marking as failed.\n";
        throw new Exception("Unknown job type: {$type}");
    }

    if ($success) {
      $db->prepare("
                UPDATE jobs
                SET status       = 'done',
                    completed_at = NOW()
                WHERE id = ?
            ")->execute([$jobId]);

      echo "[" . date('Y-m-d H:i:s') . "] ✓ Job #{$jobId} completed.\n";
    } else {
      throw new Exception('Job handler returned false — check service logs.');
    }
  } catch (Exception $e) {
    $error        = $e->getMessage();
    $attemptsNow  = (int) $job['attempts'] + 1;
    $newStatus    = $attemptsNow >= (int) $job['max_attempts'] ? 'failed' : 'pending';

    // Exponential backoff: 1min, 5min, 15min
    $backoffSeconds = match ($attemptsNow) {
      1       => 60,
      2       => 300,
      default => 900,
    };
    $retryAt = date('Y-m-d H:i:s', strtotime("+{$backoffSeconds} seconds"));

    echo "[" . date('Y-m-d H:i:s') . "] ✗ Job #{$jobId} failed (attempt {$attemptsNow}): {$error}\n";

    $db->prepare("
            UPDATE jobs
            SET status       = ?,
                error        = ?,
                available_at = CASE WHEN ? = 'pending' THEN ? ELSE available_at END
            WHERE id = ?
        ")->execute([$newStatus, $error, $newStatus, $retryAt, $jobId]);
  }

  // Small delay between jobs to avoid overwhelming services
  sleep(2);
}

echo "[" . date('Y-m-d H:i:s') . "] Worker finished.\n";
