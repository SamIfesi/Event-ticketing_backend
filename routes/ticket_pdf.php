<?php

// ============================================================
// TICKET PDF + PNG ROUTES
//
// NOTE: Specific sub-paths (/status, /regenerate, /png) MUST be
// registered BEFORE the generic /:id/ticket route to prevent
// the router matching them as booking IDs.
//
// Ordering matters — do not rearrange.
// ============================================================

// ── Attendee / Organizer ──────────────────────────────────────

// Check if a ticket has been generated (PDF + PNG status)
$router->get(
  '/api/bookings/:id/ticket/status',
  [TicketPDFController::class, 'status'],
  [AuthMiddleware::class]
);

// Download ticket as PNG image
$router->get(
  '/api/bookings/:id/ticket/png',
  [TicketPDFController::class, 'downloadPng'],
  [AuthMiddleware::class]
);

// Download ticket as PDF (generates on first request, cached after)
$router->get(
  '/api/bookings/:id/ticket',
  [TicketPDFController::class, 'download'],
  [AuthMiddleware::class]
);

// NEW END POINT
$router->get(
  '/api/tickets/:id/download/png',
  [TicketPDFController::class, 'downloadSinglePng'],
  [AuthMiddleware::class]
);

// Download ticket as PDF (generates on first request, cached after)
$router->get(
  '/api/tickets/:id/download',
  [TicketPDFController::class, 'downloadSingle'],
  [AuthMiddleware::class]
);

// ── Admin / Dev ───────────────────────────────────────────────

// Force regenerate both PDF and PNG cached files
$router->post(
  '/api/bookings/:id/ticket/regenerate',
  [TicketPDFController::class, 'regenerate'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

// Admin download any ticket as PDF
$router->get(
  '/api/admin/tickets/:id/download',
  [TicketPDFController::class, 'adminDownload'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

// Admin download any ticket as PNG
$router->get(
  '/api/admin/tickets/:id/download/png',
  [TicketPDFController::class, 'adminDownloadPng'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);
