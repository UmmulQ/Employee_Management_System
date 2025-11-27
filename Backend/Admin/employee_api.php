<?php
// employee_api.php - Complete Employee Management API
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$db   = "u950794707_ems";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    sendResponse(false, null, "Database connection failed: " . $conn->connect_error);
}

// Helper function
function sendResponse($success, $data = null, $message = '') {
    $response = ['success' => $success];
    if ($data !== null) $response['data'] = $data;
    if ($message) $response['message'] = $message;
    echo json_encode($response);
    exit;
}

// Get action from request
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_employees':
        getEmployees();
        break;
    case 'get_employee_details':
        getEmployeeDetails();
        break;
    case 'get_attendance':
        getAttendance();
        break;
    case 'get_projects':
        getProjects();
        break;
    case 'get_tasks':
        getTasks();
        break;
    case 'get_leaves':
        getLeaves();
        break;
    case 'get_daily_summary':
        getDailySummary();
        break;
    case 'get_dashboard_stats':
        getDashboardStats();
        break;
    default:
        sendResponse(false, null, "Invalid action");
}

function getEmployees() {
    global $conn;
    
    $sql = "SELECT 
                e.employee_id,
                e.employee_number,
                e.department,
                e.position,
                e.salary,
                e.date_hired,
                e.is_active,
                e.working_days,
                e.job_start_time,
                e.job_end_time,
                e.last_active_time,
                p.first_name,
                p.last_name,
                p.email,
                p.phone,
                p.date_of_birth,
                p.address
            FROM employees e
            LEFT JOIN profiles p ON e.user_id = p.user_id
            WHERE e.is_active = 1
            ORDER BY e.created_at DESC";
    
    $result = $conn->query($sql);
    $employees = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
    }
    
    sendResponse(true, ['employees' => $employees]);
}

function getEmployeeDetails() {
    global $conn;
    $employee_id = $_GET['employee_id'] ?? null;
    
    if (!$employee_id) {
        sendResponse(false, null, "Employee ID is required");
    }
    
    // Get basic employee info
    $sql = "SELECT 
                e.*,
                p.first_name,
                p.last_name,
                p.email,
                p.phone,
                p.date_of_birth,
                p.address,
                p.profile_picture_url
            FROM employees e
            LEFT JOIN profiles p ON e.user_id = p.user_id
            WHERE e.employee_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendResponse(false, null, "Employee not found");
    }
    
    $employee = $result->fetch_assoc();
    
    // Get all related data
    $employee['attendance'] = getEmployeeAttendance($employee_id);
    $employee['projects'] = getEmployeeProjectsData($employee_id);
    $employee['tasks'] = getEmployeeTasksData($employee_id);
    $employee['leaves'] = getEmployeeLeavesData($employee_id);
    $employee['daily_summary'] = getEmployeeDailySummaryData($employee_id);
    
    sendResponse(true, ['employee' => $employee]);
}

function getEmployeeAttendance($employee_id) {
    global $conn;
    
    // Mock attendance data - replace with real table
    return [
        ['date' => '2024-01-15', 'status' => 'Present', 'check_in' => '08:55', 'check_out' => '17:05'],
        ['date' => '2024-01-14', 'status' => 'Present', 'check_in' => '09:10', 'check_out' => '17:00'],
        ['date' => '2024-01-13', 'status' => 'Absent', 'check_in' => null, 'check_out' => null],
        ['date' => '2024-01-12', 'status' => 'Late', 'check_in' => '10:15', 'check_out' => '18:00'],
        ['date' => '2024-01-11', 'status' => 'Present', 'check_in' => '08:45', 'check_out' => '17:10']
    ];
}

function getEmployeeProjectsData($employee_id) {
    global $conn;
    
    $sql = "SELECT 
                p.project_id,
                p.project_name,
                p.description,
                p.status,
                p.start_date,
                p.deadline,
                c.company_name,
                ta.role
            FROM projects p
            LEFT JOIN task_assignments ta ON p.project_id = ta.project_id
            LEFT JOIN clients c ON p.client_id = c.client_id
            WHERE ta.employee_id = ?
            ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $projects = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }
    
    // Fallback mock data
    if (empty($projects)) {
        $projects = [
            [
                'project_id' => 1,
                'project_name' => 'E-commerce Platform Development',
                'description' => 'Building a complete online shopping platform',
                'status' => 'In Progress',
                'start_date' => '2024-01-01',
                'deadline' => '2024-06-30',
                'company_name' => 'Tech Solutions Inc',
                'role' => 'Senior Developer'
            ],
            [
                'project_id' => 2,
                'project_name' => 'Mobile App Redesign',
                'description' => 'Redesigning the company mobile application',
                'status' => 'Completed',
                'start_date' => '2023-11-01',
                'deadline' => '2024-01-15',
                'company_name' => 'Innovate Labs',
                'role' => 'UI/UX Designer'
            ]
        ];
    }
    
    return $projects;
}

function getEmployeeTasksData($employee_id) {
    global $conn;
    
    $sql = "SELECT 
                t.task_id,
                t.task_title,
                t.description,
                t.status,
                t.priority,
                t.due_date,
                p.project_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.project_id
            WHERE t.assigned_to = ?
            ORDER BY t.due_date ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tasks = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
    }
    
    // Fallback mock data
    if (empty($tasks)) {
        $tasks = [
            [
                'task_id' => 1,
                'task_title' => 'API Integration',
                'description' => 'Integrate payment gateway API',
                'status' => 'Completed',
                'priority' => 'High',
                'due_date' => '2024-01-10',
                'project_name' => 'E-commerce Platform'
            ],
            [
                'task_id' => 2,
                'task_title' => 'Database Optimization',
                'description' => 'Optimize database queries for better performance',
                'status' => 'In Progress',
                'priority' => 'Medium',
                'due_date' => '2024-01-20',
                'project_name' => 'E-commerce Platform'
            ],
            [
                'task_id' => 3,
                'task_title' => 'User Testing',
                'description' => 'Conduct user testing for new features',
                'status' => 'Pending',
                'priority' => 'Low',
                'due_date' => '2024-01-25',
                'project_name' => 'Mobile App Redesign'
            ]
        ];
    }
    
    return $tasks;
}

function getEmployeeLeavesData($employee_id) {
    global $conn;
    
    $sql = "SELECT 
                l.leave_id,
                l.leave_type,
                l.start_date,
                l.end_date,
                l.reason,
                l.status,
                l.applied_on
            FROM leaves l
            WHERE l.employee_id = ?
            ORDER BY l.applied_on DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $leaves = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $leaves[] = $row;
        }
    }
    
    return $leaves;
}

function getEmployeeDailySummaryData($employee_id) {
    // Mock daily summary data
    return [
        [
            'date' => '2024-01-15',
            'active_minutes' => 420,
            'idle_minutes' => 45,
            'total_screenshots' => 120,
            'productive_score' => 85
        ],
        [
            'date' => '2024-01-14',
            'active_minutes' => 455,
            'idle_minutes' => 25,
            'total_screenshots' => 95,
            'productive_score' => 92
        ],
        [
            'date' => '2024-01-13',
            'active_minutes' => 380,
            'idle_minutes' => 60,
            'total_screenshots' => 85,
            'productive_score' => 78
        ]
    ];
}

function getAttendance() {
    $employee_id = $_GET['employee_id'] ?? null;
    if (!$employee_id) sendResponse(false, null, "Employee ID required");
    
    $attendance = getEmployeeAttendance($employee_id);
    sendResponse(true, ['attendance' => $attendance]);
}

function getProjects() {
    $employee_id = $_GET['employee_id'] ?? null;
    if (!$employee_id) sendResponse(false, null, "Employee ID required");
    
    $projects = getEmployeeProjectsData($employee_id);
    sendResponse(true, ['projects' => $projects]);
}

function getTasks() {
    $employee_id = $_GET['employee_id'] ?? null;
    if (!$employee_id) sendResponse(false, null, "Employee ID required");
    
    $tasks = getEmployeeTasksData($employee_id);
    sendResponse(true, ['tasks' => $tasks]);
}

function getLeaves() {
    $employee_id = $_GET['employee_id'] ?? null;
    if (!$employee_id) sendResponse(false, null, "Employee ID required");
    
    $leaves = getEmployeeLeavesData($employee_id);
    sendResponse(true, ['leaves' => $leaves]);
}

function getDailySummary() {
    $employee_id = $_GET['employee_id'] ?? null;
    if (!$employee_id) sendResponse(false, null, "Employee ID required");
    
    $daily_summary = getEmployeeDailySummaryData($employee_id);
    sendResponse(true, ['daily_summary' => $daily_summary]);
}

function getDashboardStats() {
    global $conn;
    
    $stats = [];
    
    // Total Employees
    $result = $conn->query("SELECT COUNT(*) as count FROM employees WHERE is_active = 1");
    $stats['total_employees'] = $result->fetch_assoc()['count'];
    
    // Active Projects
    $result = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'In Progress'");
    $stats['active_projects'] = $result->fetch_assoc()['count'];
    
    // Pending Tasks
    $result = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'Pending'");
    $stats['pending_tasks'] = $result->fetch_assoc()['count'];
    
    // Today's Attendance
    $result = $conn->query("SELECT COUNT(*) as count FROM attendance WHERE date = CURDATE() AND status = 'Present'");
    $stats['present_today'] = $result->fetch_assoc()['count'];
    
    // Department Count
    $result = $conn->query("SELECT COUNT(DISTINCT department) as count FROM employees WHERE department IS NOT NULL");
    $stats['total_departments'] = $result->fetch_assoc()['count'];
    
    // Upcoming Leaves
    $result = $conn->query("SELECT COUNT(*) as count FROM leaves WHERE start_date >= CURDATE() AND status = 'Approved'");
    $stats['upcoming_leaves'] = $result->fetch_assoc()['count'];
    
    sendResponse(true, ['stats' => $stats]);
}
?>