<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "connect.php";

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication required"]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    $sql = "
        SELECT 
            u.user_id, u.username, u.created_at, r.role_name,

            -- Profile table
            p.first_name, p.last_name, p.email, p.phone, 
            p.profile_picture_url, p.date_of_birth, p.address,

            -- Employees table
            e.employee_id, e.employee_number, e.department, e.position, 
            e.salary, e.date_hired, e.manager_id,
            e.job_start_time, e.job_end_time, e.working_days

        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN profiles p ON u.user_id = p.user_id
        LEFT JOIN employees e ON u.user_id = e.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Format date fields for frontend
        if (!empty($row['date_hired'])) {
            $row['date_hired'] = date('Y-m-d', strtotime($row['date_hired']));
        }
        if (!empty($row['date_of_birth'])) {
            $row['date_of_birth'] = date('Y-m-d', strtotime($row['date_of_birth']));
        }
        
        // Handle profile picture URL
        if (!empty($row['profile_picture_url'])) {
            if (strpos($row['profile_picture_url'], 'http') === 0) {
                // URL is already absolute
            } else {
                // Make relative path absolute
                $base_url = "http://localhost/EMS/Backend/";
                $row['profile_picture_url'] = $base_url . ltrim($row['profile_picture_url'], '/');
            }
        } else {
            // Default avatar
            $row['profile_picture_url'] = "https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=150&h=150&fit=crop&crop=face";
        }
        
        // Ensure working days is properly formatted
        if (!empty($row['working_days'])) {
            $row['working_days'] = is_array($row['working_days']) ? 
                $row['working_days'] : 
                explode(',', $row['working_days']);
        } else {
            $row['working_days'] = [];
        }

        echo json_encode([
            "success" => true, 
            "profile" => $row,
            "debug" => [
                "user_id" => $user_id,
                "has_employee_data" => !empty($row['employee_id']),
                "has_profile_data" => !empty($row['first_name'])
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false, 
            "message" => "User profile not found",
            "debug" => ["user_id" => $user_id]
        ]);
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Database error: " . $e->getMessage()
    ]);
}

$conn->close();
?>