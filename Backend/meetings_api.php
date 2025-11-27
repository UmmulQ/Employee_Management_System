<?php
// Backend/meetings_api.php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session and check authentication
session_start();

// Database connection
require_once 'connect.php';

// Function to send JSON response
function sendResponse($success, $message = '', $data = null) {
    $response = ['success' => $success];
    if ($message) $response['message'] = $message;
    if ($data) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

// Function to get authenticated user ID
function getAuthenticatedUserId() {
    // Check if user is logged in via session
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    
    // Alternative: Check if user_id is passed in request (for testing)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['user_id'])) {
        return (int)$_GET['user_id'];
    }
    
    // For POST requests, check JSON input
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['user_id'])) {
            return (int)$input['user_id']; // Fixed: $input instead of $_input
        }
    }
    
    return 0;
}

// Get the request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet();
            break;
        case 'POST':
            handlePost();
            break;
        case 'DELETE':
            handleDelete();
            break;
        default:
            http_response_code(405);
            sendResponse(false, 'Method not allowed');
    }
} catch (Exception $e) {
    http_response_code(500);
    sendResponse(false, 'Server error: ' . $e->getMessage());
}

function handleGet() {
    global $conn;
    
    $userId = getAuthenticatedUserId();
    
    if ($userId === 0) {
        // For testing purposes, allow with user_id parameter
        if (isset($_GET['user_id'])) {
            $userId = (int)$_GET['user_id'];
        } else {
            sendResponse(false, 'User not authenticated. Please log in.');
            return; // Added return to stop execution
        }
    }
    
    try {
        // Create table if not exists
        createTablesIfNotExists();
        
        $query = "SELECT * FROM meetings WHERE created_by = ? ORDER BY meeting_date, start_time";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $meetings = [];
        while ($row = $result->fetch_assoc()) {
            $meetings[] = $row;
        }
        
        sendResponse(true, 'Meetings fetched successfully', $meetings);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error fetching meetings: ' . $e->getMessage());
    }
}

function handlePost() {
    global $conn;
    
    $userId = getAuthenticatedUserId();
    
    if ($userId === 0) {
        sendResponse(false, 'User not authenticated. Please log in.');
        return; // Added return to stop execution
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendResponse(false, 'Invalid JSON input');
        return; // Added return to stop execution
    }
    
    // Validate required fields
    $required = ['title', 'meeting_date', 'start_time', 'meet_link'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            sendResponse(false, "Missing required field: $field");
            return; // Added return to stop execution
        }
    }
    
    try {
        // Create table if not exists
        createTablesIfNotExists();
        
        // Set default values
        $subject = $input['subject'] ?? '';
        $description = $input['description'] ?? '';
        $participants = $input['participants'] ?? '';
        $priority = $input['priority'] ?? 'medium';
        $duration = isset($input['duration']) ? intval($input['duration']) : 30;
        
        // Calculate end time
        $startDateTime = strtotime($input['start_time']);
        if ($startDateTime === false) {
            sendResponse(false, 'Invalid start time format');
            return;
        }
        $endTime = date('H:i:s', $startDateTime + ($duration * 60));
        
        // Insert meeting
        $query = "INSERT INTO meetings (
            title, subject, description, meeting_date, start_time, 
            duration, end_time, meet_link, participants, priority, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param(
            "sssssissssi",
            $input['title'],
            $subject,
            $description,
            $input['meeting_date'],
            $input['start_time'],
            $duration,
            $endTime,
            $input['meet_link'],
            $participants,
            $priority,
            $userId
        );
        
        if ($stmt->execute()) {
            $meetingId = $stmt->insert_id;
            
            // Add participants to meeting_participants table
            if (!empty($participants)) {
                addParticipantsToMeeting($meetingId, $participants);
            }
            
            sendResponse(true, 'Meeting scheduled successfully', ['meeting_id' => $meetingId]);
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Database error: ' . $e->getMessage()); // Fixed: removed extra "
    }
}

function handleDelete() {
    global $conn;
    
    $userId = getAuthenticatedUserId();
    
    if ($userId === 0) {
        sendResponse(false, 'User not authenticated. Please log in.');
        return; // Added return to stop execution
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meeting_id'])) {
        sendResponse(false, 'Missing meeting_id');
        return; // Added return to stop execution
    }
    
    try {
        // Check if meeting belongs to user
        $checkQuery = "SELECT meeting_id FROM meetings WHERE meeting_id = ? AND created_by = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("ii", $input['meeting_id'], $userId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            sendResponse(false, 'Meeting not found or access denied');
            return; // Added return to stop execution
        }
        
        $query = "DELETE FROM meetings WHERE meeting_id = ? AND created_by = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ii", $input['meeting_id'], $userId);
        
        if ($stmt->execute()) {
            sendResponse(true, 'Meeting deleted successfully');
        } else {
            throw new Exception("Delete failed: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error: ' . $e->getMessage()); // Fixed: removed extra "
    }
}

function addParticipantsToMeeting($meetingId, $participants) {
    global $conn;
    
    $emails = array_map('trim', explode(',', $participants));
    
    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $conn->prepare("INSERT INTO meeting_participants (meeting_id, email) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param("is", $meetingId, $email);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

function checkUserExists($userId) {
    global $conn;
    
    $query = "SELECT user_id FROM profiles WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    
    return $exists;
}

function createTablesIfNotExists() {
    global $conn;
    
    // Create meetings table without foreign key constraint to avoid issues
    $meetingsTable = "CREATE TABLE IF NOT EXISTS meetings (
        meeting_id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        subject VARCHAR(500),
        description TEXT,
        meeting_date DATE NOT NULL,
        start_time TIME NOT NULL,
        duration INT NOT NULL,
        end_time TIME NOT NULL,
        meet_link VARCHAR(500) NOT NULL,
        participants TEXT,
        priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
        status ENUM('scheduled', 'cancelled', 'completed') DEFAULT 'scheduled',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if (!$conn->query($meetingsTable)) {
        throw new Exception("Meetings table creation failed: " . $conn->error);
    }
    
    // Create meeting_participants table
    $participantsTable = "CREATE TABLE IF NOT EXISTS meeting_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_id INT NOT NULL,
        user_id INT NULL,
        email VARCHAR(255) NOT NULL,
        status ENUM('invited', 'accepted', 'declined') DEFAULT 'invited',
        invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    if (!$conn->query($participantsTable)) {
        // If table creation fails, it's not critical, so we just log it
        error_log("Meeting participants table creation failed: " . $conn->error);
    }
}

// Don't close connection here as it's already closed in individual functions
?>