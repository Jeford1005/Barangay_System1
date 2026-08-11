<?php
require_once __DIR__ . '/../config.php';
require_role('admin', 'staff');
header('Content-Type: application/json');

$id = $_GET['id'] ?? null;
if (!$id) { echo json_encode(['error'=>'Missing ID']); exit; }

$stmt = $pdo->prepare("SELECT * FROM blotter_cases WHERE id = ?");
$stmt->execute([$id]);
echo json_encode($stmt->fetch() ?: ['error'=>'Not found']);
