<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "connect.php";

try {
    // Check if user is logged in
    if (empty($_SESSION['user_id'])) {
        throw new Exception("Please login first to view attendance report.");
    }

    $user_id = (int)$_SESSION['user_id'];
    $filter = $_GET['filter'] ?? 'all';

    // Get employee_id from employees table
    $employee_query = "SELECT employee_id FROM employees WHERE user_id = ?";
    $employee_stmt = $conn->prepare($employee_query);
    
    if (!$employee_stmt) {
        throw new Exception("Database connection error.");
    }
    
    $employee_stmt->bind_param("i", $user_id);
    $employee_stmt->execute();
    $employee_result = $employee_stmt->get_result();
    $employee_row = $employee_result->fetch_assoc();
    
    if (!$employee_row) {
        throw new Exception("Employee profile not found.");
    }
    
    $employee_id = (int)$employee_row['employee_id'];
    $employee_stmt->close();

    // Build date filter
    $date_condition = "";
    switch ($filter) {
        case 'today':
            $date_condition = "AND DATE(activity_time) = CURDATE()";
            break;
        case 'week':
            $date_condition = "AND activity_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_condition = "AND activity_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $date_condition = "AND YEAR(activity_time) = YEAR(CURDATE())";
            break;
        case 'all':
        default:
            $date_condition = "";
            break;
    }

    // Get attendance data - FIXED: Removed extra comma
    $attendance_query = "SELECT 
                        activity_id,
                        employee_id,
                        activity_type,
                        description,
                        activity_time,
                        duration_minutes,
                        log
                        FROM employee_activity 
                        WHERE employee_id = ? 
                        $date_condition
                        ORDER BY activity_time DESC";

    $attendance_stmt = $conn->prepare($attendance_query);
    $attendance_stmt->bind_param("i", $employee_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
    
    $attendance = [];
    while ($row = $attendance_result->fetch_assoc()) {
        $attendance[] = $row;
    }

    // Send success response
    echo json_encode([
        "success" => true,
        "attendance" => $attendance,
        "count" => count($attendance),
        "filter" => $filter,
        "employee_id" => $employee_id,
        "message" => count($attendance) . " records found"
    ]);
    
    $attendance_stmt->close();
    $conn->close();

} catch (Exception $e) {
    // Send error response
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
        "attendance" => [],
        "count" => 0
    ]);
}
?>