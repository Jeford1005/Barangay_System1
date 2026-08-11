<?php
require_once __DIR__ . '/../config.php';
require_role('admin');
header('Content-Type: application/json');

$id = $_GET['id'] ?? null;
if (!$id) { echo json_encode(['error'=>'Missing ID']); exit; }

$stmt = $pdo->prepare("SELECT id, username, email, full_name, role, status, resident_id, last_login FROM users WHERE id = ?");
$stmt->execute([$id]);
echo json_encode($stmt->fetch() ?: ['error'=>'Not found']);
