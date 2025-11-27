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

$current_user_id = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$receiver_id = $input['receiver_id'] ?? '';
$call_type = $input['call_type'] ?? 'audio';

if (empty($receiver_id)) {
    echo json_encode(["success" => false, "message" => "Receiver ID is required"]);
    exit;
}

// Insert call into database
$sql = "INSERT INTO calls (employee_id, type, start_time, status) VALUES (?, ?, NOW(), 'ongoing')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $current_user_id, $call_type);

if ($stmt->execute()) {
    $call_id = $stmt->insert_id;
    
    echo json_encode([
        "success" => true,
        "call_id" => $call_id,
        "start_time" => date('Y-m-d H:i:s')
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to start call"]);
}

$stmt->close();
$conn->close();
?>