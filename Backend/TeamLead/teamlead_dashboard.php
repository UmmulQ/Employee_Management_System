<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once 'connect.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "User not logged in"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($action) {
        case 'get_profile':
            getProfile($conn, $user_id);
            break;
            
        case 'get_attendance_status':
            getAttendanceStatus($conn, $user_id);
            break;
            
        case 'checkin':
            checkIn($conn, $user_id, $input);
            break;
            
        case 'checkout':
            checkOut($conn, $user_id, $input);
            break;
            
        case 'break':
            handleBreak($conn, $user_id, $input);
            break;
            
        case 'get_tasks':
            getTasks($conn, $user_id);
            break;
            
        case 'get_manhours':
            getManHours($conn, $user_id);
            break;
            
        case 'get_recent_activities':
            getRecentActivities($conn, $user_id);
            break;
            
        case 'get_team_metrics':
            getTeamMetrics($conn, $user_id);
            break;
            
        default:
            echo json_encode(["success" => false, "message" => "Invalid action"]);
            break;
    }
} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}

$conn->close();

function getProfile($conn, $user_id) {
    $sql = "SELECT 
                u.user_id,
                u.username,
                u.role_id,
                p.first_name,
                p.last_name,
                p.email,
                p.phone,
                p.profile_picture_url,
                p.date_of_birth,
                p.address,
                e.employee_id,
                e.employee_number,
                e.department,
                e.position,
                e.salary,
                e.date_hired,
                e.manager_id,
                e.job_start_time,
                e.job_end_time,
                e.working_days,
                e.working_hours,
                e.is_active as employee_active
            FROM users u
            LEFT JOIN profiles p ON u.user_id = p.user_id
            LEFT JOIN employees e ON u.user_id = e.user_id
            WHERE u.user_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $profile = $result->fetch_assoc();
        echo json_encode(["success" => true, "profile" => $profile]);
    } else {
        echo json_encode(["success" => false, "message" => "Profile not found"]);
    }
    $stmt->close();
}

function getAttendanceStatus($conn, $user_id) {
    // Get employee ID first
    $employee_sql = "SELECT employee_id FROM employees WHERE user_id = ?";
    $stmt = $conn->prepare($employee_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $employee_result = $stmt->get_result();
    
    if ($employee_result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Employee not found"]);
        return;
    }
    
    $employee = $employee_result->fetch_assoc();
    $employee_id = $employee['employee_id'];
    $stmt->close();
    
    // Get today's check in activity
    $checkin_sql = "SELECT activity_type, activity_time, description 
                   FROM employee_activity 
                   WHERE employee_id = ? 
                   AND DATE(activity_time) = CURDATE() 
                   AND activity_type = 'Check In'
                   ORDER BY activity_time DESC 
                   LIMIT 1";
    $stmt = $conn->prepare($checkin_sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $checkin_result = $stmt->get_result();
    
    $attendance_status = "CHECKED OUT";
    $last_activity_time = null;
    
    if ($checkin_result->num_rows > 0) {
        $checkin = $checkin_result->fetch_assoc();
        $attendance_status = "CHECKED IN";
        $last_activity_time = $checkin['activity_time'];
    }
    $stmt->close();
    
    // Check break status
    $break_status = "NO BREAK";
    if ($attendance_status === "CHECKED IN") {
        $break_sql = "SELECT activity_type FROM employee_activity 
                     WHERE employee_id = ? 
                     AND DATE(activity_time) = CURDATE() 
                     AND activity_type IN ('Break Start', 'Break End')
                     ORDER BY activity_time DESC 
                     LIMIT 1";
        $break_stmt = $conn->prepare($break_sql);
        $break_stmt->bind_param("i", $employee_id);
        $break_stmt->execute();
        $break_result = $break_stmt->get_result();
        
        if ($break_result->num_rows > 0) {
            $break_activity = $break_result->fetch_assoc();
            $break_status = $break_activity['activity_type'] === 'Break Start' ? "ON BREAK" : "NO BREAK";
        }
        $break_stmt->close();
    }
    
    // Calculate today's work hours (excluding breaks)
    $hours_sql = "SELECT 
                    SUM(CASE WHEN activity_type NOT IN ('Check In', 'Check Out', 'Break Start', 'Break End') 
                    THEN duration_minutes ELSE 0 END) as work_minutes,
                    SUM(CASE WHEN activity_type IN ('Break Start', 'Break End') 
                    THEN duration_minutes ELSE 0 END) as break_minutes
                  FROM employee_activity 
                  WHERE employee_id = ? 
                  AND DATE(activity_time) = CURDATE()";
    $hours_stmt = $conn->prepare($hours_sql);
    $hours_stmt->bind_param("i", $employee_id);
    $hours_stmt->execute();
    $hours_result = $hours_stmt->get_result();
    
    $work_minutes = 0;
    $break_minutes = 0;
    if ($hours_result->num_rows > 0) {
        $hours_data = $hours_result->fetch_assoc();
        $work_minutes = $hours_data['work_minutes'] ?? 0;
        $break_minutes = $hours_data['break_minutes'] ?? 0;
    }
    $hours_stmt->close();
    
    $total_minutes = max(0, $work_minutes - $break_minutes);
    $total_hours = $total_minutes / 60;
    $regular_hours = min($total_hours, 8);
    $overtime_hours = max(0, $total_hours - 8);
    
    echo json_encode([
        "success" => true,
        "attendance_status" => $attendance_status,
        "break_status" => $break_status,
        "last_activity_time" => $last_activity_time,
        "overtime_hours" => round($overtime_hours, 2),
        "regular_hours" => round($regular_hours, 2),
        "total_minutes" => $total_minutes
    ]);
}

function checkIn($conn, $user_id, $input) {
    $employee_id = $input['employee_id'] ?? null;
    
    if (!$employee_id) {
        echo json_encode(["success" => false, "message" => "Employee ID required"]);
        return;
    }
    
    // Verify employee belongs to user
    $verify_sql = "SELECT e.employee_id FROM employees e 
                   WHERE e.employee_id = ? AND e.user_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $employee_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Invalid employee"]);
        $verify_stmt->close();
        return;
    }
    $verify_stmt->close();
    
    // Check if already checked in today
    $check_sql = "SELECT activity_id FROM employee_activity 
                  WHERE employee_id = ? 
                  AND DATE(activity_time) = CURDATE() 
                  AND activity_type = 'Check In'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $employee_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "Already checked in today"]);
        $check_stmt->close();
        return;
    }
    $check_stmt->close();
    
    // Record check in
    $insert_sql = "INSERT INTO employee_activity 
                  (employee_id, activity_type, description, activity_time, duration_minutes, log)
                  VALUES (?, 'Check In', 'Employee checked in for work', NOW(), 0, 
                  'System: Automatic check-in recorded via dashboard')";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("i", $employee_id);
    
    if ($insert_stmt->execute()) {
        // Update employee status
        $update_sql = "UPDATE employees SET is_active = 1 WHERE employee_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $employee_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        echo json_encode(["success" => true, "message" => "Checked in successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to check in"]);
    }
    $insert_stmt->close();
}

function checkOut($conn, $user_id, $input) {
    $employee_id = $input['employee_id'] ?? null;
    
    if (!$employee_id) {
        echo json_encode(["success" => false, "message" => "Employee ID required"]);
        return;
    }
    
    // Verify employee and check if checked in
    $verify_sql = "SELECT e.employee_id FROM employees e 
                   WHERE e.employee_id = ? AND e.user_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $employee_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Invalid employee"]);
        $verify_stmt->close();
        return;
    }
    $verify_stmt->close();
    
    // Check if checked in today
    $checkin_sql = "SELECT activity_time FROM employee_activity 
                   WHERE employee_id = ? 
                   AND DATE(activity_time) = CURDATE() 
                   AND activity_type = 'Check In'";
    $checkin_stmt = $conn->prepare($checkin_sql);
    $checkin_stmt->bind_param("i", $employee_id);
    $checkin_stmt->execute();
    $checkin_result = $checkin_stmt->get_result();
    
    if ($checkin_result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Not checked in today"]);
        $checkin_stmt->close();
        return;
    }
    
    $checkin_data = $checkin_result->fetch_assoc();
    $checkin_time = $checkin_data['activity_time'];
    $checkin_stmt->close();
    
    // Check if already checked out
    $checkout_sql = "SELECT activity_id FROM employee_activity 
                    WHERE employee_id = ? 
                    AND DATE(activity_time) = CURDATE() 
                    AND activity_type = 'Check Out'";
    $checkout_stmt = $conn->prepare($checkout_sql);
    $checkout_stmt->bind_param("i", $employee_id);
    $checkout_stmt->execute();
    $checkout_result = $checkout_stmt->get_result();
    
    if ($checkout_result->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "Already checked out today"]);
        $checkout_stmt->close();
        return;
    }
    $checkout_stmt->close();
    
    // Calculate total work duration for the day
    $duration_sql = "SELECT SUM(duration_minutes) as total_minutes 
                    FROM employee_activity 
                    WHERE employee_id = ? 
                    AND DATE(activity_time) = CURDATE() 
                    AND activity_type NOT IN ('Check In', 'Check Out')";
    $duration_stmt = $conn->prepare($duration_sql);
    $duration_stmt->bind_param("i", $employee_id);
    $duration_stmt->execute();
    $duration_result = $duration_stmt->get_result();
    $duration_data = $duration_result->fetch_assoc();
    $total_minutes = $duration_data['total_minutes'] ?? 0;
    $duration_stmt->close();
    
    // Record check out
    $insert_sql = "INSERT INTO employee_activity 
                  (employee_id, activity_type, description, activity_time, duration_minutes, log)
                  VALUES (?, 'Check Out', 'Employee checked out', NOW(), ?, 
                  'System: Automatic check-out recorded. Total work time: ? minutes')";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("iii", $employee_id, $total_minutes, $total_minutes);
    
    if ($insert_stmt->execute()) {
        // Update employee status
        $update_sql = "UPDATE employees SET is_active = 0 WHERE employee_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $employee_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        echo json_encode([
            "success" => true, 
            "message" => "Checked out successfully",
            "total_minutes" => $total_minutes,
            "total_hours" => round($total_minutes / 60, 2)
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to check out"]);
    }
    $insert_stmt->close();
}

function handleBreak($conn, $user_id, $input) {
    $action = $input['action'] ?? '';
    $employee_id = $input['employee_id'] ?? null;
    
    if (!in_array($action, ['start', 'end']) || !$employee_id) {
        echo json_encode(["success" => false, "message" => "Invalid break action or employee ID"]);
        return;
    }
    
    // Verify employee belongs to user and is checked in
    $verify_sql = "SELECT e.employee_id FROM employees e 
                   WHERE e.employee_id = ? AND e.user_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $employee_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Invalid employee"]);
        $verify_stmt->close();
        return;
    }
    $verify_stmt->close();
    
    // Check if checked in today
    $checkin_sql = "SELECT activity_id FROM employee_activity 
                   WHERE employee_id = ? 
                   AND DATE(activity_time) = CURDATE() 
                   AND activity_type = 'Check In'";
    $checkin_stmt = $conn->prepare($checkin_sql);
    $checkin_stmt->bind_param("i", $employee_id);
    $checkin_stmt->execute();
    $checkin_result = $checkin_stmt->get_result();
    
    if ($checkin_result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Not checked in today"]);
        $checkin_stmt->close();
        return;
    }
    $checkin_stmt->close();
    
    $activity_type = $action === 'start' ? 'Break Start' : 'Break End';
    $description = $action === 'start' ? 'Employee started break' : 'Employee ended break';
    
    // For break end, calculate duration since last break start
    $duration_minutes = 0;
    if ($action === 'end') {
        $break_start_sql = "SELECT activity_time FROM employee_activity 
                           WHERE employee_id = ? 
                           AND DATE(activity_time) = CURDATE() 
                           AND activity_type = 'Break Start'
                           ORDER BY activity_time DESC 
                           LIMIT 1";
        $break_start_stmt = $conn->prepare($break_start_sql);
        $break_start_stmt->bind_param("i", $employee_id);
        $break_start_stmt->execute();
        $break_start_result = $break_start_stmt->get_result();
        
        if ($break_start_result->num_rows > 0) {
            $break_start = $break_start_result->fetch_assoc();
            $start_time = new DateTime($break_start['activity_time']);
            $end_time = new DateTime();
            $interval = $start_time->diff($end_time);
            $duration_minutes = ($interval->h * 60) + $interval->i;
        }
        $break_start_stmt->close();
    }
    
    // Record break activity
    $insert_sql = "INSERT INTO employee_activity 
                  (employee_id, activity_type, description, activity_time, duration_minutes, log)
                  VALUES (?, ?, ?, NOW(), ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $log = $action === 'start' ? 'System: Break started' : "System: Break ended after $duration_minutes minutes";
    $insert_stmt->bind_param("issis", $employee_id, $activity_type, $description, $duration_minutes, $log);
    
    if ($insert_stmt->execute()) {
        $message = $action === 'start' ? "Break started successfully" : "Break ended successfully";
        echo json_encode([
            "success" => true, 
            "message" => $message,
            "duration_minutes" => $duration_minutes
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to record break activity"]);
    }
    $insert_stmt->close();
}

function getTasks($conn, $user_id) {
    $filters = [
        'assigned_to' => $_GET['assigned_to'] ?? null,
        'period' => $_GET['period'] ?? 'all',
        'status' => $_GET['status'] ?? null
    ];
    
    $sql = "SELECT 
                t.task_id,
                t.task_title,
                t.description,
                t.project_id,
                t.assigned_to,
                t.assigned_by,
                t.priority,
                t.status,
                t.created_at,
                t.updated_at,
                t.due_date,
                t.estimated_hours,
                p.project_name,
                CONCAT(up.first_name, ' ', up.last_name) as assigned_by_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.project_id
            LEFT JOIN profiles up ON t.assigned_by = up.user_id
            WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if ($filters['assigned_to']) {
        $sql .= " AND t.assigned_to = ?";
        $params[] = $filters['assigned_to'];
        $types .= "i";
    }
    
    if ($filters['period'] === 'today') {
        $sql .= " AND DATE(t.due_date) = CURDATE()";
    } elseif ($filters['period'] === 'week') {
        $sql .= " AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    }
    
    if ($filters['status']) {
        $sql .= " AND t.status = ?";
        $params[] = $filters['status'];
        $types .= "s";
    }
    
    $sql .= " ORDER BY 
              CASE t.priority 
                WHEN 'high' THEN 1
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 3
                ELSE 4
              END,
              t.due_date ASC";
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate if task is overdue
        $is_overdue = $row['due_date'] && 
                     new DateTime($row['due_date']) < new DateTime() && 
                     $row['status'] !== 'completed';
        
        $row['is_overdue'] = $is_overdue;
        $tasks[] = $row;
    }
    
    echo json_encode(["success" => true, "tasks" => $tasks]);
    $stmt->close();
}

function getManHours($conn, $user_id) {
    $period = $_GET['period'] ?? 'month';
    $employee_id = $_GET['employee_id'] ?? null;
    
    // If no employee_id provided, use the logged-in user's employee ID
    if (!$employee_id) {
        $emp_sql = "SELECT employee_id FROM employees WHERE user_id = ?";
        $emp_stmt = $conn->prepare($emp_sql);
        $emp_stmt->bind_param("i", $user_id);
        $emp_stmt->execute();
        $emp_result = $emp_stmt->get_result();
        
        if ($emp_result->num_rows > 0) {
            $employee = $emp_result->fetch_assoc();
            $employee_id = $employee['employee_id'];
        } else {
            echo json_encode(["success" => false, "message" => "Employee not found"]);
            $emp_stmt->close();
            return;
        }
        $emp_stmt->close();
    }
    
    $sql = "SELECT 
                activity_id,
                employee_id,
                activity_type,
                description,
                activity_time,
                duration_minutes,
                log
            FROM employee_activity 
            WHERE employee_id = ?";
    
    $params = [$employee_id];
    $types = "i";
    
    if ($period === 'today') {
        $sql .= " AND DATE(activity_time) = CURDATE()";
    } elseif ($period === 'week') {
        $sql .= " AND activity_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $sql .= " AND activity_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    $sql .= " ORDER BY activity_time ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $activities = [];
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    
    echo json_encode(["success" => true, "activities" => $activities]);
    $stmt->close();
}

function getRecentActivities($conn, $user_id) {
    $employee_id = $_GET['employee_id'] ?? null;
    $limit = $_GET['limit'] ?? 5;
    
    if (!$employee_id) {
        // Get employee ID from user_id
        $emp_sql = "SELECT employee_id FROM employees WHERE user_id = ?";
        $emp_stmt = $conn->prepare($emp_sql);
        $emp_stmt->bind_param("i", $user_id);
        $emp_stmt->execute();
        $emp_result = $emp_stmt->get_result();
        
        if ($emp_result->num_rows > 0) {
            $employee = $emp_result->fetch_assoc();
            $employee_id = $employee['employee_id'];
        } else {
            echo json_encode(["success" => false, "message" => "Employee not found"]);
            $emp_stmt->close();
            return;
        }
        $emp_stmt->close();
    }
    
    $sql = "SELECT 
                activity_id,
                employee_id,
                activity_type,
                description,
                activity_time,
                duration_minutes,
                log
            FROM employee_activity 
            WHERE employee_id = ?
            ORDER BY activity_time DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $employee_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $activities = [];
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    
    echo json_encode(["success" => true, "activities" => $activities]);
    $stmt->close();
}

function getTeamMetrics($conn, $user_id) {
    // Get team lead's department
    $dept_sql = "SELECT department FROM employees WHERE user_id = ?";
    $dept_stmt = $conn->prepare($dept_sql);
    $dept_stmt->bind_param("i", $user_id);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    
    if ($dept_result->num_rows === 0) {
        echo json_encode(["success" => false, "message" => "Department not found"]);
        return;
    }
    
    $dept_data = $dept_result->fetch_assoc();
    $department = $dept_data['department'];
    $dept_stmt->close();
    
    // Get team metrics
    $metrics_sql = "SELECT 
                    COUNT(*) as total_employees,
                    SUM(CASE WHEN e.is_active = 1 THEN 1 ELSE 0 END) as active_employees,
                    (SELECT COUNT(DISTINCT ea.employee_id) 
                     FROM employee_activity ea
                     JOIN employees e2 ON ea.employee_id = e2.employee_id
                     WHERE e2.department = ? 
                     AND DATE(ea.activity_time) = CURDATE()
                     AND ea.activity_type = 'Check In') as checked_in_today
                    FROM employees e
                    WHERE e.department = ?";
    
    $metrics_stmt = $conn->prepare($metrics_sql);
    $metrics_stmt->bind_param("ss", $department, $department);
    $metrics_stmt->execute();
    $metrics_result = $metrics_stmt->get_result();
    
    $metrics = $metrics_result->fetch_assoc();
    $metrics_stmt->close();
    
    echo json_encode([
        "success" => true,
        "metrics" => [
            "totalEmployees" => $metrics['total_employees'],
            "activeToday" => $metrics['checked_in_today'],
            "onLeave" => $metrics['total_employees'] - $metrics['active_employees'],
            "productivity" => $metrics['checked_in_today'] > 0 ? 
                round(($metrics['checked_in_today'] / $metrics['total_employees']) * 100, 1) : 0
        ]
    ]);
}
?>