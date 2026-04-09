<?php

class TicketController
{
    private PDO $db;
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->db      = Database::connect();
    }

    // ============================================================
    // GET /api/tickets/:id
    // Protected: ticket owner or event organizer or dev
    // Returns ticket details + QR code URL
    // ============================================================
    public function show(array $params): void
    {
        $ticketId = (int) $params['id'];
        $userId   = $this->request->user['id'];
        $role     = $this->request->user['role'];

        $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.qr_token,
                t.is_used,
                t.used_at,
                t.created_at,
                t.user_id,
                b.quantity,
                b.total_amount,
                b.payment_status,
                e.id          AS event_id,
                e.title       AS event_title,
                e.location    AS event_location,
                e.start_date  AS event_start_date,
                e.end_date    AS event_end_date,
                e.banner_image,
                e.organizer_id,
                tt.name       AS ticket_type,
                u.name        AS attendee_name,
                u.email       AS attendee_email
            FROM tickets t
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN events       e  ON e.id  = t.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN users        u  ON u.id  = t.user_id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            Response::notFound('Ticket not found.');
        }

        // Only the ticket owner, event organizer, or dev can view it
        $isOwner     = (int) $ticket['user_id']      === $userId;
        $isOrganizer = (int) $ticket['organizer_id'] === $userId;
        $isDev       = $role === Constants::ROLE_DEV;

        if (!$isOwner && !$isOrganizer && !$isDev) {
            Response::forbidden('You do not have access to this ticket.');
        }

        // Only show ticket if booking is paid
        if ($ticket['payment_status'] !== Constants::PAYMENT_PAID) {
            Response::error('This ticket is not yet confirmed.', 400);
        }

        // Generate QR code image and attach public URL
        QRCodeService::generate($ticket['qr_token']);
        $ticket['qr_code_url'] = QRCodeService::getUrl($ticket['qr_token']);

        // Don't expose the raw token — React only needs the image URL
        unset($ticket['qr_token']);

        Response::success(['ticket' => $ticket]);
    }

    // ============================================================
    // GET /api/tickets/booking/:bookingId
    // Protected: booking owner or dev
    // Returns ALL tickets under one booking
    // (when a user bought multiple tickets at once)
    // ============================================================
    public function byBooking(array $params): void
    {
        $bookingId = (int) $params['bookingId'];
        $userId    = $this->request->user['id'];
        $role      = $this->request->user['role'];

        $stmt = $this->db->prepare("
            SELECT id, user_id, payment_status FROM bookings WHERE id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            Response::notFound('Booking not found.');
        }

        $isOwner = (int) $booking['user_id'] === $userId;
        $isDev   = $role === Constants::ROLE_DEV;

        if (!$isOwner && !$isDev) {
            Response::forbidden('You do not have access to these tickets.');
        }

        if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
            Response::error('This booking is not yet confirmed.', 400);
        }

        $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.qr_token,
                t.is_used,
                t.used_at,
                t.created_at,
                e.title      AS event_title,
                e.location   AS event_location,
                e.start_date AS event_start_date,
                tt.name      AS ticket_type
            FROM tickets t
            JOIN events       e  ON e.id  = t.event_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE t.booking_id = ?
        ");
        $stmt->execute([$bookingId]);
        $tickets = $stmt->fetchAll();

        foreach ($tickets as &$ticket) {
            QRCodeService::generate($ticket['qr_token']);
            $ticket['qr_code_url'] = QRCodeService::getUrl($ticket['qr_token']);
            unset($ticket['qr_token']);
        }

        Response::success(['tickets' => $tickets]);
    }

    // ============================================================
    // POST /api/tickets/checkin
    // Protected: organizer or dev only
    // Called when organizer scans a QR code at the gate
    // ============================================================
    public function checkin(): void
    {
        $qrToken = trim($this->request->input('qr_token', ''));
        $userId  = $this->request->user['id'];
        $role    = $this->request->user['role'];

        if (empty($qrToken)) {
            Response::validationError(['qr_token' => 'QR token is required.']);
        }

        $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.is_used,
                t.used_at,
                t.user_id,
                e.id          AS event_id,
                e.title       AS event_title,
                e.organizer_id,
                u.name        AS attendee_name,
                tt.name       AS ticket_type
            FROM tickets t
            JOIN events       e  ON e.id  = t.event_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN users        u  ON u.id  = t.user_id
            WHERE t.qr_token = ?
        ");
        $stmt->execute([$qrToken]);
        $ticket = $stmt->fetch();

        // Case 1 — QR token not found
        if (!$ticket) {
            Response::error('Invalid ticket. This QR code is not recognized.', 404);
        }

        // Case 2 — Organizer scanning a ticket for someone else's event
        if (
            $role === Constants::ROLE_ORGANIZER &&
            (int) $ticket['organizer_id'] !== $userId
        ) {
            Response::forbidden('This ticket is not for one of your events.');
        }

        // Case 3 — Ticket already used
        if ($ticket['is_used']) {
            Response::error(
                'This ticket was already used at ' . date('d M Y, g:ia', strtotime($ticket['used_at'])) . '.',
                400
            );
        }

        // Case 4 — All good, mark as used
        $this->db->prepare("
            UPDATE tickets SET is_used = 1, used_at = NOW() WHERE id = ?
        ")->execute([$ticket['id']]);

        Response::success([
            'attendee_name' => $ticket['attendee_name'],
            'ticket_type'   => $ticket['ticket_type'],
            'event_title'   => $ticket['event_title'],
            'checked_in_at' => date('d M Y, g:ia'),
        ], 'Valid ticket. Attendee checked in successfully.');
    }

    // ============================================================
    // GET /api/organizer/events/:id/checkins
    // Protected: organizer or dev
    // Returns all tickets + check-in status for an event
    // ============================================================
    public function checkinList(array $params): void
    {
        $eventId = (int) $params['id'];
        $userId  = $this->request->user['id'];
        $role    = $this->request->user['role'];

        $stmt = $this->db->prepare('SELECT organizer_id FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::notFound('Event not found.');
        }

        if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
            Response::forbidden('This is not your event.');
        }

        $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.is_used,
                t.used_at,
                u.name        AS attendee_name,
                u.email       AS attendee_email,
                tt.name       AS ticket_type
            FROM tickets t
            JOIN users        u  ON u.id  = t.user_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE t.event_id = ?
            ORDER BY t.is_used ASC, u.name ASC
        ");
        $stmt->execute([$eventId]);
        $tickets = $stmt->fetchAll();

        $total     = count($tickets);
        $checkedIn = count(array_filter($tickets, fn($t) => $t['is_used']));

        Response::success([
            'summary' => [
                'total'      => $total,
                'checked_in' => $checkedIn,
                'remaining'  => $total - $checkedIn,
            ],
            'tickets' => $tickets,
        ]);
    }
}