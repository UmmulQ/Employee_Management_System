<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once "connect.php";

if (!isset($_GET["project_id"])) {
    echo json_encode(["success" => false, "message" => "Missing project_id"]);
    exit;
}

$project_id = intval($_GET["project_id"]);
$sql = "SELECT * FROM tasks WHERE project_id = $project_id ORDER BY task_id DESC";
$result = $conn->query($sql);

$tasks = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }
    echo json_encode(["success" => true, "tasks" => $tasks]);
} else {
    echo json_encode(["success" => false, "message" => "No tasks found"]);
}

$conn->close();
?>
