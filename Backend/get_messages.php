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
$receiver_id = $_GET['receiver_id'] ?? '';

if (empty($receiver_id)) {
    echo json_encode(["success" => false, "message" => "Receiver ID is required"]);
    exit;
}

// Fetch messages between current user and selected employee
$sql = "
    SELECT * FROM chats 
    WHERE (sender_id = ? AND receiver_id = ?) 
       OR (sender_id = ? AND receiver_id = ?)
    ORDER BY sent_at ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $current_user_id, $receiver_id, $receiver_id, $current_user_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}

echo json_encode([
    "success" => true, 
    "messages" => $messages
]);

$stmt->close();
$conn->close();
?>