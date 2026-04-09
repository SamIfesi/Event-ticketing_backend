<?php

// ============================================================
// AUTH ROUTES
// ============================================================

// Public routes
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login',    [AuthController::class, 'login']);

// Protected routes — must be logged in
$router->post('/api/auth/logout',      [AuthController::class, 'logout'],      [AuthMiddleware::class]);
$router->get('/api/auth/me',          [AuthController::class, 'me'],          [AuthMiddleware::class]);
$router->post('/api/auth/verify-email', [AuthController::class, 'verifyEmail'], [AuthMiddleware::class]);
$router->post('/api/auth/resend-otp',  [AuthController::class, 'resendOTP'],   [AuthMiddleware::class]);
