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
$period = $_GET['period'] ?? 'week';

try {
    // Get employee details
    $query = "SELECT employee_id FROM employees WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || !$row = $result->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "Employee profile not found"]);
        exit;
    }
    
    $employee_id = (int) $row['employee_id'];
    $stmt->close();

    // Calculate date range
    $end_date = date('Y-m-d');
    switch ($period) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'month':
            $start_date = date('Y-m-d', strtotime('-30 days'));
            break;
        case 'quarter':
            $start_date = date('Y-m-d', strtotime('-90 days'));
            break;
        default:
            $start_date = date('Y-m-d', strtotime('-7 days'));
    }

    // Get daily breakdown
    $daily_data = [];
    $current_date = $start_date;
    $total_working_hours = 0;
    $total_break_time = 0;
    $total_overtime = 0;
    $total_man_hours = 0;
    $days_count = 0;

    while ($current_date <= $end_date) {
        // Calculate hours for each day
        $activities_query = "SELECT activity_type, activity_time 
                            FROM employee_activity 
                            WHERE employee_id = ? AND DATE(activity_time) = ?
                            ORDER BY activity_time";
        $activities_stmt = $conn->prepare($activities_query);
        $activities_stmt->bind_param("is", $employee_id, $current_date);
        $activities_stmt->execute();
        $activities_result = $activities_stmt->get_result();
        
        $activities = [];
        while ($row = $activities_result->fetch_assoc()) {
            $activities[] = $row;
        }
        $activities_stmt->close();

        // Calculate daily metrics
        $working_seconds = 0;
        $break_seconds = 0;
        $check_in_time = null;
        $break_start_time = null;

        foreach ($activities as $activity) {
            $activity_type = $activity['activity_type'];
            $activity_time = strtotime($activity['activity_time']);
            
            switch ($activity_type) {
                case 'CHECK-IN':
                    $check_in_time = $activity_time;
                    break;
                case 'CHECK-OUT':
                    if ($check_in_time) {
                        $session_duration = $activity_time - $check_in_time;
                        $working_seconds += $session_duration;
                        $check_in_time = null;
                    }
                    break;
                case 'BREAK START':
                    $break_start_time = $activity_time;
                    break;
                case 'BREAK END':
                    if ($break_start_time) {
                        $break_duration = $activity_time - $break_start_time;
                        $break_seconds += $break_duration;
                        $break_start_time = null;
                    }
                    break;
            }
        }

        // Handle active session
        if ($check_in_time) {
            $current_time = time();
            $active_session_duration = $current_time - $check_in_time;
            $working_seconds += $active_session_duration;
        }

        $net_working_seconds = $working_seconds - $break_seconds;
        $working_hours = round(max(0, $net_working_seconds) / 3600, 2);
        $break_hours = round($break_seconds / 3600, 2);
        
        // Calculate overtime (assuming standard 8-hour workday)
        $standard_hours = 8;
        $overtime_hours = max(0, $working_hours - $standard_hours);
        
        // Man hours equals working hours (simplified)
        $man_hours = $working_hours;

        // Get tasks data for the day
        $tasks_query = "SELECT COUNT(*) as total_tasks, 
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
                        FROM tasks 
                        WHERE assigned_to = ? AND DATE(created_at) = ?";
        $tasks_stmt = $conn->prepare($tasks_query);
        $tasks_stmt->bind_param("is", $employee_id, $current_date);
        $tasks_stmt->execute();
        $tasks_result = $tasks_stmt->get_result();
        $tasks_data = $tasks_result->fetch_assoc();
        $tasks_stmt->close();

        $total_tasks = $tasks_data['total_tasks'] ?? 0;
        $completed_tasks = $tasks_data['completed_tasks'] ?? 0;

        if ($working_hours > 0 || $total_tasks > 0) {
            $daily_data[] = [
                'date' => $current_date,
                'working_hours' => $working_hours,
                'break_time' => $break_hours,
                'overtime' => $overtime_hours,
                'man_hours' => $man_hours,
                'tasks_completed' => (int)$completed_tasks,
                'total_tasks' => (int)$total_tasks
            ];

            $total_working_hours += $working_hours;
            $total_break_time += $break_hours;
            $total_overtime += $overtime_hours;
            $total_man_hours += $man_hours;
            $days_count++;
        }

        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
    }

    // Calculate averages
    $avg_break_time = $days_count > 0 ? round($total_break_time / $days_count, 1) : 0;
    $avg_overtime = $days_count > 0 ? round($total_overtime / $days_count, 1) : 0;

    $report = [
        'period' => $period,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_working_hours' => round($total_working_hours, 1),
        'total_break_time' => round($total_break_time, 1),
        'total_overtime' => round($total_overtime, 1),
        'total_man_hours' => round($total_man_hours, 1),
        'avg_break_time' => $avg_break_time,
        'avg_overtime' => $avg_overtime,
        'days_analyzed' => $days_count,
        'daily_breakdown' => $daily_data
    ];

    echo json_encode([
        "success" => true,
        "report" => $report
    ]);
    
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "❌ Error generating report: " . $e->getMessage()
    ]);
}
?>