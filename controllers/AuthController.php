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
    $name     = trim($this->request->input('name', ''));
    $email    = trim($this->request->input('email', ''));
    $password = $this->request->input('password', '');

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

    // Check email not taken
    $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
      Response::error('An account with this email already exists.', 409);
    }

    // Insert user
    $this->db->prepare('
            INSERT INTO users (name, email, password_hash, role)
            VALUES (?, ?, ?, ?)
        ')->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), Constants::ROLE_ATTENDEE]);

    $userId = $this->db->lastInsertId();

    // Fetch new user
    $stmt = $this->db->prepare('
            SELECT id, name, email, role, email_verified, created_at FROM users WHERE id = ?
        ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Generate OTP and store it
    $otp       = $this->generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $this->db->prepare("
            INSERT INTO email_verifications (user_id, email, otp, type, expires_at)
            VALUES (?, ?, ?, 'register', ?)
        ")->execute([$userId, $email, $otp, $expiresAt]);

    // QUEUE the email instead of sending directly — returns instantly
    QueueService::sendOTP($email, $name, $otp, 'register');

    // Log activity
    $this->logActivity($userId, 'register', 'Account created');

    // Issue JWT
    $token = JWTService::generate($user);

    Response::success([
      'user'         => $user,
      'token'        => $token,
      'message_hint' => 'A 6-digit OTP has been sent to your email.',
    ], 'Account created successfully.', 201);
  }

  // ============================================================
  // POST /api/auth/verify-email
  // Protected: logged in
  // ============================================================
  public function verifyEmail(): void
  {
    $userId = $this->request->user['id'];
    $otp    = trim($this->request->input('otp', ''));

    if (empty($otp)) {
      Response::validationError(['otp' => 'OTP is required.']);
    }

    $stmt = $this->db->prepare("
            SELECT id, expires_at FROM email_verifications
            WHERE user_id = ? AND otp = ? AND type = 'register' AND is_used = 0
            ORDER BY created_at DESC LIMIT 1
        ");
    $stmt->execute([$userId, $otp]);
    $record = $stmt->fetch();

    if (!$record) {
      Response::error('Invalid OTP. Please check the code and try again.', 400);
    }

    if (strtotime($record['expires_at']) < time()) {
      Response::error('This OTP has expired. Please request a new one.', 400);
    }

    $this->db->prepare("UPDATE email_verifications SET is_used = 1 WHERE id = ?")
      ->execute([$record['id']]);

    $this->db->prepare("
            UPDATE users SET email_verified = 1, email_verified_at = NOW() WHERE id = ?
        ")->execute([$userId]);

    $this->logActivity($userId, 'email_verified', 'Email address verified');

    Response::success(null, 'Email verified successfully.');
  }

  // ============================================================
  // POST /api/auth/resend-otp
  // Protected: logged in
  // ============================================================
  public function resendOTP(): void
  {
    $userId = $this->request->user['id'];

    $stmt = $this->db->prepare('SELECT name, email, email_verified FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user['email_verified']) {
      Response::error('Your email is already verified.', 400);
    }

    // Invalidate old OTPs
    $this->db->prepare("
            UPDATE email_verifications SET is_used = 1
            WHERE user_id = ? AND type = 'register' AND is_used = 0
        ")->execute([$userId]);

    // Generate new OTP
    $otp       = $this->generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $this->db->prepare("
            INSERT INTO email_verifications (user_id, email, otp, type, expires_at)
            VALUES (?, ?, ?, 'register', ?)
        ")->execute([$userId, $user['email'], $otp, $expiresAt]);

    // Queue the email
    QueueService::sendOTP($user['email'], $user['name'], $otp, 'register');

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

    $stmt = $this->db->prepare('
            SELECT id, name, email, password_hash, role, is_active, email_verified
            FROM users WHERE email = ?
        ');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      Response::error('Invalid email or password.', 401);
    }

    if (!$user['is_active']) {
      Response::error('Your account has been deactivated. Please contact support.', 403);
    }

    unset($user['password_hash'], $user['is_active']);

    $this->logActivity($user['id'], 'login', 'Logged in');

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
  // POST /api/auth/forgotPasswordOtp
  // ============================================================
  public function forgotPasswordOtp(): void
  {
    $email = trim($this->request->input('email', '')); // Get email from request

    $errors = ValidationHelper::check(
      ['email' => $email],
      ['email' => 'required|email']
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // check if email exist in database
    $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
      Response::error('No account associated with this email!', 404);
    }

    // $userId = $user['id'];
    // $this->db->prepare("UPDATE email_verifications SET is_used = 1 WHERE user_id = ? AND type = 'forgot_password'")
    //   ->execute([$userId]);

    // Generate OTP and store it
    $otp       = $this->generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $this->db->prepare("
            INSERT INTO email_verifications (email, otp, type, expires_at)
            VALUES (?, ?, 'forgot_password', ?)
        ")->execute([$email, $otp, $expiresAt]);

    QueueService::sendOTP($email, $otp, 'forgot_password');

    Response::success(['message_hint' => "A 6-digit verification code has been sent to {$email}"], 201);
  }

  // ============================================================
  // PRIVATE HELPERS
  // ============================================================

  private function generateOTP(): string
  {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  }

  private function logActivity(int $userId, string $action, string $description = ''): void
  {
    try {
      $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address)
                VALUES (?, ?, ?, ?)
            ")->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
      error_log('Activity log error: ' . $e->getMessage());
    }
  }
}
