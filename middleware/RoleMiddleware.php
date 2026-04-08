<?php

class RoleMiddleware
{
    private array $allowedRoles;

    /**
     * Pass in the roles that are allowed to access the route
     * e.g. new RoleMiddleware(['admin', 'dev'])
     */
    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function handle(Request $request): void
    {
        // AuthMiddleware must run first — $request->user must exist
        if (!$request->user) {
            Response::unauthorized('Not authenticated.');
        }

        $userRole = $request->user['role'];

        // dev role can access everything — always passes
        if ($userRole === Constants::ROLE_DEV) {
            return;
        }

        if (!in_array($userRole, $this->allowedRoles, true)) {
            Response::forbidden('You do not have permission to access this resource.');
        }
    }
}