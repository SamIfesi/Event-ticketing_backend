<?php

// Attendee — view their tickets
$router->get('/api/tickets/:id',                   [TicketController::class, 'show'],       [AuthMiddleware::class]);
$router->get('/api/tickets/booking/:bookingId',    [TicketController::class, 'byBooking'],  [AuthMiddleware::class]);

// Organizer — check in attendees at the gate
$router->post('/api/tickets/checkin',              [TicketController::class, 'checkin'],    [AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]);

// Organizer — view full check-in list for an event
$router->get('/api/organizer/events/:id/checkins', [TicketController::class, 'checkinList'],[AuthMiddleware::class, RoleMiddleware::class => ['organizer', 'dev']]);