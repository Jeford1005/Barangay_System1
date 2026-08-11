<?php
require_once __DIR__ . '/../config.php';
require_role('admin', 'staff');
header('Content-Type: application/json');

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['error' => 'Missing ID']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM residents WHERE id = ?");
$stmt->execute([$id]);
$resident = $stmt->fetch();

if (!$resident) {
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Format dates for display
$resident['birth_date'] = $resident['birth_date'] ?? '';
echo json_encode($resident);
