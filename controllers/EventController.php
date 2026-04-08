<?php

class EventController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // GET /api/events
  // Public — anyone can see published events
  // Supports filtering by: category, search, date
  // Supports pagination: ?page=1&limit=12
  // ============================================================
  public function index(): void
  {
    // Grab optional filter params from query string
    $page       = max(1, (int) $this->request->query('page', '1'));
    $limit      = min(50, max(1, (int) $this->request->query('limit', '12')));
    $offset     = ($page - 1) * $limit;
    $search     = trim($this->request->query('search', ''));
    $categoryId = $this->request->query('category', '');
    $dateFilter = $this->request->query('date', ''); // 'upcoming' | 'past' | ''

    // Build query dynamically based on filters
    $conditions = ["e.status = 'published'"];
    $params     = [];

    if (!empty($search)) {
      $conditions[] = '(e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)';
      $params[]     = "%{$search}%";
      $params[]     = "%{$search}%";
      $params[]     = "%{$search}%";
    }

    if (!empty($categoryId)) {
      $conditions[] = 'e.category_id = ?';
      $params[]     = $categoryId;
    }

    if ($dateFilter === 'upcoming') {
      $conditions[] = 'e.start_date >= NOW()';
    } elseif ($dateFilter === 'past') {
      $conditions[] = 'e.end_date < NOW()';
    }

    $where = implode(' AND ', $conditions);

    // Get total count for pagination
    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM events e WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Get paginated events with organizer name and category name
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.title,
                e.slug,
                e.description,
                e.location,
                e.banner_image,
                e.start_date,
                e.end_date,
                e.total_tickets,
                e.tickets_sold,
                e.status,
                e.created_at,
                u.name  AS organizer_name,
                c.name  AS category_name,
                c.icon  AS category_icon
            FROM events e
            JOIN users       u ON u.id = e.organizer_id
            LEFT JOIN categories c ON c.id = e.category_id
            WHERE {$where}
            ORDER BY e.start_date ASC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    Response::success([
      'events'      => $events,
      'pagination'  => [
        'total'        => $total,
        'page'         => $page,
        'limit'        => $limit,
        'total_pages'  => (int) ceil($total / $limit),
      ],
    ]);
  }

  // ============================================================
  // GET /api/events/:id
  // Public — get a single event by ID
  // ============================================================
  public function show(array $params): void
  {
    $eventId = (int) $params['id'];

    $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.title,
                e.slug,
                e.description,
                e.location,
                e.banner_image,
                e.start_date,
                e.end_date,
                e.total_tickets,
                e.tickets_sold,
                e.status,
                e.created_at,
                u.id    AS organizer_id,
                u.name  AS organizer_name,
                c.name  AS category_name,
                c.icon  AS category_icon
            FROM events e
            JOIN users       u ON u.id = e.organizer_id
            LEFT JOIN categories c ON c.id = e.category_id
            WHERE e.id = ? AND e.status = 'published'
        ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    // Also fetch the ticket types for this event
    $stmt = $this->db->prepare("
            SELECT id, name, description, price, quantity, quantity_sold, sales_end_at
            FROM ticket_types
            WHERE event_id = ?
            ORDER BY price ASC
        ");
    $stmt->execute([$eventId]);
    $ticketTypes = $stmt->fetchAll();

    // Add available count to each ticket type
    foreach ($ticketTypes as &$type) {
      $type['available'] = (int) $type['quantity'] - (int) $type['quantity_sold'];
    }

    $event['ticket_types'] = $ticketTypes;

    Response::success(['event' => $event]);
  }

  // ============================================================
  // POST /api/events
  // Protected: organizer or dev only
  // ============================================================
  public function store(): void
  {
    $input = $this->request->body;

    // Validate required fields
    $errors = [];

    if (empty($input['title'])) {
      $errors['title'] = 'Event title is required.';
    }

    if (empty($input['start_date'])) {
      $errors['start_date'] = 'Start date is required.';
    }

    if (empty($input['end_date'])) {
      $errors['end_date'] = 'End date is required.';
    }

    if (!empty($input['start_date']) && !empty($input['end_date'])) {
      if (strtotime($input['end_date']) <= strtotime($input['start_date'])) {
        $errors['end_date'] = 'End date must be after start date.';
      }
    }

    if (!isset($input['total_tickets']) || (int) $input['total_tickets'] < 1) {
      $errors['total_tickets'] = 'Total tickets must be at least 1.';
    }

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // Generate a URL slug from the title
    $slug = $this->generateSlug($input['title']);

    // Make sure slug is unique
    $stmt = $this->db->prepare('SELECT id FROM events WHERE slug = ?');
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
      // Append a random string if slug already exists
      $slug = $slug . '-' . substr(uniqid(), -4);
    }

    $stmt = $this->db->prepare("
            INSERT INTO events
                (organizer_id, category_id, title, slug, description, location, banner_image, start_date, end_date, total_tickets, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

    $stmt->execute([
      $this->request->user['id'],
      $input['category_id']   ?? null,
      trim($input['title']),
      $slug,
      $input['description']   ?? null,
      $input['location']      ?? null,
      $input['banner_image']  ?? null,
      $input['start_date'],
      $input['end_date'],
      (int) $input['total_tickets'],
      $input['status']        ?? Constants::EVENT_DRAFT,
    ]);

    $eventId = $this->db->lastInsertId();

    // If ticket types were sent, insert them too
    if (!empty($input['ticket_types']) && is_array($input['ticket_types'])) {
      $this->insertTicketTypes($eventId, $input['ticket_types']);
    }

    // Fetch and return the newly created event
    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    Response::success(['event' => $event], 'Event created successfully.', 201);
  }

  // ============================================================
  // PUT /api/events/:id
  // Protected: organizer (own events only) or dev
  // ============================================================
  public function update(array $params): void
  {
    $eventId = (int) $params['id'];
    $userId  = $this->request->user['id'];
    $role    = $this->request->user['role'];

    // Find the event
    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    // Organizers can only edit their own events
    // Dev can edit any event
    if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
      Response::forbidden('You can only edit your own events.');
    }

    $input = $this->request->body;

    // Validate dates if both are provided
    if (!empty($input['start_date']) && !empty($input['end_date'])) {
      if (strtotime($input['end_date']) <= strtotime($input['start_date'])) {
        Response::validationError(['end_date' => 'End date must be after start date.']);
      }
    }

    // Only update fields that were actually sent
    $stmt = $this->db->prepare("
            UPDATE events SET
                category_id   = COALESCE(?, category_id),
                title         = COALESCE(?, title),
                description   = COALESCE(?, description),
                location      = COALESCE(?, location),
                banner_image  = COALESCE(?, banner_image),
                start_date    = COALESCE(?, start_date),
                end_date      = COALESCE(?, end_date),
                total_tickets = COALESCE(?, total_tickets),
                status        = COALESCE(?, status)
            WHERE id = ?
        ");

    $stmt->execute([
      $input['category_id']   ?? null,
      $input['title']         ?? null,
      $input['description']   ?? null,
      $input['location']      ?? null,
      $input['banner_image']  ?? null,
      $input['start_date']    ?? null,
      $input['end_date']      ?? null,
      $input['total_tickets'] ?? null,
      $input['status']        ?? null,
      $eventId,
    ]);

    // Return updated event
    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $updated = $stmt->fetch();

    Response::success(['event' => $updated], 'Event updated successfully.');
  }

  // ============================================================
  // DELETE /api/events/:id
  // Protected: organizer (own events) or admin or dev
  // ============================================================
  public function destroy(array $params): void
  {
    $eventId = (int) $params['id'];
    $userId  = $this->request->user['id'];
    $role    = $this->request->user['role'];

    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    // Organizers can only delete their own events
    if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
      Response::forbidden('You can only delete your own events.');
    }

    // Instead of hard deleting, mark as cancelled
    // This preserves booking history for users who already bought tickets
    $stmt = $this->db->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$eventId]);

    Response::success(null, 'Event cancelled successfully.');
  }

  // ============================================================
  // GET /api/organizer/events
  // Protected: organizer sees only their own events
  // Dev sees all events regardless of status
  // ============================================================
  public function myEvents(): void
  {
    $userId = $this->request->user['id'];
    $role   = $this->request->user['role'];

    if ($role === Constants::ROLE_DEV) {
      // Dev sees every event on the platform
      $stmt = $this->db->prepare("
                SELECT e.*, u.name AS organizer_name, c.name AS category_name
                FROM events e
                JOIN users u ON u.id = e.organizer_id
                LEFT JOIN categories c ON c.id = e.category_id
                ORDER BY e.created_at DESC
            ");
      $stmt->execute();
    } else {
      // Organizer sees only their own events
      $stmt = $this->db->prepare("
                SELECT e.*, c.name AS category_name
                FROM events e
                LEFT JOIN categories c ON c.id = e.category_id
                WHERE e.organizer_id = ?
                ORDER BY e.created_at DESC
            ");
      $stmt->execute([$userId]);
    }

    $events = $stmt->fetchAll();

    Response::success(['events' => $events]);
  }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

  /**
   * Convert an event title to a URL-friendly slug
   * "My Event Title!" → "my-event-title"
   */
  private function generateSlug(string $title): string
  {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);  // remove special chars
    $slug = preg_replace('/[\s-]+/', '-', $slug);         // spaces to hyphens
    $slug = trim($slug, '-');
    return $slug;
  }

  /**
   * Insert ticket types for a newly created event
   */
  private function insertTicketTypes(int $eventId, array $ticketTypes): void
  {
    $stmt = $this->db->prepare("
            INSERT INTO ticket_types (event_id, name, description, price, quantity, sales_end_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

    foreach ($ticketTypes as $type) {
      if (empty($type['name']) || !isset($type['price']) || empty($type['quantity'])) {
        continue; // skip incomplete ticket types
      }

      $stmt->execute([
        $eventId,
        trim($type['name']),
        $type['description']  ?? null,
        (float) $type['price'],
        (int)   $type['quantity'],
        $type['sales_end_at'] ?? null,
      ]);
    }
  }
}
