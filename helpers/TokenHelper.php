<?php
class TokenHelper{
  public static function generateQRToken():string{
    $timestamp = time();
    $random    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    return 'TKT-{$timestamp}-{$random}';
    }
    
    public static function generatePaystackReference(): string {
      $timestamp = time();
      $random    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
      return 'EVT-{$timestamp}-{$random}';
  }
}