<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
session_start();

// Database configuration - update with your actual credentials
$host = "localhost";
$username = "root";
$password = "";
$database = "u950794707_ems";

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Database connection failed: " . $conn->connect_error]));
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';

// Build query based on filter
$query = "SELECT * FROM employee_daily_summary WHERE 1=1";

switch ($filter) {
    case 'today':
        $query .= " AND work_date = CURDATE()";
        break;
    case 'week':
        $query .= " AND YEARWEEK(work_date, 1) = YEARWEEK(CURDATE(), 1)";
        break;
    case 'month':
        $query .= " AND YEAR(work_date) = YEAR(CURDATE()) AND MONTH(work_date) = MONTH(CURDATE())";
        break;
    case 'year':
        $query .= " AND YEAR(work_date) = YEAR(CURDATE())";
        break;
    // 'all' case - no additional conditions
}

$query .= " ORDER BY work_date DESC, employee_id";

$result = $conn->query($query);

if ($result) {
    $summary = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $summary[] = $row;
        }
    }
    
    echo json_encode([
        "success" => true,
        "summary" => $summary,
        "total" => count($summary),
        "message" => count($summary) . " records found"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Error fetching summary: " . $conn->error
    ]);
}

$conn->close();
?>