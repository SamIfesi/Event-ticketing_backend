<?php

class BookingController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // POST /api/bookings
  // Protected: attendee or dev
  // Step 1 of payment flow — creates a pending booking
  // and returns Paystack payment details to React
  // ============================================================
  public function store(): void
  {
    $userId       = $this->request->user['id'];
    $userEmail    = $this->request->user['email'];
    $ticketTypeId = (int) $this->request->input('ticket_type_id');
    $quantity     = max(1, (int) $this->request->input('quantity', 1));

    if (!$ticketTypeId) {
      Response::validationError(['ticket_type_id' => 'Please select a ticket type.']);
    }

    $stmt = $this->db->prepare("
            SELECT
                tt.*,
                e.id         AS event_id,
                e.title      AS event_title,
                e.status     AS event_status,
                e.start_date AS event_start_date
            FROM ticket_types tt
            JOIN events e ON e.id = tt.event_id
            WHERE tt.id = ?
        ");
    $stmt->execute([$ticketTypeId]);
    $ticketType = $stmt->fetch();

    if (!$ticketType) {
      Response::notFound('Ticket type not found.');
    }

    // 3. Make sure the event is still published
    if ($ticketType['event_status'] !== Constants::EVENT_PUBLISHED) {
      Response::error('This event is no longer available.', 400);
    }

    // 4. Make sure the event hasn't started yet
    if (strtotime($ticketType['event_start_date']) < time()) {
      Response::error('Ticket sales for this event have ended.', 400);
    }

    // 5. Check if sales deadline has passed (if one was set)
    if ($ticketType['sales_end_at'] && strtotime($ticketType['sales_end_at']) < time()) {
      Response::error('Ticket sales for this tier have ended.', 400);
    }

    // 6. Check availability
    $available = (int) $ticketType['quantity'] - (int) $ticketType['quantity_sold'];
    if ($quantity > $available) {
      Response::error(
        $available === 0
          ? 'Sorry, this ticket type is sold out.'
          : "Only {$available} ticket(s) remaining.",
        400
      );
    }

    // 7. Calculate total
    $unitPrice   = (float) $ticketType['price'];
    $totalAmount = $unitPrice * $quantity;

    // 8. FREE TICKET — skip Paystack entirely
    if ($totalAmount == 0) {
      $stmt = $this->db->prepare("
        INSERT INTO bookings
            (user_id, event_id, ticket_type_id, quantity, unit_price, total_amount, payment_status, paid_at)
        VALUES
            (?, ?, ?, ?, 0, 0, 'paid', NOW())
    ");
      $stmt->execute([
        $userId,
        $ticketType['event_id'],
        $ticketTypeId,
        $quantity,
      ]);

      $bookingId = $this->db->lastInsertId();

      // Issue tickets immediately
      $tickets = [];
      $stmt    = $this->db->prepare("
        INSERT INTO tickets (booking_id, user_id, event_id, qr_token)
        VALUES (?, ?, ?, ?)
    ");

      for ($i = 0; $i < $quantity; $i++) {
        $qrToken = TokenHelper::generateQRToken();
        $stmt->execute([$bookingId, $userId, $ticketType['event_id'], $qrToken]);
        $tickets[] = ['id' => $this->db->lastInsertId(), 'qr_token' => $qrToken];
      }

      QueueService::sendTicketConfirmation(
        $userEmail,
        $this->request->user['name'],
        $ticketType['event_title'],
        $ticketType['event_start_date'],
        $ticketType['location'] ?? '',
        $ticketType['name'],
        $quantity,
        0,
        Environment::get('APP_URL') . '/dashboard'
      );

      Response::success([
        'booking_id' => $bookingId,
        'free'       => true,
        'tickets'    => $tickets,
      ], 'Your free ticket has been issued!');
    }

    // 9. Generate a unique Paystack reference
    $reference = TokenHelper::generatePaystackReference();

    // 10. Create a PENDING booking in the database
    //    It stays pending until payment is verified
    $stmt = $this->db->prepare("
            INSERT INTO bookings
                (user_id, event_id, ticket_type_id, quantity, unit_price, total_amount, paystack_reference, payment_status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
    $stmt->execute([
      $userId,
      $ticketType['event_id'],
      $ticketTypeId,
      $quantity,
      $unitPrice,
      $totalAmount,
      $reference,
    ]);

    $bookingId = $this->db->lastInsertId();

    // 11. Initialize transaction with Paystack
    //     If Paystack is down or the key is wrong, we catch the error
    try {
      $paystack    = new PaystackService();
      $transaction = $paystack->initializeTransaction(
        $userEmail,
        $totalAmount,
        $reference,
        [
          'booking_id'  => $bookingId,
          'event_title' => $ticketType['event_title'],
          'quantity'    => $quantity,
        ]
      );

      // 12. Return the Paystack data React needs to open the payment popup
      Response::success([
        'booking_id'        => $bookingId,
        'reference'         => $reference,
        'amount'            => $totalAmount,
        'authorization_url' => $transaction['authorization_url'],
        'access_code'       => $transaction['access_code'],
      ], 'Payment initialized. Complete your payment to confirm booking.');
    } catch (Exception $e) {
      // If Paystack initialization fails, delete the pending booking
      $this->db->prepare('DELETE FROM bookings WHERE id = ?')->execute([$bookingId]);
      error_log('Paystack error: ' . $e->getMessage());
      Response::error('Payment initialization failed: ' . $e->getMessage(), 500);
    }
  }

  // ============================================================
  // POST /api/bookings/verify
  // Protected: attendee or dev
  // Step 2 — verifies Paystack payment and issues tickets.
  //
  // FIX #1: Removed the two manual UPDATE statements that were
  // incrementing ticket_types.quantity_sold and events.tickets_sold.
  // Both are now handled automatically:
  //   - ticket_types.quantity_sold → trg_booking_paid trigger
  //   - events.tickets_sold        → column removed; use v_event_sales
  // Keeping those updates here caused double-increments on every payment.
  // ============================================================
  public function verify(): void
  {
    $reference = trim($this->request->input('reference', ''));
    $userId    = $this->request->user['id'];

    if (empty($reference)) {
      Response::validationError(['reference' => 'Payment reference is required.']);
    }

    // 1. Find the booking by reference
    $stmt = $this->db->prepare("
            SELECT b.*, tt.name AS ticket_type_name, e.title AS event_title,
                   e.location AS event_location, e.start_date AS event_start_date,
                   u.name AS user_name, u.email AS user_email
            FROM bookings b
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN events e        ON e.id  = b.event_id
            JOIN users u         ON u.id  = b.user_id
            WHERE b.paystack_reference = ?
        ");
    $stmt->execute([$reference]);
    $booking = $stmt->fetch();

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    // 2. Make sure this booking belongs to the requesting user
    if ((int) $booking['user_id'] !== $userId) {
      Response::forbidden('This booking does not belong to you.');
    }

    // 3. If booking is already paid, don't process again
    //    (user might refresh the success page)
    if ($booking['payment_status'] === Constants::PAYMENT_PAID) {
      Response::success(['booking_id' => $booking['id']], 'Booking already confirmed.');
    }

    // 4. Verify with Paystack — this is the critical step
    //    We ask Paystack directly: "did this payment actually go through?"
    try {
      $paystack    = new PaystackService();
      $transaction = $paystack->verifyTransaction($reference);

      // 5. Check Paystack says payment was successful
      if ($transaction['status'] !== 'success') {
        // Mark booking as failed
        $this->db->prepare("UPDATE bookings SET payment_status = 'failed' WHERE id = ?")
          ->execute([$booking['id']]);
        Response::error('Payment was not successful. Please try again.', 400);
      }

      // 6. Double-check the amount paid matches what we expected
      //    Prevents someone from paying ₦1 for a ₦10,000 ticket
      $amountPaidKobo    = (int) $transaction['amount'];
      $expectedKobo      = (int) ($booking['total_amount'] * 100);

      if ($amountPaidKobo < $expectedKobo) {
        Response::error('Payment amount does not match. Please contact support.', 400);
      }

      // Mark booking as paid.
      // The trg_booking_paid trigger fires here automatically and increments
      // ticket_types.quantity_sold — no manual UPDATE needed.
      $this->db->prepare("
            UPDATE bookings
            SET payment_status = 'paid', paid_at = NOW()
            WHERE id = ?
        ")->execute([$booking['id']]);

      // Generate one ticket row per quantity purchased
      $tickets = [];
      $stmt    = $this->db->prepare("
            INSERT INTO tickets (booking_id, user_id, event_id, qr_token)
            VALUES (?, ?, ?, ?)
        ");

      for ($i = 0; $i < (int) $booking['quantity']; $i++) {
        $qrToken = TokenHelper::generateQRToken();
        $stmt->execute([
          $booking['id'],
          $booking['user_id'],
          $booking['event_id'],
          $qrToken,
        ]);
        $tickets[] = [
          'id'       => $this->db->lastInsertId(),
          'qr_token' => $qrToken,
        ];
      }

      // Queue ticket confirmation email
      QueueService::sendTicketConfirmation(
        $booking['user_email'],
        $booking['user_name'],
        $booking['event_title'],
        $booking['event_start_date'],
        $booking['event_location'],
        $booking['ticket_type_name'],
        (int) $booking['quantity'],
        (float) $booking['total_amount'],
        Environment::get('APP_URL') . '/dashboard'
      );

      Response::success([
        'booking_id' => $booking['id'],
        'event'      => $booking['event_title'],
        'tickets'    => $tickets,
      ], 'Payment confirmed! Your tickets have been issued.');
    } catch (Exception $e) {
      Response::error('Could not verify payment. Please contact support.', 500);
    }
  }

  // ============================================================
  // GET /api/bookings/mine
  // FIX #6: Added deleted_at IS NULL filter
  // ============================================================
  public function myBookings(): void
  {
    $userId = $this->request->user['id'];

    $stmt = $this->db->prepare("
            SELECT
                b.id,
                b.quantity,
                b.total_amount,
                b.payment_status,
                b.paid_at,
                b.refunded_at,
                b.created_at,
                e.title      AS event_title,
                e.location   AS event_location,
                e.start_date AS event_start_date,
                e.banner_image,
                tt.name      AS ticket_type
            FROM bookings b
            JOIN events       e  ON e.id  = b.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE b.user_id = ?
              AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC
        ");
    $stmt->execute([$userId]);

    Response::success(['bookings' => $stmt->fetchAll()]);
  }

  // ============================================================
  // GET /api/bookings/:id
  // FIX #6: Added deleted_at IS NULL check
  // ============================================================
  public function show(array $params): void
  {
    $bookingId = (int) $params['id'];
    $userId    = $this->request->user['id'];
    $role      = $this->request->user['role'];

    $stmt = $this->db->prepare("
            SELECT
                b.*,
                e.title      AS event_title,
                e.location   AS event_location,
                e.start_date AS event_start_date,
                e.organizer_id,
                tt.name      AS ticket_type,
                u.name       AS attendee_name,
                u.email      AS attendee_email
            FROM bookings b
            JOIN events       e  ON e.id  = b.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN users        u  ON u.id  = b.user_id
            WHERE b.id = ?
              AND b.deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    // Only the attendee, the event organizer, or dev can see this booking
    $isOwner     = (int) $booking['user_id']      === $userId;
    $isOrganizer = (int) $booking['organizer_id'] === $userId;
    $isDev       = $role === Constants::ROLE_DEV;

    if (!$isOwner && !$isOrganizer && !$isDev) {
      Response::forbidden('You do not have access to this booking.');
    }

    // Also fetch the tickets for this booking
    $stmt = $this->db->prepare("
            SELECT id, qr_token, is_used, used_at, created_at
            FROM tickets
            WHERE booking_id = ? AND deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    $booking['tickets'] = $stmt->fetchAll();

    Response::success(['booking' => $booking]);
  }

  // ============================================================
  // GET /api/organizer/events/:id/bookings
  // FIX #6: Added deleted_at IS NULL filter
  // ============================================================
  public function eventBookings(array $params): void
  {
    $eventId = (int) $params['id'];
    $userId  = $this->request->user['id'];
    $role    = $this->request->user['role'];

    // Confirm the event exists and belongs to this organizer
    $stmt = $this->db->prepare('SELECT organizer_id FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
      Response::forbidden('You can only view bookings for your own events.');
    }

    $stmt = $this->db->prepare("
            SELECT
                b.id,
                b.quantity,
                b.total_amount,
                b.payment_status,
                b.paid_at,
                b.refunded_at,
                u.name  AS attendee_name,
                u.email AS attendee_email,
                tt.name AS ticket_type
            FROM bookings b
            JOIN users        u  ON u.id  = b.user_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE b.event_id = ?
              AND b.payment_status = 'paid'
              AND b.deleted_at IS NULL
            ORDER BY b.paid_at DESC
        ");
    $stmt->execute([$eventId]);

    Response::success(['bookings' => $stmt->fetchAll()]);
  }
}
