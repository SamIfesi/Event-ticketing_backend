<?php

//Attendee routes
$router->post('/api/org_applications', [OrganizerApplicationController::class, 'store'],  [AuthMiddleware::class]);
$router->get('/api/org_applications/mine', [OrganizerApplicationController::class, 'mine'],  [AuthMiddleware::class]);

// Admin routes
$router->get('/api/admin/org_applications', [OrganizerApplicationController::class, 'index'],  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->put('/api/admin/org_applications/:id/approve', [OrganizerApplicationController::class, 'approve'],  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->put('/api/admin/org_applications/:id/reject', [OrganizerApplicationController::class, 'reject'],  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);