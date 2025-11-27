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

$user_id = (int) $_SESSION['user_id'];
$current_time = date("Y-m-d H:i:s");

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

    // Insert check-in activity
    $activity_type = "CHECK-IN";
    $description = "Checked in at " . $current_time;
    
    $insert_query = "INSERT INTO employee_activity (employee_id, activity_type, description, activity_time) 
                    VALUES (?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_query);
    $insert_stmt->bind_param("isss", $employee_id, $activity_type, $description, $current_time);
    
    if ($insert_stmt->execute()) {
        // Update session variables
        $_SESSION['checked_in'] = true;
        $_SESSION['on_break'] = false;
        $_SESSION['last_activity'] = $current_time;
        $_SESSION['check_in_time'] = $current_time;
        
        echo json_encode([
            "success" => true,
            "message" => "Checked in successfully",
            "check_in_time" => $current_time,
            "activity_type" => $activity_type,
            "inserted_id" => $insert_stmt->insert_id
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