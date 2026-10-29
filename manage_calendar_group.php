<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !isset($data['action']) || !isset($data['group_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

$user_id = $_SESSION['user_id'];
$group_id = (int)$data['group_id'];

if ($group_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid calendar id']);
    exit;
}

$db = getDb();

// Confirm the group belongs to this user before doing anything to it.
$stmt = $db->prepare("SELECT id, visible FROM calendar_groups WHERE id = ? AND user_id = ?");
$stmt->execute([$group_id, $user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    echo json_encode(['success' => false, 'message' => 'Calendar not found']);
    exit;
}

if ($data['action'] === 'toggle') {
    $newVisible = $group['visible'] ? 0 : 1;
    $result = updateCalendarGroupVisibility($user_id, $group_id, $newVisible);
    echo json_encode(['success' => (bool)$result, 'visible' => $newVisible]);
} elseif ($data['action'] === 'delete') {
    // 1. DELETE all child events/items belonging to this calendar group
    $stmt = $db->prepare("DELETE FROM calendar_items WHERE calendar_group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);

    // 2. Unlink tasks (or change to DELETE if you want tasks gone too)
    $stmt = $db->prepare("UPDATE tasks SET calendar_group_id = NULL WHERE calendar_group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);

    // 3. Delete the actual calendar group
    $result = deleteCalendarGroup($user_id, $group_id);
    echo json_encode(['success' => (bool)$result]);
} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
