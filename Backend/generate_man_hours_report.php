<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

require_once "connect.php";

if (empty($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$period = $_GET['period'] ?? 'week';

try {
    // Get employee details from profiles table
    $query = "SELECT profile_id, first_name, last_name FROM profiles WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || !$row = $result->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "Employee profile not found"]);
        exit;
    }
    
    $employee_id = (int) $row['profile_id'];
    $employee_name = $row['first_name'] . ' ' . $row['last_name'];
    $position = "Employee"; // Default position or get from another table if available
    $stmt->close();

    // Calculate date range
    $end_date = date('Y-m-d');
    switch ($period) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('-7 days'));
            $period_label = 'Weekly';
            break;
        case 'month':
            $start_date = date('Y-m-d', strtotime('-30 days'));
            $period_label = 'Monthly';
            break;
        case 'quarter':
            $start_date = date('Y-m-d', strtotime('-90 days'));
            $period_label = 'Quarterly';
            break;
        default:
            $start_date = date('Y-m-d', strtotime('-7 days'));
            $period_label = 'Weekly';
    }

    // Generate comprehensive report
    $report = generateComprehensiveReport($conn, $employee_id, $employee_name, $position, $start_date, $end_date, $period_label);
    
    echo json_encode([
        "success" => true,
        "report" => $report
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "❌ Error: " . $e->getMessage()
    ]);
}

function generateComprehensiveReport($conn, $employee_id, $employee_name, $position, $start_date, $end_date, $period_label) {
    // Get detailed activities
    $activities_query = "SELECT DATE(activity_time) as activity_date, activity_type, activity_time 
                        FROM employee_activity 
                        WHERE employee_id = ? AND DATE(activity_time) BETWEEN ? AND ?
                        ORDER BY activity_time";
    $stmt = $conn->prepare($activities_query);
    $stmt->bind_param("iss", $employee_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $activities_by_date = [];
    while ($row = $result->fetch_assoc()) {
        $date = $row['activity_date'];
        if (!isset($activities_by_date[$date])) {
            $activities_by_date[$date] = [];
        }
        $activities_by_date[$date][] = $row;
    }
    $stmt->close();
    
    $daily_breakdown = [];
    $total_working_hours = 0;
    $total_man_hours = 0;
    $total_productivity = 0;
    $days_count = 0;
    
    foreach ($activities_by_date as $date => $activities) {
        $working_seconds = 0;
        $break_seconds = 0;
        $check_in_time = null;
        $break_start_time = null;
        
        foreach ($activities as $activity) {
            $activity_type = $activity['activity_type'];
            $activity_time = new DateTime($activity['activity_time']);
            
            switch ($activity_type) {
                case 'CHECK-IN':
                    $check_in_time = $activity_time;
                    break;
                case 'CHECK-OUT':
                    if ($check_in_time) {
                        $working_seconds += $activity_time->getTimestamp() - $check_in_time->getTimestamp();
                        $check_in_time = null;
                    }
                    break;
                case 'BREAK START':
                    $break_start_time = $activity_time;
                    break;
                case 'BREAK END':
                    if ($break_start_time) {
                        $break_seconds += $activity_time->getTimestamp() - $break_start_time->getTimestamp();
                        $break_start_time = null;
                    }
                    break;
            }
        }
        
        $working_hours = ($working_seconds - $break_seconds) / 3600;
        $productivity = calculateDailyProductivity($working_hours, $break_seconds / 3600);
        $man_hours = $working_hours * ($productivity / 100);
        
        $daily_breakdown[] = [
            'date' => $date,
            'working_hours' => round(max(0, $working_hours), 2),
            'break_time' => round($break_seconds / 3600, 2),
            'man_hours' => round($man_hours, 2),
            'productivity' => round($productivity, 2),
            'utilization' => round(calculateUtilizationRate($working_hours), 2)
        ];
        
        $total_working_hours += $working_hours;
        $total_man_hours += $man_hours;
        $total_productivity += $productivity;
        $days_count++;
    }
    
    $avg_productivity = $days_count > 0 ? $total_productivity / $days_count : 0;
    $utilization_rate = calculateOverallUtilization($total_working_hours, $days_count);
    
    return [
        'employee_name' => $employee_name,
        'position' => $position,
        'period' => $period_label,
        'report_date' => date('Y-m-d'),
        'date_range' => $start_date . ' to ' . $end_date,
        'total_working_hours' => round($total_working_hours, 2),
        'total_man_hours' => round($total_man_hours, 2),
        'avg_productivity' => round($avg_productivity, 2),
        'utilization_rate' => round($utilization_rate, 2),
        'total_overtime' => round(max(0, $total_working_hours - ($days_count * 8)), 2),
        'daily_breakdown' => $daily_breakdown
    ];
}

function calculateDailyProductivity($working_hours, $break_hours) {
    // Simple productivity calculation - you can modify this based on your business logic
    $base_productivity = 85; // Base productivity percentage
    $break_penalty = max(0, ($break_hours - 1) * 10); // Penalty for breaks longer than 1 hour
    $overtime_bonus = max(0, ($working_hours - 8) * 2); // Bonus for overtime
    
    return min(100, max(60, $base_productivity - $break_penalty + $overtime_bonus));
}

function calculateUtilizationRate($working_hours) {
    // Utilization rate based on 8-hour workday
    return min(100, ($working_hours / 8) * 100);
}

function calculateOverallUtilization($total_hours, $days) {
    $scheduled_hours = $days * 8; // 8 hours per day
    return $scheduled_hours > 0 ? min(100, ($total_hours / $scheduled_hours) * 100) : 0;
}
?>