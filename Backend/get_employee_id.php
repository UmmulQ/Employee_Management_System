<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}


require_once "connect.php";

if (empty($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    // Try to get employee_id from employees table
    $sql = "SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $_SESSION['employee_id'] = $row['employee_id'];
        echo json_encode([
            "success" => true, 
            "employee_id" => $row['employee_id'],
            "source" => "database"
        ]);
    } else {
        // If no employee record, use user_id as employee_id
        $employee_id = (string) $user_id;
        $_SESSION['employee_id'] = $employee_id;
        echo json_encode([
            "success" => true, 
            "employee_id" => $employee_id,
            "source" => "fallback_user_id"
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    // Fallback: use user_id as employee_id
    $employee_id = (string) $user_id;
    $_SESSION['employee_id'] = $employee_id;
    echo json_encode([
        "success" => true, 
        "employee_id" => $employee_id,
        "source" => "error_fallback",
        "message" => $e->getMessage()
    ]);
}

$conn->close();
?>