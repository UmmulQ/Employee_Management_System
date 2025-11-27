<?php
// AttendanceReportTL.php
require_once 'connect.php';

class AttendanceReport {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    public function handleRequest() {
        try {
            $action = $_GET['action'] ?? 'get_attendance';
            
            switch($action) {
                case 'get_attendance':
                    $this->getAttendanceData();
                    break;
                case 'test':
                    $this->testConnection();
                    break;
                default:
                    $this->sendError('Invalid action specified');
            }
        } catch (Exception $e) {
            $this->sendError('Server error: ' . $e->getMessage());
        }
    }
    
    private function testConnection() {
        $this->sendSuccess('API is working!', [
            'timestamp' => date('Y-m-d H:i:s'),
            'server_info' => [
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
            ]
        ]);
    }
    
    private function getAttendanceData() {
        $filter = $this->conn->real_escape_string($_GET['filter'] ?? 'all');
        
        // Build WHERE clause
        $whereClause = $this->buildWhereClause($filter);
        
        // Main query with better error handling
        $query = "
            SELECT 
                ea.activity_id,
                ea.employee_id,
                ea.activity_type,
                ea.description,
                ea.activity_time,
                ea.duration_minutes,
                ea.log,
                e.department,
                e.position,
                COALESCE(p.first_name, 'Unknown') as first_name,
                COALESCE(p.last_name, 'User') as last_name
            FROM employee_activity ea
            LEFT JOIN employees e ON ea.employee_id = e.employee_id
            LEFT JOIN users u ON e.user_id = u.user_id
            LEFT JOIN profiles p ON u.user_id = p.user_id
            WHERE 1=1 $whereClause
            ORDER BY ea.activity_time DESC
            LIMIT 500
        ";
        
        $result = $this->conn->query($query);
        
        if (!$result) {
            throw new Exception("Query failed: " . $this->conn->error);
        }
        
        $attendance = [];
        while ($row = $result->fetch_assoc()) {
            $attendance[] = $this->formatRecord($row);
        }
        
        $this->sendSuccess('Attendance data retrieved successfully', [
            'attendance' => $attendance,
            'total_records' => count($attendance),
            'filter_applied' => $filter,
            'query_info' => [
                'has_data' => !empty($attendance),
                'first_record' => !empty($attendance) ? $attendance[0]['activity_time'] : null,
                'last_record' => !empty($attendance) ? end($attendance)['activity_time'] : null
            ]
        ]);
    }
    
    private function buildWhereClause($filter) {
        switch($filter) {
            case 'today':
                return " AND DATE(ea.activity_time) = CURDATE()";
            case 'week':
                return " AND ea.activity_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            case 'month':
                return " AND YEAR(ea.activity_time) = YEAR(CURDATE()) AND MONTH(ea.activity_time) = MONTH(CURDATE())";
            case 'year':
                return " AND YEAR(ea.activity_time) = YEAR(CURDATE())";
            case 'all':
            default:
                return "";
        }
    }
    
    private function formatRecord($row) {
        return [
            'activity_id' => (int)$row['activity_id'],
            'employee_id' => (int)$row['employee_id'],
            'employee_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'activity_type' => $row['activity_type'] ?? 'Unknown',
            'description' => $row['description'] ?? '',
            'activity_time' => $row['activity_time'],
            'duration_minutes' => $row['duration_minutes'] ? (int)$row['duration_minutes'] : null,
            'log' => $row['log'] ?? '',
            'department' => $row['department'] ?? 'Unknown',
            'position' => $row['position'] ?? 'Unknown'
        ];
    }
    
    private function sendSuccess($message, $data = []) {
        $response = [
            'success' => true,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if (!empty($data)) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    private function sendError($message) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'error_details' => $this->conn->error ?? 'No database error'
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize and process request
try {
    $attendanceReport = new AttendanceReport($conn);
    $attendanceReport->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to initialize: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}
?>