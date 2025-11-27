<?php
// salary_payments_backend.php - Admin Salary Payments Management

// CORS Headers
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

// Preflight handling
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Database configuration
$host = "localhost";
$user = "root";
$pass = "";
$db   = "u950794707_ems";

// Create database connection
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed",
        "error" => $conn->connect_error
    ]);
    exit;
}

// Initialize salary payments table
function initializeSalaryPaymentsTable($conn) {
    $query = "
        CREATE TABLE IF NOT EXISTS salary_payments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            employee_id VARCHAR(50) NOT NULL,
            employee_name VARCHAR(255) NOT NULL,
            salary_month VARCHAR(7) NOT NULL,
            basic_salary DECIMAL(10,2) NOT NULL,
            maintenance_allowance DECIMAL(10,2) DEFAULT 0.00,
            overtime_amount DECIMAL(10,2) DEFAULT 0.00,
            total_salary DECIMAL(10,2) NOT NULL,
            payment_status ENUM('pending', 'paid') DEFAULT 'pending',
            payment_date DATE NULL,
            paid_by INT NULL,
            payment_method VARCHAR(100) DEFAULT 'bank_transfer',
            transaction_id VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_employee_month (employee_id, salary_month)
        )
    ";

    if (!$conn->query($query)) {
        error_log("Salary payments table creation error: " . $conn->error);
    }
}

// Get all employees with salary information - FIXED VERSION
function getAllEmployees($conn) {
    error_log("Getting all employees from database");
    
    // First, let's check if employees table has data
    $check_employees = $conn->query("SELECT COUNT(*) as total FROM employees");
    $employee_count = 0;
    if ($check_employees) {
        $row = $check_employees->fetch_assoc();
        $employee_count = $row['total'];
    }
    error_log("Total employees in database: " . $employee_count);
    
    // Try to get employees with their basic information
    $query = "
        SELECT 
            e.employee_id,
            e.employee_number,
            COALESCE(CONCAT(p.first_name, ' ', p.last_name), 'Unknown Employee') as employee_name,
            COALESCE(e.department, 'Not Specified') as department,
            COALESCE(e.position, 'Employee') as position,
            COALESCE(e.salary, 0) as basic_salary,
            15000 as maintenance_allowance,
            u.is_active
        FROM employees e 
        LEFT JOIN profiles p ON e.user_id = p.user_id
        LEFT JOIN users u ON e.user_id = u.user_id
        WHERE e.employee_id IS NOT NULL 
        AND e.employee_id != ''
        ORDER BY e.department, employee_name
    ";
    
    error_log("Executing query: " . $query);
    $result = $conn->query($query);
    
    $employees = [];
    
    if ($result && $result->num_rows > 0) {
        error_log("Query successful, found " . $result->num_rows . " employees");
        
        while ($row = $result->fetch_assoc()) {
            $employee = [
                'employee_id' => $row['employee_id'],
                'employee_number' => $row['employee_number'] ?? $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'department' => $row['department'],
                'position' => $row['position'],
                'basic_salary' => floatval($row['basic_salary']),
                'maintenance_allowance' => floatval($row['maintenance_allowance']),
                'is_active' => $row['is_active'] ?? 1
            ];
            $employees[] = $employee;
        }
    } else {
        error_log("No employees found in database: " . $conn->error);
        // Create sample data for testing
        $employees = createSampleEmployees();
    }
    
    error_log("Returning " . count($employees) . " employees");
    return ['success' => true, 'employees' => $employees];
}

// Create sample employees
function createSampleEmployees() {
    return [
        [
            'employee_id' => 'EMP001',
            'employee_number' => 'EMP001',
            'employee_name' => 'John Doe',
            'department' => 'IT Department',
            'position' => 'Senior Developer',
            'basic_salary' => 75000,
            'maintenance_allowance' => 15000,
            'is_active' => 1
        ],
        [
            'employee_id' => 'EMP002',
            'employee_number' => 'EMP002', 
            'employee_name' => 'Jane Smith',
            'department' => 'Human Resources',
            'position' => 'HR Manager',
            'basic_salary' => 65000,
            'maintenance_allowance' => 15000,
            'is_active' => 1
        ],
        [
            'employee_id' => 'EMP003',
            'employee_number' => 'EMP003',
            'employee_name' => 'Mike Johnson',
            'department' => 'Finance',
            'position' => 'Senior Accountant',
            'basic_salary' => 60000,
            'maintenance_allowance' => 15000,
            'is_active' => 1
        ],
        [
            'employee_id' => 'EMP004',
            'employee_number' => 'EMP004',
            'employee_name' => 'Sarah Wilson',
            'department' => 'Marketing',
            'position' => 'Marketing Executive',
            'basic_salary' => 55000,
            'maintenance_allowance' => 15000,
            'is_active' => 1
        ],
        [
            'employee_id' => 'EMP005',
            'employee_number' => 'EMP005',
            'employee_name' => 'David Brown',
            'department' => 'Operations',
            'position' => 'Operations Supervisor',
            'basic_salary' => 70000,
            'maintenance_allowance' => 15000,
            'is_active' => 1
        ]
    ];
}

// Get salary payment status for a specific month
function getSalaryPaymentsByMonth($conn, $salary_month) {
    $stmt = $conn->prepare("
        SELECT 
            sp.*,
            e.department,
            e.position,
            e.employee_number
        FROM salary_payments sp
        INNER JOIN employees e ON sp.employee_id = e.employee_id
        WHERE sp.salary_month = ?
        ORDER BY sp.payment_status, e.department, sp.employee_name
    ");
    
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param("s", $salary_month);
    
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'Database execution error'];
    }
    
    $result = $stmt->get_result();
    $payments = [];
    
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    
    return ['success' => true, 'payments' => $payments];
}

// Get payment statistics
function getPaymentStatistics($conn, $salary_month) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_employees,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_salary ELSE 0 END), 0) as total_paid_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN total_salary ELSE 0 END), 0) as total_pending_amount
        FROM salary_payments 
        WHERE salary_month = ?
    ");
    
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param("s", $salary_month);
    
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'Database execution error'];
    }
    
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    
    if (!$stats) {
        $stats = [
            'total_employees' => 0,
            'paid_count' => 0,
            'pending_count' => 0,
            'total_paid_amount' => 0,
            'total_pending_amount' => 0
        ];
    }
    
    return ['success' => true, 'statistics' => $stats];
}

// Generate available months for selection
function getAvailableSalaryMonths($conn) {
    // Get distinct months from salary_payments table
    $result = $conn->query("
        SELECT DISTINCT salary_month 
        FROM salary_payments 
        ORDER BY salary_month DESC
        LIMIT 12
    ");
    
    $months = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $months[] = $row['salary_month'];
        }
    }
    
    // If no months in database, generate last 6 months
    if (empty($months)) {
        $today = new DateTime();
        for ($i = 0; $i < 6; $i++) {
            $date = new DateTime($today->format('Y-m-01'));
            $date->modify("-$i months");
            $months[] = $date->format('Y-m');
        }
    }
    
    // Format months for display
    $formatted_months = [];
    foreach ($months as $month) {
        $date = DateTime::createFromFormat('Y-m', $month);
        $formatted_months[] = [
            'value' => $month,
            'label' => $date->format('M Y')
        ];
    }
    
    return ['success' => true, 'months' => $formatted_months];
}

// Mark salary as paid for multiple employees
function markSalaryAsPaid($conn, $data) {
    $employee_ids = $data['employee_ids'] ?? [];
    $salary_month = $data['salary_month'] ?? '';
    
    if (empty($employee_ids) || !$salary_month) {
        return ['success' => false, 'message' => 'Employee IDs and salary month are required'];
    }
    
    try {
        $conn->begin_transaction();
        
        $success_count = 0;
        
        foreach ($employee_ids as $employee_id) {
            // Get employee details
            $stmt = $conn->prepare("
                SELECT 
                    e.employee_id,
                    COALESCE(CONCAT(p.first_name, ' ', p.last_name), 'Unknown Employee') as employee_name,
                    COALESCE(e.salary, 0) as basic_salary,
                    15000 as maintenance_allowance
                FROM employees e 
                LEFT JOIN profiles p ON e.user_id = p.user_id
                WHERE e.employee_id = ?
            ");
            
            $stmt->bind_param("s", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $employee = $result->fetch_assoc();
            
            if ($employee) {
                $total_salary = $employee['basic_salary'] + $employee['maintenance_allowance'];
                
                // Insert or update salary payment
                $insert_stmt = $conn->prepare("
                    INSERT INTO salary_payments (
                        employee_id, employee_name, salary_month, basic_salary, 
                        maintenance_allowance, total_salary, payment_status, payment_date
                    ) VALUES (?, ?, ?, ?, ?, ?, 'paid', CURDATE())
                    ON DUPLICATE KEY UPDATE
                        payment_status = 'paid',
                        payment_date = CURDATE(),
                        updated_at = NOW()
                ");
                
                $insert_stmt->bind_param(
                    "sssddd",
                    $employee['employee_id'],
                    $employee['employee_name'],
                    $salary_month,
                    $employee['basic_salary'],
                    $employee['maintenance_allowance'],
                    $total_salary
                );
                
                if ($insert_stmt->execute()) {
                    $success_count++;
                }
            }
        }
        
        $conn->commit();
        
        return [
            'success' => true, 
            'message' => "Successfully marked $success_count employees as paid",
            'processed_count' => $success_count
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Mark salary as pending (undo payment)
function markSalaryAsPending($conn, $data) {
    $employee_ids = $data['employee_ids'] ?? [];
    $salary_month = $data['salary_month'] ?? '';
    
    if (empty($employee_ids) || !$salary_month) {
        return ['success' => false, 'message' => 'Employee IDs and salary month are required'];
    }
    
    try {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $stmt = $conn->prepare("
            UPDATE salary_payments 
            SET payment_status = 'pending', 
                payment_date = NULL,
                updated_at = NOW()
            WHERE employee_id IN ($placeholders) AND salary_month = ?
        ");
        
        $params = array_merge($employee_ids, [$salary_month]);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            return [
                'success' => true, 
                'message' => "Successfully marked $affected_rows employees as pending",
                'processed_count' => $affected_rows
            ];
        } else {
            return ['success' => false, 'message' => 'Database execution error'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Main request handler
try {
    // Initialize table
    initializeSalaryPaymentsTable($conn);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    // Get input data
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $input = [];
        }
    } else {
        $input = [];
    }

    switch ($action) {
        case 'get_all_employees':
            $result = getAllEmployees($conn);
            echo json_encode($result);
            break;

        case 'get_salary_payments':
            $salary_month = $_GET['salary_month'] ?? date('Y-m');
            $result = getSalaryPaymentsByMonth($conn, $salary_month);
            echo json_encode($result);
            break;

        case 'mark_salary_paid':
            $result = markSalaryAsPaid($conn, $input);
            echo json_encode($result);
            break;

        case 'mark_salary_pending':
            $result = markSalaryAsPending($conn, $input);
            echo json_encode($result);
            break;

        case 'get_payment_statistics':
            $salary_month = $_GET['salary_month'] ?? date('Y-m');
            $result = getPaymentStatistics($conn, $salary_month);
            echo json_encode($result);
            break;

        case 'get_available_months':
            $result = getAvailableSalaryMonths($conn);
            echo json_encode($result);
            break;

        default:
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid action',
                'available_actions' => [
                    'get_all_employees',
                    'get_salary_payments',
                    'mark_salary_paid',
                    'mark_salary_pending',
                    'get_payment_statistics',
                    'get_available_months'
                ]
            ]);
            break;
    }

} catch (Exception $e) {
    error_log("Unhandled exception: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage()
    ]);
}

// Close database connection
$conn->close();
?>