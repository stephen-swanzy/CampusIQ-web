<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Only allow authenticated users
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDb();

// Fetch last 10 activities
$stmt = $db->query('SELECT a.*, u.name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 10');
$activities = $stmt->fetchAll();

// Format the response
$formattedActivities = [];
foreach ($activities as $activity) {
    $formattedActivities[] = [
        'id' => $activity['id'],
        'user' => $activity['name'] ?: 'Unknown User',
        'action' => $activity['action'],
        'details' => $activity['details'],
        'time' => date('d M Y H:i', strtotime($activity['created_at']))
    ];
}

echo json_encode([
    'success' => true,
    'activities' => $formattedActivities,
    'count' => count($formattedActivities)
]);
?>
