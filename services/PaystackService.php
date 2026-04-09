<?php
class PaystackService {
  private string $secretKey;
  private string $baseUrl = 'https://api.paystack.co';

  public function __construct(){
    $this->secretKey = Environment::get('PAYSTACK_SECRET_KEY');
  }

  public function initializeTransaction(string $email, float $amount, string $reference, array $metadata = []): array{
    $body = json_encode([
      'email'     => $email,
      'amount'    => (int)($amount * 100), // Paystack expects amount in kobo
      'reference' => $reference,
      'metadata'  => $metadata,
      'currency' => 'NGN',
    ]);

    $response = $this->makeRequest('POST', '/transaction/initialize', $body);

    if(!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to initialize payment.');
    }
    return $response['data'];
  }

  public function verifyTransaction(string $reference): array{
    $response = $this->makeRequest('GET', "/transaction/verify/{$reference}");

    if(!$response['status']) {
      throw new Exception($response['message'] ?? 'Failed to verify payment.');
    }
    return $response['data'];
  }

  private function makeRequest(string $method, string $endpoint, ?string $body = null): array {
    $curl = curl_init();

    $options = [
      CURLOPT_URL            => $this->baseUrl . $endpoint,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        'Authorization:Bearer ' . $this->secretKey,
        'Content-Type: application/json',
        'Cache-Control: no-cache',
      ],
    ];

    if ($method === 'POST') {
      $options[CURLOPT_POST]       = true;
      $options[CURLOPT_POSTFIELDS] = $body;
    }

    
        curl_setopt_array($curl, $options);
 
        $response = curl_exec($curl);
        $error    = curl_error($curl);
 
        curl_close($curl);
 
        if ($error) {
            throw new Exception('Network error contacting Paystack: ' . $error);
        }
 
        return json_decode($response, true);
  }
}