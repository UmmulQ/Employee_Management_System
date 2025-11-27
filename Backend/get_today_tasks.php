<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "connect.php";

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("User not authenticated. Please log in.");
    }

    $user_id = (int) $_SESSION['user_id'];
    $today = date("Y-m-d");

    // Logging for backend debug
    error_log("📅 Fetching today's tasks for user_id = $user_id");

    $query = "
        SELECT 
            task_id,
            COALESCE(task_title, 'Untitled Task') as title,
            COALESCE(description, '') as description,
            COALESCE(status, 'pending') as status,
            COALESCE(priority, 'medium') as priority,
            due_date,
            created_at,
            project_id,
            estimated_hours
        FROM tasks
        WHERE assigned_to = ?
        AND (
            due_date = ?
            OR due_date IS NULL
            OR due_date = '0000-00-00'
            OR due_date = ''
            OR DATE(created_at) = ?
            OR (due_date < ? AND status IN ('pending', 'in progress', 'in_progress'))
        )
        ORDER BY 
            CASE 
                WHEN status = 'pending' THEN 1
                WHEN status = 'in progress' THEN 2
                WHEN status = 'in_progress' THEN 2
                ELSE 3
            END,
            CASE 
                WHEN priority = 'high' THEN 1
                WHEN priority = 'medium' THEN 2
                WHEN priority = 'low' THEN 3
                ELSE 4
            END,
            due_date ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("SQL Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("isss", $user_id, $today, $today, $today);
    $stmt->execute();
    $result = $stmt->get_result();

    $tasks = [];
    $status_map = [
        'todo' => 'pending',
        'in_progress' => 'in progress',
        'review' => 'in progress',
        'done' => 'completed',
        'completed' => 'completed',
        'pending' => 'pending'
    ];

    while ($row = $result->fetch_assoc()) {
        $normalized_status = strtolower(trim($row['status']));
        $mapped_status = $status_map[$normalized_status] ?? 'pending';

        $tasks[] = [
            'task_id' => $row['task_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'status' => $mapped_status,
            'priority' => $row['priority'],
            'due_date' => $row['due_date'],
            'project_id' => $row['project_id'],
            'estimated_hours' => $row['estimated_hours'],
            'created_at' => $row['created_at'],
        ];
    }

    echo json_encode([
        "success" => true,
        "tasks" => $tasks,
        "count" => count($tasks),
        "user_id" => $user_id,
        "debug_info" => [
            "today" => $today,
            "task_count" => count($tasks)
        ]
    ]);
    
    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log("❌ Task fetch error: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage(),
        "tasks" => [],
    ]);
}
?>
