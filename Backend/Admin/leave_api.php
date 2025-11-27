<?php
// Enable CORS for React frontend
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Database configuration
$host = "localhost";
$user = "root";
$pass = "";
$db   = "u950794707_ems";

// Create connection
$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8
$conn->set_charset("utf8");

// Response helper function
function sendResponse($success, $data = null, $message = '') {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        handleGetRequest();
    } else if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        handlePostRequest($input);
    } else {
        sendResponse(false, null, 'Method not allowed');
    }
} catch (Exception $e) {
    sendResponse(false, null, 'Server error: ' . $e->getMessage());
}

function handleGetRequest() {
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        switch ($action) {
            case 'get_all_leaves':
                getAllLeaves();
                break;
            case 'get_leave_stats':
                getLeaveStats();
                break;
            case 'get_leave_types':
                getLeaveTypes();
                break;
            default:
                sendResponse(false, null, 'Unknown action');
        }
    } else {
        getAllLeaves();
    }
}

function handlePostRequest($input) {
    if (!isset($input['action'])) {
        sendResponse(false, null, 'Action parameter required');
    }

    $action = $input['action'];
    switch ($action) {
        case 'update_leave_status':
            if (isset($input['leave_id']) && isset($input['status'])) {
                updateLeaveStatus($input['leave_id'], $input['status'], $input['approved_by'] ?? null);
            } else {
                sendResponse(false, null, 'Leave ID and status required');
            }
            break;
        default:
            sendResponse(false, null, 'Unknown action');
    }
}

// Get all leaves with filters - SIMPLIFIED VERSION
function getAllLeaves() {
    global $conn;
    
    $status = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';
    
    $query = "
        SELECT 
            l.leave_id,
            l.employee_id,
            lt.name as leave_type_name,
            lt.leave_type_id,
            l.start_date,
            l.end_date,
            l.reason,
            l.status,
            l.approved_by,
            l.applied_on,
            DATEDIFF(l.end_date, l.start_date) + 1 as days
        FROM leaves l
        LEFT JOIN leave_types lt ON l.leave_type_id = lt.leave_type_id
        WHERE 1=1
    ";
    
    $params = [];
    $types = "";
    
    if ($status !== 'all') {
        $query .= " AND l.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if (!empty($search)) {
        $query .= " AND (lt.name LIKE ? OR l.reason LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ss";
    }
    
    $query .= " ORDER BY l.applied_on DESC";
    
    $stmt = $conn->prepare($query);
    if ($types && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $leaves = [];
    while ($row = $result->fetch_assoc()) {
        $leaves[] = $row;
    }
    
    sendResponse(true, $leaves);
}

// Update leave status
function updateLeaveStatus($leave_id, $status, $approved_by = null) {
    global $conn;
    
    $valid_statuses = ['approved', 'rejected', 'pending'];
    if (!in_array($status, $valid_statuses)) {
        sendResponse(false, null, 'Invalid status');
    }
    
    if ($approved_by) {
        $stmt = $conn->prepare("
            UPDATE leaves 
            SET status = ?, approved_by = ?, approved_on = NOW() 
            WHERE leave_id = ?
        ");
        $stmt->bind_param("sii", $status, $approved_by, $leave_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE leaves 
            SET status = ? 
            WHERE leave_id = ?
        ");
        $stmt->bind_param("si", $status, $leave_id);
    }
    
    if ($stmt->execute()) {
        sendResponse(true, null, "Leave status updated successfully");
    } else {
        sendResponse(false, null, 'Failed to update leave status');
    }
}

// Get leave statistics
function getLeaveStats() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM leaves
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    
    sendResponse(true, $stats);
}

// Get all leave types
function getLeaveTypes() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT leave_type_id, name, default_days, description 
        FROM leave_types 
        ORDER BY name
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $leave_types = [];
    while ($row = $result->fetch_assoc()) {
        $leave_types[] = $row;
    }
    
    sendResponse(true, $leave_types);
}
?>