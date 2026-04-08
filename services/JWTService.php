<?php

class JWTService
{
    /**
     * Create a JWT token for a user
     * Stores id, email, role, and expiry inside the token
     */
    public static function generate(array $user): string
    {
        $secret = Environment::get('JWT_SECRET');
        $expiry = (int) Environment::get('JWT_EXPIRY', '86400'); // default 24 hours

        // Header
        $header = self::base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]));

        // Payload — what gets stored in the token
        $payload = self::base64UrlEncode(json_encode([
            'id'    => $user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'name'  => $user['name'],
            'iat'   => time(),               // issued at
            'exp'   => time() + $expiry,     // expiry
        ]));

        // Signature — proves the token hasn't been tampered with
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Verify a token and return its payload
     * Returns null if invalid or expired
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        // Recompute the signature and compare
        $secret   = Environment::get('JWT_SECRET');
        $expected = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        // Signature mismatch — token was tampered with
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payload), true);

        // Token has expired
        if (!$data || $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}