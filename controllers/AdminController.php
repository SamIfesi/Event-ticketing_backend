<?php

class AdminController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // GET /api/admin/users
  // Protected: admin or dev
  // Returns all users EXCEPT dev accounts
  // ============================================================
  public function users(): void
  {
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset = ($page - 1) * $limit;
    $search = trim($this->request->query('search', ''));
    $role   = trim($this->request->query('role', ''));

    // Build filters
    // CRITICAL: always exclude dev accounts from admin view
    $conditions = ["role != 'dev'"];
    $params     = [];

    if (!empty($search)) {
      $conditions[] = '(name LIKE ? OR email LIKE ?)';
      $params[]     = "%{$search}%";
      $params[]     = "%{$search}%";
    }

    // Admin can filter by role but dev is always excluded
    if (!empty($role) && in_array($role, Constants::PUBLIC_ROLES, true)) {
      $conditions[] = 'role = ?';
      $params[]     = $role;
    }

    $where = implode(' AND ', $conditions);

    // Total count for pagination
    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                id,
                name,
                email,
                role,
                is_active,
                avatar,
                created_at
            FROM users
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    Response::success([
      'users'      => $users,
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // ============================================================
  // GET /api/admin/users/:id
  // Protected: admin or dev
  // Returns a single user's profile + their activity summary
  // ============================================================
  public function showUser(array $params): void
  {
    $userId = (int) $params['id'];

    // Block looking up dev accounts
    $stmt = $this->db->prepare("
            SELECT id, name, email, role, is_active, avatar, created_at
            FROM users
            WHERE id = ? AND role != 'dev'
        ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    // Attach booking summary
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                      AS total_bookings,
                SUM(total_amount)                             AS total_spent,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_bookings
            FROM bookings
            WHERE user_id = ?
        ");
    $stmt->execute([$userId]);
    $user['booking_summary'] = $stmt->fetch();

    // If organizer, attach their event summary
    if ($user['role'] === Constants::ROLE_ORGANIZER) {
      $stmt = $this->db->prepare("
                SELECT
                    COUNT(*)                                               AS total_events,
                    SUM(tickets_sold)                                      AS total_tickets_sold,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS live_events
                FROM events
                WHERE organizer_id = ?
            ");
      $stmt->execute([$userId]);
      $user['organizer_summary'] = $stmt->fetch();
    }

    Response::success(['user' => $user]);
  }

  // ============================================================
  // PUT /api/admin/users/:id/role
  // Protected: admin or dev
  // Change a user's role — dev role cannot be assigned here
  // ============================================================
  public function updateRole(array $params): void
  {
    $targetId = (int) $params['id'];
    $newRole  = trim($this->request->input('role', ''));

    // Validate role — dev role can never be assigned through this endpoint
    if (!in_array($newRole, Constants::PUBLIC_ROLES, true)) {
      Response::validationError([
        'role' => 'Invalid role. Must be one of: ' . implode(', ', Constants::PUBLIC_ROLES),
      ]);
    }

    // Find the target user — block if they're a dev account
    $stmt = $this->db->prepare("SELECT id, role FROM users WHERE id = ? AND role != 'dev'");
    $stmt->execute([$targetId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    // Admin cannot change their own role
    if ($targetId === (int) $this->request->user['id']) {
      Response::error('You cannot change your own role.', 400);
    }

    $this->db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);

    Response::success(null, "User role updated to '{$newRole}' successfully.");
  }

  // ============================================================
  // PUT /api/admin/users/:id/status
  // Protected: admin or dev
  // Activate or deactivate a user account
  // ============================================================
  public function updateStatus(array $params): void
  {
    $targetId = (int) $params['id'];
    $isActive = (int) $this->request->input('is_active', 1);

    // Block acting on dev accounts
    $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND role != 'dev'");
    $stmt->execute([$targetId]);

    if (!$stmt->fetch()) {
      Response::notFound('User not found.');
    }

    // Admin cannot deactivate themselves
    if ($targetId === (int) $this->request->user['id']) {
      Response::error('You cannot deactivate your own account.', 400);
    }

    $this->db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$isActive ? 1 : 0, $targetId]);

    $message = $isActive ? 'User account activated.' : 'User account deactivated.';
    Response::success(null, $message);
  }

  // ============================================================
  // GET /api/admin/events
  // Protected: admin or dev
  // Admin can see and manage ALL events on the platform
  // ============================================================
  public function events(): void
  {
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset = ($page - 1) * $limit;
    $status = trim($this->request->query('status', ''));

    $conditions = ['1=1'];
    $params     = [];

    if (!empty($status)) {
      $conditions[] = 'e.status = ?';
      $params[]     = $status;
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM events e WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.title,
                e.status,
                e.start_date,
                e.total_tickets,
                e.tickets_sold,
                e.created_at,
                u.name  AS organizer_name,
                u.email AS organizer_email,
                c.name  AS category_name
            FROM events e
            JOIN users u ON u.id = e.organizer_id
            LEFT JOIN categories c ON c.id = e.category_id
            WHERE {$where}
            ORDER BY e.created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    Response::success([
      'events'     => $events,
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // ============================================================
  // PUT /api/admin/events/:id/status
  // Protected: admin or dev
  // Admin can force-change any event's status
  // ============================================================
  public function updateEventStatus(array $params): void
  {
    $eventId   = (int) $params['id'];
    $newStatus = trim($this->request->input('status', ''));

    $validStatuses = [
      Constants::EVENT_DRAFT,
      Constants::EVENT_PUBLISHED,
      Constants::EVENT_CANCELLED,
      Constants::EVENT_COMPLETED,
    ];

    if (!in_array($newStatus, $validStatuses, true)) {
      Response::validationError(['status' => 'Invalid status value.']);
    }

    $stmt = $this->db->prepare('SELECT id FROM events WHERE id = ?');
    $stmt->execute([$eventId]);

    if (!$stmt->fetch()) {
      Response::notFound('Event not found.');
    }

    $this->db->prepare("UPDATE events SET status = ? WHERE id = ?")->execute([$newStatus, $eventId]);

    Response::success(null, "Event status updated to '{$newStatus}'.");
  }

  // ============================================================
  // GET /api/admin/stats
  // Protected: admin or dev
  // Platform-wide statistics for the admin dashboard
  // ============================================================
  public function stats(): void
  {
    // Users — always exclude dev accounts from counts
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                        AS total_users,
                SUM(CASE WHEN role = 'attendee'  THEN 1 ELSE 0 END)            AS attendees,
                SUM(CASE WHEN role = 'organizer' THEN 1 ELSE 0 END)            AS organizers,
                SUM(CASE WHEN role = 'admin'     THEN 1 ELSE 0 END)            AS admins,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND role != 'dev' THEN 1 ELSE 0 END)                 AS new_this_month
            FROM users
            WHERE role != 'dev'
        ");
    $stmt->execute();
    $userStats = $stmt->fetch();

    // Events
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                              AS total_events,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END)                AS published,
                SUM(CASE WHEN status = 'draft'     THEN 1 ELSE 0 END)                AS drafts,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)                AS cancelled,
                SUM(CASE WHEN start_date >= NOW() AND status = 'published'
                          THEN 1 ELSE 0 END)                                          AS upcoming
            FROM events
        ");
    $stmt->execute();
    $eventStats = $stmt->fetch();

    // Bookings and revenue
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                           AS total_bookings,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END)          AS paid_bookings,
                SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END)       AS pending_bookings,
                SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) AS total_revenue
            FROM bookings
        ");
    $stmt->execute();
    $bookingStats = $stmt->fetch();

    // Tickets issued
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                      AS total_tickets,
                SUM(CASE WHEN is_used = 1 THEN 1 ELSE 0 END) AS checked_in
            FROM tickets
        ");
    $stmt->execute();
    $ticketStats = $stmt->fetch();

    // Recent activity — last 7 days bookings
    $stmt = $this->db->prepare("
            SELECT
                DATE(created_at)                                                AS date,
                COUNT(*)                                                        AS bookings,
                SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) AS revenue
            FROM bookings
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
    $stmt->execute();
    $recentActivity = $stmt->fetchAll();

    Response::success([
      'users'           => $userStats,
      'events'          => $eventStats,
      'bookings'        => $bookingStats,
      'tickets'         => $ticketStats,
      'recent_activity' => $recentActivity,
    ]);
  }
}
