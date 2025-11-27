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

if (empty($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$today = date("Y-m-d");

// ✅ Get employee_id
$stmt = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || !$row = $result->fetch_assoc()) {
    echo json_encode(["success" => false, "message" => "Employee profile not found"]);
    exit;
}

$employee_id = (int) $row['employee_id'];
$stmt->close();

// ✅ Get current status
$status_query = "
    SELECT 
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM employee_activity 
                WHERE employee_id = ? 
                AND activity_type = 'Check-In' 
                AND DATE(activity_time) = ?
                AND activity_id NOT IN (
                    SELECT activity_id FROM employee_activity 
                    WHERE employee_id = ? 
                    AND activity_type = 'Check-Out' 
                    AND DATE(activity_time) = ?
                )
            ) THEN 'Checked In'
            WHEN EXISTS (
                SELECT 1 FROM employee_activity 
                WHERE employee_id = ? 
                AND activity_type = 'Break-In' 
                AND DATE(activity_time) = ?
                AND activity_id NOT IN (
                    SELECT activity_id FROM employee_activity 
                    WHERE employee_id = ? 
                    AND activity_type = 'Break-Out' 
                    AND DATE(activity_time) = ?
                )
            ) THEN 'On Break'
            ELSE 'Not Checked In'
        END as current_status";

$status_stmt = $conn->prepare($status_query);
$status_stmt->bind_param("isisisis", 
    $employee_id, $today, 
    $employee_id, $today,
    $employee_id, $today,
    $employee_id, $today
);
$status_stmt->execute();
$status_result = $status_stmt->get_result();
$status_data = $status_result->fetch_assoc();

echo json_encode([
    "success" => true,
    "status" => $status_data['current_status'] ?? 'Not Checked In',
    "employee_id" => $employee_id
]);

$status_stmt->close();
$conn->close();
?>