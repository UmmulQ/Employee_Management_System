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

try {
    if (empty($_SESSION['user_id'])) {
        throw new Exception("Not logged in");
    }

    $user_id = (int) $_SESSION['user_id'];
    $filter = $_GET['filter'] ?? 'today';

    // Get employee_id
    $employee_query = "SELECT employee_id FROM employees WHERE user_id = ?";
    $employee_stmt = $conn->prepare($employee_query);
    
    if (!$employee_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $employee_stmt->bind_param("i", $user_id);
    $employee_stmt->execute();
    $employee_result = $employee_stmt->get_result();
    
    if (!$employee_result || !$employee_row = $employee_result->fetch_assoc()) {
        echo json_encode([
            "success" => true,
            "attendance" => [],
            "message" => "No employee profile found"
        ]);
        exit;
    }
    
    $employee_id = (int) $employee_row['employee_id'];
    $employee_stmt->close();

    // Build date filter
    $date_condition = "";
    switch ($filter) {
        case 'week':
            $date_condition = "AND activity_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_condition = "AND activity_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'today':
        default:
            $date_condition = "AND DATE(activity_time) = CURDATE()";
            break;
    }

    // Get attendance data from employee_activity table
    $attendance_query = "SELECT 
                        activity_id,
                        employee_id,
                        activity_type as type,
                        description,
                        activity_time as time,
                        duration_minutes,
                        screenshot_url
                        FROM employee_activity 
                        WHERE employee_id = ? 
                        $date_condition
                        ORDER BY activity_time DESC";
    
    $attendance_stmt = $conn->prepare($attendance_query);
    
    if (!$attendance_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $attendance_stmt->bind_param("i", $employee_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
    
    $attendance = [];
    while ($row = $attendance_result->fetch_assoc()) {
        $attendance[] = $row;
    }
    
    echo json_encode([
        "success" => true,
        "attendance" => $attendance,
        "count" => count($attendance),
        "filter" => $filter
    ]);
    
    $attendance_stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "❌ Attendance error: " . $e->getMessage(),
        "attendance" => []
    ]);
}
?>