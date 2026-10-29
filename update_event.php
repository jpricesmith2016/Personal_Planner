<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

requireLogin();

$user_id = $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$item_id = isset($data['id']) ? (int)$data['id'] : 0;
if ($item_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing event id']);
    exit;
}

$deleteSeries = isset($data['delete_series']) && (int)$data['delete_series'] === 1;
$db = getDb();

if (isset($data['action']) && $data['action'] === 'delete') {
    if ($deleteSeries) {
        $stmt = $db->prepare("SELECT series_key FROM calendar_items WHERE id = ? AND user_id = ?");
        $stmt->execute([$item_id, $user_id]);
        $seriesKey = $stmt->fetchColumn();

        if (!empty($seriesKey)) {
            $deleteStmt = $db->prepare("DELETE FROM calendar_items WHERE user_id = ? AND series_key = ?");
            $result = $deleteStmt->execute([$user_id, $seriesKey]);
        } else {
            $deleteStmt = $db->prepare("DELETE FROM calendar_items WHERE id = ? AND user_id = ?");
            $result = $deleteStmt->execute([$item_id, $user_id]);
        }
    } else {
        $deleteStmt = $db->prepare("DELETE FROM calendar_items WHERE id = ? AND user_id = ?");
        $result = $deleteStmt->execute([$item_id, $user_id]);
    }
} else {
    $title = isset($data['title']) ? trim($data['title']) : '';
    $start_at = isset($data['start_at']) ? trim($data['start_at']) : null;
    $end_at = isset($data['end_at']) ? trim($data['end_at']) : null;
    $series_key = isset($data['series_key']) && $data['series_key'] !== '' ? $data['series_key'] : '';
    $calendar_group_id = isset($data['calendar_group_id']) && $data['calendar_group_id'] !== '' ? (int)$data['calendar_group_id'] : null;
    
    // Check if user explicitly clicked "Save Entire Series"
    $updateSeries = isset($data['update_series']) && (int)$data['update_series'] === 1;

    $color = (isset($data['color']) && $data['color'] === 'inherit') ? 'inherit' : ($data['color'] ?? 'inherit');
    if ($color !== 'inherit' && !preg_match('/^#([A-Fa-f0-9]{6})$/', $color)) {
        $color = 'inherit';
    }

    // FIX: Only update all matching elements if they belong to a series AND the user clicked "Save Entire Series"
    if ($updateSeries && !empty($series_key)) {
        $stmt = $db->prepare("UPDATE calendar_items SET title = ?, color = ?, calendar_group_id = ? WHERE user_id = ? AND series_key = ?");
        $result = $stmt->execute([$title, $color, $calendar_group_id, $user_id, $series_key]);
    } else {
        // Otherwise, update only this singular event instance
        $stmt = $db->prepare("UPDATE calendar_items SET title = ?, start_at = ?, end_at = ?, color = ?, calendar_group_id = ? WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$title, $start_at, $end_at, $color, $calendar_group_id, $item_id, $user_id]);
    }
}

echo json_encode(['success' => (bool)$result]);