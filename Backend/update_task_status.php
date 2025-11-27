<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once "connect.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["task_id"]) || !isset($data["status"])) {
    echo json_encode(["success" => false, "message" => "Missing required data"]);
    exit;
}

$task_id = intval($data["task_id"]);
$status = $conn->real_escape_string(strtolower(trim($data["status"])));

$sql = "UPDATE tasks SET status = '$status' WHERE task_id = $task_id";

if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "Task status updated"]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update status"]);
}

$conn->close();
?>
