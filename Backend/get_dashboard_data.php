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

if (empty($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    // Get employee_id
    $query = "SELECT employee_id FROM employees WHERE user_id = ?";
    $stmt = $conn->prepare($stmt);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || !$row = $result->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "Employee profile not found"]);
        exit;
    }
    
    $employee_id = (int) $row['employee_id'];
    $stmt->close();

    // Get current status (from get_attendance_status.php logic)
    $current_date = date("Y-m-d");
    $status_query = "
        SELECT activity_type, activity_time 
        FROM employee_activity 
        WHERE employee_id = ? AND DATE(activity_time) = ?
        ORDER BY activity_time DESC 
        LIMIT 1
    ";
    $status_stmt = $conn->prepare($status_query);
    $status_stmt->bind_param("is", $employee_id, $current_date);
    $status_stmt->execute();
    $status_result = $status_stmt->get_result();

    $attendance_status = "NOT CHECKED IN";
    $break_status = "NOT ON BREAK";
    $last_activity_time = null;
    $is_late = false;
    $arrival_status = "NOT CHECKED IN";

    if ($status_result->num_rows > 0) {
        $activity = $status_result->fetch_assoc();
        $last_activity_time = $activity['activity_time'];
        
        $activity_type = strtoupper(trim($activity['activity_type']));
        
        if ($activity_type === 'CHECK-IN' || $activity_type === 'CHECK_IN') {
            $attendance_status = "CHECKED IN";
            
            $check_in_time = strtotime($last_activity_time);
            $late_threshold = strtotime(date('Y-m-d 09:00:00'));
            $is_late = $check_in_time > $late_threshold;
            
            $arrival_status = $is_late ? "LATE" : "ON TIME";
            
        } elseif ($activity_type === 'CHECK-OUT' || $activity_type === 'CHECK_OUT') {
            $attendance_status = "NOT CHECKED IN";
            $arrival_status = "CHECKED OUT";
        }
        
        if ($activity_type === 'BREAK START' || $activity_type === 'BREAK_IN') {
            $break_status = "ON BREAK";
        } elseif ($activity_type === 'BREAK END' || $activity_type === 'BREAK_OUT') {
            $break_status = "NOT ON BREAK";
        }
    }

    // Get attendance history for the month
    $attendance_query = "
        SELECT 
            activity_id as id,
            activity_type as type,
            activity_time as time,
            description,
            duration_minutes,
            screenshot_url
        FROM employee_activity 
        WHERE employee_id = ? AND activity_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY activity_time DESC
    ";
    
    $attendance_stmt = $conn->prepare($attendance_query);
    $attendance_stmt->bind_param("i", $employee_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();

    $attendance = [];
    while ($row = $attendance_result->fetch_assoc()) {
        $attendance[] = $row;
    }

    echo json_encode([
        "success" => true,
        "current_status" => [
            "attendance_status" => $attendance_status,
            "break_status" => $break_status,
            "arrival_status" => $arrival_status,
            "last_activity_time" => $last_activity_time,
            "is_late" => $is_late
        ],
        "attendance_history" => $attendance,
        "count" => count($attendance)
    ]);

    $status_stmt->close();
    $attendance_stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ]);
}
?>