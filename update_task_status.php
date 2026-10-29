<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
if (isset($data['id']) && isset($data['status'])) {
    $item_id = (int)$data['id'];
    $status = $data['status'];
    $type = $data['type'] ?? 'task';

    if ($type === 'event') {
        $stmt = getDb()->prepare("UPDATE calendar_items SET status = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$status, $item_id, $_SESSION['user_id']]);
    } else {
        $stmt = getDb()->prepare("UPDATE tasks SET status = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$status, $item_id, $_SESSION['user_id']]);
    }

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
}
