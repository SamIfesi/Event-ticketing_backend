<?php

// ============================================================
// TICKET ROUTES
//
// NOTE: Specific sub-paths (/status, /regenerate) MUST be
// registered before the generic /:id/ticket route to prevent
// the router matching them as booking IDs.
// ============================================================

// ── Attendee / Organizer ──────────────────────────────────────

// Check if a ticket has been generated
$router->get(
  '/api/bookings/:id/ticket/status',
  [TicketPDFController::class, 'status'],
  [AuthMiddleware::class]
);

// Download ticket (generates on first request, cached after)
$router->get(
  '/api/bookings/:id/ticket',
  [TicketPDFController::class, 'download'],
  [AuthMiddleware::class]
);

// ── Admin / Dev ───────────────────────────────────────────────

// Force regenerate a cached ticket
$router->post(
  '/api/bookings/:id/ticket/regenerate',
  [TicketPDFController::class, 'regenerate'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

// Admin download any ticket directly
$router->get(
  '/api/admin/tickets/:id/download',
  [TicketPDFController::class, 'adminDownload'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);
