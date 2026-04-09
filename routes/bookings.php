<?php

// Attendee routes
$router->post('/api/bookings',        [BookingController::class, 'store'],         [AuthMiddleware::class]);
$router->post('/api/bookings/verify', [BookingController::class, 'verify'],        [AuthMiddleware::class]);
$router->get('/api/bookings/mine',   [BookingController::class, 'myBookings'],    [AuthMiddleware::class]);
$router->get('/api/bookings/:id',    [BookingController::class, 'show'],          [AuthMiddleware::class]);

// Organizer — see all bookings for a specific event
$router->get(
  '/api/organizer/events/:id/bookings',
  [BookingController::class, 'eventBookings'],
  [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'admin', 'dev']]
);
