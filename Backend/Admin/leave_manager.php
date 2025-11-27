<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include config file
require_once __DIR__ . '/config.php';

class LeaveManagement {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }

    // Get all leave applications with employee details
    public function getLeaveApplications($filters = []) {
        try {
            $whereConditions = [];
            $params = [];
            $types = "";

            // Build WHERE conditions based on filters
            if (!empty($filters['status'])) {
                $whereConditions[] = "l.status = ?";
                $params[] = $filters['status'];
                $types .= "s";
            }

            if (!empty($filters['leave_type'])) {
                $whereConditions[] = "l.leave_type = ?";
                $params[] = $filters['leave_type'];
                $types .= "s";
            }

            if (!empty($filters['department'])) {
                $whereConditions[] = "e.department = ?";
                $params[] = $filters['department'];
                $types .= "s";
            }

            if (!empty($filters['start_date'])) {
                $whereConditions[] = "l.start_date >= ?";
                $params[] = $filters['start_date'];
                $types .= "s";
            }

            if (!empty($filters['end_date'])) {
                $whereConditions[] = "l.end_date <= ?";
                $params[] = $filters['end_date'];
                $types .= "s";
            }

            $whereClause = "";
            if (!empty($whereConditions)) {
                $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            }

            $query = "SELECT 
                        l.*,
                        e.employee_id,
                        e.employee_number,
                        e.department,
                        e.position,
                        p.first_name,
                        p.last_name,
                        p.email,
                        p.phone,
                        a.first_name as approved_by_first_name,
                        a.last_name as approved_by_last_name,
                        DATEDIFF(l.end_date, l.start_date) + 1 as total_days
                      FROM leaves l
                      LEFT JOIN employees e ON l.employee_id = e.employee_id
                      LEFT JOIN profiles p ON e.user_id = p.user_id
                      LEFT JOIN employees ae ON l.approved_by = ae.employee_id
                      LEFT JOIN profiles a ON ae.user_id = a.user_id
                      $whereClause
                    --   ORDER BY l.created_at DESC";

            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
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

    // Get leave application by ID
    public function getLeaveById($leave_id) {
        try {
            $query = "SELECT 
                        l.*,
                        e.employee_id,
                        e.employee_number,
                        e.department,
                        e.position,
                        p.first_name,
                        p.last_name,
                        p.email,
                        p.phone,
                        a.first_name as approved_by_first_name,
                        a.last_name as approved_by_last_name,
                        DATEDIFF(l.end_date, l.start_date) + 1 as total_days
                      FROM leaves l
                      LEFT JOIN employees e ON l.employee_id = e.employee_id
                      LEFT JOIN profiles p ON e.user_id = p.user_id
                      LEFT JOIN employees ae ON l.approved_by = ae.employee_id
                      LEFT JOIN profiles a ON ae.user_id = a.user_id
                      WHERE l.leave_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $leave_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Update leave status (approve/reject)
    public function updateLeaveStatus($data) {
        try {
            if (empty($data['leave_id']) || empty($data['status']) || empty($data['approved_by'])) {
                return ['error' => 'Leave ID, status, and approver are required'];
            }

            // Check if leave exists
            $existingLeave = $this->getLeaveById($data['leave_id']);
            if (!$existingLeave) {
                return ['error' => 'Leave application not found'];
            }

            // Check if leave is already processed
            if ($existingLeave['status'] !== 'Pending') {
                return ['error' => 'Leave application has already been processed'];
            }

            $query = "UPDATE leaves SET 
                      status = ?,
                      approved_by = ?,
                      approval_notes = ?,
                      approved_at = NOW()
                      WHERE leave_id = ?";
            
            $stmt = $this->conn->prepare($query);
            
            $approval_notes = $data['approval_notes'] ?? '';
            
            $stmt->bind_param(
                "sisi",
                $data['status'],
                $data['approved_by'],
                $approval_notes,
                $data['leave_id']
            );
            
            if ($stmt->execute()) {
                return [
                    'success' => 'Leave application ' . strtolower($data['status']) . ' successfully',
                    'leave' => $this->getLeaveById($data['leave_id'])
                ];
            } else {
                return ['error' => 'Failed to update leave status: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get leave statistics
    public function getLeaveStats() {
        try {
            $query = "SELECT 
                        COUNT(*) as total_leaves,
                        COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_leaves,
                        COUNT(CASE WHEN status = 'Approved' THEN 1 END) as approved_leaves,
                        COUNT(CASE WHEN status = 'Rejected' THEN 1 END) as rejected_leaves,
                        COUNT(CASE WHEN leave_type = 'Sick Leave' THEN 1 END) as sick_leaves,
                        COUNT(CASE WHEN leave_type = 'Vacation' THEN 1 END) as vacation_leaves,
                        COUNT(CASE WHEN leave_type = 'Personal Leave' THEN 1 END) as personal_leaves
                      FROM leaves";
            
            $result = $this->conn->query($query);
            $stats = $result->fetch_assoc();

            // Get monthly leave trends (last 6 months)
            $trendQuery = "SELECT 
                            DATE_FORMAT(created_at, '%Y-%m') as month,
                            COUNT(*) as count,
                            COUNT(CASE WHEN status = 'Approved' THEN 1 END) as approved_count
                          FROM leaves 
                          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                          GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                          ORDER BY month DESC
                          LIMIT 6";
            
            $trendResult = $this->conn->query($trendQuery);
            $monthly_trends = [];
            while ($row = $trendResult->fetch_assoc()) {
                $monthly_trends[] = $row;
            }

            $stats['monthly_trends'] = array_reverse($monthly_trends);
            
            return $stats;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get departments list for filtering
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

    // Get leave types
    public function getLeaveTypes() {
        try {
            $query = "SELECT COLUMN_TYPE 
                      FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE TABLE_NAME = 'leaves' 
                      AND COLUMN_NAME = 'leave_type'";
            
            $result = $this->conn->query($query);
            $row = $result->fetch_assoc();
            
            preg_match("/^enum\(\'(.*)\'\)$/", $row['COLUMN_TYPE'], $matches);
            $types = explode("','", $matches[1]);
            
            return $types;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Bulk update leave status
    public function bulkUpdateLeaveStatus($data) {
        try {
            if (empty($data['leave_ids']) || empty($data['status']) || empty($data['approved_by'])) {
                return ['error' => 'Leave IDs, status, and approver are required'];
            }

            $leave_ids = implode(',', array_map('intval', $data['leave_ids']));
            
            $query = "UPDATE leaves SET 
                      status = ?,
                      approved_by = ?,
                      approval_notes = ?,
                      approved_at = NOW()
                      WHERE leave_id IN ($leave_ids) AND status = 'Pending'";
            
            $stmt = $this->conn->prepare($query);
            
            $approval_notes = $data['approval_notes'] ?? '';
            
            $stmt->bind_param(
                "sis",
                $data['status'],
                $data['approved_by'],
                $approval_notes
            );
            
            if ($stmt->execute()) {
                $affected_rows = $stmt->affected_rows;
                return [
                    'success' => "$affected_rows leave applications " . strtolower($data['status']) . ' successfully'
                ];
            } else {
                return ['error' => 'Failed to update leave status: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

$leaveManager = new LeaveManagement($conn);
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
            case 'get_leaves':
                $filters = [
                    'status' => $query_params['status'] ?? '',
                    'leave_type' => $query_params['leave_type'] ?? '',
                    'department' => $query_params['department'] ?? '',
                    'start_date' => $query_params['start_date'] ?? '',
                    'end_date' => $query_params['end_date'] ?? ''
                ];
                $result = $leaveManager->getLeaveApplications($filters);
                break;
                
            case 'get_leave':
                if (isset($query_params['leave_id'])) {
                    $result = $leaveManager->getLeaveById($query_params['leave_id']);
                } else {
                    $result = ['error' => 'Leave ID is required'];
                }
                break;
                
            case 'get_stats':
                $result = $leaveManager->getLeaveStats();
                break;
                
            case 'get_departments':
                $result = $leaveManager->getDepartments();
                break;
                
            case 'get_leave_types':
                $result = $leaveManager->getLeaveTypes();
                break;
                
            default:
                // Return leaves by default
                $filters = [
                    'status' => $query_params['status'] ?? '',
                    'leave_type' => $query_params['leave_type'] ?? '',
                    'department' => $query_params['department'] ?? '',
                    'start_date' => $query_params['start_date'] ?? '',
                    'end_date' => $query_params['end_date'] ?? ''
                ];
                $result = $leaveManager->getLeaveApplications($filters);
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
            case 'update_status':
                $result = $leaveManager->updateLeaveStatus($input);
                break;
                
            case 'bulk_update':
                $result = $leaveManager->bulkUpdateLeaveStatus($input);
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