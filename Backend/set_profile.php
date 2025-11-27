<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
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
    echo json_encode(["success" => false, "message" => "Authentication required. Please log in."]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Debug: Log received data
error_log("=== PROFILE UPDATE REQUEST ===");
error_log("User ID: " . $user_id);
error_log("Received POST data: " . print_r($_POST, true));
error_log("Received FILES data: " . print_r($_FILES, true));

// Extract data with proper field names
$first_name    = $_POST['first_name'] ?? '';
$last_name     = $_POST['last_name'] ?? '';
$email         = $_POST['email'] ?? '';
$phone         = $_POST['phone'] ?? '';
$date_of_birth = $_POST['date_of_birth'] ?? '';
$address       = $_POST['address'] ?? '';
$profile_pic   = null;

$employee_number = $_POST['employee_number'] ?? '';
$department      = $_POST['department'] ?? '';
$position        = $_POST['position'] ?? '';
$salary          = isset($_POST['salary']) && $_POST['salary'] !== "" ? (float) $_POST['salary'] : null;
$date_hired      = $_POST['date_hired'] ?? '';
$manager_id      = isset($_POST['manager_id']) && $_POST['manager_id'] !== "" ? $_POST['manager_id'] : null; // Keep as string first
$job_start_time  = $_POST['job_start_time'] ?? '';
$job_end_time    = $_POST['job_end_time'] ?? '';
$working_days    = $_POST['working_days'] ?? '';

// Convert manager_id to integer if not empty
if (!empty($manager_id)) {
    $manager_id = (int) $manager_id;
} else {
    $manager_id = null;
}

// Debug received values
error_log("=== FORM DATA ===");
error_log("First Name: " . $first_name);
error_log("Last Name: " . $last_name);
error_log("Email: " . $email);
error_log("Employee Number: " . $employee_number);
error_log("Department: " . $department);
error_log("Position: " . $position);
error_log("Manager ID: " . ($manager_id ?? 'NULL'));
error_log("Working Days: " . $working_days);
error_log("Working Days Raw: " . $_POST['working_days'] ?? 'NOT SET');

// Simple validation - check if required fields are not empty
$errors = [];

if (empty(trim($first_name))) $errors[] = "First name is required.";
if (empty(trim($last_name))) $errors[] = "Last name is required.";
if (empty(trim($email))) $errors[] = "Email address is required.";
if (empty(trim($employee_number))) $errors[] = "Employee number is required.";
if (empty(trim($department))) $errors[] = "Department is required.";
if (empty(trim($position))) $errors[] = "Position is required.";
if (empty(trim($working_days))) $errors[] = "At least one working day must be selected.";

// Return validation errors
if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        "success" => false, 
        "message" => "Validation failed", 
        "errors" => $errors,
        "debug" => [
            "first_name" => $first_name,
            "last_name" => $last_name,
            "email" => $email,
            "employee_number" => $employee_number,
            "department" => $department,
            "position" => $position,
            "manager_id" => $manager_id,
            "working_days" => $working_days,
            "working_days_raw" => $_POST['working_days'] ?? 'NOT SET'
        ]
    ]);
    exit;
}

// Start database transaction
$conn->begin_transaction();

try {
    // ================= PROFILE PICTURE UPLOAD ================= //
    if (isset($_FILES['profile_picture_url']) && $_FILES['profile_picture_url']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = "uploads/profiles/";
        
        // Create upload directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileInfo = $_FILES['profile_picture_url'];
        $fileName = uniqid("profile_{$user_id}_") . '_' . time();
        $fileExtension = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        $targetPath = $uploadDir . $fileName . '.' . $fileExtension;

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $detectedType = mime_content_type($fileInfo['tmp_name']);
        
        if (!in_array($detectedType, $allowedTypes)) {
            throw new Exception("Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.");
        }

        // Move uploaded file
        if (move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
            $profile_pic = $targetPath;
        } else {
            throw new Exception("Failed to upload profile picture. Please try again.");
        }
    }

    // ================= PROFILE TABLE OPERATIONS ================= //
    $checkProfile = $conn->prepare("SELECT profile_id FROM profiles WHERE user_id = ?");
    $checkProfile->bind_param("i", $user_id);
    $checkProfile->execute();
    $checkProfile->store_result();

    $profileUpdated = false;
    
    if ($checkProfile->num_rows > 0) {
        // Update existing profile
        if ($profile_pic) {
            $stmtProfile = $conn->prepare("
                UPDATE profiles 
                SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                    date_of_birth = ?, address = ?, profile_picture_url = ?
                WHERE user_id = ?
            ");
            $stmtProfile->bind_param(
                "sssssssi", 
                $first_name, $last_name, $email, $phone, 
                $date_of_birth, $address, $profile_pic, $user_id
            );
        } else {
            $stmtProfile = $conn->prepare("
                UPDATE profiles 
                SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                    date_of_birth = ?, address = ?
                WHERE user_id = ?
            ");
            $stmtProfile->bind_param(
                "ssssssi", 
                $first_name, $last_name, $email, $phone, 
                $date_of_birth, $address, $user_id
            );
        }
    } else {
        // Insert new profile
        $defaultProfilePic = "uploads/profiles/default-avatar.png";
        $profilePicToUse = $profile_pic ?: $defaultProfilePic;
        
        $stmtProfile = $conn->prepare("
            INSERT INTO profiles 
            (user_id, first_name, last_name, email, phone, date_of_birth, address, profile_picture_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtProfile->bind_param(
            "isssssss", 
            $user_id, $first_name, $last_name, $email, $phone, 
            $date_of_birth, $address, $profilePicToUse
        );
    }

    if ($stmtProfile->execute()) {
        $profileUpdated = true;
        error_log("Profile updated successfully");
    } else {
        throw new Exception("Profile operation failed: " . $stmtProfile->error);
    }
    
    if (isset($stmtProfile)) {
        $stmtProfile->close();
    }
    $checkProfile->close();

    // ================= EMPLOYEES TABLE OPERATIONS ================= //
    $checkEmployee = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
    $checkEmployee->bind_param("i", $user_id);
    $checkEmployee->execute();
    $checkEmployee->store_result();

    $employeeUpdated = false;
    
    // Handle manager_id - set to NULL if invalid
    $finalManagerId = null;
    if (!empty($manager_id)) {
        // Quick check if manager exists
        $checkManager = $conn->prepare("SELECT employee_id FROM employees WHERE employee_id = ?");
        $checkManager->bind_param("i", $manager_id);
        $checkManager->execute();
        $checkManager->store_result();
        if ($checkManager->num_rows > 0) {
            $finalManagerId = $manager_id;
            error_log("Manager ID {$manager_id} is valid, using it.");
        } else {
            error_log("Manager ID {$manager_id} is invalid, setting to NULL.");
            $finalManagerId = null;
        }
        $checkManager->close();
    } else {
        error_log("Manager ID is empty, setting to NULL.");
        $finalManagerId = null;
    }
    
    // Handle working days - ensure it's properly formatted
    $finalWorkingDays = trim($working_days);
    error_log("Final Working Days to insert: '{$finalWorkingDays}'");
    
    if ($checkEmployee->num_rows > 0) {
        // Update existing employee record
        $stmtEmployee = $conn->prepare("
            UPDATE employees 
            SET employee_number = ?, department = ?, position = ?, salary = ?, 
                date_hired = ?, manager_id = ?, job_start_time = ?, job_end_time = ?, 
                working_days = ?
            WHERE user_id = ?
        ");
        
        // Debug the bind parameters
        error_log("Binding params: employee_number={$employee_number}, department={$department}, position={$position}, salary=" . ($salary ?? 'NULL') . ", date_hired={$date_hired}, manager_id=" . ($finalManagerId ?? 'NULL') . ", job_start_time={$job_start_time}, job_end_time={$job_end_time}, working_days={$finalWorkingDays}, user_id={$user_id}");
        
        $stmtEmployee->bind_param(
            "sssdsssssi", 
            $employee_number, $department, $position, $salary,
            $date_hired, $finalManagerId, $job_start_time, $job_end_time,
            $finalWorkingDays, $user_id
        );
    } else {
        // Insert new employee record
        $stmtEmployee = $conn->prepare("
            INSERT INTO employees 
            (user_id, employee_number, department, position, salary, date_hired, 
             manager_id, job_start_time, job_end_time, working_days)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmtEmployee->bind_param(
            "isssdsssss", 
            $user_id, $employee_number, $department, $position, $salary,
            $date_hired, $finalManagerId, $job_start_time, $job_end_time,
            $finalWorkingDays
        );
    }

    if ($stmtEmployee->execute()) {
        $employeeUpdated = true;
        error_log("Employee record updated successfully!");
        error_log("Manager ID used: " . ($finalManagerId ?? 'NULL'));
        error_log("Working Days used: '{$finalWorkingDays}'");
    } else {
        throw new Exception("Employee operation failed: " . $stmtEmployee->error);
    }
    
    if (isset($stmtEmployee)) {
        $stmtEmployee->close();
    }
    $checkEmployee->close();

    // Commit transaction if all operations successful
    $conn->commit();
    
    // Success response
    echo json_encode([
        "success" => true, 
        "message" => "Profile updated successfully!",
        "data" => [
            "profile_updated" => $profileUpdated,
            "employee_updated" => $employeeUpdated,
            "manager_id_used" => $finalManagerId,
            "working_days_used" => $finalWorkingDays,
            "user_id" => $user_id
        ]
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Log error for debugging
    error_log("Profile update error for user {$user_id}: " . $e->getMessage());
    
    // Error response
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => $e->getMessage(),
        "debug" => [
            "user_id" => $user_id,
            "manager_id_received" => $manager_id,
            "final_manager_id" => $finalManagerId ?? 'NULL',
            "working_days_received" => $working_days,
            "timestamp" => date('Y-m-d H:i:s')
        ]
    ]);
}

$conn->close();
?>