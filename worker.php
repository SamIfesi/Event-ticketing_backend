<?php

// ============================================================
// QUEUE WORKER
// Runs as a background process or cron job
// Picks up pending jobs and processes them one by one
//
// HOW TO RUN LOCALLY (in a separate terminal):
//   php worker.php
//
// HOW TO RUN ON RAILWAY:
//   Add a cron job in Railway that runs: php worker.php
//   Set it to run every minute: * * * * *
// ============================================================

declare(strict_types=1);

// Load everything the worker needs
require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/services/MailService.php';

$db = Database::connect();

echo "[" . date('Y-m-d H:i:s') . "] Worker started.\n";

// ============================================================
// Fetch up to 10 pending jobs that are ready to run
// ============================================================
$stmt = $db->prepare("
    SELECT * FROM jobs
    WHERE status = 'pending'
      AND available_at <= NOW()
      AND attempts < max_attempts
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
        UPDATE jobs SET status = 'processing', attempts = attempts + 1 WHERE id = ?
    ")->execute([$jobId]);

  try {
    $mailer = new MailService();
    $success = false;

    // ---- Route to the correct mail method ----
    switch ($type) {

      case 'send_otp':
        $success = $mailer->sendOTP(
          $payload['email'],
          $payload['name'],
          $payload['otp'],
          $payload['type']
        );
        break;

      case 'send_ticket_confirmation':
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
        $success = $mailer->sendPasswordChanged(
          $payload['email'],
          $payload['name']
        );
        break;

      default:
        throw new Exception("Unknown job type: {$type}");
    }

    if ($success) {
      // Mark as done
      $db->prepare("
                UPDATE jobs SET status = 'done', completed_at = NOW() WHERE id = ?
            ")->execute([$jobId]);
      echo "[" . date('Y-m-d H:i:s') . "] Job #{$jobId} completed successfully.\n";
    } else {
      throw new Exception('Mail send returned false — check SMTP credentials.');
    }
  } catch (Exception $e) {
    $error = $e->getMessage();
    echo "[" . date('Y-m-d H:i:s') . "] Job #{$jobId} failed: {$error}\n";

    // Check if we've hit max attempts
    $attemptsNow = (int) $job['attempts'] + 1;
    $newStatus   = $attemptsNow >= (int) $job['max_attempts'] ? 'failed' : 'pending';

    // If retrying, wait 60 seconds before next attempt
    $retryAt = date('Y-m-d H:i:s', strtotime('+60 seconds'));

    $db->prepare("
            UPDATE jobs
            SET status       = ?,
                error        = ?,
                available_at = CASE WHEN ? = 'pending' THEN ? ELSE available_at END
            WHERE id = ?
        ")->execute([$newStatus, $error, $newStatus, $retryAt, $jobId]);
  }

  sleep(3);
}

echo "[" . date('Y-m-d H:i:s') . "] Worker finished.\n";
sleep(60); // Sleep before next run (if running in a loop)

// Restart the worker by running this script again (e.g., via cron or a loop)
passthru("php " . __FILE__);