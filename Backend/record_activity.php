<?php
// record_activity.php

// CORS headers
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$db   = "u950794707_ems";

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $conn->connect_error
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Log received data for debugging
    error_log("Received activity data: " . print_r($data, true));
    
    if ($data === null) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON data received",
            "raw_input" => $input
        ]);
        exit;
    }
    
    $employee_id = $data['employee_id'] ?? '';
    $activity_type = $data['activity_type'] ?? '';
    $description = $data['description'] ?? '';
    $duration_minutes = intval($data['duration_minutes'] ?? 0);
    $activity_time = $data['activity_time'] ?? date('Y-m-d H:i:s');
    
    // Create log entry
    $log = "Activity: " . $activity_type . " | Description: " . $description . " | Duration: " . $duration_minutes . " minutes";
    
    // Validate required fields
    if (empty($employee_id) || empty($activity_type)) {
        echo json_encode([
            "success" => false,
            "message" => "Missing required fields: employee_id or activity_type",
            "received_data" => $data
        ]);
        exit;
    }
    
    try {
        // Prepare and execute INSERT statement - INCLUDING LOG COLUMN
        $stmt = $conn->prepare(
            "INSERT INTO employee_activity 
            (employee_id, activity_type, description, activity_time, duration_minutes, log) 
            VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ssssis", 
            $employee_id, 
            $activity_type, 
            $description, 
            $activity_time, 
            $duration_minutes,
            $log
        );
        
        $success = $stmt->execute();
        
        if ($success) {
            $activity_id = $stmt->insert_id;
            
            echo json_encode([
                "success" => true,
                "message" => "Activity recorded successfully in database",
                "activity_id" => $activity_id,
                "inserted_data" => [
                    "employee_id" => $employee_id,
                    "activity_type" => $activity_type,
                    "description" => $description,
                    "duration_minutes" => $duration_minutes,
                    "activity_time" => $activity_time,
                    "log" => $log
                ]
            ]);
            
            // Log success
            error_log("Activity inserted successfully. ID: " . $activity_id);
            
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage(),
            "error_details" => $conn->error
        ]);
    }
    
} else {
    echo json_encode([
        "success" => false,
        "message" => "Only POST method allowed",
        "received_method" => $_SERVER['REQUEST_METHOD']
    ]);
}

$conn->close();
?>
