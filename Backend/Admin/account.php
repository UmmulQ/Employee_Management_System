<?php
// account.php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

class AccountManager {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    // Get user account details
    public function getUserAccount($user_id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT user_id, username, email, role_id, is_active, created_at 
                FROM users 
                WHERE user_id = ?
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return [
                    'success' => false,
                    'message' => 'User not found'
                ];
            }
            
            $user = $result->fetch_assoc();
            
            return [
                'success' => true,
                'user' => $user
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching user data: ' . $e->getMessage()
            ];
        }
    }
    
    // Update user profile (username and email)
    public function updateProfile($user_id, $username, $email) {
        try {
            // Check if username already exists (excluding current user)
            $check_stmt = $this->conn->prepare("
                SELECT user_id FROM users WHERE username = ? AND user_id != ?
            ");
            $check_stmt->bind_param("si", $username, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                return [
                    'success' => false,
                    'message' => 'Username already exists'
                ];
            }
            
            // Check if email already exists (excluding current user)
            $check_stmt = $this->conn->prepare("
                SELECT user_id FROM users WHERE email = ? AND user_id != ?
            ");
            $check_stmt->bind_param("si", $email, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                return [
                    'success' => false,
                    'message' => 'Email already exists'
                ];
            }
            
            // Update user profile
            $stmt = $this->conn->prepare("
                UPDATE users 
                SET username = ?, email = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $stmt->bind_param("ssi", $username, $email, $user_id);
            
            if ($stmt->execute()) {
                // Get updated user data
                $user_data = $this->getUserAccount($user_id);
                
                return [
                    'success' => true,
                    'message' => 'Profile updated successfully',
                    'user' => $user_data['user']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update profile'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error updating profile: ' . $e->getMessage()
            ];
        }
    }
    
    // Change user password
    public function changePassword($user_id, $current_password, $new_password) {
        try {
            // Verify current password
            $stmt = $this->conn->prepare("
                SELECT password_hash FROM users WHERE user_id = ?
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return [
                    'success' => false,
                    'message' => 'User not found'
                ];
            }
            
            $user = $result->fetch_assoc();
            
            // Verify current password
            if (!password_verify($current_password, $user['password_hash'])) {
                return [
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ];
            }
            
            // Hash new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $update_stmt = $this->conn->prepare("
                UPDATE users 
                SET password_hash = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $update_stmt->bind_param("si", $new_password_hash, $user_id);
            
            if ($update_stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Password updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update password'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error changing password: ' . $e->getMessage()
            ];
        }
    }
    
    // Get all users (for admin)
    public function getAllUsers() {
        try {
            $stmt = $this->conn->prepare("
                SELECT user_id, username, email, role_id, is_active, created_at 
                FROM users 
                ORDER BY created_at DESC
            ");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            
            return [
                'success' => true,
                'users' => $users
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching users: ' . $e->getMessage()
            ];
        }
    }
    
    // Update user role and status (admin only)
    public function updateUserRole($admin_id, $user_id, $role_id, $is_active) {
        try {
            // Verify admin privileges
            $admin_stmt = $this->conn->prepare("
                SELECT role_id FROM users WHERE user_id = ?
            ");
            $admin_stmt->bind_param("i", $admin_id);
            $admin_stmt->execute();
            $admin_result = $admin_stmt->get_result();
            
            if ($admin_result->num_rows === 0) {
                return [
                    'success' => false,
                    'message' => 'Admin not found'
                ];
            }
            
            $admin = $admin_result->fetch_assoc();
            if ($admin['role_id'] != 1) { // 1 = Administrator
                return [
                    'success' => false,
                    'message' => 'Insufficient privileges'
                ];
            }
            
            // Update user role and status
            $stmt = $this->conn->prepare("
                UPDATE users 
                SET role_id = ?, is_active = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $stmt->bind_param("iii", $role_id, $is_active, $user_id);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'User updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to update user'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error updating user: ' . $e->getMessage()
            ];
        }
    }
    
    // Delete user (admin only)
    public function deleteUser($admin_id, $user_id) {
        try {
            // Verify admin privileges and prevent self-deletion
            if ($admin_id == $user_id) {
                return [
                    'success' => false,
                    'message' => 'Cannot delete your own account'
                ];
            }
            
            $admin_stmt = $this->conn->prepare("
                SELECT role_id FROM users WHERE user_id = ?
            ");
            $admin_stmt->bind_param("i", $admin_id);
            $admin_stmt->execute();
            $admin_result = $admin_stmt->get_result();
            
            if ($admin_result->num_rows === 0) {
                return [
                    'success' => false,
                    'message' => 'Admin not found'
                ];
            }
            
            $admin = $admin_result->fetch_assoc();
            if ($admin['role_id'] != 1) { // 1 = Administrator
                return [
                    'success' => false,
                    'message' => 'Insufficient privileges'
                ];
            }
            
            // Delete user
            $stmt = $this->conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'User deleted successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to delete user'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error deleting user: ' . $e->getMessage()
            ];
        }
    }
}

// Main request handler
$accountManager = new AccountManager($conn);

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get user account details
        if (isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            $response = $accountManager->getUserAccount($user_id);
        } 
        // Get all users (admin only)
        elseif (isset($_GET['action']) && $_GET['action'] == 'all_users' && isset($_GET['admin_id'])) {
            $admin_id = intval($_GET['admin_id']);
            $response = $accountManager->getAllUsers();
        }
        else {
            $response = [
                'success' => false,
                'message' => 'Invalid request'
            ];
        }
        break;
        
    case 'PUT':
        // Parse JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (isset($input['user_id'])) {
            // Update profile (username and email)
            if (isset($input['username']) && isset($input['email']) && !isset($input['current_password'])) {
                $response = $accountManager->updateProfile(
                    $input['user_id'],
                    $input['username'],
                    $input['email']
                );
            }
            // Change password
            elseif (isset($input['current_password']) && isset($input['new_password'])) {
                $response = $accountManager->changePassword(
                    $input['user_id'],
                    $input['current_password'],
                    $input['new_password']
                );
            }
            // Update user role (admin only)
            elseif (isset($input['admin_id']) && isset($input['target_user_id']) && isset($input['role_id']) && isset($input['is_active'])) {
                $response = $accountManager->updateUserRole(
                    $input['admin_id'],
                    $input['target_user_id'],
                    $input['role_id'],
                    $input['is_active']
                );
            }
            else {
                $response = [
                    'success' => false,
                    'message' => 'Invalid parameters'
                ];
            }
        } else {
            $response = [
                'success' => false,
                'message' => 'User ID required'
            ];
        }
        break;
        
    case 'DELETE':
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (isset($input['admin_id']) && isset($input['user_id'])) {
            $response = $accountManager->deleteUser(
                $input['admin_id'],
                $input['user_id']
            );
        } else {
            $response = [
                'success' => false,
                'message' => 'Admin ID and User ID required'
            ];
        }
        break;
        
    default:
        $response = [
            'success' => false,
            'message' => 'Method not allowed'
        ];
        break;
}

echo json_encode($response);
$conn->close();
?>