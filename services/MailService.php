<?php

/**
 * MailService
 *
 * Sends transactional emails via the SendByte/Resend API.
 * Same code path for development and production — only the
 * .env values change between environments.
 *
 * ENV variables needed:
 *   SENDBYTE_API_KEY     — from your SendByte dashboard (sk_test_... in dev, sk_live_... in prod)
 *   MAIL_API_URL         — SendByte endpoint, defaults to https://api.sendbyte.africa/v1/emails
 *   MAIL_FROM_ADDRESS    — verified sender e.g. no-reply@ticketer.ng
 *   MAIL_FROM_NAME       — display name e.g. Ticketer
 *   MAIL_LOGO_URL        — full Cloudinary URL to your logo image
 *   APP_NAME             — Ticketer
 *   APP_URL              — https://yourapp.com
 *
 * To rotate keys or switch environments: only touch .env, never this file.
 */
class MailService
{
  private string $apiKey;
  private string $apiUrl;
  private string $fromEmail;
  private string $fromName;
  private string $appName;
  private string $appUrl;
  private string $logoUrl;

  // Brand colours
  private string $colorAccent = '#2563eb';
  private string $colorMuted  = '#71717a';

  public function __construct()
  {
    $this->apiKey    = Environment::get('SENDBYTE_API_KEY');
    $this->apiUrl    = Environment::get('MAIL_API_URL',      'https://api.sendbyte.africa/v1/emails');
    $this->fromEmail = Environment::get('MAIL_FROM_ADDRESS', 'no-reply@ticketer.ng');
    $this->fromName  = Environment::get('MAIL_FROM_NAME',    'Ticketer');
    $this->appName   = Environment::get('APP_NAME',          'Ticketer');
    $this->appUrl    = Environment::get('APP_URL',           'https://ticketer.ng');
    $this->logoUrl   = Environment::get('MAIL_LOGO_URL',     '');
  }

  // ============================================================
  // Welcome email — new user (email registration OR Google)
  // ============================================================
  public function sendWelcome(string $toEmail, string $toName): bool
  {
    $body =
      $this->paragraph("Your account is ready. Here's what you can do right now:") .
      $this->spacer(4) .
      $this->featureList([
        '&#127881; Browse and book tickets for events across Nigeria',
        '&#127914; Get your QR code ticket instantly after payment',
        '&#128248; Show your QR code at the gate — no printing needed',
        '&#128640; Apply to become an organiser and host your own events',
      ]) .
      $this->spacer(20) .
      $this->button('Browse Events', $this->appUrl . '/events') .
      $this->spacer(16) .
      $this->muted("If you didn't create this account, please contact us at support@ticketer.ng");

    return $this->send(
      $toEmail,
      $toName,
      "Welcome to {$this->appName}!",
      $this->template(
        "Welcome, {$toName}! &#127881;",
        "You're all set. Your {$this->appName} account has been created successfully.",
        $body
      )
    );
  }

  // ============================================================
  // OTP — registration or email change
  // ============================================================
  public function sendOTP(
    string $toEmail,
    string $toName,
    string $otp,
    string $type = 'register'
  ): bool {
    $isRegister = ($type === 'register');

    $subject  = $isRegister
      ? 'Verify your email address'
      : 'Confirm your new email address';

    $headline = $isRegister
      ? 'Verify your email'
      : 'Confirm your new email';

    $intro = $isRegister
      ? "Thanks for signing up! Use the code below to verify your email address. It expires in <strong>30 minutes</strong>."
      : "Use the code below to confirm your new email address. It expires in <strong>30 minutes</strong>.";

    $body =
      $this->otpBlock($otp) .
      $this->spacer(8) .
      $this->muted("If you didn't request this, you can safely ignore this email.");

    return $this->send(
      $toEmail,
      $toName,
      $subject,
      $this->template($headline, $intro, $body)
    );
  }

  // ============================================================
  // Forgot password OTP
  // ============================================================
  public function sendForgotPasswordOTP(
    string $toEmail,
    string $toName,
    string $otp
  ): bool {
    $body =
      $this->otpBlock($otp) .
      $this->spacer(8) .
      $this->muted("This code expires in <strong>30 minutes</strong>. If you didn't request a password reset, you can safely ignore this email.");

    return $this->send(
      $toEmail,
      $toName,
      'Reset your password',
      $this->template(
        'Reset your password',
        "Use the code below to reset your {$this->appName} password.",
        $body
      )
    );
  }

  // ============================================================
  // Ticket confirmation — after successful payment
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
    string $dashboardUrl,
    string $bookingReference = ''
  ): bool {
    $formattedAmount = '&#8358;' . number_format($totalAmount, 2);
    $formattedDate   = date('D, d M Y \a\t g:ia', strtotime($eventDate));

    $body =
      $this->summaryTable([
        ['Event',       $eventTitle,                                           false],
        ['Date',        $formattedDate,                                        false],
        ['Location',    $eventLocation ?: 'TBC',                               false],
        ['Ticket type', $ticketType,                                           false],
        ['Quantity',    $quantity . ' ticket' . ($quantity !== 1 ? 's' : ''),  false],
        ['Total paid',  $formattedAmount,                                      true],
      ]) .
      $this->spacer(24) .
      $this->button('View My Tickets', $dashboardUrl) .
      $this->spacer(16) .
      $this->muted('Show your QR code at the gate for check-in. Your tickets are in your dashboard.');

    return $this->send(
      $toEmail,
      $toName,
      "You're going to {$eventTitle}!",
      $this->template(
        "You're going! &#127881;",
        "Your payment was successful and your tickets have been issued.",
        $body
      ),
      '',
      $bookingReference ? "booking-{$bookingReference}-confirm" : ''
    );
  }

  // ============================================================
  // Password changed notification
  // ============================================================
  public function sendPasswordChanged(string $toEmail, string $toName): bool
  {
    $body =
      $this->paragraph("Your account password was successfully updated. If you made this change, no further action is needed.") .
      $this->spacer(16) .
      $this->button('Go to Dashboard', $this->appUrl . '/dashboard') .
      $this->spacer(16) .
      $this->muted('If you did <strong>not</strong> make this change, please reset your password immediately.');

    return $this->send(
      $toEmail,
      $toName,
      'Your password was changed',
      $this->template(
        'Password changed',
        "Hi {$toName}, your {$this->appName} password has been updated.",
        $body
      )
    );
  }

  // ============================================================
  // PRIVATE — SendByte API call
  // ============================================================
  private function send(
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    string $text           = '',
    string $idempotencyKey = ''
  ): bool {
    if (empty($this->apiKey)) {
      error_log('MailService: SENDBYTE_API_KEY is not set.');
      return false;
    }

    $body = [
      'from'    => "{$this->fromName} <{$this->fromEmail}>",
      'to'      => $toEmail,
      'subject' => $subject,
      'html'    => $html,
      'text'    => $text ?: strip_tags($html),
    ];

    if (!empty($idempotencyKey)) {
      $body['idempotency_key'] = $idempotencyKey;
    }

    $payload = json_encode($body);

    $ch = curl_init($this->apiUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $this->apiKey,
        'Content-Type: application/json',
      ],
      CURLOPT_TIMEOUT => 15,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
      error_log('MailService cURL error: ' . $curlError);
      return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
      error_log('MailService SendByte error: HTTP ' . $httpCode . ' — ' . $response);
      return false;
    }

    return true;
  }

  // ============================================================
  // PRIVATE — Master HTML template
  // ============================================================
  private function template(
    string $headline,
    string $intro,
    string $body
  ): string {
    $year    = date('Y');
    $accent  = $this->colorAccent;
    $appName = $this->appName;
    $appUrl  = $this->appUrl;

    // Logo: image if MAIL_LOGO_URL is set, else bold text fallback
    $logoHtml = !empty($this->logoUrl)
      ? "<a href=\"{$appUrl}\" style=\"text-decoration:none;\">
                 <img src=\"{$this->logoUrl}\" alt=\"{$appName}\" width=\"130\" height=\"auto\"
                      style=\"display:block;border:0;outline:none;max-width:130px;height:auto;\" />
               </a>"
      : "<a href=\"{$appUrl}\" style=\"text-decoration:none;\">
                 <span style=\"font-size:22px;font-weight:900;color:{$accent};letter-spacing:-0.03em;\">{$appName}</span>
               </a>";

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>{$appName}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f5;padding:40px 16px;">
    <tr>
      <td align="center">

        <!-- Card -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:520px;background-color:#ffffff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.08),0 4px 16px rgba(0,0,0,0.06);overflow:hidden;">

          <!-- Top accent bar -->
          <tr>
            <td style="height:4px;background-color:{$accent};font-size:0;line-height:0;">&nbsp;</td>
          </tr>

          <!-- Logo -->
          <tr>
            <td align="center" style="padding:32px 40px 24px;">
              {$logoHtml}
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding:0 40px;">
              <div style="height:1px;background-color:#f1f1f1;"></div>
            </td>
          </tr>

          <!-- Content -->
          <tr>
            <td style="padding:32px 40px 40px;">
              <h1 style="margin:0 0 8px;font-size:22px;font-weight:800;color:#18181b;letter-spacing:-0.02em;line-height:1.3;">{$headline}</h1>
              <p style="margin:0 0 24px;font-size:15px;color:#52525b;line-height:1.6;">{$intro}</p>
              {$body}
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:20px 40px 32px;border-top:1px solid #f1f1f1;">
              <p style="margin:0;font-size:12px;color:#a1a1aa;text-align:center;line-height:1.6;">
                &copy; {$year} {$appName}. All rights reserved.<br/>
                <a href="{$appUrl}" style="color:#a1a1aa;text-decoration:underline;">{$appUrl}</a>
              </p>
            </td>
          </tr>

        </table>
        <!-- /Card -->

      </td>
    </tr>
  </table>

</body>
</html>
HTML;
  }

    // ============================================================
    // PRIVATE — Reusable building blocks
    // ============================================================

  /** Large centred OTP code block */
  private function otpBlock(string $otp): string
  {
    $accent = $this->colorAccent;
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td align="center" style="padding:8px 0 24px;">
      <div style="display:inline-block;background-color:#eff6ff;border:1.5px dashed {$accent};border-radius:10px;padding:20px 48px;">
        <p style="margin:0 0 6px;font-size:11px;font-weight:700;color:{$accent};text-transform:uppercase;letter-spacing:0.12em;">Your verification code</p>
        <p style="margin:0;font-size:38px;font-weight:900;color:{$accent};letter-spacing:0.3em;font-family:'Courier New',Courier,monospace;">{$otp}</p>
      </div>
    </td>
  </tr>
</table>
HTML;
  }

  /** Full-width CTA button */
  private function button(string $label, string $url): string
  {
    $accent = $this->colorAccent;
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td align="center" style="padding:4px 0;">
      <a href="{$url}"
         style="display:inline-block;background-color:{$accent};color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 40px;border-radius:8px;letter-spacing:0.01em;">
        {$label}
      </a>
    </td>
  </tr>
</table>
HTML;
  }

  /** Booking summary table */
  private function summaryTable(array $rows): string
  {
    $accent   = $this->colorAccent;
    $rowsHtml = '';

    foreach ($rows as [$label, $value, $highlight]) {
      $valueStyle = $highlight
        ? "font-size:15px;font-weight:800;color:{$accent};"
        : 'font-size:14px;font-weight:600;color:#18181b;';

      $rowsHtml .= <<<HTML
<tr>
  <td style="padding:11px 0;border-bottom:1px solid #f4f4f5;font-size:13px;color:#71717a;font-weight:500;width:42%;">{$label}</td>
  <td style="padding:11px 0;border-bottom:1px solid #f4f4f5;{$valueStyle}text-align:right;">{$value}</td>
</tr>
HTML;
    }

    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;margin-bottom:4px;">
  <tr>
    <td style="padding:0 16px;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        {$rowsHtml}
      </table>
    </td>
  </tr>
</table>
HTML;
  }

  /** Bullet feature list for welcome email */
  private function featureList(array $items): string
  {
    $itemsHtml = '';
    foreach ($items as $item) {
      $itemsHtml .= <<<HTML
<tr>
  <td style="padding:6px 0;font-size:14px;color:#3f3f46;line-height:1.5;">{$item}</td>
</tr>
HTML;
    }

    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background-color:#f8faff;border-radius:8px;padding:4px 0;margin-bottom:4px;">
  <tr>
    <td style="padding:12px 20px;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        {$itemsHtml}
      </table>
    </td>
  </tr>
</table>
HTML;
  }

  /** Standard paragraph */
  private function paragraph(string $text): string
  {
    return "<p style=\"margin:0 0 12px;font-size:15px;color:#52525b;line-height:1.6;\">{$text}</p>";
  }

  /** Small muted helper text */
  private function muted(string $text): string
  {
    return "<p style=\"margin:0;font-size:13px;color:{$this->colorMuted};line-height:1.6;\">{$text}</p>";
  }

  /** Vertical spacer */
  private function spacer(int $px): string
  {
    return "<div style=\"height:{$px}px;font-size:0;line-height:0;\">&nbsp;</div>";
  }
}
