<?php

// ============================================================
// AUTH ROUTES
// Base: /api/auth
// ============================================================

// Public routes — no token needed
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login',    [AuthController::class, 'login']);

// Protected routes — must be logged in (AuthMiddleware runs first)
$router->post('/api/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
$router->get('/api/auth/me',      [AuthController::class, 'me'],     [AuthMiddleware::class]);
