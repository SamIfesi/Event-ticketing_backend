<?php

class DevMiddleware
{
    /**
     * Only the dev role can pass through
     * This middleware is used on all /api/dev/* routes
     * Even admins get a 404 — not a 403 — so they don't know the route exists
     */
    public function handle(Request $request): void
    {
        $token = $request->bearerToken();

        if (!$token) {
            // Return 404 so it looks like the route simply doesn't exist
            Response::notFound();
        }

        $payload = JWTService::verify($token);

        if (!$payload || $payload['role'] !== Constants::ROLE_DEV) {
            // Same — 404 not 403, keeps the backdoor invisible
            Response::notFound();
        }

        $request->user = $payload;
    }
}