<?php
class QRCOdeService {
  public static function generate(string $qrToken): string {
    require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';

    $outputDir = Constants::STORAGE_QRCODES;
    $filePath  = $outputDir . $qrToken . '.png';

    if (file_exists($filePath)) {
      return $filePath;
    }

    if(!is_dir($outputDir)) {
      mkdir($outputDir, 0755, true);
    }

    QRcode::png($qrToken, $filePath, QR_ECLEVEL_M, 8, 2);

    return $filePath;
  }

  public static function getUrl(string $qrToken): string{
    $appUrl = Environment::get('APP_URL', 'http://localhost/');
    return "{$appUrl}/storage/qrcodes/{$qrToken}.png";
  }

  public static function delete(string $qrToken): void{
    $filePath = Constants::STORAGE_QRCODES . $qrToken . '.png';

    if (file_exists($filePath)){
      unlink($filePath);
    }
  }
}