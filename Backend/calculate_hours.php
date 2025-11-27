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
$date = $_GET['date'] ?? date('Y-m-d');

try {
    // Get employee_id
    $query = "SELECT employee_id, job_start_time, job_end_time FROM employees WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || !$row = $result->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "Employee profile not found"]);
        exit;
    }
    
    $employee_id = (int) $row['employee_id'];
    $job_end_time = $row['job_end_time'] ?? '18:00:00';
    $stmt->close();

    // SIMPLE SQL-BASED CALCULATION
    $calculation_query = "
        SELECT 
            -- Calculate total working seconds (CHECK-IN to CHECK-OUT)
            COALESCE(SUM(
                CASE 
                    WHEN a1.activity_type = 'CHECK-IN' AND a2.activity_type = 'CHECK-OUT' 
                    THEN TIMESTAMPDIFF(SECOND, a1.activity_time, a2.activity_time)
                    ELSE 0 
                END
            ), 0) as total_working_seconds,
            
            -- Calculate total break seconds (BREAK START to BREAK END)  
            COALESCE(SUM(
                CASE 
                    WHEN a1.activity_type = 'BREAK START' AND a2.activity_type = 'BREAK END'
                    THEN TIMESTAMPDIFF(SECOND, a1.activity_time, a2.activity_time)
                    ELSE 0 
                END
            ), 0) as total_break_seconds,
            
            -- Calculate overtime seconds
            COALESCE(SUM(
                CASE 
                    WHEN a1.activity_type = 'CHECK-IN' AND a2.activity_type = 'CHECK-OUT' 
                    THEN GREATEST(0, TIMESTAMPDIFF(SECOND, 
                          CONCAT(DATE(a1.activity_time), ' $job_end_time'),
                          a2.activity_time))
                    ELSE 0 
                END
            ), 0) as total_overtime_seconds
            
        FROM employee_activity a1
        LEFT JOIN employee_activity a2 ON (
            a1.employee_id = a2.employee_id 
            AND DATE(a1.activity_time) = DATE(a2.activity_time)
            AND a2.activity_time > a1.activity_time
            AND (
                (a1.activity_type = 'CHECK-IN' AND a2.activity_type = 'CHECK-OUT') OR
                (a1.activity_type = 'BREAK START' AND a2.activity_type = 'BREAK END')
            )
        )
        WHERE a1.employee_id = ? 
        AND DATE(a1.activity_time) = ?
        AND a2.activity_id IS NOT NULL
    ";
    
    $calc_stmt = $conn->prepare($calculation_query);
    $calc_stmt->bind_param("is", $employee_id, $date);
    $calc_stmt->execute();
    $calc_result = $calc_stmt->get_result();
    $calc_data = $calc_result->fetch_assoc();
    $calc_stmt->close();

    // Convert seconds to hours
    $working_hours = ($calc_data['total_working_seconds'] - $calc_data['total_break_seconds']) / 3600;
    $break_hours = $calc_data['total_break_seconds'] / 3600;
    $overtime_hours = $calc_data['total_overtime_seconds'] / 3600;

    echo json_encode([
        "success" => true,
        "date" => $date,
        "working_hours" => round(max(0, $working_hours), 2),
        "break_time" => round($break_hours, 2),
        "overtime" => round($overtime_hours, 2),
        "debug" => [
            "working_seconds" => $calc_data['total_working_seconds'],
            "break_seconds" => $calc_data['total_break_seconds'],
            "overtime_seconds" => $calc_data['total_overtime_seconds']
        ]
    ]);
    
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "❌ Error: " . $e->getMessage()
    ]);
}
?>