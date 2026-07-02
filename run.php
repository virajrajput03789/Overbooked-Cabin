<?php
declare(strict_types=1);

require_once __DIR__ . '/payload_generator.php';
require_once __DIR__ . '/BookingManager.php';

// Adjust DSN for your local environment
$dsn = "mysql:host=127.0.0.1;dbname=overbooked_cabin;charset=utf8mb4";
$user = "root";
$pass = "";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
    echo "Please adjust the credentials in run.php and create the required DB/Table first.\n";
    exit(1);
}

// Custom logger to echo output directly
$logger = function(string $message) {
    echo "[LOG] " . $message . "\n";
};

$manager = new BookingManager($pdo, $logger);

echo "Generating mock payload...\n";
$payload = generateMessyPayload();

echo "Processing payload...\n";
$summary = $manager->processPayload($payload);

echo "Processing complete.\n";
echo "Summary:\n";
print_r($summary);
