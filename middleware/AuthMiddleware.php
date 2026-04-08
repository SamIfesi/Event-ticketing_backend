<?php

class AuthMiddleware
{
  /**
   * Called before every protected route
   * Reads the JWT from Authorization header, verifies it,
   * and attaches the user payload to $request->user
   */
  public function handle(Request $request): void
  {
    $token = $request->bearerToken();

    if (!$token) {
      Response::unauthorized('No token provided. Please log in.');
    }

    $payload = JWTService::verify($token);

    if (!$payload) {
      Response::unauthorized('Invalid or expired token. Please log in again.');
    }

    // Attach decoded user data to the request so controllers can use it
    // e.g. $request->user['id'], $request->user['role']
    $request->user = $payload;
  }
}
