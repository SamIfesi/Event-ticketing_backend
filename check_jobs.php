<?php

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/config/Database.php';

$db = Database::connect();

echo "=== Connected to database successfully ===" . PHP_EOL . PHP_EOL;

// Check ALL jobs regardless of status
$stmt = $db->query('SELECT id, type, status, attempts, max_attempts, available_at, created_at FROM jobs ORDER BY id DESC');
$jobs = $stmt->fetchAll();

if (empty($jobs)) {
  echo "NO JOBS FOUND IN TABLE AT ALL." . PHP_EOL;
} else {
  echo "Found " . count($jobs) . " job(s) in table:" . PHP_EOL;
  foreach ($jobs as $job) {
    echo "---" . PHP_EOL;
    echo "  ID:           " . $job['id'] . PHP_EOL;
    echo "  Type:         " . $job['type'] . PHP_EOL;
    echo "  Status:       " . $job['status'] . PHP_EOL;
    echo "  Attempts:     " . $job['attempts'] . PHP_EOL;
    echo "  Max Attempts: " . $job['max_attempts'] . PHP_EOL;
    echo "  Available At: " . $job['available_at'] . PHP_EOL;
    echo "  Created At:   " . $job['created_at'] . PHP_EOL;
  }
}

echo PHP_EOL;

// Check what the worker query actually sees
echo "=== What the worker query sees ===" . PHP_EOL;
$stmt = $db->prepare("
    SELECT * FROM jobs
    WHERE status = 'pending'
    
      AND available_at <= NOW()
      AND attempts < max_attempts
    ORDER BY created_at ASC
    LIMIT 10
");
$stmt->execute();
$pending = $stmt->fetchAll();

echo "Pending jobs ready for worker: " . count($pending) . PHP_EOL . PHP_EOL;

// Show current server time vs available_at
echo "=== Time Check ===" . PHP_EOL;
$stmt = $db->query("SELECT NOW() as db_time");
$row = $stmt->fetch();
echo "Database NOW():  " . $row['db_time'] . PHP_EOL;
echo "PHP time():      " . date('Y-m-d H:i:s') . PHP_EOL;
