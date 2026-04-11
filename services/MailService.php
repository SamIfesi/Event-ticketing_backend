<?php

class MailService
{
  private string $fromEmail;
  private string $fromName;
  private string $appEnv;

  public function __construct()
  {
    $this->fromEmail = Environment::get('MAIL_FROM_ADDRESS');
    $this->fromName  = Environment::get('MAIL_FROM_NAME', 'Event Ticketing');
    $this->appEnv    = Environment::get('APP_ENV', 'development');
  }

  // ============================================================
  // Send OTP verification email
  // ============================================================
  public function sendOTP(string $toEmail, string $toName, string $otp, string $type = 'register'): bool
  {
    $subject = $type === 'register'
      ? 'Verify your email address'
      : 'Confirm your new email address';

    $heading = $type === 'register'
      ? 'Welcome! Please verify your email'
      : 'Confirm your email change';

    $body = $this->template($heading, "
            <p><strong>Hello {$toName}</strong>,</p>
            <p>Thanks for signing up with Event Ticketing! Before you get started, we need to confirm your email address.</p>
            <p>Please use the OTP below to confirm your email address.</p>
            <div style='text-align:center; margin: 2rem 0;'>
                <span style='
                    font-size: 1.5rem;
                    font-weight: 800;
                    letter-spacing: 0.5rem;
                    color: #f97316;
                    background: #1a1a2e;
                    padding: 1rem 2rem;
                    border-radius: 0.5rem;
                    display: inline-block;
                '>{$otp}</span>
            </div>
            <p>It expires in <strong>10 minutes</strong>.</p>
            <p style='color:#888; font-size:0.9rem;'>If you did not request this, please ignore this email.</p>
        ");

    return $this->send($toEmail, $toName, $subject, $body);
  }

  // ============================================================
  // Send ticket confirmation email after successful payment
  // ============================================================
  public function sendTicketConfirmation(
    string $toEmail,
    string $toName,
    string $eventTitle,
    string $eventDate,
    string $eventLocation,
    string $ticketType,
    int    $quantity,
    float  $totalAmount,
    string $dashboardUrl
  ): bool {
    $subject         = "Your tickets for {$eventTitle} are confirmed!";
    $formattedAmount = '₦' . number_format($totalAmount, 2);
    $formattedDate   = date('D, d M Y \a\t g:ia', strtotime($eventDate));

    $body = $this->template("You're going to {$eventTitle}! 🎉", "
            <p>Hi <strong>{$toName}</strong>,</p>
            <p>Your payment was successful and your tickets have been issued. See you there!</p>

            <div style='background:#1a1a2e; border-radius:0.75rem; padding:1.5rem; margin: 1.5rem 0;'>
                <h3 style='color:#f97316; margin:0 0 1rem;'>Booking Summary</h3>
                <table style='width:100%; border-collapse:collapse; color:#e2e8f0;'>
                    <tr>
                        <td style='padding:0.5rem 0; color:#888;'>Event</td>
                        <td style='padding:0.5rem 0; text-align:right; font-weight:600;'>{$eventTitle}</td>
                    </tr>
                    <tr>
                        <td style='padding:0.5rem 0; color:#888;'>Date</td>
                        <td style='padding:0.5rem 0; text-align:right;'>{$formattedDate}</td>
                    </tr>
                    <tr>
                        <td style='padding:0.5rem 0; color:#888;'>Location</td>
                        <td style='padding:0.5rem 0; text-align:right;'>{$eventLocation}</td>
                    </tr>
                    <tr>
                        <td style='padding:0.5rem 0; color:#888;'>Ticket Type</td>
                        <td style='padding:0.5rem 0; text-align:right;'>{$ticketType}</td>
                    </tr>
                    <tr>
                        <td style='padding:0.5rem 0; color:#888;'>Quantity</td>
                        <td style='padding:0.5rem 0; text-align:right;'>{$quantity}</td>
                    </tr>
                    <tr style='border-top:1px solid #2a2a4a;'>
                        <td style='padding:0.75rem 0 0; font-weight:700;'>Total Paid</td>
                        <td style='padding:0.75rem 0 0; text-align:right; font-weight:700; color:#f97316;'>{$formattedAmount}</td>
                    </tr>
                </table>
            </div>

            <div style='text-align:center; margin:2rem 0;'>
                <a href='{$dashboardUrl}' style='
                    background:#f97316;
                    color:#fff;
                    padding:0.85rem 2rem;
                    border-radius:0.5rem;
                    text-decoration:none;
                    font-weight:700;
                    display:inline-block;
                '>View My Tickets</a>
            </div>

            <p style='color:#888; font-size:0.85rem;'>
                Your QR code tickets are available in your dashboard.
                Show your QR code at the gate for check-in.
            </p>
        ");

    return $this->send($toEmail, $toName, $subject, $body);
  }

  // ============================================================
  // Send password changed notification
  // ============================================================
  public function sendPasswordChanged(string $toEmail, string $toName): bool
  {
    $body = $this->template('Your password was changed', "
            <p>Hi <strong>{$toName}</strong>,</p>
            <p>Your account password was successfully changed.</p>
            <p style='color:#888;'>If you did not make this change, please contact support immediately.</p>
        ");

    return $this->send($toEmail, $toName, 'Password changed successfully', $body);
  }

  // ============================================================
  // Routes to the correct mail driver based on APP_ENV
  // development → Mailtrap API
  // production  → Gmail API
  // ============================================================
  private function send(string $toEmail, string $toName, string $subject, string $body): bool
  {
    if ($this->appEnv === 'production') {
      return $this->sendViaGmail($toEmail, $toName, $subject, $body);
    }

    return $this->sendViaMailtrap($toEmail, $toName, $subject, $body);
  }

  // ============================================================
  // DEVELOPMENT: Mailtrap API -- APP_ENV=development
  // ============================================================
  private function sendViaMailtrap(string $toEmail, string $toName, string $subject, string $body): bool
  {
    $apiToken = Environment::get('MAIL_TOKEN');
    $inboxId  = Environment::get('MAIL_INBOX_ID');

    $url  = "https://sandbox.api.mailtrap.io/api/send/{$inboxId}";
    $data = [
      'to'       => [['email' => $toEmail, 'name' => $toName]],
      'from'     => ['email' => $this->fromEmail, 'name' => $this->fromName],
      'subject'  => $subject,
      'html'     => $body,
      'category' => 'Transactional',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      "Authorization: Bearer {$apiToken}",
      'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
      error_log('MailService cURL error: ' . curl_error($ch));
      curl_close($ch);
      return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
      return true;
    }

    error_log('Mailtrap API error: HTTP ' . $httpCode . ' | ' . $response);
    return false;
  }

  // ============================================================
  // PRODUCTION: Gmail API
  // .env variables needed:
  //   GMAIL_CLIENT_ID=your_client_id
  //   GMAIL_CLIENT_SECRET=your_client_secret
  //   GMAIL_REFRESH_TOKEN=your_refresh_token
  //   APP_ENV=production
  //
  // Setup steps (do this when ready to deploy to Railway):
  // 1. Go to console.cloud.google.com
  // 2. Create a project → Enable Gmail API
  // 3. Create OAuth2 credentials → Desktop app type
  // 4. Get refresh token via OAuth Playground:
  //    https://developers.google.com/oauthplayground
  // 5. Run: composer require google/apiclient
  // 6. Set APP_ENV=production in Railway environment variables
  // ============================================================
  private function sendViaGmail(string $toEmail, string $toName, string $subject, string $body): bool
  {
    try {
      $client = new Google\Client();
      $client->setClientId(Environment::get('GMAIL_CLIENT_ID'));
      $client->setClientSecret(Environment::get('GMAIL_CLIENT_SECRET'));
      $client->refreshToken(Environment::get('GMAIL_REFRESH_TOKEN'));

      $service = new Google\Service\Gmail($client);

      $boundary    = uniqid(rand(), true);
      $rawMessage  = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
      $rawMessage .= "To: {$toName} <{$toEmail}>\r\n";
      $rawMessage .= "Subject: =?utf-8?B?" . base64_encode($subject) . "?=\r\n";
      $rawMessage .= "MIME-Version: 1.0\r\n";
      $rawMessage .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
      $rawMessage .= "--{$boundary}\r\n";
      $rawMessage .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
      $rawMessage .= strip_tags($body) . "\r\n\r\n";
      $rawMessage .= "--{$boundary}\r\n";
      $rawMessage .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
      $rawMessage .= $body . "\r\n\r\n";
      $rawMessage .= "--{$boundary}--";

      $encodedMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

      $message = new Google\Service\Gmail\Message();
      $message->setRaw($encodedMessage);
      $service->users_messages->send('me', $message);

      return true;
    } catch (Exception $e) {
      error_log('Gmail API error: ' . $e->getMessage());
      return false;
    }
  }

  // ============================================================
  // Consistent HTML email template
  // ============================================================
  private function template(string $heading, string $content): string
  {
    $appName = $this->fromName;

    return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='
            margin:0; padding:0;
            background:#0f1117;
            font-family: Arial, sans-serif;
            color:#e2e8f0;
        '>
            <div style='max-width:560px; margin:2rem auto; padding:0 1rem;'>

                <div style='text-align:center; padding:2rem 0 1rem;'>
                    <span style='color:#f97316; font-size:1.5rem; font-weight:800;'>{$appName}</span>
                </div>

                <div style='
                    background:#151a23;
                    border-radius:1rem;
                    border:1px solid #1e2433;
                    padding:2rem;
                '>
                    <h2 style='color:#f1f5f9; margin:0 0 1.25rem; font-size:1.3rem;'>{$heading}</h2>
                    {$content}
                </div>

                <p style='text-align:center; color:#475569; font-size:0.8rem; margin-top:1.5rem;'>
                    &copy; " . date('Y') . " {$appName}. All rights reserved.
                </p>
            </div>
        </body>
        </html>
        ";
  }
}
