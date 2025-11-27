<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}


require_once "connect.php";

if (empty($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not authenticated"]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$employee_id = $input['employee_id'] ?? null;
$is_active = $input['is_active'] ?? false;

if (!$employee_id) {
    echo json_encode(["success" => false, "message" => "Employee ID required"]);
    exit;
}

try {
    // Update employee status - handle both string and numeric employee_ids
    if ($is_active) {
        $sql = "UPDATE employees SET is_active = 1, last_active_time = NOW() WHERE employee_id = ? OR user_id = ?";
    } else {
        $sql = "UPDATE employees SET is_active = 0 WHERE employee_id = ? OR user_id = ?";
    }
    
    $stmt = $conn->prepare($sql);
    
    // Try to extract user_id from employee_id if it's in "user_X" format
    $user_id_param = $employee_id;
    if (strpos($employee_id, 'user_') === 0) {
        $user_id_param = str_replace('user_', '', $employee_id);
    }
    
    $stmt->bind_param("ss", $employee_id, $user_id_param);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true, 
            "message" => "Employee status updated to " . ($is_active ? "ACTIVE" : "INACTIVE"),
            "rows_affected" => $stmt->affected_rows
        ]);
    } else {
        echo json_encode([
            "success" => false, 
            "message" => "No employee found with ID: " . $employee_id,
            "debug" => "Tried employee_id: $employee_id and user_id: $user_id_param"
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Employee status update error: " . $e->getMessage());
    echo json_encode([
        "success" => false, 
        "message" => "Database error: " . $e->getMessage()
    ]);
}

$conn->close();
?>