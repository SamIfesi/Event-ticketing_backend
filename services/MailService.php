<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
  private PHPMailer $mailer;

  public function __construct()
  {
    $this->mailer = new PHPMailer(true);

    // SMTP configuration — uses your .env values
    $this->mailer->isSMTP();
    $this->mailer->Host       = Environment::get('MAIL_HOST', 'smtp.gmail.com');
    $this->mailer->SMTPAuth   = true;
    $this->mailer->Username   = Environment::get('MAIL_USERNAME');
    $this->mailer->Password   = Environment::get('MAIL_PASSWORD');
    $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $this->mailer->Port       = (int) Environment::get('MAIL_PORT', '587');

    // Sender details
    $this->mailer->setFrom(
      Environment::get('MAIL_FROM_ADDRESS'),
      Environment::get('MAIL_FROM_NAME', 'Event Ticketing')
    );

    $this->mailer->isHTML(true);
  }

  // ============================================================
  // Send OTP verification email
  // Called after register and after email change request
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
            <p>Hi <strong>{$toName}</strong>,</p>
            <p>Use the OTP below to verify your email address. It expires in <strong>10 minutes</strong>.</p>
            <div style='text-align:center; margin: 2rem 0;'>
                <span style='
                    font-size: 2.5rem;
                    font-weight: 800;
                    letter-spacing: 0.5rem;
                    color: #f97316;
                    background: #1a1a2e;
                    padding: 1rem 2rem;
                    border-radius: 0.5rem;
                    display: inline-block;
                '>{$otp}</span>
            </div>
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
    $subject  = "Your tickets for {$eventTitle} are confirmed!";
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
  // PRIVATE HELPERS
  // ============================================================

  private function send(string $toEmail, string $toName, string $subject, string $body): bool
  {
    try {
      $this->mailer->clearAddresses();
      $this->mailer->addAddress($toEmail, $toName);
      $this->mailer->Subject = $subject;
      $this->mailer->Body    = $body;
      $this->mailer->send();
      return true;
    } catch (Exception $e) {
      // Log the error but don't crash the app
      error_log('MailService error: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * Wraps content in a consistent HTML email template
   */
  private function template(string $heading, string $content): string
  {
    $appName = Environment::get('MAIL_FROM_NAME', 'Event Ticketing');

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

                <!-- Header -->
                <div style='text-align:center; padding:2rem 0 1rem;'>
                    <span style='color:#f97316; font-size:1.5rem; font-weight:800;'>{$appName}</span>
                </div>

                <!-- Card -->
                <div style='
                    background:#151a23;
                    border-radius:1rem;
                    border:1px solid #1e2433;
                    padding:2rem;
                '>
                    <h2 style='color:#f1f5f9; margin:0 0 1.25rem; font-size:1.3rem;'>{$heading}</h2>
                    {$content}
                </div>

                <!-- Footer -->
                <p style='text-align:center; color:#475569; font-size:0.8rem; margin-top:1.5rem;'>
                    &copy; " . date('Y') . " {$appName}. All rights reserved.
                </p>

            </div>
        </body>
        </html>
        ";
  }
}
