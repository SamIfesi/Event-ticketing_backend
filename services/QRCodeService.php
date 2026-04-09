<?php

use BaconQrCode\Renderer\Image\ImagickImageBackend;
use BaconQrCode\Renderer\Image\SvgImageBackend;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QRCOdeService
{
  public static function generate(string $qrToken): string
  {
    $outputDir = Constants::STORAGE_QRCODES;
    $filePath  = $outputDir . $qrToken . '.svg';

    if (file_exists($filePath)) {
      return $filePath;
    }

    if (!is_dir($outputDir)) {
      mkdir($outputDir, 0755, true);
    }
    $renderer = new ImageRenderer(
      new RendererStyle(300),
      new SvgImageBackend()
    );

    $writer = new Writer($renderer);
    $writer->writeFile($qrToken, $filePath);

    return $filePath;
  }

  public static function getUrl(string $qrToken): string
  {
    $appUrl = Environment::get('APP_URL', 'http://localhost');
    return "{$appUrl}/storage/qrcodes/{$qrToken}.svg";
  }

  public static function delete(string $qrToken): void
  {
    $filePath = Constants::STORAGE_QRCODES . $qrToken . '.svg';

    if (file_exists($filePath)) {
      unlink($filePath);
    }
  }
}
