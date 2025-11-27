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


$employee_id = $_GET['employee_id'] ?? null;

if (!$employee_id) {
    echo json_encode(["success" => false, "message" => "Employee ID required"]);
    exit;
}

try {
    $sql = "SELECT job_start_time, job_end_time, working_days FROM employees WHERE employee_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            "success" => true,
            "job_start_time" => $row['job_start_time'],
            "job_end_time" => $row['job_end_time'],
            "working_days" => $row['working_days']
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Employee not found"
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}

$conn->close();
?>