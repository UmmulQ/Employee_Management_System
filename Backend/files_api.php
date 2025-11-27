<?php
// Include your existing connect.php
include 'connect.php';

// Start session - FIXED: Always start session at the beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set default employee ID for demo - FIXED: Better session handling
if (!isset($_SESSION['employee_id'])) {
    // You can set a default or redirect to login
    $_SESSION['employee_id'] = 1; // Default for demo
    // For production, you might want to redirect to login:
    // header('Location: login.php');
    // exit;
}

// Helper function to send JSON responses
function sendJsonResponse($success, $message = '', $data = null) {
    header('Content-Type: application/json');
    $response = ['success' => $success];
    if ($message) $response['message'] = $message;
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

// Main API logic
try {
    $action = $_GET['action'] ?? '';
    
    // FIXED: Ensure employee_id is always available
    $employee_id = $_SESSION['employee_id'] ?? 1;

    // Debug: Check if session is working (you can remove this later)
    if (!isset($_SESSION['employee_id'])) {
        error_log("Session employee_id not set. Using default: " . $employee_id);
    }

    switch ($action) {
        case 'get_files':
            // Modified query to include employee check or show accessible files
            $sql = "SELECT * FROM files WHERE is_deleted = 0 
                    AND (employee_id = ? OR access_level = 'public') 
                    ORDER BY type DESC, created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $files = [];
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $files[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'file_path' => $row['file_path'] ?? '',
                        'file_size' => (int)($row['file_size'] ?? 0),
                        'mime_type' => $row['mime_type'] ?? '',
                        'modified' => date('M d, Y', strtotime($row['modified_at'] ?? $row['created_at'])),
                        'access' => ucfirst($row['access_level'] ?? 'private'),
                        'created_at' => $row['created_at'],
                        'is_owner' => ($row['employee_id'] == $employee_id) // Add ownership info
                    ];
                }
            }
            
            sendJsonResponse(true, 'Files loaded successfully', $files);
            break;

        case 'create_folder':
            $name = $_POST['name'] ?? '';
            
            if (empty(trim($name))) {
                sendJsonResponse(false, 'Folder name is required');
            }
            
            $stmt = $conn->prepare("INSERT INTO files (name, type, employee_id, access_level) VALUES (?, 'folder', ?, 'private')");
            $stmt->bind_param("si", $name, $employee_id);
            
            if ($stmt->execute()) {
                sendJsonResponse(true, 'Folder created successfully', ['folder_id' => $stmt->insert_id]);
            } else {
                sendJsonResponse(false, 'Failed to create folder: ' . $conn->error);
            }
            break;

        case 'rename':
            $file_id = $_POST['file_id'] ?? 0;
            $new_name = $_POST['new_name'] ?? '';
            
            if (empty(trim($new_name))) {
                sendJsonResponse(false, 'New name is required');
            }
            
            // FIXED: Added ownership check
            $stmt = $conn->prepare("UPDATE files SET name = ? WHERE id = ? AND employee_id = ?");
            $stmt->bind_param("sii", $new_name, $file_id, $employee_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    sendJsonResponse(true, 'Renamed successfully');
                } else {
                    sendJsonResponse(false, 'File not found or access denied');
                }
            } else {
                sendJsonResponse(false, 'Failed to rename: ' . $conn->error);
            }
            break;

        case 'delete':
            $file_id = $_POST['file_id'] ?? 0;
            
            // FIXED: Added ownership check
            $stmt = $conn->prepare("UPDATE files SET is_deleted = 1 WHERE id = ? AND employee_id = ?");
            $stmt->bind_param("ii", $file_id, $employee_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    sendJsonResponse(true, 'File deleted successfully');
                } else {
                    sendJsonResponse(false, 'File not found or access denied');
                }
            } else {
                sendJsonResponse(false, 'Failed to delete: ' . $conn->error);
            }
            break;

        case 'update_access':
            $file_id = $_POST['file_id'] ?? 0;
            $access_level = $_POST['access_level'] ?? 'private';
            
            // FIXED: Added ownership check
            $stmt = $conn->prepare("UPDATE files SET access_level = ? WHERE id = ? AND employee_id = ?");
            $stmt->bind_param("sii", $access_level, $file_id, $employee_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    sendJsonResponse(true, 'Access updated successfully');
                } else {
                    sendJsonResponse(false, 'File not found or access denied');
                }
            } else {
                sendJsonResponse(false, 'Failed to update access: ' . $conn->error);
            }
            break;

        case 'download':
            $file_id = $_GET['file_id'] ?? 0;
            
            // FIXED: Check access rights for download
            $stmt = $conn->prepare("SELECT * FROM files WHERE id = ? AND is_deleted = 0 
                                   AND (employee_id = ? OR access_level = 'public')");
            $stmt->bind_param("ii", $file_id, $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $file = $result->fetch_assoc();
            
            if (!$file) {
                sendJsonResponse(false, 'File not found or access denied');
            }
            
            if ($file['type'] === 'folder') {
                sendJsonResponse(false, 'Cannot download folder');
            }
            
            if (!file_exists($file['file_path'])) {
                sendJsonResponse(false, 'File not found on server');
            }
            
            // Set download headers
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
            header('Content-Length: ' . $file['file_size']);
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            readfile($file['file_path']);
            exit;
            break;

        default:
            sendJsonResponse(false, 'Invalid action specified');
    }
    
} catch (Exception $e) {
    sendJsonResponse(false, 'An error occurred: ' . $e->getMessage());
}

// Close connection
$conn->close();
?>