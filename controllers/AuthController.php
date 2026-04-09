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
  // Creates account, sends OTP, logs user in immediately
  // ============================================================
  public function register(): void
  {
    $name     = trim($this->request->input('name', ''));
    $email    = trim($this->request->input('email', ''));
    $password = $this->request->input('password', '');

    // Validate
    $errors = ValidationHelper::check(
      ['name' => $name, 'email' => $email, 'password' => $password],
      [
        'name'     => 'required|min:2|max:150',
        'email'    => 'required|email',
        'password' => 'required|min:8',
      ]
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // Check email not already taken
    $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
      Response::error('An account with this email already exists.', 409);
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Insert user — email_verified defaults to 0 (unverified)
    $stmt = $this->db->prepare('
            INSERT INTO users (name, email, password_hash, role)
            VALUES (?, ?, ?, ?)
        ');
    $stmt->execute([$name, $email, $passwordHash, Constants::ROLE_ATTENDEE]);
    $userId = $this->db->lastInsertId();

    // Fetch new user
    $stmt = $this->db->prepare('
            SELECT id, name, email, role, email_verified, created_at FROM users WHERE id = ?
        ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Generate OTP and store it
    $otp       = $this->generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $this->db->prepare("
            INSERT INTO email_verifications (user_id, email, otp, type, expires_at)
            VALUES (?, ?, ?, 'register', ?)
        ")->execute([$userId, $email, $otp, $expiresAt]);

    // Send OTP email — failure doesn't block registration
    $mailer = new MailService();
    $mailer->sendOTP($email, $name, $otp, 'register');

    // Log activity
    $this->logActivity($userId, 'register', 'Account created');

    // Issue JWT — logged in immediately even before verifying
    $token = JWTService::generate($user);

    Response::success([
      'user'         => $user,
      'token'        => $token,
      'message_hint' => 'A 6-digit OTP has been sent to your email. Please verify your account.',
    ], 'Account created successfully.', 201);
  }

  // ============================================================
  // POST /api/auth/verify-email
  // Protected: logged in
  // User submits the OTP they received after registering
  // ============================================================
  public function verifyEmail(): void
  {
    $userId = $this->request->user['id'];
    $otp    = trim($this->request->input('otp', ''));

    if (empty($otp)) {
      Response::validationError(['otp' => 'OTP is required.']);
    }

    // Find a valid unused OTP for this user
    $stmt = $this->db->prepare("
            SELECT id, expires_at FROM email_verifications
            WHERE user_id = ?
              AND otp      = ?
              AND type     = 'register'
              AND is_used  = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
    $stmt->execute([$userId, $otp]);
    $record = $stmt->fetch();

    if (!$record) {
      Response::error('Invalid OTP. Please check the code and try again.', 400);
    }

    // Check it hasn't expired
    if (strtotime($record['expires_at']) < time()) {
      Response::error('This OTP has expired. Please request a new one.', 400);
    }

    // Mark OTP as used
    $this->db->prepare("UPDATE email_verifications SET is_used = 1 WHERE id = ?")
      ->execute([$record['id']]);

    // Mark user as verified
    $this->db->prepare("
            UPDATE users SET email_verified = 1, email_verified_at = NOW() WHERE id = ?
        ")->execute([$userId]);

    // Log activity
    $this->logActivity($userId, 'email_verified', 'Email address verified');

    Response::success(null, 'Email verified successfully.');
  }

  // ============================================================
  // POST /api/auth/resend-otp
  // Protected: logged in
  // Resends a fresh OTP if the previous one expired
  // ============================================================
  public function resendOTP(): void
  {
    $userId = $this->request->user['id'];

    // Fetch user info
    $stmt = $this->db->prepare('SELECT name, email, email_verified FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user['email_verified']) {
      Response::error('Your email is already verified.', 400);
    }

    // Invalidate all previous unused OTPs for this user
    $this->db->prepare("
            UPDATE email_verifications SET is_used = 1
            WHERE user_id = ? AND type = 'register' AND is_used = 0
        ")->execute([$userId]);

    // Generate and store new OTP
    $otp       = $this->generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $this->db->prepare("
            INSERT INTO email_verifications (user_id, email, otp, type, expires_at)
            VALUES (?, ?, ?, 'register', ?)
        ")->execute([$userId, $user['email'], $otp, $expiresAt]);

    // Send fresh OTP
    $mailer = new MailService();
    $mailer->sendOTP($user['email'], $user['name'], $otp, 'register');

    Response::success(null, 'A new OTP has been sent to your email.');
  }

  // ============================================================
  // POST /api/auth/login
  // ============================================================
  public function login(): void
  {
    $email    = trim($this->request->input('email', ''));
    $password = $this->request->input('password', '');

    $errors = ValidationHelper::check(
      ['email' => $email, 'password' => $password],
      ['email' => 'required|email', 'password' => 'required']
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // Find user by email — all roles including dev can log in
    $stmt = $this->db->prepare('
            SELECT id, name, email, password_hash, role, is_active, email_verified
            FROM users WHERE email = ?
        ');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Same message for wrong email OR wrong password — prevents enumeration
    if (!$user || !password_verify($password, $user['password_hash'])) {
      Response::error('Invalid email or password.', 401);
    }

    if (!$user['is_active']) {
      Response::error('Your account has been deactivated. Please contact support.', 403);
    }

    unset($user['password_hash'], $user['is_active']);

    // Log activity
    $this->logActivity($user['id'], 'login', 'Logged in from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    $token = JWTService::generate($user);

    Response::success([
      'user'           => $user,
      'token'          => $token,
      'email_verified' => (bool) $user['email_verified'],
    ], 'Logged in successfully.');
  }

  // ============================================================
  // POST /api/auth/logout
  // Protected: logged in
  // ============================================================
  public function logout(): void
  {
    $this->logActivity($this->request->user['id'], 'logout', 'Logged out');
    Response::success(null, 'Logged out successfully.');
  }

  // ============================================================
  // GET /api/auth/me
  // Protected: logged in
  // ============================================================
  public function me(): void
  {
    $userId = $this->request->user['id'];

    $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, email_verified, email_verified_at, created_at
            FROM users WHERE id = ?
        ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    Response::success(['user' => $user]);
  }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

  /**
   * Generate a random 6-digit OTP
   */
  private function generateOTP(): string
  {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  }

  /**
   * Write to activity_logs table
   */
  private function logActivity(int $userId, string $action, string $description = ''): void
  {
    try {
      $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address)
                VALUES (?, ?, ?, ?)
            ")->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
      // Never let logging crash auth
      error_log('Activity log error: ' . $e->getMessage());
    }
  }
}
