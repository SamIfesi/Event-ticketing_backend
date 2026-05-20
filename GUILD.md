# COMPLETE PRECISE INTEGRATION GUIDE
# Every change shows the EXACT surrounding code from your file.
# Find that block → add the new code exactly where marked.
# ══════════════════════════════════════════════════════════════


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 1 — Run the SQL migration FIRST                        │
# └──────────────────────────────────────────────────────────────┘
# Open phpMyAdmin → select your database → SQL tab
# Paste and run: database/migration_full_system.sql
# This creates: notifications, transaction_logs,
#               organizer_payment_details, event_payouts
# And alters:   events (adds platform_fee_percentage column)


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 2 — Drop new files into your project                   │
# └──────────────────────────────────────────────────────────────┘
#
# REPLACE (full file replacement):
#   config/Constants.php          ← new payout + strike constants
#   services/PaystackService.php  ← added 6 new Paystack methods
#
# ADD (new files):
#   services/NotificationService.php
#   services/TransactionService.php
#   services/PayoutService.php
#   controllers/NotificationController.php  ← from Controllers.php
#   controllers/TransactionController.php   ← from Controllers.php
#   controllers/PayoutController.php        ← from Controllers.php
#   controllers/OrganizerPaymentController.php
#   payout_worker.php
#
# For the routes, split all_routes.php into 4 separate files:
#   routes/notifications.php      ← lines 1-8 of all_routes.php
#   routes/transactions.php       ← lines 11-30
#   routes/organizer_payment.php  ← lines 33-57
#   routes/payouts.php            ← lines 60-85
#
# NOTE: Controllers.php contains 3 classes. Split it:
#   → Cut the PayoutController class → controllers/PayoutController.php
#   → Cut the NotificationController class → controllers/NotificationController.php
#   → Cut the TransactionController class → controllers/TransactionController.php
#   (Each file gets its own <?php tag at the top)


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 3 — index.php                                          │
# └──────────────────────────────────────────────────────────────┘

# ── CHANGE 3A: Add new require_once for services ──────────────
# Find this line in the AUTOLOAD section:

require_once __DIR__ . '/services/QueueService.php';

# ADD directly below it:

require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/TransactionService.php';
require_once __DIR__ . '/services/PayoutService.php';

# ── CHANGE 3B: Add new require_once for controllers ───────────
# Find this line in the AUTOLOAD section:

require_once __DIR__ . '/controllers/DevController.php';

# ADD directly below it:

require_once __DIR__ . '/controllers/NotificationController.php';
require_once __DIR__ . '/controllers/TransactionController.php';
require_once __DIR__ . '/controllers/PayoutController.php';
require_once __DIR__ . '/controllers/OrganizerPaymentController.php';

# ── CHANGE 3C: Register new routes ───────────────────────────
# Find this block in the REGISTER ROUTES section:

require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/dev.php';

# REPLACE with:

require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/notifications.php';
require_once __DIR__ . '/routes/transactions.php';
require_once __DIR__ . '/routes/organizer_payment.php';
require_once __DIR__ . '/routes/payouts.php';
require_once __DIR__ . '/routes/dev.php';


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 4 — database/migration_full_system.sql                 │
# │  Add paystack_recipient_code column                          │
# └──────────────────────────────────────────────────────────────┘
# The organizer_payment_details table needs one extra column.
# After running the migration, run this additional ALTER:

ALTER TABLE `organizer_payment_details`
  ADD COLUMN `paystack_recipient_code` VARCHAR(100) DEFAULT NULL
  AFTER `paystack_subaccount_id`;


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 5 — controllers/BookingController.php                  │
# └──────────────────────────────────────────────────────────────┘

# ── CHANGE 5A: Add organizer_id to the SELECT in verify() ─────
# Find the big SELECT query at the top of verify(). It currently has:

    $stmt = $this->db->prepare("
            SELECT b.*, tt.name AS ticket_type_name, e.title AS event_title,
                   e.location AS event_location, e.start_date AS event_start_date,
                   u.name AS user_name, u.email AS user_email

# ADD e.organizer_id to that list:

    $stmt = $this->db->prepare("
            SELECT b.*, tt.name AS ticket_type_name, e.title AS event_title,
                   e.location AS event_location, e.start_date AS event_start_date,
                   e.organizer_id,
                   u.name AS user_name, u.email AS user_email


# ── CHANGE 5B: Log payment initiation in store() ──────────────
# Find this block at the END of store(), just before Response::success():

    Response::success([
      'booking_id'        => $bookingId,
      'reference'         => $reference,
      'amount'            => $totalAmount,
      'authorization_url' => $transaction['authorization_url'],
      'access_code'       => $transaction['access_code'],
    ], 'Payment initialized. Complete your payment to confirm booking.');

# ADD this block DIRECTLY ABOVE that Response::success():

    // ── NEW: transaction audit log ──
    $orgStmt = $this->db->prepare("SELECT organizer_id FROM events WHERE id = ?");
    $orgStmt->execute([$ticketType['event_id']]);
    $organizerId = (int) $orgStmt->fetchColumn();

    TransactionService::paymentInitiated([
        'id'                 => $bookingId,
        'user_id'            => $userId,
        'event_id'           => $ticketType['event_id'],
        'paystack_reference' => $reference,
        'quantity'           => $quantity,
        'unit_price'         => $unitPrice,
        'ticket_type_name'   => $ticketType['name'],
        'event_title'        => $ticketType['event_title'],
    ], $organizerId);
    // ── END NEW ──


# ── CHANGE 5C: Log failed payment in verify() ─────────────────
# Find this exact block inside verify():

    if ($transaction['status'] !== 'success') {
      // Mark booking as failed
      $this->db->prepare("UPDATE bookings SET payment_status = 'failed' WHERE id = ?")
        ->execute([$booking['id']]);
      Response::error('Payment was not successful. Please try again.', 400);
    }

# ADD two lines between execute() and Response::error():

    if ($transaction['status'] !== 'success') {
      $this->db->prepare("UPDATE bookings SET payment_status = 'failed' WHERE id = ?")
        ->execute([$booking['id']]);

      // ── NEW ──
      TransactionService::paymentFailed($booking, 'Paystack returned: ' . $transaction['status']);
      NotificationService::bookingFailed((int)$booking['user_id'], (int)$booking['id'], $booking['event_title']);
      // ── END NEW ──

      Response::error('Payment was not successful. Please try again.', 400);
    }


# ── CHANGE 5D: Log confirmed payment + notifications in verify() ──
# Find this block (the UPDATE that marks booking as paid):

    $this->db->prepare("
            UPDATE bookings
            SET payment_status = 'paid', paid_at = NOW()
            WHERE id = ?
        ")->execute([$booking['id']]);

    // Generate one ticket row per quantity purchased
    $tickets = [];

# ADD the new block BETWEEN the execute() and the "Generate one ticket" comment:

    $this->db->prepare("
            UPDATE bookings
            SET payment_status = 'paid', paid_at = NOW()
            WHERE id = ?
        ")->execute([$booking['id']]);

    // ── NEW: fee calculation + audit + notifications + payout accumulation ──
    $feePercent      = PayoutService::getFeePercentage((int)$booking['event_id'], (int)$booking['organizer_id']);
    $split           = PayoutService::calculateSplit((float)$booking['total_amount'], $feePercent);

    TransactionService::paymentConfirmed(
        $booking,
        $split['platform_fee'],
        $split['organizer_amount'],
        $transaction['status']
    );

    PayoutService::accumulateRevenue(
        (int)   $booking['event_id'],
        (int)   $booking['organizer_id'],
        (float) $booking['total_amount'],
        $feePercent
    );

    NotificationService::bookingConfirmed(
        (int)   $booking['user_id'],
        (int)   $booking['id'],
        $booking['event_title'],
        (int)   $booking['event_id'],
        (int)   $booking['quantity'],
        (float) $booking['total_amount']
    );

    NotificationService::newBookingReceived(
        (int)   $booking['organizer_id'],
        (int)   $booking['id'],
        $booking['user_name'],
        $booking['event_title'],
        (int)   $booking['event_id'],
        (int)   $booking['quantity'],
        (float) $booking['total_amount']
    );

    // Low tickets warning — fires if ≤10% tickets remain
    $salesStmt = $this->db->prepare("
        SELECT tt.quantity, COALESCE(s.tickets_sold,0) AS sold
        FROM ticket_types tt
        LEFT JOIN v_event_sales s ON s.event_id = tt.event_id
        WHERE tt.id = ?
    ");
    $salesStmt->execute([$booking['ticket_type_id']]);
    $sales = $salesStmt->fetch();
    if ($sales) {
        $remaining = (int)$sales['quantity'] - (int)$sales['sold'];
        $pct = $sales['quantity'] > 0 ? ($remaining / $sales['quantity']) : 1;
        if ($pct <= 0.10 && $remaining > 0) {
            NotificationService::lowTicketsWarning(
                (int)$booking['organizer_id'], (int)$booking['event_id'],
                $booking['event_title'], $remaining, (int)$sales['quantity']
            );
        }
    }
    // ── END NEW ──

    // Generate one ticket row per quantity purchased
    $tickets = [];


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 6 — controllers/EventController.php                    │
# └──────────────────────────────────────────────────────────────┘

# ── CHANGE 6A: Block publishing without bank details ──────────
# Find this block inside store(), just before the INSERT:

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // Generate a URL slug from the title
    $slug = $this->generateSlug($input['title']);

# ADD the bank details check BETWEEN the error check and the slug generation:

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // ── NEW: block publishing if no bank details ──
    $requestedStatus = $input['status'] ?? Constants::EVENT_DRAFT;
    if ($requestedStatus === Constants::EVENT_PUBLISHED) {
        $bankStmt = $this->db->prepare("
            SELECT id FROM organizer_payment_details
            WHERE user_id = ? AND is_verified = 1
        ");
        $bankStmt->execute([$this->request->user['id']]);
        if (!$bankStmt->fetch()) {
            NotificationService::bankDetailsRequired((int)$this->request->user['id']);
            Response::error(
                'You must add your bank account details before publishing an event. Go to Payment Settings.',
                403
            );
        }
    }
    // ── END NEW ──

    // Generate a URL slug from the title
    $slug = $this->generateSlug($input['title']);


# ── CHANGE 6B: Same bank details check in update() ───────────
# Find this block inside update(), just before the UPDATE statement:

    // Only update fields that were actually sent
    $stmt = $this->db->prepare("
            UPDATE events SET

# ADD before it:

    // ── NEW: block publishing without bank details ──
    if (isset($input['status']) && $input['status'] === Constants::EVENT_PUBLISHED) {
        $bankStmt = $this->db->prepare("
            SELECT id FROM organizer_payment_details
            WHERE user_id = ? AND is_verified = 1
        ");
        $bankStmt->execute([$this->request->user['id']]);
        if (!$bankStmt->fetch()) {
            NotificationService::bankDetailsRequired((int)$this->request->user['id']);
            Response::error(
                'You must add your bank account details before publishing an event. Go to Payment Settings.',
                403
            );
        }
    }
    // ── END NEW ──

    // Only update fields that were actually sent
    $stmt = $this->db->prepare("


# ── CHANGE 6C: Strike system + notify attendees in destroy() ──
# Find the end of destroy() — currently:

    $this->db->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?")
      ->execute([$eventId]);

    Response::success(null, 'Event cancelled successfully. All existing bookings and tickets are preserved.');

# REPLACE with:

    $this->db->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?")
      ->execute([$eventId]);

    // ── NEW: strike system + notify attendees ──
    $wasFlagged = PayoutService::recordCancellation((int)$event['organizer_id']);
    PayoutService::cancelPayout($eventId);

    NotificationService::notifyEventAttendees(
        $this->db, $eventId, 'event_cancelled',
        "Event Cancelled — {$event['title']}",
        "{$event['title']} has been cancelled by the organizer. Please contact support if you need a refund.",
        "/bookings"
    );
    // ── END NEW ──

    Response::success(null, 'Event cancelled successfully. All existing bookings and tickets are preserved.');


# ── CHANGE 6D: Update hold_until when event end_date changes ──
# Find the very end of update(), just before Response::success():

    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);

    Response::success(['event' => $stmt->fetch()], 'Event updated successfully.');

# ADD between execute() and Response::success():

    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $updatedEvent = $stmt->fetch();

    // ── NEW: update payout hold_until if end_date changed ──
    if (!empty($input['end_date'])) {
        PayoutService::setHoldUntil($eventId, $updatedEvent['end_date']);
    }
    // ── END NEW ──

    Response::success(['event' => $updatedEvent], 'Event updated successfully.');


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 7 — controllers/TicketController.php                   │
# └──────────────────────────────────────────────────────────────┘

# ── CHANGE 7A: Notify attendee on check-in ───────────────────
# Find the end of checkin() Case 4:

        $this->db->prepare("
            UPDATE tickets SET is_used = 1, used_at = NOW() WHERE id = ?
        ")->execute([$ticket['id']]);

        Response::success([
            'attendee_name' => $ticket['attendee_name'],

# ADD between execute() and Response::success():

        $this->db->prepare("
            UPDATE tickets SET is_used = 1, used_at = NOW() WHERE id = ?
        ")->execute([$ticket['id']]);

        // ── NEW ──
        NotificationService::ticketCheckedIn(
            (int) $ticket['user_id'],
            (int) $ticket['id'],
            $ticket['event_title']
        );
        // ── END NEW ──

        Response::success([
            'attendee_name' => $ticket['attendee_name'],


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 8 — controllers/AdminController.php                    │
# └──────────────────────────────────────────────────────────────┘

# ── CHANGE 8A: Notify user when role changes ─────────────────
# Find the end of updateRole():

    $this->db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);

    Response::success(null, "User role updated to '{$newRole}' successfully.");

# ADD between execute() and Response::success():

    $this->db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);

    // ── NEW ──
    NotificationService::roleChanged($targetId, $newRole);
    // ── END NEW ──

    Response::success(null, "User role updated to '{$newRole}' successfully.");


# ── CHANGE 8B: Notify user when deactivated ──────────────────
# Find the end of updateStatus():

    $this->db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$isActive ? 1 : 0, $targetId]);

    Response::success(null, $isActive ? 'User account activated.' : 'User account deactivated.');

# ADD between execute() and Response::success():

    $this->db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$isActive ? 1 : 0, $targetId]);

    // ── NEW ──
    if (!$isActive) {
        NotificationService::accountDeactivated($targetId);
    }
    // ── END NEW ──

    Response::success(null, $isActive ? 'User account activated.' : 'User account deactivated.');


# ── CHANGE 8C: Notify attendees when admin cancels event ──────
# Find the if/else at the end of updateEventStatus():

    if ($newStatus === Constants::EVENT_DELETED) {
      $this->db->prepare("
                UPDATE events SET status = 'deleted', deleted_at = NOW() WHERE id = ?
            ")->execute([$eventId]);
    } else {
      $this->db->prepare("
                UPDATE events SET status = ?, deleted_at = NULL WHERE id = ?
            ")->execute([$newStatus, $eventId]);
    }

    Response::success(null, "Event status updated to '{$newStatus}'.");

# ADD between the closing } and Response::success():

    if ($newStatus === Constants::EVENT_DELETED) {
      $this->db->prepare("UPDATE events SET status = 'deleted', deleted_at = NOW() WHERE id = ?")->execute([$eventId]);
    } else {
      $this->db->prepare("UPDATE events SET status = ?, deleted_at = NULL WHERE id = ?")->execute([$newStatus, $eventId]);
    }

    // ── NEW ──
    if (in_array($newStatus, ['cancelled', 'deleted'])) {
        $evtStmt = $this->db->prepare("SELECT title FROM events WHERE id = ?");
        $evtStmt->execute([$eventId]);
        $evtTitle = $evtStmt->fetchColumn();

        NotificationService::notifyEventAttendees(
            $this->db, $eventId, 'event_cancelled',
            "Event Cancelled — {$evtTitle}",
            "{$evtTitle} has been cancelled. Please contact support if you need a refund.",
            "/bookings"
        );
        PayoutService::cancelPayout($eventId);
    }
    // ── END NEW ──

    Response::success(null, "Event status updated to '{$newStatus}'.");


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 9 — controllers/OrganizerApplicationController.php     │
# └──────────────────────────────────────────────────────────────┘

# ── CHANGE 9A: Notify user on application decision ───────────
# Find this block at the end of reviewApplication():

      $message = $decision === 'approved'
        ? 'Application approved. User has been upgraded to organizer.'
        : 'Application rejected.';

      Response::success(null, $message);

# ADD directly ABOVE the $message line:

      // ── NEW ──
      if ($decision === 'approved') {
          NotificationService::organizerApproved($application['user_id'], $application['org_name']);
      } else {
          NotificationService::organizerRejected($application['user_id'], $application['org_name']);
      }
      // ── END NEW ──

      $message = $decision === 'approved'
        ? 'Application approved. User has been upgraded to organizer.'
        : 'Application rejected.';

      Response::success(null, $message);


# ┌──────────────────────────────────────────────────────────────┐
# │  STEP 10 — controllers/DevController.php                     │
# └──────────────────────────────────────────────────────────────┘

# ── CHANGE 10A: Audit log + notify on force-pay ──────────────
# Find the end of forcePay():

    Response::success([
      'booking_id' => $bookingId,
      'tickets'    => $tickets,
    ], 'Booking force-paid and tickets issued.');

# ADD directly ABOVE that Response::success():

    // ── NEW ──
    $orgStmt = $this->db->prepare("SELECT organizer_id FROM events WHERE id = ?");
    $orgStmt->execute([$booking['event_id']]);
    $organizerId = (int) $orgStmt->fetchColumn();

    TransactionService::forcedPayment(array_merge($booking, [
        'organizer_id'     => $organizerId,
        'ticket_type_name' => $booking['ticket_type_name'],
    ]), (int) $this->request->user['id']);

    NotificationService::bookingConfirmed(
        (int)   $booking['user_id'],
        (int)   $booking['id'],
        $booking['event_title'],
        (int)   $booking['event_id'],
        (int)   $booking['quantity'],
        (float) $booking['total_amount']
    );
    // ── END NEW ──

    Response::success([
      'booking_id' => $bookingId,
      'tickets'    => $tickets,
    ], 'Booking force-paid and tickets issued.');


# ══════════════════════════════════════════════════════════════
# SUMMARY
# ══════════════════════════════════════════════════════════════
#
# Files replaced (full replacement):
#   config/Constants.php
#   services/PaystackService.php
#
# New files added:
#   services/NotificationService.php
#   services/TransactionService.php
#   services/PayoutService.php
#   controllers/NotificationController.php
#   controllers/TransactionController.php
#   controllers/PayoutController.php
#   controllers/OrganizerPaymentController.php
#   routes/notifications.php
#   routes/transactions.php
#   routes/organizer_payment.php
#   routes/payouts.php
#   payout_worker.php
#
# Files with additions only (existing logic untouched):
#   index.php                              (Steps 3A, 3B, 3C)
#   controllers/BookingController.php      (Steps 5A, 5B, 5C, 5D)
#   controllers/EventController.php        (Steps 6A, 6B, 6C, 6D)
#   controllers/TicketController.php       (Step 7A)
#   controllers/AdminController.php        (Steps 8A, 8B, 8C)
#   controllers/OrganizerApplicationController.php (Step 9A)
#   controllers/DevController.php          (Step 10A)
#
# Railway cron to add:
#   php payout_worker.php  →  schedule: 0 2 * * *  (2am daily)