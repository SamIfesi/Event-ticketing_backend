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

  public function index(): void
  {
    $page = max(1, (int) $this->request->query('page', '1'));
    $limit = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset = ($page - 1) * $limit;
    $status = trim($this->request->query('status', 'pending'));

    $conditions = ['1=1'];
    $params = [];

    if (!empty($status)) {
      $conditions[] = 'a.status = ?';
      $params[]     =  $status;
    }

    $where = implode(' AND ', $conditions);
    $countSTmt = $this->db->prepare("SELECT COUNT(*) FROM organizer_applications a WHERE {$where}");
    $countSTmt->execute($params);
    $totla = (int) $countSTmt->fetchColumn();

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
        u.id AS user_id,
        u.name AS user_name,
        u.email AS user_email,
        r.name AS reviewed_by_name
      FROM organizer_applications a JOIN users u ON u.id = a.user_id LEFT JOIN users r    ON r.id = a.reviewed_by WHERE {$where} ORDER BY a.created_at DESC LIMIT ? OFFSET ?
    ");

    $stmt->execute($params);
    $applications = $stmt->fetchAll();

    Response::success([
      'applications' => $applications,
      'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }
}

