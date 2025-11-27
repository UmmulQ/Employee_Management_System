<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "connect.php";

if (empty($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

// Check if user is checked in
if (!isset($_SESSION['checked_in']) || $_SESSION['checked_in'] !== true) {
    echo json_encode(["success" => false, "message" => "Please check in first before taking a break"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? ''; // 'start' or 'end'

if (!in_array($action, ['start', 'end'])) {
    echo json_encode(["success" => false, "message" => "Invalid action"]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    // Get employee_id
    $query = "SELECT employee_id FROM employees WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || !$row = $result->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "Employee profile not found"]);
        exit;
    }
    
    $employee_id = (int) $row['employee_id'];
    $stmt->close();

    // Additional validation for break actions
    if ($action === 'start') {
        // Check if already on break
        if (isset($_SESSION['on_break']) && $_SESSION['on_break'] === true) {
            echo json_encode(["success" => false, "message" => "You are already on break"]);
            exit;
        }
        $activity_type = "BREAK START";
        $description = "Started break";
        $_SESSION['on_break'] = true;
    } else {
        // Check if not on break when trying to end break
        if (!isset($_SESSION['on_break']) || $_SESSION['on_break'] !== true) {
            echo json_encode(["success" => false, "message" => "You are not currently on break"]);
            exit;
        }
        $activity_type = "BREAK END";
        $description = "Ended break";
        $_SESSION['on_break'] = false;
    }
    
    $activity_time = date("Y-m-d H:i:s");
    
    $insert_query = "INSERT INTO employee_activity (employee_id, activity_type, description, activity_time) VALUES (?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_query);
    $insert_stmt->bind_param("isss", $employee_id, $activity_type, $description, $activity_time);
    
    if ($insert_stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => ucfirst($action) . " break successfully",
            "activity_time" => $activity_time,
            "activity_type" => $activity_type
        ]);
    } else {
        throw new Exception("Insert failed: " . $insert_stmt->error);
    }
    
    $insert_stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "❌ Error: " . $e->getMessage()
    ]);
}
?>