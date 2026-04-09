<?php

// Public — anyone can browse categories
$router->get('/api/categories',      [CategoryController::class, 'index']);
$router->get('/api/categories/:id',  [CategoryController::class, 'show']);

// Admin/Dev only — manage categories
$router->post  ('/api/categories',      [CategoryController::class, 'store'],   [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->put   ('/api/categories/:id',  [CategoryController::class, 'update'],  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->delete('/api/categories/:id',  [CategoryController::class, 'destroy'], [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);