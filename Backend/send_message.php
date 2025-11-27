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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$receiver_id = $input['receiver_id'] ?? '';
$message = $input['message'] ?? '';

if (empty($receiver_id) || empty($message)) {
    echo json_encode(["success" => false, "message" => "Receiver ID and message are required"]);
    exit;
}

// Insert message into database
$sql = "INSERT INTO chats (sender_id, receiver_id, message, sent_at, is_read) VALUES (?, ?, ?, NOW(), 0)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iis", $current_user_id, $receiver_id, $message);

if ($stmt->execute()) {
    $chat_id = $stmt->insert_id;
    
    // Fetch the saved message
    $fetch_sql = "SELECT * FROM chats WHERE chat_id = ?";
    $fetch_stmt = $conn->prepare($fetch_sql);
    $fetch_stmt->bind_param("i", $chat_id);
    $fetch_stmt->execute();
    $result = $fetch_stmt->get_result();
    $message_data = $result->fetch_assoc();
    
    echo json_encode([
        "success" => true, 
        "message" => "Message sent successfully",
        "message_data" => $message_data
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to send message"]);
}

$stmt->close();
$conn->close();
?>