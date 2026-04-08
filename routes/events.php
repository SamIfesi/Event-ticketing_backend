<?php

// Public routes — no login needed
$router->get('/api/events',      [EventController::class, 'index']);
$router->get('/api/events/:id',  [EventController::class, 'show']);

// Organizer routes — must be logged in as organizer or dev
$router->post('/api/events',          [EventController::class, 'store'],    [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]);
$router->put('/api/events/:id',      [EventController::class, 'update'],   [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]);
$router->delete('/api/events/:id',      [EventController::class, 'destroy'],  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'admin', 'dev']]);
$router->get('/api/organizer/events', [EventController::class, 'myEvents'], [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]);
