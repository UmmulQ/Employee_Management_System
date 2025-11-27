<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require_once "connect.php";

if (empty($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$call_id = $input['call_id'] ?? '';

if (empty($call_id)) {
    echo json_encode(["success" => false, "message" => "Call ID is required"]);
    exit;
}

// Update call status
$sql = "UPDATE calls SET end_time = NOW(), status = 'ended' WHERE call_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $call_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Call ended successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to end call"]);
}

$stmt->close();
$conn->close();
?>