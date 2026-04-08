<?php

class Database
{
  // Holds the single PDO instance
  private static ?PDO $instance = null;

  private function __construct() {}

  public static function connect(): PDO
  {
    if (self::$instance === null) {
      $host   = Environment::get('DB_HOST', '127.0.0.1');
      $name   = Environment::get('DB_NAME');
      $user   = Environment::get('DB_USER');
      $pass   = Environment::get('DB_PASS');

      $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

      try {
        self::$instance = new PDO($dsn, $user, $pass, [
          // Throw exceptions on errors instead of silent failures
          PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
          // Return results as associative arrays by default
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          // Disable emulated prepares for true prepared statements
          PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
      } catch (PDOException $e) {
        // In production you would log this, not expose the message
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
      }
    }

    return self::$instance;
  }
}
