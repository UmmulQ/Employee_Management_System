<?php
// Include database connection
require_once 'connect.php';

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "User not logged in"]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle GET request - Fetch profile data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    getProfile($conn, $user_id);
}

// Handle POST request - Update profile data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    updateProfile($conn, $user_id);
}

function getProfile($conn, $user_id) {
    try {
        // Fetch data from users, profiles, and employees tables using JOIN
        $sql = "SELECT 
                    u.user_id,
                    u.username,
                    u.role_id,
                    p.first_name,
                    p.last_name,
                    p.email,
                    p.phone,
                    p.profile_picture_url,
                    p.date_of_birth,
                    p.address,
                    e.employee_id,
                    e.employee_number,
                    e.department,
                    e.position,
                    e.salary,
                    e.date_hired,
                    e.manager_id,
                    e.job_start_time,
                    e.job_end_time,
                    e.working_days,
                    e.is_active as employee_status
                FROM users u
                LEFT JOIN profiles p ON u.user_id = p.user_id
                LEFT JOIN employees e ON u.user_id = e.user_id
                WHERE u.user_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $profile = $result->fetch_assoc();
            
            // Format the data for frontend
            $formattedProfile = [
                'user_id' => $profile['user_id'],
                'username' => $profile['username'],
                'role_id' => $profile['role_id'],
                'first_name' => $profile['first_name'] ?? '',
                'last_name' => $profile['last_name'] ?? '',
                'email' => $profile['email'] ?? '',
                'phone' => $profile['phone'] ?? '',
                'profile_picture_url' => $profile['profile_picture_url'] ?? '',
                'date_of_birth' => $profile['date_of_birth'] ?? '',
                'address' => $profile['address'] ?? '',
                'employee_number' => $profile['employee_number'] ?? '',
                'department' => $profile['department'] ?? '',
                'position' => $profile['position'] ?? '',
                'salary' => $profile['salary'] ?? '',
                'date_hired' => $profile['date_hired'] ?? '',
                'manager_id' => $profile['manager_id'] ?? '',
                'job_start_time' => $profile['job_start_time'] ?? '',
                'job_end_time' => $profile['job_end_time'] ?? '',
                'working_days' => $profile['working_days'] ?? '',
                'employee_status' => $profile['employee_status'] ?? 0
            ];
            
            echo json_encode([
                "success" => true,
                "profile" => $formattedProfile
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Profile not found"
            ]);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Error fetching profile: " . $e->getMessage()
        ]);
    }
}

function updateProfile($conn, $user_id) {
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Handle file upload
        $profile_picture_url = null;
        if (isset($_FILES['profile_picture_url']) && $_FILES['profile_picture_url']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = "../TLuploads/profiles/";
            
            // Create directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileExtension = pathinfo($_FILES['profile_picture_url']['name'], PATHINFO_EXTENSION);
            $fileName = "profile_" . $user_id . "_" . time() . "." . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['profile_picture_url']['tmp_name'], $filePath)) {
                $profile_picture_url = "TLuploads/profiles/" . $fileName;
            }
        }
        
        // Get form data
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $date_of_birth = $_POST['date_of_birth'] ?? '';
        $address = $_POST['address'] ?? '';
        
        // Employee data
        $employee_number = $_POST['employee_number'] ?? '';
        $department = $_POST['department'] ?? '';
        $position = $_POST['position'] ?? '';
        $salary = $_POST['salary'] ?? '';
        $date_hired = $_POST['date_hired'] ?? '';
        $manager_id = $_POST['manager_id'] ?? '';
        $job_start_time = $_POST['job_start_time'] ?? '';
        $job_end_time = $_POST['job_end_time'] ?? '';
        $working_days = $_POST['working_days'] ?? '';
        
        // Update or Insert into profiles table
        $checkProfile = $conn->prepare("SELECT profile_id FROM profiles WHERE user_id = ?");
        $checkProfile->bind_param("i", $user_id);
        $checkProfile->execute();
        $profileExists = $checkProfile->get_result()->num_rows > 0;
        $checkProfile->close();
        
        if ($profileExists) {
            // Update existing profile
            if ($profile_picture_url) {
                $profileSql = "UPDATE profiles SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                              profile_picture_url = ?, date_of_birth = ?, address = ? WHERE user_id = ?";
                $profileStmt = $conn->prepare($profileSql);
                $profileStmt->bind_param("sssssssi", $first_name, $last_name, $email, $phone, 
                                       $profile_picture_url, $date_of_birth, $address, $user_id);
            } else {
                $profileSql = "UPDATE profiles SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                              date_of_birth = ?, address = ? WHERE user_id = ?";
                $profileStmt = $conn->prepare($profileSql);
                $profileStmt->bind_param("ssssssi", $first_name, $last_name, $email, $phone, 
                                       $date_of_birth, $address, $user_id);
            }
        } else {
            // Insert new profile
            if ($profile_picture_url) {
                $profileSql = "INSERT INTO profiles (user_id, first_name, last_name, email, phone, 
                              profile_picture_url, date_of_birth, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $profileStmt = $conn->prepare($profileSql);
                $profileStmt->bind_param("isssssss", $user_id, $first_name, $last_name, $email, $phone, 
                                       $profile_picture_url, $date_of_birth, $address);
            } else {
                $profileSql = "INSERT INTO profiles (user_id, first_name, last_name, email, phone, 
                              date_of_birth, address) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $profileStmt = $conn->prepare($profileSql);
                $profileStmt->bind_param("issssss", $user_id, $first_name, $last_name, $email, $phone, 
                                       $date_of_birth, $address);
            }
        }
        
        $profileStmt->execute();
        $profileStmt->close();
        
        // Update or Insert into employees table
        $checkEmployee = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
        $checkEmployee->bind_param("i", $user_id);
        $checkEmployee->execute();
        $employeeExists = $checkEmployee->get_result()->num_rows > 0;
        $checkEmployee->close();
        
        if ($employeeExists) {
            // Update existing employee
            $employeeSql = "UPDATE employees SET 
                           employee_number = ?, department = ?, position = ?, salary = ?, 
                           date_hired = ?, manager_id = ?, job_start_time = ?, job_end_time = ?, 
                           working_days = ?, updated_at = NOW() 
                           WHERE user_id = ?";
            $employeeStmt = $conn->prepare($employeeSql);
            $employeeStmt->bind_param("sssssssssi", $employee_number, $department, $position, $salary,
                                    $date_hired, $manager_id, $job_start_time, $job_end_time, 
                                    $working_days, $user_id);
        } else {
            // Insert new employee
            $employeeSql = "INSERT INTO employees 
                           (user_id, employee_number, department, position, salary, date_hired, 
                           manager_id, job_start_time, job_end_time, working_days, created_at, updated_at, is_active) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)";
            $employeeStmt = $conn->prepare($employeeSql);
            $employeeStmt->bind_param("isssssssss", $user_id, $employee_number, $department, $position, $salary,
                                    $date_hired, $manager_id, $job_start_time, $job_end_time, $working_days);
        }
        
        $employeeStmt->execute();
        $employeeStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "Profile updated successfully"
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        echo json_encode([
            "success" => false,
            "message" => "Error updating profile: " . $e->getMessage()
        ]);
    }
}

// Close connection
$conn->close();
?>