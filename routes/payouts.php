<?php
// NOTE: /pending MUST come before /:eventId

$router->get(
  '/api/admin/payouts/pending',
  [PayoutController::class, 'pending'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->get(
  '/api/admin/payouts',
  [PayoutController::class, 'index'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->post(
  '/api/admin/payouts/:eventId/trigger',
  [PayoutController::class, 'trigger'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->post(
  '/api/admin/payouts/:eventId/freeze',
  [PayoutController::class, 'freeze'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->post(
  '/api/admin/payouts/:eventId/unfreeze',
  [PayoutController::class, 'unfreeze'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->post(
  '/api/admin/payouts/:eventId/refund-all',
  [PayoutController::class, 'refundAll'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);

$router->post(
  '/api/admin/organizers/:id/clear-flag',
  [PayoutController::class, 'clearFlag'],
  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]
);
