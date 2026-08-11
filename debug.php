<?php
header('Content-Type: text/plain');
echo "DB_HOST=" . (getenv('DB_HOST') ?: '(empty)') . "\n";
echo "DB_PORT=" . (getenv('DB_PORT') ?: '(empty)') . "\n";
echo "DB_USER=" . (getenv('DB_USER') ?: '(empty)') . "\n";
echo "DB_NAME=" . (getenv('DB_NAME') ?: '(empty)') . "\n";
echo "DB_PASS set=" . (getenv('DB_PASSWORD') ? 'yes' : 'no') . "\n";
$host = getenv('DB_HOST') ?: 'altaria.proxy.rlwy.net';
$port = getenv('DB_PORT') ?: 30414;
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$db   = getenv('DB_NAME') ?: 'barangay_bidduang_db';
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
try {
    $p = new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT=>10, PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4']);
    echo "CONNECTION: OK\n";
} catch (PDOException $e) {
    echo "PDO ERROR: " . $e->getMessage() . "\n";
    echo "CODE: " . $e->getCode() . "\n";
}