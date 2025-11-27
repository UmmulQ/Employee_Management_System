<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

// ✅ Handle preflight
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "connect.php";

// ✅ Optional filter by project_id
$project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : null;

$sql = "SELECT 
            task_id,
            task_title,
            description,
            project_id,
            assigned_to,
            assigned_by,
            priority,
            status,
            created_at,
            updated_at,
            due_date
        FROM task";

if ($project_id) {
    $sql .= " WHERE project_id = ?";
}

$stmt = $conn->prepare($sql);

if ($project_id) {
    $stmt->bind_param("i", $project_id);
}

$stmt->execute();
$result = $stmt->get_result();

$tasks = [];
while ($row = $result->fetch_assoc()) {
    $tasks[] = [
        "task_id"     => (int) $row["task_id"],
        "task_title"  => $row["task_title"],
        "description" => $row["description"],
        "project_id"  => (int) $row["project_id"],
        "assigned_to" => $row["assigned_to"],
        "assigned_by" => $row["assigned_by"],
        "priority"    => strtolower(trim($row["priority"])), // ✅ normalize
        "status"      => strtolower(trim($row["status"])),   // ✅ normalize
        "created_at"  => $row["created_at"],
        "updated_at"  => $row["updated_at"],
        "due_date"    => $row["due_date"]
    ];
}

echo json_encode([
    "success" => true,
    "tasks"   => $tasks
]);

$stmt->close();
$conn->close();

