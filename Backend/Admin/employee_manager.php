<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include config file
require_once __DIR__ . '/config.php';

class EmployeeManagement {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }

    // Get all employees with user and profile details
    public function getEmployees($filters = []) {
        try {
            $whereConditions = [];
            $params = [];
            $types = "";

            // Build WHERE conditions based on filters
            if (!empty($filters['search'])) {
                $whereConditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR e.department LIKE ? OR e.position LIKE ?)";
                $params[] = "%" . $filters['search'] . "%";
                $params[] = "%" . $filters['search'] . "%";
                $params[] = "%" . $filters['search'] . "%";
                $params[] = "%" . $filters['search'] . "%";
                $types .= "ssss";
            }

            if (!empty($filters['department'])) {
                $whereConditions[] = "e.department = ?";
                $params[] = $filters['department'];
                $types .= "s";
            }

            if (!empty($filters['is_active'])) {
                $whereConditions[] = "e.is_active = ?";
                $params[] = $filters['is_active'];
                $types .= "i";
            }

            $whereClause = "";
            if (!empty($whereConditions)) {
                $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            }

            $query = "SELECT 
                        e.*,
                        u.username,
                        u.role_id,
                        p.first_name,
                        p.last_name,
                        p.email,
                        p.phone,
                        p.profile_picture_url,
                        p.date_of_birth,
                        p.address,
                        m.first_name as manager_first_name,
                        m.last_name as manager_last_name
                      FROM employees e
                      LEFT JOIN users u ON e.user_id = u.user_id
                      LEFT JOIN profiles p ON e.user_id = p.user_id
                      LEFT JOIN employees em ON e.manager_id = em.employee_id
                      LEFT JOIN profiles m ON em.user_id = m.user_id
                      $whereClause
                      ORDER BY p.first_name, p.last_name";

            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $employees = [];
            while ($row = $result->fetch_assoc()) {
                $employees[] = $row;
            }
            
            return $employees;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee by ID with complete details
    public function getEmployeeById($employee_id) {
        try {
            $query = "SELECT 
                        e.*,
                        u.username,
                        u.role_id,
                        p.first_name,
                        p.last_name,
                        p.email,
                        p.phone,
                        p.profile_picture_url,
                        p.date_of_birth,
                        p.address,
                        m.first_name as manager_first_name,
                        m.last_name as manager_last_name
                      FROM employees e
                      LEFT JOIN users u ON e.user_id = u.user_id
                      LEFT JOIN profiles p ON e.user_id = p.user_id
                      LEFT JOIN employees em ON e.manager_id = em.employee_id
                      LEFT JOIN profiles m ON em.user_id = m.user_id
                      WHERE e.employee_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee attendance records
    public function getEmployeeAttendance($employee_id, $start_date = null, $end_date = null) {
        try {
            $query = "SELECT 
                        a.attendance_id,
                        a.employee_id,
                        a.attendance_date,
                        a.check_in_time,
                        a.check_out_time,
                        a.status,
                        a.notes,
                        a.total_hours,
                        a.created_at
                      FROM attendance a
                      WHERE a.employee_id = ?";
            
            $params = [$employee_id];
            $types = "i";

            if ($start_date) {
                $query .= " AND a.attendance_date >= ?";
                $params[] = $start_date;
                $types .= "s";
            }

            if ($end_date) {
                $query .= " AND a.attendance_date <= ?";
                $params[] = $end_date;
                $types .= "s";
            }

            $query .= " ORDER BY a.attendance_date DESC LIMIT 30";

            $stmt = $this->conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $attendance = [];
            while ($row = $result->fetch_assoc()) {
                $attendance[] = $row;
            }
            
            return $attendance;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee projects
    public function getEmployeeProjects($employee_id) {
        try {
            $query = "SELECT 
                        p.project_id,
                        p.project_name,
                        p.description,
                        p.start_date,
                        p.deadline,
                        p.status,
                        pm.role as project_role
                      FROM projects p
                      INNER JOIN project_member pm ON p.project_id = pm.project_id
                      WHERE pm.employee_id = ?
                      ORDER BY p.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $projects = [];
            while ($row = $result->fetch_assoc()) {
                $projects[] = $row;
            }
            
            return $projects;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee tasks
    public function getEmployeeTasks($employee_id) {
        try {
            $query = "SELECT 
                        t.task_id,
                        t.task_title,
                        t.description,
                        t.priority,
                        t.status,
                        t.due_date,
                        t.created_at,
                        p.project_name,
                        a.first_name as assigned_by_first_name,
                        a.last_name as assigned_by_last_name
                      FROM tasks t
                      LEFT JOIN projects p ON t.project_id = p.project_id
                      LEFT JOIN employees ae ON t.assigned_by = ae.employee_id
                      LEFT JOIN profiles a ON ae.user_id = a.user_id
                      WHERE t.assigned_to = ?
                      ORDER BY t.due_date ASC, t.priority DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $tasks = [];
            while ($row = $result->fetch_assoc()) {
                $tasks[] = $row;
            }
            
            return $tasks;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee leaves
    public function getEmployeeLeaves($employee_id) {
        try {
            $query = "SELECT 
                        l.leave_id,
                        l.leave_type,
                        l.start_date,
                        l.end_date,
                        l.reason,
                        l.status,
                        l.approved_by,
                        l.created_at,
                        a.first_name as approved_by_first_name,
                        a.last_name as approved_by_last_name
                      FROM leaves l
                      LEFT JOIN employees ae ON l.approved_by = ae.employee_id
                      LEFT JOIN profiles a ON ae.user_id = a.user_id
                      WHERE l.employee_id = ?
                      ORDER BY l.start_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $leaves = [];
            while ($row = $result->fetch_assoc()) {
                $leaves[] = $row;
            }
            
            return $leaves;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee daily activity summary
    public function getEmployeeDailySummary($employee_id, $start_date = null, $end_date = null) {
        try {
            $query = "SELECT 
                        ds.summary_id,
                        ds.employee_id,
                        ds.work_date,
                        ds.active_minutes,
                        ds.idle_minutes,
                        ds.total_screenshots,
                        ds.total_keystrokes,
                        ds.total_mouse_clicks,
                        ds.created_at
                      FROM employee_daily_summary ds
                      WHERE ds.employee_id = ?";
            
            $params = [$employee_id];
            $types = "i";

            if ($start_date) {
                $query .= " AND ds.work_date >= ?";
                $params[] = $start_date;
                $types .= "s";
            }

            if ($end_date) {
                $query .= " AND ds.work_date <= ?";
                $params[] = $end_date;
                $types .= "s";
            }

            $query .= " ORDER BY ds.work_date DESC LIMIT 30";

            $stmt = $this->conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $summary = [];
            while ($row = $result->fetch_assoc()) {
                $summary[] = $row;
            }
            
            return $summary;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee recent activities
    public function getEmployeeActivities($employee_id, $limit = 50) {
        try {
            $query = "SELECT 
                        a.activity_id,
                        a.activity_type,
                        a.description,
                        a.activity_time,
                        a.duration_minutes,
                        a.screenshot_url,
                        a.application_name,
                        a.window_title
                      FROM employees_activity a
                      WHERE a.employee_id = ?
                      ORDER BY a.activity_time DESC
                      LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $employee_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $activities = [];
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }
            
            return $activities;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get employee performance statistics
    public function getEmployeeStats($employee_id) {
        try {
            $stats = [];

            // Attendance stats
            $attendanceQuery = "SELECT 
                                COUNT(*) as total_days,
                                COUNT(CASE WHEN status = 'Present' THEN 1 END) as present_days,
                                COUNT(CASE WHEN status = 'Absent' THEN 1 END) as absent_days,
                                COUNT(CASE WHEN status = 'Late' THEN 1 END) as late_days,
                                CASE 
                                    WHEN COUNT(*) = 0 THEN 0
                                    ELSE ROUND((COUNT(CASE WHEN status IN ('Present', 'Late') THEN 1 END) * 100.0 / COUNT(*)), 2)
                                END as attendance_rate
                              FROM attendance 
                              WHERE employee_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            
            $stmt1 = $this->conn->prepare($attendanceQuery);
            $stmt1->bind_param("i", $employee_id);
            $stmt1->execute();
            $attendanceStats = $stmt1->get_result()->fetch_assoc();
            $stats['attendance'] = $attendanceStats;

            // Task stats
            $taskQuery = "SELECT 
                          COUNT(*) as total_tasks,
                          COUNT(CASE WHEN status = 'Completed' THEN 1 END) as completed_tasks,
                          COUNT(CASE WHEN status = 'In Progress' THEN 1 END) as in_progress_tasks,
                          COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_tasks,
                          CASE 
                              WHEN COUNT(*) = 0 THEN 0
                              ELSE ROUND((COUNT(CASE WHEN status = 'Completed' THEN 1 END) * 100.0 / COUNT(*)), 2)
                          END as completion_rate
                        FROM tasks 
                        WHERE assigned_to = ?";
            
            $stmt2 = $this->conn->prepare($taskQuery);
            $stmt2->bind_param("i", $employee_id);
            $stmt2->execute();
            $taskStats = $stmt2->get_result()->fetch_assoc();
            $stats['tasks'] = $taskStats;

            // Activity stats (last 7 days)
            $activityQuery = "SELECT 
                              SUM(active_minutes) as total_active_minutes,
                              SUM(idle_minutes) as total_idle_minutes,
                              AVG(active_minutes) as avg_active_minutes,
                              AVG(idle_minutes) as avg_idle_minutes,
                              CASE 
                                  WHEN SUM(active_minutes + idle_minutes) = 0 THEN 0
                                  ELSE ROUND((SUM(active_minutes) * 100.0 / SUM(active_minutes + idle_minutes)), 2)
                              END as productivity_rate
                            FROM employee_daily_summary 
                            WHERE employee_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            
            $stmt3 = $this->conn->prepare($activityQuery);
            $stmt3->bind_param("i", $employee_id);
            $stmt3->execute();
            $activityStats = $stmt3->get_result()->fetch_assoc();
            $stats['activity'] = $activityStats;

            return $stats;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Update employee information
    public function updateEmployee($data) {
        try {
            if (empty($data['employee_id'])) {
                return ['error' => 'Employee ID is required'];
            }

            // Update employee table
            $employeeQuery = "UPDATE employees SET 
                             department = ?,
                             position = ?,
                             salary = ?,
                             manager_id = ?,
                             job_start_time = ?,
                             job_end_time = ?,
                             working_days = ?,
                             is_active = ?,
                             updated_at = NOW()
                             WHERE employee_id = ?";
            
            $stmt1 = $this->conn->prepare($employeeQuery);
            $stmt1->bind_param(
                "ssdisssii",
                $data['department'],
                $data['position'],
                $data['salary'],
                $data['manager_id'],
                $data['job_start_time'],
                $data['job_end_time'],
                $data['working_days'],
                $data['is_active'],
                $data['employee_id']
            );

            // Update profile table
            $profileQuery = "UPDATE profiles SET 
                            first_name = ?,
                            last_name = ?,
                            email = ?,
                            phone = ?,
                            date_of_birth = ?,
                            address = ?
                            WHERE user_id = (SELECT user_id FROM employees WHERE employee_id = ?)";
            
            $stmt2 = $this->conn->prepare($profileQuery);
            $stmt2->bind_param(
                "ssssssi",
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $data['phone'],
                $data['date_of_birth'],
                $data['address'],
                $data['employee_id']
            );

            // Start transaction
            $this->conn->begin_transaction();

            try {
                $stmt1->execute();
                $stmt2->execute();
                
                $this->conn->commit();
                
                return [
                    'success' => 'Employee updated successfully',
                    'employee' => $this->getEmployeeById($data['employee_id'])
                ];
                
            } catch (Exception $e) {
                $this->conn->rollback();
                return ['error' => 'Error updating employee: ' . $e->getMessage()];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Create new employee
    public function createEmployee($data) {
        try {
            // Validate required fields
            $required = ['first_name', 'last_name', 'email', 'department', 'position', 'salary'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['error' => "Field '$field' is required"];
                }
            }

            // Start transaction
            $this->conn->begin_transaction();

            try {
                // Generate employee number
                $employeeNumber = 'EMP' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

                // Create user first
                $userQuery = "INSERT INTO users (username, password_hash, is_active, role_id, created_at) 
                             VALUES (?, ?, 1, 2, NOW())";
                
                $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);
                $stmt1 = $this->conn->prepare($userQuery);
                $stmt1->bind_param("ss", $data['email'], $defaultPassword);
                $stmt1->execute();
                $user_id = $this->conn->insert_id;

                // Create profile
                $profileQuery = "INSERT INTO profiles 
                               (user_id, first_name, last_name, email, phone, date_of_birth, address) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $stmt2 = $this->conn->prepare($profileQuery);
                $stmt2->bind_param(
                    "issssss",
                    $user_id,
                    $data['first_name'],
                    $data['last_name'],
                    $data['email'],
                    $data['phone'] ?? null,
                    $data['date_of_birth'] ?? null,
                    $data['address'] ?? null
                );
                $stmt2->execute();

                // Create employee record
                $employeeQuery = "INSERT INTO employees 
                                (user_id, employee_number, department, position, salary, date_hired, 
                                 manager_id, job_start_time, job_end_time, working_days, is_active) 
                                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 1)";
                
                $stmt3 = $this->conn->prepare($employeeQuery);
                $stmt3->bind_param(
                    "isssissss",
                    $user_id,
                    $employeeNumber,
                    $data['department'],
                    $data['position'],
                    $data['salary'],
                    $data['manager_id'] ?? null,
                    $data['job_start_time'] ?? '09:00',
                    $data['job_end_time'] ?? '17:00',
                    $data['working_days'] ?? 'Mon-Fri'
                );
                $stmt3->execute();
                $employee_id = $this->conn->insert_id;

                $this->conn->commit();
                
                return [
                    'success' => 'Employee created successfully',
                    'employee_id' => $employee_id,
                    'employee' => $this->getEmployeeById($employee_id)
                ];
                
            } catch (Exception $e) {
                $this->conn->rollback();
                return ['error' => 'Error creating employee: ' . $e->getMessage()];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get departments list
    public function getDepartments() {
        try {
            $query = "SELECT DISTINCT department FROM employees WHERE department IS NOT NULL ORDER BY department";
            $result = $this->conn->query($query);
            
            $departments = [];
            while ($row = $result->fetch_assoc()) {
                $departments[] = $row['department'];
            }
            
            return $departments;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get managers list
    public function getManagers() {
        try {
            $query = "SELECT 
                        e.employee_id,
                        p.first_name,
                        p.last_name,
                        e.position,
                        e.department
                      FROM employees e
                      LEFT JOIN profiles p ON e.user_id = p.user_id
                      WHERE e.is_active = 1
                      ORDER BY p.first_name, p.last_name";
            
            $result = $this->conn->query($query);
            
            $managers = [];
            while ($row = $result->fetch_assoc()) {
                $managers[] = $row;
            }
            
            return $managers;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

$employeeManager = new EmployeeManagement($conn);
$method = $_SERVER['REQUEST_METHOD'];

// Handle different endpoints and parameters
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$query_string = parse_url($request_uri, PHP_URL_QUERY);
$segments = explode('/', $path);
$endpoint = end($segments);

// Parse query parameters
$query_params = [];
if ($query_string) {
    parse_str($query_string, $query_params);
}

// Get input data for POST, PUT
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        $action = $query_params['action'] ?? '';
        
        switch ($action) {
            case 'get_employees':
                $filters = [
                    'search' => $query_params['search'] ?? '',
                    'department' => $query_params['department'] ?? '',
                    'is_active' => $query_params['is_active'] ?? ''
                ];
                $result = $employeeManager->getEmployees($filters);
                break;
                
            case 'get_employee':
                if (isset($query_params['employee_id'])) {
                    $result = $employeeManager->getEmployeeById($query_params['employee_id']);
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_attendance':
                if (isset($query_params['employee_id'])) {
                    $result = $employeeManager->getEmployeeAttendance(
                        $query_params['employee_id'],
                        $query_params['start_date'] ?? null,
                        $query_params['end_date'] ?? null
                    );
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_projects':
                if (isset($query_params['employee_id'])) {
                    $result = $employeeManager->getEmployeeProjects($query_params['employee_id']);
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_tasks':
                if (isset($query_params['employee_id'])) {
                    $result = $employeeManager->getEmployeeTasks($query_params['employee_id']);
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_leaves':
                if (isset($query_params['employee_id'])) {
                    $result = $employeeManager->getEmployeeLeaves($query_params['employee_id']);
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_daily_summary':
                if (isset($query_params['employee_id'])) {
                    $result = $employeeManager->getEmployeeDailySummary(
                        $query_params['employee_id'],
                        $query_params['start_date'] ?? null,
                        $query_params['end_date'] ?? null
                    );
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_activities':
                if (isset($query_params['employee_id'])) {
                    $limit = $query_params['limit'] ?? 50;
                    $result = $employeeManager->getEmployeeActivities($query_params['employee_id'], $limit);
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_stats':
                if (isset($query_params['employee_id'])) {
                    $result = $employeeManager->getEmployeeStats($query_params['employee_id']);
                } else {
                    $result = ['error' => 'Employee ID is required'];
                }
                break;
                
            case 'get_departments':
                $result = $employeeManager->getDepartments();
                break;
                
            case 'get_managers':
                $result = $employeeManager->getManagers();
                break;
                
            default:
                // Return employees by default
                $filters = [
                    'search' => $query_params['search'] ?? '',
                    'department' => $query_params['department'] ?? '',
                    'is_active' => $query_params['is_active'] ?? ''
                ];
                $result = $employeeManager->getEmployees($filters);
                break;
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, 'Data retrieved successfully');
        }
        break;
        
    case 'POST':
        $action = $query_params['action'] ?? '';
        
        switch ($action) {
            case 'create_employee':
                $result = $employeeManager->createEmployee($input);
                break;
                
            case 'update_employee':
                $result = $employeeManager->updateEmployee($input);
                break;
                
            default:
                $result = ['error' => 'Invalid action'];
                break;
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, $result['success']);
        }
        break;
        
    case 'OPTIONS':
        http_response_code(200);
        exit();
        
    default:
        http_response_code(405);
        sendResponse(false, null, 'Method not allowed');
        break;
}
?>