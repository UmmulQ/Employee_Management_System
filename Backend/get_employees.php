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

$sql = "
    SELECT 
        u.user_id,
        p.first_name,
        p.last_name,
        p.email
    FROM users u
    LEFT JOIN profiles p ON u.user_id = p.user_id
    WHERE u.user_id != ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();

$employees = [];
while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

if (count($employees) > 0) {
    echo json_encode(["success" => true, "employees" => $employees]);
} else {
    echo json_encode(["success" => false, "message" => "No employees found"]);
}

$stmt->close();
$conn->close();
 