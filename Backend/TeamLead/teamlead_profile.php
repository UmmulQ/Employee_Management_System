<?php
// Include config file
require_once __DIR__ . '/../connect.php';

class TeamLeadProfile {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }

    // Get team lead profile data for logged-in user
    public function getProfile($username) {
        try {
            $query = "SELECT 
                        u.user_id,
                        u.username,
                        u.role_id,
                        p.profile_id,
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
                        e.created_at as employee_created,
                        e.updated_at as employee_updated
                      FROM users u
                      LEFT JOIN profiles p ON u.user_id = p.user_id
                      LEFT JOIN employees e ON u.user_id = e.user_id
                      WHERE u.username = ? AND u.role_id = 3"; // role_id 3 for team leads
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return ['error' => 'Team lead profile not found'];
            }
            
            $profile = $result->fetch_assoc();
            
            // Format the data for frontend
            $formattedProfile = [
                'user_id' => $profile['user_id'],
                'username' => $profile['username'],
                'role_id' => $profile['role_id'],
                
                // Profile data
                'first_name' => $profile['first_name'],
                'last_name' => $profile['last_name'],
                'email' => $profile['email'],
                'phone' => $profile['phone'],
                'profile_picture_url' => $profile['profile_picture_url'],
                'date_of_birth' => $profile['date_of_birth'],
                'address' => $profile['address'],
                
                // Employee data
                'employee_id' => $profile['employee_id'],
                'employee_number' => $profile['employee_number'],
                'department' => $profile['department'],
                'position' => $profile['position'],
                'salary' => $profile['salary'],
                'date_hired' => $profile['date_hired'],
                'manager_id' => $profile['manager_id'],
                'job_start_time' => $profile['job_start_time'],
                'job_end_time' => $profile['job_end_time'],
                'working_days' => $profile['working_days']
            ];
            
            return $formattedProfile;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Update team lead profile for logged-in user
    public function updateProfile($data, $files, $username) {
        try {
            // First get user_id from username
            $user_id = $this->getUserIdByUsername($username);
            if (!$user_id) {
                return ['error' => 'User not found'];
            }

            // Start transaction
            $this->conn->begin_transaction();

            try {
                // Update profiles table
                $profileQuery = "UPDATE profiles SET 
                                first_name = ?,
                                last_name = ?,
                                email = ?,
                                phone = ?,
                                date_of_birth = ?,
                                address = ?,
                                profile_picture_url = COALESCE(?, profile_picture_url)
                                WHERE user_id = ?";
                
                $profileStmt = $this->conn->prepare($profileQuery);
                
                // Handle file upload
                $profilePictureUrl = null;
                if (!empty($files['profile_picture_url'])) {
                    $uploadResult = $this->handleFileUpload($files['profile_picture_url']);
                    if (isset($uploadResult['error'])) {
                        throw new Exception($uploadResult['error']);
                    }
                    $profilePictureUrl = $uploadResult['file_path'];
                }

                $profileStmt->bind_param(
                    "sssssssi",
                    $data['first_name'],
                    $data['last_name'],
                    $data['email'],
                    $data['phone'],
                    $data['date_of_birth'],
                    $data['address'],
                    $profilePictureUrl,
                    $user_id
                );
                
                $profileStmt->execute();

                // Update employees table
                $employeeQuery = "UPDATE employees SET 
                                 employee_number = ?,
                                 department = ?,
                                 position = ?,
                                 salary = ?,
                                 date_hired = ?,
                                 manager_id = ?,
                                 job_start_time = ?,
                                 job_end_time = ?,
                                 working_days = ?,
                                 updated_at = NOW()
                                 WHERE user_id = ?";
                
                $employeeStmt = $this->conn->prepare($employeeQuery);
                
                $manager_id = !empty($data['manager_id']) ? $data['manager_id'] : null;
                $salary = !empty($data['salary']) ? $data['salary'] : null;
                
                $employeeStmt->bind_param(
                    "sssdsssssi",
                    $data['employee_number'],
                    $data['department'],
                    $data['position'],
                    $salary,
                    $data['date_hired'],
                    $manager_id,
                    $data['job_start_time'],
                    $data['job_end_time'],
                    $data['working_days'],
                    $user_id
                );
                
                $employeeStmt->execute();

                $this->conn->commit();

                return ['success' => 'Profile updated successfully'];

            } catch (Exception $e) {
                $this->conn->rollback();
                return ['error' => 'Error updating profile: ' . $e->getMessage()];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get user_id by username
    private function getUserIdByUsername($username) {
        $query = "SELECT user_id FROM users WHERE username = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['user_id'];
        }
        return null;
    }

    // Handle file upload
    private function handleFileUpload($file) {
        try {
            $uploadDir = __DIR__ . '/../uploads/profiles/';
            
            // Create uploads directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Validate file
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($file['type'], $allowedTypes)) {
                return ['error' => 'Only JPG, PNG, GIF, and WebP images are allowed'];
            }

            if ($file['size'] > $maxFileSize) {
                return ['error' => 'File size must be less than 5MB'];
            }

            // Generate unique filename
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = 'profile_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                return [
                    'success' => true,
                    'file_path' => 'http://localhost/EMS/Backend/uploads/profiles/' . $fileName,
                    'file_name' => $fileName
                ];
            } else {
                return ['error' => 'Failed to upload file'];
            }

        } catch (Exception $e) {
            return ['error' => 'File upload error: ' . $e->getMessage()];
        }
    }

    // Get manager options for dropdown
    public function getManagers() {
        try {
            $query = "SELECT 
                        e.employee_id,
                        e.manager_id,
                        p.first_name,
                        p.last_name,
                        e.position
                      FROM employees e
                      JOIN profiles p ON e.user_id = p.user_id
                      WHERE e.position LIKE '%Manager%' OR e.position LIKE '%Director%'
                      ORDER BY p.first_name, p.last_name";
            
            $result = $this->conn->query($query);
            
            $managers = [];
            while ($row = $result->fetch_assoc()) {
                $managers[] = $row;
            }
            
            return $managers;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Response helper function
function sendResponse($success, $data = null, $message = '') {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

$profileManager = new TeamLeadProfile($conn);
$method = $_SERVER['REQUEST_METHOD'];

// Get logged-in username from session
session_start();
$logged_in_username = $_SESSION['username'] ?? 'TeamLead'; // Default to 'TeamLead' for testing

// Handle different content types
if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Handle form data with files
        $inputData = $_POST;
        $files = $_FILES;
    } else {
        // Handle JSON data
        $input = json_decode(file_get_contents('php://input'), true);
        $inputData = $input ?? [];
        $files = [];
    }
} else {
    $inputData = $_GET;
    $files = [];
}

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? '';
        
        switch ($action) {
            case 'get_profile':
                $result = $profileManager->getProfile($logged_in_username);
                break;
                
            case 'get_managers':
                $result = $profileManager->getManagers();
                break;
                
            default:
                $result = $profileManager->getProfile($logged_in_username);
                break;
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, 'Profile data retrieved successfully');
        }
        break;
        
    case 'POST':
        $action = $_GET['action'] ?? 'update_profile';
        
        switch ($action) {
            case 'update_profile':
                $data = [
                    'first_name' => $inputData['first_name'] ?? '',
                    'last_name' => $inputData['last_name'] ?? '',
                    'email' => $inputData['email'] ?? '',
                    'phone' => $inputData['phone'] ?? '',
                    'date_of_birth' => $inputData['date_of_birth'] ?? '',
                    'address' => $inputData['address'] ?? '',
                    'employee_number' => $inputData['employee_number'] ?? '',
                    'department' => $inputData['department'] ?? '',
                    'position' => $inputData['position'] ?? '',
                    'salary' => $inputData['salary'] ?? '',
                    'date_hired' => $inputData['date_hired'] ?? '',
                    'manager_id' => $inputData['manager_id'] ?? '',
                    'job_start_time' => $inputData['job_start_time'] ?? '',
                    'job_end_time' => $inputData['job_end_time'] ?? '',
                    'working_days' => $inputData['working_days'] ?? ''
                ];
                
                $result = $profileManager->updateProfile($data, $files, $logged_in_username);
                break;
                
            default:
                $result = ['error' => 'Invalid action'];
                break;
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, $result['success']);
        }
        break;
        
    default:
        http_response_code(405);
        sendResponse(false, null, 'Method not allowed');
        break;
}
?>