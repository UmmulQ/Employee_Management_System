<?php
// payslip_backend.php - Simplified Backend for Payslip Management

// Enable error reporting but don't display errors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// CORS Headers and Configuration
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

// Database configuration
$host = "localhost";
$user = "root";
$pass = "";
$db   = "u950794707_ems";

// Create database connection
try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed",
        "error" => $e->getMessage()
    ]);
    exit;
}

// Initialize database tables
function initializePayslipTables($conn) {
    $tables = [
        "employee_payslip_info" => "
            CREATE TABLE IF NOT EXISTS employee_payslip_info (
                id INT PRIMARY KEY AUTO_INCREMENT,
                employee_id VARCHAR(50) UNIQUE NOT NULL,
                bank_account_name VARCHAR(255) NOT NULL,
                bank_account_number VARCHAR(50) NOT NULL,
                maintenance_allowance DECIMAL(10,2) DEFAULT 15000.00,
                country VARCHAR(100) DEFAULT 'Pakistan',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ",
        "employee_payslips" => "
            CREATE TABLE IF NOT EXISTS employee_payslips (
                payslip_id INT PRIMARY KEY AUTO_INCREMENT,
                employee_id VARCHAR(50) NOT NULL,
                salary_month VARCHAR(7) NOT NULL,
                basic_salary DECIMAL(10,2) NOT NULL,
                maintenance_allowance DECIMAL(10,2) NOT NULL,
                overtime_hours DECIMAL(5,2) DEFAULT 0.00,
                overtime_amount DECIMAL(10,2) DEFAULT 0.00,
                total_salary DECIMAL(10,2) NOT NULL,
                generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_employee_month (employee_id, salary_month)
            )
        "
    ];

    foreach ($tables as $tableName => $query) {
        if (!$conn->query($query)) {
            error_log("Table creation error for $tableName: " . $conn->error);
        }
    }
}

// Get employee basic information
function getEmployeeBasicInfo($conn, $employee_id) {
    $stmt = $conn->prepare("
        SELECT 
            p.first_name, 
            p.last_name, 
            p.email, 
            p.phone, 
            p.address,
            e.employee_id,
            e.employee_number,
            e.department,
            e.position,
            e.salary as basic_salary
        FROM employees e 
        INNER JOIN profiles p ON e.user_id = p.user_id
        INNER JOIN users u ON e.user_id = u.user_id
        WHERE e.employee_id = ? AND u.is_active = 1
    ");
    
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("s", $employee_id);
    
    if (!$stmt->execute()) {
        return null;
    }
    
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get or create employee payslip info
function getEmployeePayslipInfo($conn, $employee_id) {
    $stmt = $conn->prepare("SELECT * FROM employee_payslip_info WHERE employee_id = ?");
    
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("s", $employee_id);
    
    if (!$stmt->execute()) {
        return null;
    }
    
    $result = $stmt->get_result();
    $payslip_info = $result->fetch_assoc();

    // If no payslip info exists, create default one
    if (!$payslip_info) {
        $employee = getEmployeeBasicInfo($conn, $employee_id);
        if ($employee) {
            $insert_stmt = $conn->prepare("
                INSERT INTO employee_payslip_info (
                    employee_id, bank_account_name, bank_account_number, 
                    maintenance_allowance, country
                ) VALUES (?, ?, ?, ?, ?)
            ");
            
            if ($insert_stmt) {
                $default_account_name = $employee['first_name'] . ' ' . $employee['last_name'];
                $default_account_number = 'Not Provided';
                $default_allowance = 15000.00;
                $default_country = 'Pakistan';
                
                $insert_stmt->bind_param(
                    "sssds", 
                    $employee_id,
                    $default_account_name,
                    $default_account_number,
                    $default_allowance,
                    $default_country
                );
                
                if ($insert_stmt->execute()) {
                    // Get the newly created record
                    return getEmployeePayslipInfo($conn, $employee_id);
                }
            }
        }
    }
    
    return $payslip_info;
}

// Generate payslip data (SIMPLIFIED VERSION)
function generatePayslipData($conn, $employee_id, $salary_month) {
    try {
        // Get employee basic information
        $employee = getEmployeeBasicInfo($conn, $employee_id);
        if (!$employee) {
            return ['success' => false, 'message' => 'Employee not found or inactive'];
        }

        // Get payslip info
        $payslip_info = getEmployeePayslipInfo($conn, $employee_id);
        if (!$payslip_info) {
            $payslip_info = [
                'bank_account_name' => $employee['first_name'] . ' ' . $employee['last_name'],
                'bank_account_number' => 'Not Provided',
                'maintenance_allowance' => 15000,
                'country' => 'Pakistan'
            ];
        }

        // Calculate basic values
        $basic_salary = $employee['basic_salary'] ?? 0;
        $maintenance_allowance = $payslip_info['maintenance_allowance'] ?? 15000;
        
        // For now, set overtime to 0 (you can add calculation later)
        $ot_hours = 0;
        $ot_days = 0;
        $ot_per_day_rate = round($basic_salary / 30, 2);
        $ot_amount = 0;

        // Calculate totals
        $salary_per_month = $basic_salary + $maintenance_allowance;
        $total_salary = $salary_per_month + $ot_amount;

        // Format dates
        $month_name = date('M Y', strtotime($salary_month . '-01'));
        $next_month = date('Y-m', strtotime($salary_month . '-01 +1 month'));
        $pay_transfer_date = date('d M Y', strtotime($next_month . '-02'));

        // Generate employee SSN
        $employee_ssn = 'XXXX-XXXX-' . substr($employee['employee_number'] ?? '0000', -4);

        $payslip = [
            'salary_month' => $month_name,
            'pay_transfer_date' => $pay_transfer_date,
            'employee_name' => $employee['first_name'] . ' ' . $employee['last_name'],
            'employee_ssn' => $employee_ssn,
            'employee_designation' => $employee['position'] ?? 'Employee',
            'employee_location' => $payslip_info['country'] ?? 'Pakistan',
            'employee_address' => $employee['address'] ?? 'Not specified',
            'email' => $employee['email'],
            'mobile' => $employee['phone'] ?? 'Not specified',
            'salary_currency' => 'PKR',
            'payment_mode' => 'Bank Account Transfer',
            'bank_account_name' => $payslip_info['bank_account_name'],
            'bank_account_number' => $payslip_info['bank_account_number'],
            
            // Salary Components
            'basic_salary' => floatval($basic_salary),
            'maintenance_allowance' => floatval($maintenance_allowance),
            
            // Overtime
            'ot_hours' => floatval($ot_hours),
            'ot_days' => floatval($ot_days),
            'ot_per_day_rate' => floatval($ot_per_day_rate),
            'ot_amount' => floatval($ot_amount),
            
            // Totals
            'salary_per_month' => floatval($salary_per_month),
            'total_salary' => floatval($total_salary)
        ];

        // Store payslip in database
        storePayslipRecord($conn, $employee_id, $salary_month, $payslip);

        return ['success' => true, 'payslip' => $payslip];

    } catch (Exception $e) {
        error_log("Payslip generation error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error generating payslip: ' . $e->getMessage()];
    }
}

// Store payslip record
function storePayslipRecord($conn, $employee_id, $salary_month, $payslip_data) {
    $stmt = $conn->prepare("
        INSERT INTO employee_payslips (
            employee_id, salary_month, basic_salary, maintenance_allowance,
            overtime_hours, overtime_amount, total_salary
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            basic_salary = VALUES(basic_salary),
            maintenance_allowance = VALUES(maintenance_allowance),
            overtime_hours = VALUES(overtime_hours),
            overtime_amount = VALUES(overtime_amount),
            total_salary = VALUES(total_salary),
            generated_at = NOW()
    ");
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param(
        "ssddddd", 
        $employee_id,
        $salary_month,
        $payslip_data['basic_salary'],
        $payslip_data['maintenance_allowance'],
        $payslip_data['ot_hours'],
        $payslip_data['ot_amount'],
        $payslip_data['total_salary']
    );
    
    return $stmt->execute();
}

// Get payslip history
function getPayslipHistory($conn, $employee_id) {
    $stmt = $conn->prepare("
        SELECT 
            salary_month,
            DATE_FORMAT(STR_TO_DATE(CONCAT(salary_month, '-01'), '%Y-%m-%d'), '%M %Y') as formatted_month,
            basic_salary,
            maintenance_allowance,
            overtime_hours,
            overtime_amount,
            total_salary,
            generated_at
        FROM employee_payslips 
        WHERE employee_id = ?
        ORDER BY salary_month DESC
        LIMIT 12
    ");
    
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $stmt->bind_param("s", $employee_id);
    
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $result = $stmt->get_result();
    $payslip_history = [];
    
    while ($row = $result->fetch_assoc()) {
        $payslip_history[] = $row;
    }
    
    return ['success' => true, 'payslip_history' => $payslip_history];
}

// Get available months
function getAvailableMonths($conn, $employee_id) {
    // Simple fallback - return last 6 months
    $available_months = [];
    $today = new DateTime();
    
    for ($i = 0; $i < 6; $i++) {
        $date = new DateTime($today->format('Y-m-01'));
        $date->modify("-$i months");
        $available_months[] = [
            'value' => $date->format('Y-m'),
            'label' => $date->format('M Y')
        ];
    }
    
    return ['success' => true, 'available_months' => $available_months];
}

// Update payslip info
function updatePayslipInfo($conn, $data) {
    $employee_id = $data['employee_id'] ?? null;
    
    if (!$employee_id) {
        return ['success' => false, 'message' => 'Employee ID is required'];
    }

    // Check if record exists
    $check_stmt = $conn->prepare("SELECT id FROM employee_payslip_info WHERE employee_id = ?");
    if (!$check_stmt) {
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $check_stmt->bind_param("s", $employee_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc();

    if ($exists) {
        // Update existing record
        $stmt = $conn->prepare("
            UPDATE employee_payslip_info 
            SET bank_account_name = ?, bank_account_number = ?, 
                maintenance_allowance = ?, country = ?
            WHERE employee_id = ?
        ");
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        
        $stmt->bind_param(
            "ssdss", 
            $data['bank_account_name'],
            $data['bank_account_number'],
            $data['maintenance_allowance'],
            $data['country'],
            $employee_id
        );
    } else {
        // Insert new record
        $stmt = $conn->prepare("
            INSERT INTO employee_payslip_info 
            (employee_id, bank_account_name, bank_account_number, maintenance_allowance, country) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        
        $stmt->bind_param(
            "sssds", 
            $employee_id,
            $data['bank_account_name'],
            $data['bank_account_number'],
            $data['maintenance_allowance'],
            $data['country']
        );
    }
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Payslip information updated successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to update payslip information'];
    }
}

// Main request handler
try {
    // Initialize tables
    initializePayslipTables($conn);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    // Get input data
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = [];
    }

    switch ($action) {
        case 'generate_payslip':
            if ($method === 'POST') {
                $employee_id = $input['employee_id'] ?? null;
                $salary_month = $input['salary_month'] ?? null;

                if (!$employee_id || !$salary_month) {
                    echo json_encode(['success' => false, 'message' => 'Employee ID and salary month are required']);
                    exit;
                }

                $result = generatePayslipData($conn, $employee_id, $salary_month);
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'get_payslip_info':
            if ($method === 'GET') {
                $employee_id = $_GET['employee_id'] ?? null;
                if ($employee_id) {
                    $payslip_info = getEmployeePayslipInfo($conn, $employee_id);
                    if ($payslip_info) {
                        echo json_encode(['success' => true, 'payslip_info' => $payslip_info]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Payslip information not found']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'update_payslip_info':
            if ($method === 'POST') {
                $result = updatePayslipInfo($conn, $input);
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'get_history':
            if ($method === 'GET') {
                $employee_id = $_GET['employee_id'] ?? null;
                if ($employee_id) {
                    $result = getPayslipHistory($conn, $employee_id);
                    echo json_encode($result);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'get_available_months':
            if ($method === 'GET') {
                $employee_id = $_GET['employee_id'] ?? null;
                if ($employee_id) {
                    $result = getAvailableMonths($conn, $employee_id);
                    echo json_encode($result);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        default:
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid action',
                'available_actions' => [
                    'get_payslip_info',
                    'update_payslip_info',
                    'generate_payslip',
                    'get_history', 
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