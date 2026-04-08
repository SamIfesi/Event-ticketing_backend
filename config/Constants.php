<?php

class Constants
{
    // User roles — must match ENUM in users table
    const ROLE_ATTENDEE   = 'attendee';
    const ROLE_ORGANIZER  = 'organizer';
    const ROLE_ADMIN      = 'admin';
    const ROLE_DEV        = 'dev';

    // Roles that admins can see and assign (dev is intentionally excluded)
    const PUBLIC_ROLES = [
        self::ROLE_ATTENDEE,
        self::ROLE_ORGANIZER,
        self::ROLE_ADMIN,
    ];

    // Event statuses — must match ENUM in events table
    const EVENT_DRAFT      = 'draft';
    const EVENT_PUBLISHED  = 'published';
    const EVENT_CANCELLED  = 'cancelled';
    const EVENT_COMPLETED  = 'completed';

    // Payment statuses — must match ENUM in bookings table
    const PAYMENT_PENDING  = 'pending';
    const PAYMENT_PAID     = 'paid';
    const PAYMENT_FAILED   = 'failed';
    const PAYMENT_REFUNDED = 'refunded';

    // Storage paths
    const STORAGE_TICKETS  = __DIR__ . '/../storage/tickets/';
    const STORAGE_QRCODES  = __DIR__ . '/../storage/qrcodes/';
    const STORAGE_BANNERS  = __DIR__ . '/../storage/banners/';
}