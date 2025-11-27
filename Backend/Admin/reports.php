<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include config file
require_once __DIR__ . '/config.php';

class ReportManagement {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    // Get all daily summaries with user details
    public function getDailySummaries() {
        try {
            $query = "SELECT 
                        ds.summary_id,
                        ds.employee_id,
                        ds.work_date,
                        ds.active_minutes,
                        ds.idle_minutes,
                        ds.total_screenshots,
                        u.username
                      FROM employee_daily_summary ds
                      LEFT JOIN users u ON ds.employee_id = u.user_id
                      ORDER BY ds.work_date DESC, u.username ASC";
            
            $result = $this->conn->query($query);
            
            if (!$result) {
                return ['error' => 'Database query failed: ' . $this->conn->error];
            }
            
            $summaries = [];
            while ($row = $result->fetch_assoc()) {
                $summaries[] = $row;
            }
            
            return $summaries;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get daily summary by ID
    public function getSummaryById($summary_id) {
        try {
            $query = "SELECT 
                        ds.*,
                        u.username,
                        u.email
                      FROM employee_daily_summary ds
                      LEFT JOIN users u ON ds.employee_id = u.user_id
                      WHERE ds.summary_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $summary_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get summaries by employee ID
    public function getSummariesByEmployee($employee_id) {
        try {
            $query = "SELECT 
                        ds.*,
                        u.username,
                        u.email
                      FROM employee_daily_summary ds
                      LEFT JOIN users u ON ds.employee_id = u.user_id
                      WHERE ds.employee_id = ?
                      ORDER BY ds.work_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $summaries = [];
            while ($row = $result->fetch_assoc()) {
                $summaries[] = $row;
            }
            
            return $summaries;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get summaries by date range
    public function getSummariesByDateRange($start_date, $end_date) {
        try {
            $query = "SELECT 
                        ds.*,
                        u.username,
                        u.email
                      FROM employee_daily_summary ds
                      LEFT JOIN users u ON ds.employee_id = u.user_id
                      WHERE ds.work_date BETWEEN ? AND ?
                      ORDER BY ds.work_date DESC, u.username ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $summaries = [];
            while ($row = $result->fetch_assoc()) {
                $summaries[] = $row;
            }
            
            return $summaries;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get productivity statistics
    public function getProductivityStats($employee_id = null, $start_date = null, $end_date = null) {
        try {
            $query = "SELECT 
                        ds.employee_id,
                        u.username,
                        COUNT(ds.summary_id) as total_days,
                        SUM(ds.active_minutes) as total_active_minutes,
                        SUM(ds.idle_minutes) as total_idle_minutes,
                        SUM(ds.total_screenshots) as total_screenshots,
                        AVG(ds.active_minutes / (ds.active_minutes + ds.idle_minutes) * 100) as avg_productivity
                      FROM employee_daily_summary ds
                      LEFT JOIN users u ON ds.employee_id = u.user_id
                      WHERE 1=1";
            
            $params = [];
            $types = "";
            
            if ($employee_id) {
                $query .= " AND ds.employee_id = ?";
                $params[] = $employee_id;
                $types .= "i";
            }
            
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
            
            $query .= " GROUP BY ds.employee_id, u.username
                       ORDER BY avg_productivity DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $stats = [];
            while ($row = $result->fetch_assoc()) {
                $row['avg_productivity'] = round($row['avg_productivity'], 2);
                $stats[] = $row;
            }
            
            return $stats;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Generate CSV report
    public function generateCSVReport($employee_id = null, $start_date = null, $end_date = null) {
        try {
            $query = "SELECT 
                        u.username,
                        ds.work_date,
                        ds.active_minutes,
                        ds.idle_minutes,
                        ds.total_screenshots,
                        ROUND((ds.active_minutes / (ds.active_minutes + ds.idle_minutes)) * 100, 2) as productivity
                      FROM employee_daily_summary ds
                      LEFT JOIN users u ON ds.employee_id = u.user_id
                      WHERE 1=1";
            
            $params = [];
            $types = "";
            
            if ($employee_id) {
                $query .= " AND ds.employee_id = ?";
                $params[] = $employee_id;
                $types .= "i";
            }
            
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
            
            $query .= " ORDER BY ds.work_date DESC, u.username ASC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $csv_data = [];
            $csv_data[] = ['Employee', 'Date', 'Active Minutes', 'Idle Minutes', 'Total Minutes', 'Screenshots', 'Productivity %'];
            
            while ($row = $result->fetch_assoc()) {
                $total_minutes = $row['active_minutes'] + $row['idle_minutes'];
                $csv_data[] = [
                    $row['username'],
                    $row['work_date'],
                    $row['active_minutes'],
                    $row['idle_minutes'],
                    $total_minutes,
                    $row['total_screenshots'],
                    $row['productivity']
                ];
            }
            
            return $csv_data;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

$reportManager = new ReportManagement($conn);
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

switch ($method) {
    case 'GET':
        if (isset($query_params['employee_id'])) {
            // Get summaries for specific employee
            $result = $reportManager->getSummariesByEmployee($query_params['employee_id']);
        } elseif (isset($query_params['start_date']) && isset($query_params['end_date'])) {
            // Get summaries by date range
            $result = $reportManager->getSummariesByDateRange($query_params['start_date'], $query_params['end_date']);
        } elseif (isset($query_params['stats'])) {
            // Get productivity statistics
            $employee_id = $query_params['employee_id'] ?? null;
            $start_date = $query_params['start_date'] ?? null;
            $end_date = $query_params['end_date'] ?? null;
            $result = $reportManager->getProductivityStats($employee_id, $start_date, $end_date);
        } elseif (isset($query_params['csv'])) {
            // Generate CSV data
            $employee_id = $query_params['employee_id'] ?? null;
            $start_date = $query_params['start_date'] ?? null;
            $end_date = $query_params['end_date'] ?? null;
            $result = $reportManager->generateCSVReport($employee_id, $start_date, $end_date);
        } elseif (isset($query_params['summary_id'])) {
            // Get specific summary
            $result = $reportManager->getSummaryById($query_params['summary_id']);
        } else {
            // Get all summaries
            $result = $reportManager->getDailySummaries();
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, 'Data retrieved successfully');
        }
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (isset($input['action'])) {
            switch ($input['action']) {
                case 'generate_csv':
                    $employee_id = $input['employee_id'] ?? null;
                    $start_date = $input['start_date'] ?? null;
                    $end_date = $input['end_date'] ?? null;
                    $result = $reportManager->generateCSVReport($employee_id, $start_date, $end_date);
                    
                    if (isset($result['error'])) {
                        sendResponse(false, null, $result['error']);
                    } else {
                        // Return CSV data for download
                        header('Content-Type: text/csv');
                        header('Content-Disposition: attachment; filename="employee_reports_' . date('Y-m-d') . '.csv"');
                        
                        $output = fopen('php://output', 'w');
                        foreach ($result as $row) {
                            fputcsv($output, $row);
                        }
                        fclose($output);
                        exit;
                    }
                    break;
                    
                default:
                    sendResponse(false, null, 'Invalid action');
                    break;
            }
        } else {
            sendResponse(false, null, 'Action parameter required');
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