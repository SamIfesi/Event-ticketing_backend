<?php
// NOTE: /banks and /resolve-account MUST come before any /:id routes

$router->get(
  '/api/organizer/banks',
  [OrganizerPaymentController::class, 'getBanks'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]
);

$router->post(
  '/api/organizer/resolve-account',
  [OrganizerPaymentController::class, 'resolveAccount'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]
);

$router->get(
  '/api/organizer/payment-details',
  [OrganizerPaymentController::class, 'show'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]
);

$router->post(
  '/api/organizer/payment-details',
  [OrganizerPaymentController::class, 'store'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]
);

$router->put(
  '/api/organizer/payment-details',
  [OrganizerPaymentController::class, 'update'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]
);

$router->get(
  '/api/organizer/payouts',
  [OrganizerPaymentController::class, 'myPayouts'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]
);
