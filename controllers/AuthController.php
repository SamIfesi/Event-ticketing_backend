<?php

class AuthController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // POST /api/auth/register
  // ============================================================
  public function register(): void
  {
    // 1. Get input from request body
    $name     = trim($this->request->input('name', ''));
    $email    = trim($this->request->input('email', ''));
    $password = $this->request->input('password', '');

    // 2. Validate input
    $errors = [];

    if (empty($name)) {
      $errors['name'] = 'Name is required.';
    }

    if (empty($email)) {
      $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($password)) {
      $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
      $errors['password'] = 'Password must be at least 8 characters.';
    }

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // 3. Check if email is already taken
    $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
      Response::error('An account with this email already exists.', 409);
    }

    // 4. Hash the password — NEVER store plain text passwords
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // 5. Insert the new user
    //    New users always start as 'attendee'
    //    Role cannot be set from the request — prevents privilege escalation
    $stmt = $this->db->prepare('
            INSERT INTO users (name, email, password_hash, role)
            VALUES (?, ?, ?, ?)
        ');
    $stmt->execute([$name, $email, $passwordHash, Constants::ROLE_ATTENDEE]);

    $userId = $this->db->lastInsertId();

    // 6. Fetch the newly created user
    $stmt = $this->db->prepare('
            SELECT id, name, email, role, created_at
            FROM users
            WHERE id = ?
        ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // 7. Generate JWT token so they're logged in immediately after registering
    $token = JWTService::generate($user);

    Response::success([
      'user'  => $user,
      'token' => $token,
    ], 'Account created successfully.', 201);
  }

  // ============================================================
  // POST /api/auth/login
  // ============================================================
  public function login(): void
  {
    // 1. Get input
    $email    = trim($this->request->input('email', ''));
    $password = $this->request->input('password', '');

    // 2. Validate
    $errors = [];

    if (empty($email)) {
      $errors['email'] = 'Email is required.';
    }

    if (empty($password)) {
      $errors['password'] = 'Password is required.';
    }

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // 3. Find user by email
    //    We fetch all roles including 'dev' here — login works for everyone
    $stmt = $this->db->prepare('
            SELECT id, name, email, password_hash, role, is_active
            FROM users
            WHERE email = ?
        ');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // 4. Verify credentials
    //    We use the same error message for both "user not found" and "wrong password"
    //    This prevents attackers from knowing which emails are registered (enumeration attack)
    if (!$user || !password_verify($password, $user['password_hash'])) {
      Response::error('Invalid email or password.', 401);
    }

    // 5. Check account is active
    if (!$user['is_active']) {
      Response::error('Your account has been deactivated. Please contact support.', 403);
    }

    // 6. Remove password_hash before sending user data back
    unset($user['password_hash']);
    unset($user['is_active']);

    // 7. Generate and return JWT token
    $token = JWTService::generate($user);

    Response::success([
      'user'  => $user,
      'token' => $token,
    ], 'Logged in successfully.');
  }

  // ============================================================
  // POST /api/auth/logout
  // Protected: must be logged in
  // ============================================================
  public function logout(): void
  {
    // With JWT, logout is handled on the React side by deleting the token
    // from localStorage. The server doesn't store tokens so there's nothing
    // to invalidate here.
    // This endpoint exists so React has a clean endpoint to call,
    // and so you can add token blacklisting here later if needed.

    Response::success(null, 'Logged out successfully.');
  }

  // ============================================================
  // GET /api/auth/me
  // Protected: must be logged in
  // Returns the current authenticated user's data
  // ============================================================
  public function me(): void
  {
    // $request->user is set by AuthMiddleware from the JWT payload
    $userId = $this->request->user['id'];

    // Fetch fresh data from DB in case anything changed since token was issued
    $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, created_at
            FROM users
            WHERE id = ?
        ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    Response::success(['user' => $user]);
  }
}
