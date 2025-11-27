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
    $current_date = date("Y-m-d");

    // Get employee_id
    $employee_query = "SELECT employee_id FROM employees WHERE user_id = ?";
    $employee_stmt = $conn->prepare($employee_query);
    $employee_stmt->bind_param("i", $user_id);
    $employee_stmt->execute();
    $employee_result = $employee_stmt->get_result();
    
    if (!$employee_result || !$employee_row = $employee_result->fetch_assoc()) {
        echo json_encode([
            "success" => true,
            "attendance_status" => "NOT CHECKED IN",
            "arrival_status" => "NOT CHECKED IN",
            "break_status" => "NOT ON BREAK",
            "last_activity_time" => null
        ]);
        exit;
    }
    
    $employee_id = (int) $employee_row['employee_id'];
    $employee_stmt->close();

    // Get today's activities to determine current status
    $activity_query = "SELECT activity_type, activity_time 
                      FROM employee_activity 
                      WHERE employee_id = ? 
                      AND DATE(activity_time) = ? 
                      ORDER BY activity_time DESC 
                      LIMIT 1";
    
    $activity_stmt = $conn->prepare($activity_query);
    $activity_stmt->bind_param("is", $employee_id, $current_date);
    $activity_stmt->execute();
    $activity_result = $activity_stmt->get_result();
    
    $attendance_status = "NOT CHECKED IN";
    $arrival_status = "NOT CHECKED IN";
    $break_status = "NOT ON BREAK";
    $last_activity_time = null;

    if ($activity_row = $activity_result->fetch_assoc()) {
        $last_activity_time = $activity_row['activity_time'];
        
        switch ($activity_row['activity_type']) {
            case 'CHECK-IN':
                $attendance_status = "CHECKED IN";
                // Check if late (after 9:00 AM)
                $check_in_time = strtotime($activity_row['activity_time']);
                $nine_am = strtotime(date('Y-m-d 09:00:00'));
                $arrival_status = ($check_in_time > $nine_am) ? "LATE" : "ON TIME";
                break;
            case 'CHECK-OUT':
                $attendance_status = "CHECKED OUT";
                $arrival_status = "CHECKED OUT";
                break;
            case 'BREAK START':
                $attendance_status = "CHECKED IN";
                $break_status = "ON BREAK";
                break;
            case 'BREAK END':
                $attendance_status = "CHECKED IN";
                $break_status = "NOT ON BREAK";
                break;
        }
    }
    
    echo json_encode([
        "success" => true,
        "attendance_status" => $attendance_status,
        "arrival_status" => $arrival_status,
        "break_status" => $break_status,
        "last_activity_time" => $last_activity_time
    ]);
    
    $activity_stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "❌ Status error: " . $e->getMessage(),
        "attendance_status" => "ERROR",
        "arrival_status" => "ERROR",
        "break_status" => "ERROR"
    ]);
}
?>