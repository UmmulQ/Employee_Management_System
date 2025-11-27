<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Database connection
$host = 'localhost';
$dbname = 'u950794707_ems';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_employee_leaves':
            getEmployeeLeaves($pdo);
            break;
        case 'get_leave_types':
            getLeaveTypes($pdo);
            break;
        case 'apply_leave':
            applyLeave($pdo);
            break;
        case 'cancel_leave':
            cancelLeave($pdo);
            break;
        case 'add_leave_type':
            addLeaveType($pdo);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function getEmployeeLeaves($pdo) {
    $employee_id = $_GET['employee_id'] ?? null;
    if (!$employee_id) {
        echo json_encode(['success' => false, 'message' => 'Employee ID required']);
        return;
    }

    try {
        // Get employee leaves with leave type names
        $stmt = $pdo->prepare("
            SELECT l.*, lt.name as leave_type_name 
            FROM leaves l 
            LEFT JOIN leave_types lt ON l.leave_type_id = lt.leave_type_id 
            WHERE l.employee_id = ? 
            ORDER BY l.applied_on DESC
        ");
        $stmt->execute([$employee_id]);
        $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate leave balance
        $balanceStmt = $pdo->prepare("
            SELECT lt.name, lt.default_days,
                   COALESCE(SUM(
                       CASE 
                           WHEN l.status = 'approved' THEN 
                               DATEDIFF(l.end_date, l.start_date) + 1
                           ELSE 0 
                       END
                   ), 0) as used_days
            FROM leave_types lt
            LEFT JOIN leaves l ON lt.leave_type_id = l.leave_type_id AND l.employee_id = ?
            GROUP BY lt.leave_type_id, lt.name, lt.default_days
        ");
        $balanceStmt->execute([$employee_id]);
        $balances = $balanceStmt->fetchAll(PDO::FETCH_ASSOC);

        $leaveBalance = [];
        foreach ($balances as $balance) {
            $key = strtolower(str_replace(' ', '_', $balance['name']));
            $leaveBalance[$key] = $balance['default_days'] - $balance['used_days'];
        }

        echo json_encode([
            'success' => true,
            'leaves' => $leaves,
            'leaveBalance' => $leaveBalance
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getLeaveTypes($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM leave_types ORDER BY name");
        $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'leave_types' => $leave_types
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function applyLeave($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        return;
    }
    
    $employee_id = $input['employee_id'] ?? null;
    $leave_type_id = $input['leave_type_id'] ?? null;
    $start_date = $input['start_date'] ?? null;
    $end_date = $input['end_date'] ?? null;
    $reason = $input['reason'] ?? null;

    if (!$employee_id || !$leave_type_id || !$start_date || !$end_date || !$reason) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO leaves (employee_id, leave_type_id, start_date, end_date, reason, status, applied_on) 
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $success = $stmt->execute([$employee_id, $leave_type_id, $start_date, $end_date, $reason]);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Leave applied successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to apply leave']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function cancelLeave($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        return;
    }
    
    $leave_id = $input['leave_id'] ?? null;
    $employee_id = $input['employee_id'] ?? null;

    if (!$leave_id || !$employee_id) {
        echo json_encode(['success' => false, 'message' => 'Leave ID and Employee ID required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM leaves 
            WHERE leave_id = ? AND employee_id = ? AND status = 'pending'
        ");
        
        $stmt->execute([$leave_id, $employee_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Leave cancelled successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Leave not found or cannot be cancelled']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function addLeaveType($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        return;
    }
    
    $name = $input['name'] ?? null;
    $default_days = $input['default_days'] ?? null;

    if (!$name || !$default_days) {
        echo json_encode(['success' => false, 'message' => 'Name and default days are required']);
        return;
    }

    // Validate name is not empty
    $name = trim($name);
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Leave type name cannot be empty']);
        return;
    }

    // Validate default_days is a positive number
    if (!is_numeric($default_days) || $default_days < 0) {
        echo json_encode(['success' => false, 'message' => 'Default days must be a positive number']);
        return;
    }

    try {
        // Check if leave type already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_types WHERE name = ?");
        $checkStmt->execute([$name]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            echo json_encode(['success' => false, 'message' => 'Leave type already exists']);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO leave_types (name, default_days) 
            VALUES (?, ?)
        ");
        
        $success = $stmt->execute([$name, $default_days]);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Leave type added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add leave type']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>