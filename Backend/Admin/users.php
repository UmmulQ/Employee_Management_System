<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include config file
require_once __DIR__ . '/config.php';

class UserManagement {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    public function getUsers() {
        try {
            $query = "SELECT 
                        user_id, 
                        username, 
                        is_active, 
                        created_at,
                        role_id
                      FROM users 
                      ORDER BY username";
            
            $result = $this->conn->query($query);
            
            if (!$result) {
                return ['error' => 'Database query failed: ' . $this->conn->error];
            }
            
            $users = [];
            while ($row = $result->fetch_assoc()) {
                // Convert is_active to boolean for frontend
                $row['is_active'] = (bool)$row['is_active'];
                $users[] = $row;
            }
            
            return $users;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get active users only (for task assignment)
    public function getActiveUsers() {
        try {
            $query = "SELECT 
                        user_id, 
                        username,
                        role_id
                      FROM users 
                      WHERE is_active = 1
                      ORDER BY username";
            
            $result = $this->conn->query($query);
            
            if (!$result) {
                return ['error' => 'Database query failed: ' . $this->conn->error];
            }
            
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            
            return $users;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get user by ID
    public function getUserById($user_id) {
        try {
            $query = "SELECT 
                        user_id, 
                        username, 
                        is_active, 
                        created_at,
                        role_id
                      FROM users 
                      WHERE user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $user = $result->fetch_assoc();
            if ($user) {
                $user['is_active'] = (bool)$user['is_active'];
            }
            
            return $user;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Create new user
    public function createUser($data) {
        try {
            // Validate required fields
            $required = ['username', 'password', 'role_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['error' => "Field '$field' is required"];
                }
            }

            // Check if username already exists
            $checkQuery = "SELECT user_id FROM users WHERE username = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bind_param("s", $data['username']);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                return ['error' => 'Username already exists'];
            }

            $query = "INSERT INTO users 
                      (username, password_hash, is_active, role_id, created_at) 
                      VALUES 
                      (?, ?, 1, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            
            // Hash the password
            $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt->bind_param(
                "ssi", 
                $data['username'],
                $hashed_password,
                $data['role_id']
            );
            
            if ($stmt->execute()) {
                $user_id = $this->conn->insert_id;
                return [
                    'success' => 'User created successfully',
                    'user_id' => $user_id,
                    'user' => $this->getUserById($user_id)
                ];
            } else {
                return ['error' => 'Failed to create user: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Update user
    public function updateUser($data) {
        try {
            if (empty($data['user_id'])) {
                return ['error' => 'User ID is required'];
            }

            // Check if user exists
            $existingUser = $this->getUserById($data['user_id']);
            if (!$existingUser) {
                return ['error' => 'User not found'];
            }

            $query = "UPDATE users SET 
                      username = ?,
                      is_active = ?,
                      role_id = ?
                      WHERE user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            
            $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            
            $stmt->bind_param(
                "siii",
                $data['username'],
                $is_active,
                $data['role_id'],
                $data['user_id']
            );
            
            if ($stmt->execute()) {
                return [
                    'success' => 'User updated successfully',
                    'user' => $this->getUserById($data['user_id'])
                ];
            } else {
                return ['error' => 'Failed to update user: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Delete user
    public function deleteUser($data) {
        try {
            if (empty($data['user_id'])) {
                return ['error' => 'User ID is required'];
            }
            
            $query = "DELETE FROM users WHERE user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $data['user_id']);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    return ['success' => 'User deleted successfully'];
                } else {
                    return ['error' => 'User not found'];
                }
            } else {
                return ['error' => 'Failed to delete user: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

$userManager = new UserManagement($conn);
$method = $_SERVER['REQUEST_METHOD'];

// Handle different endpoints
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', $path);
$endpoint = end($segments);

if ($method == 'GET') {
    if ($endpoint === 'active-users') {
        $result = $userManager->getActiveUsers();
    } else {
        $result = $userManager->getUsers();
    }
    
    if (isset($result['error'])) {
        sendResponse(false, null, $result['error']);
    } else {
        sendResponse(true, $result, 'Users retrieved successfully');
    }
} else if ($method == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $result = $userManager->createUser($input);
    if (isset($result['error'])) {
        sendResponse(false, null, $result['error']);
    } else {
        sendResponse(true, $result, $result['success']);
    }
} else if ($method == 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $result = $userManager->updateUser($input);
    if (isset($result['error'])) {
        sendResponse(false, null, $result['error']);
    } else {
        sendResponse(true, $result, $result['success']);
    }
} else if ($method == 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $result = $userManager->deleteUser($input);
    if (isset($result['error'])) {
        sendResponse(false, null, $result['error']);
    } else {
        sendResponse(true, null, $result['success']);
    }
} else if ($method == 'OPTIONS') {
    http_response_code(200);
    exit();
} else {
    http_response_code(405);
    sendResponse(false, null, 'Method not allowed');
}
?>