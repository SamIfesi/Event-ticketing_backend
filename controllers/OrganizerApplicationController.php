<?php

class OrganizerApplicationController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // POST /api/organizer-applications
  // Attendee submits an application to become an organizer
  // admin sees all application with filter
  public function store(): void
  {
    $userId    = $this->request->user['id'];
    $role      = $this->request->user['role'];

    // Only attendees can apply — organizers/admins already have access
    if ($role !== Constants::ROLE_ATTENDEE) {
      Response::error('Only attendees can apply to become an organizer.', 400);
    }

    // Check if they already have a pending or approved application
    $stmt = $this->db->prepare("
      SELECT id, status FROM organizer_applications
      WHERE user_id = ? AND status IN ('pending', 'approved')
      LIMIT 1
    ");
    $stmt->execute([$userId]);
    $existing = $stmt->fetch();

    if ($existing) {
      $msg = $existing['status'] === 'approved'
        ? 'Your application has already been approved.'
        : 'You already have a pending application. Please wait for review.';
      Response::error($msg, 409);
    }

    $orgName    = trim($this->request->input('org_name', ''));
    $eventTypes = trim($this->request->input('event_types', ''));
    $phone      = trim($this->request->input('phone', ''));
    $reason     = trim($this->request->input('reason', ''));

    $errors = ValidationHelper::check(
      ['org_name' => $orgName, 'event_types' => $eventTypes, 'phone' => $phone],
      [
        'org_name'    => 'required|min:2|max:255',
        'event_types' => 'required|min:2|max:255',
        'phone'       => 'required|min:7|max:20',
      ]
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    $this->db->prepare("
      INSERT INTO organizer_applications (user_id, org_name, event_types, phone, reason)
      VALUES (?, ?, ?, ?, ?)
    ")->execute([$userId, $orgName, $eventTypes, $phone, $reason ?: null]);

    Response::success(null, 'Application submitted successfully. We will review it shortly.', 201);
  }

  // GET /api/organizer-applications/mine
  // Attendee checks the status of their own application
  public function mine(): void
  {
    $userId = $this->request->user['id'];

    $stmt = $this->db->prepare("
      SELECT id, org_name, event_types, phone, reason, status, created_at, reviewed_at
      FROM organizer_applications
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 1
    ");
    $stmt->execute([$userId]);
    $application = $stmt->fetch();

    Response::success(['application' => $application ?: null]);
  }

  // GET /api/admin/organizer-applications
  // Admin sees all applications with filters
  public function index(): void
  {
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset = ($page - 1) * $limit;
    $status = trim($this->request->query('status', 'pending'));

    $conditions = ['1=1'];
    $params     = [];

    if (!empty($status)) {
      $conditions[] = 'a.status = ?';
      $params[]     = $status;
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare("
      SELECT COUNT(*) FROM organizer_applications a WHERE {$where}
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
      SELECT
        a.id,
        a.org_name,
        a.event_types,
        a.phone,
        a.reason,
        a.status,
        a.created_at,
        a.reviewed_at,
        u.id    AS user_id,
        u.name  AS user_name,
        u.email AS user_email,
        r.name  AS reviewed_by_name
      FROM organizer_applications a
      JOIN users u ON u.id = a.user_id
      LEFT JOIN users r ON r.id = a.reviewed_by
      WHERE {$where}
      ORDER BY a.created_at DESC
      LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $applications = $stmt->fetchAll();

    Response::success([
      'applications' => $applications,
      'pagination'   => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // PUT /api/admin/organizer-applications/:id/approve
  public function approve(array $params): void
  {
    $this->reviewApplication((int) $params['id'], 'approved');
  }

  // PUT /api/admin/organizer-applications/:id/reject
  public function reject(array $params): void
  {
    $this->reviewApplication((int) $params['id'], 'rejected');
  }

  private function reviewApplication(int $applicationId, string $decision): void
  {
    $adminId = $this->request->user['id'];

    $stmt = $this->db->prepare("
      SELECT a.*, u.role AS current_role
      FROM organizer_applications a
      JOIN users u ON u.id = a.user_id
      WHERE a.id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();

    if (!$application) {
      Response::notFound('Application not found.');
    }

    if ($application['status'] !== 'pending') {
      Response::error('This application has already been reviewed.', 400);
    }

    // Update the application status
    $this->db->prepare("
      UPDATE organizer_applications
      SET status = ?, reviewed_by = ?, reviewed_at = NOW()
      WHERE id = ?
    ")->execute([$decision, $adminId, $applicationId]);

    // If approved, upgrade the user's role immediately
    if ($decision === 'approved') {
      $this->db->prepare("
        UPDATE users SET role = ? WHERE id = ?
      ")->execute([Constants::ROLE_ORGANIZER, $application['user_id']]);
    }

    $message = $decision === 'approved'
      ? 'Application approved. User has been upgraded to organizer.'
      : 'Application rejected.';

    Response::success(null, $message);
  }
}
