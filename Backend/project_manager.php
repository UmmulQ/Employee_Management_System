<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'connect.php';

// Set headers for JSON response
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Get the action parameter
$action = $_GET['action'] ?? '';

// Helper function to send JSON response
function sendResponse($success, $data = null, $message = '') {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

// Helper function to get request data based on content type
function getRequestData() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        return json_decode($input, true);
    } else {
        // For form data or URL encoded
        return $_POST;
    }
}

// Helper function to sanitize input
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)));
}

// Helper function to handle file upload
function handleFileUpload($file, $projectId, $taskId = null) {
    global $conn;
    
    $uploadDir = '../uploads/';
    
    // Create uploads directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Create project directory if it doesn't exist
    $projectDir = $uploadDir . 'project_' . $projectId . '/';
    if (!file_exists($projectDir)) {
        mkdir($projectDir, 0777, true);
    }
    
    $fileName = time() . '_' . basename($file['name']);
    $filePath = $projectDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        // Insert file record into database
        $sql = "INSERT INTO task_files (task_id, project_id, file_name, file_path, file_size, file_type, uploaded_by, uploaded_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        $fileSize = $file['size'];
        $fileType = $file['type'];
        $uploadedBy = $_POST['uploaded_by'] ?? 1;
        
        $stmt->bind_param('iissisi', $taskId, $projectId, $fileName, $filePath, $fileSize, $fileType, $uploadedBy);
        
        if ($stmt->execute()) {
            return [
                'file_id' => $conn->insert_id,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'file_type' => $fileType
            ];
        }
    }
    
    return false;
}

// Get user information by employee ID
function getUserInfo($employeeId) {
    global $conn;
    
    $sql = "SELECT u.username, p.email, p.first_name, p.last_name, e.position 
            FROM users u 
            INNER JOIN employees e ON u.user_id = e.user_id 
            LEFT JOIN profiles p ON u.user_id = p.user_id
            WHERE e.employee_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $userInfo = $result->fetch_assoc();
        // Use first_name + last_name if available, otherwise use username
        if (!empty($userInfo['first_name']) && !empty($userInfo['last_name'])) {
            $userInfo['display_name'] = $userInfo['first_name'] . ' ' . $userInfo['last_name'];
        } else {
            $userInfo['display_name'] = $userInfo['username'];
        }
        return $userInfo;
    }
    
    return null;
}

// Get all projects
if ($action === 'get_projects') {
    try {
        $sql = "SELECT p.*, 
                       c.company_name as client_name,
                       u.username as team_lead_name
                FROM projects p
                LEFT JOIN clients c ON p.client_id = c.client_id
                LEFT JOIN employees e ON p.team_lead_id = e.employee_id
                LEFT JOIN users u ON e.user_id = u.user_id
                ORDER BY p.created_at DESC";
        
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $projects = [];
            while ($row = $result->fetch_assoc()) {
                $projects[] = $row;
            }
            sendResponse(true, $projects, 'Projects fetched successfully');
        } else {
            sendResponse(true, [], 'No projects found');
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get tasks for a specific project
if ($action === 'get_project_tasks') {
    try {
        $project_id = $_GET['project_id'] ?? '';
        
        if (empty($project_id)) {
            sendResponse(false, [], 'Project ID is required');
        }
        
        $sql = "SELECT t.*, 
                       u1.username as assigned_to_name,
                       u2.username as assigned_by_name,
                       us.story_title,
                       us.story_points
                FROM tasks t
                LEFT JOIN employees e1 ON t.assigned_to = e1.employee_id
                LEFT JOIN users u1 ON e1.user_id = u1.user_id
                LEFT JOIN employees e2 ON t.assigned_by = e2.employee_id
                LEFT JOIN users u2 ON e2.user_id = u2.user_id
                LEFT JOIN user_stories us ON t.user_story_id = us.story_id
                WHERE t.project_id = ?
                ORDER BY 
                    CASE 
                        WHEN t.status = 'todo' THEN 1
                        WHEN t.status = 'in progress' THEN 2
                        WHEN t.status = 'review' THEN 3
                        WHEN t.status = 'done' THEN 4
                        ELSE 5
                    END,
                    t.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
        
        sendResponse(true, $tasks, 'Tasks fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Create new project
if ($action === 'create_project') {
    try {
        $data = getRequestData();
        
        // Log received data
        error_log("Received project data: " . print_r($data, true));
        
        $required_fields = ['project_name', 'description', 'client_id', 'team_lead_id', 'start_date', 'deadline'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                sendResponse(false, [], "Missing required field: $field");
            }
        }
        
        $sql = "INSERT INTO projects (project_name, description, client_id, team_lead_id, start_date, deadline, status, color, category, methodology, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, NOW())";
        
        $color = $data['color'] ?? '#3B82F6';
        $category = $data['category'] ?? 'development';
        $methodology = $data['methodology'] ?? 'scrum';
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            sendResponse(false, [], 'Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('ssiisssss', 
            $data['project_name'],
            $data['description'],
            $data['client_id'],
            $data['team_lead_id'],
            $data['start_date'],
            $data['deadline'],
            $color,
            $category,
            $methodology
        );
        
        if ($stmt->execute()) {
            $project_id = $conn->insert_id;
            
            // Add team lead as project member
            $member_sql = "INSERT INTO project_members (project_id, employee_id, role, assigned_date) 
                          VALUES (?, ?, 'Team Lead', NOW())";
            $member_stmt = $conn->prepare($member_sql);
            $member_stmt->bind_param('ii', $project_id, $data['team_lead_id']);
            $member_stmt->execute();
            
            sendResponse(true, ['project_id' => $project_id], 'Project created successfully');
        } else {
            sendResponse(false, [], 'Failed to create project: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Create new task
if ($action === 'create_task') {
    try {
        $data = getRequestData();
        
        // Log received data for debugging
        error_log("Received task data: " . print_r($data, true));
        
        $required_fields = ['task_title', 'description', 'project_id', 'assigned_to'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                sendResponse(false, [], "Missing required field: $field");
            }
        }
        
        $sql = "INSERT INTO tasks (task_title, description, project_id, assigned_to, assigned_by, priority, status, due_date, estimated_hours, story_points, user_story_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'todo', ?, ?, ?, ?, NOW())";
        
        $assigned_by = $data['assigned_by'] ?? 1;
        $priority = $data['priority'] ?? 'medium';
        $due_date = !empty($data['due_date']) ? $data['due_date'] : null;
        $estimated_hours = !empty($data['estimated_hours']) ? $data['estimated_hours'] : null;
        $story_points = !empty($data['story_points']) ? $data['story_points'] : null;
        $user_story_id = !empty($data['user_story_id']) ? $data['user_story_id'] : null;
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            sendResponse(false, [], 'Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('ssiiissssii', 
            $data['task_title'],
            $data['description'],
            $data['project_id'],
            $data['assigned_to'],
            $assigned_by,
            $priority,
            $due_date,
            $estimated_hours,
            $story_points,
            $user_story_id
        );
        
        if ($stmt->execute()) {
            $task_id = $conn->insert_id;
            error_log("Task created successfully with ID: " . $task_id);
            sendResponse(true, ['task_id' => $task_id], 'Task created successfully');
        } else {
            error_log("Execute failed: " . $stmt->error);
            sendResponse(false, [], 'Failed to create task: ' . $stmt->error);
        }
    } catch (Exception $e) {
        error_log("Exception in create_task: " . $e->getMessage());
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Update task status
if ($action === 'update_task_status') {
    try {
        $data = getRequestData();
        
        if (empty($data['task_id']) || empty($data['status'])) {
            sendResponse(false, [], 'Task ID and status are required');
        }
        
        $allowed_statuses = ['todo', 'in progress', 'review', 'done'];
        if (!in_array($data['status'], $allowed_statuses)) {
            sendResponse(false, [], 'Invalid status');
        }
        
        $sql = "UPDATE tasks SET status = ?, updated_at = NOW() WHERE task_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $data['status'], $data['task_id']);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Task status updated successfully');
        } else {
            sendResponse(false, [], 'Failed to update task status: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Update project status
if ($action === 'update_project_status') {
    try {
        $data = getRequestData();
        
        if (empty($data['project_id']) || empty($data['status'])) {
            sendResponse(false, [], 'Project ID and status are required');
        }
        
        $allowed_statuses = ['active', 'completed', 'on_hold'];
        if (!in_array($data['status'], $allowed_statuses)) {
            sendResponse(false, [], 'Invalid status');
        }
        
        $sql = "UPDATE projects SET status = ?, updated_at = NOW() WHERE project_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $data['status'], $data['project_id']);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Project status updated successfully');
        } else {
            sendResponse(false, [], 'Failed to update project status: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Add comment to task
if ($action === 'add_task_comment') {
    try {
        $data = getRequestData();
        
        if (empty($data['task_id']) || empty($data['comment_text'])) {
            sendResponse(false, [], 'Task ID and comment text are required');
        }
        
        $commented_by = $data['commented_by'] ?? 1;
        
        // Get user information before adding comment
        $userInfo = getUserInfo($commented_by);
        if (!$userInfo) {
            sendResponse(false, [], 'User not found');
        }
        
        $sql = "INSERT INTO task_comments (task_id, comment_text, commented_by, created_at) 
                VALUES (?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isi', $data['task_id'], $data['comment_text'], $commented_by);
        
        if ($stmt->execute()) {
            $comment_id = $conn->insert_id;
            
            // Return comment with user information
            $comment_data = [
                'comment_id' => $comment_id,
                'task_id' => $data['task_id'],
                'comment_text' => $data['comment_text'],
                'commented_by' => $commented_by,
                'commented_by_name' => $userInfo['display_name'],
                'commented_by_position' => $userInfo['position'],
                'created_at' => date('Y-m-d H:i:s'),
                'formatted_date' => date('M j, Y g:i A')
            ];
            
            sendResponse(true, $comment_data, 'Comment added successfully');
        } else {
            sendResponse(false, [], 'Failed to add comment: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get task comments
if ($action === 'get_task_comments') {
    try {
        $task_id = $_GET['task_id'] ?? '';
        
        if (empty($task_id)) {
            sendResponse(false, [], 'Task ID is required');
        }
        
        $sql = "SELECT tc.*, 
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as commented_by_name,
                       e.position as commented_by_position,
                       DATE_FORMAT(tc.created_at, '%b %e, %Y %l:%i %p') as formatted_date
                FROM task_comments tc
                LEFT JOIN employees e ON tc.commented_by = e.employee_id
                LEFT JOIN users u ON e.user_id = u.user_id
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE tc.task_id = ?
                ORDER BY tc.created_at ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $task_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $comments = [];
        while ($row = $result->fetch_assoc()) {
            $comments[] = $row;
        }
        
        sendResponse(true, $comments, 'Comments fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get project comments (all comments for all tasks in a project)
if ($action === 'get_project_comments') {
    try {
        $project_id = $_GET['project_id'] ?? '';
        
        if (empty($project_id)) {
            sendResponse(false, [], 'Project ID is required');
        }
        
        $sql = "SELECT tc.*, 
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as commented_by_name,
                       e.position as commented_by_position,
                       DATE_FORMAT(tc.created_at, '%b %e, %Y %l:%i %p') as formatted_date,
                       t.task_title
                FROM task_comments tc
                INNER JOIN tasks t ON tc.task_id = t.task_id
                LEFT JOIN employees e ON tc.commented_by = e.employee_id
                LEFT JOIN users u ON e.user_id = u.user_id
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE t.project_id = ?
                ORDER BY tc.created_at DESC
                LIMIT 50";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $comments = [];
        while ($row = $result->fetch_assoc()) {
            $comments[] = $row;
        }
        
        sendResponse(true, $comments, 'Project comments fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Upload file to task
if ($action === 'upload_task_file') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
            sendResponse(false, [], 'File upload required');
        }
        
        $task_id = $_POST['task_id'] ?? '';
        $project_id = $_POST['project_id'] ?? '';
        $uploaded_by = $_POST['uploaded_by'] ?? 1;
        
        if (empty($task_id) || empty($project_id)) {
            sendResponse(false, [], 'Task ID and Project ID are required');
        }
        
        // Get user information
        $userInfo = getUserInfo($uploaded_by);
        if (!$userInfo) {
            sendResponse(false, [], 'User not found');
        }
        
        $file = $_FILES['file'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            sendResponse(false, [], 'File upload error: ' . $file['error']);
        }
        
        // Check file size (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            sendResponse(false, [], 'File size too large. Maximum 10MB allowed.');
        }
        
        // Check file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                         'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                         'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                         'text/plain'];
        if (!in_array($file['type'], $allowed_types)) {
            sendResponse(false, [], 'File type not allowed');
        }
        
        $uploadResult = handleFileUpload($file, $project_id, $task_id);
        
        if ($uploadResult) {
            // Add user information to the response
            $uploadResult['uploaded_by_name'] = $userInfo['display_name'];
            $uploadResult['uploaded_by_position'] = $userInfo['position'];
            $uploadResult['formatted_date'] = date('M j, Y g:i A');
            
            sendResponse(true, $uploadResult, 'File uploaded successfully');
        } else {
            sendResponse(false, [], 'Failed to upload file');
        }
        
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get task files
if ($action === 'get_task_files') {
    try {
        $task_id = $_GET['task_id'] ?? '';
        
        if (empty($task_id)) {
            sendResponse(false, [], 'Task ID is required');
        }
        
        $sql = "SELECT tf.*, 
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as uploaded_by_name,
                       e.position as uploaded_by_position,
                       DATE_FORMAT(tf.uploaded_at, '%b %e, %Y %l:%i %p') as formatted_date
                FROM task_files tf
                LEFT JOIN employees e ON tf.uploaded_by = e.employee_id
                LEFT JOIN users u ON e.user_id = u.user_id
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE tf.task_id = ?
                ORDER BY tf.uploaded_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $task_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $files = [];
        while ($row = $result->fetch_assoc()) {
            $files[] = $row;
        }
        
        sendResponse(true, $files, 'Files fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get project files (all files for all tasks in a project)
if ($action === 'get_project_files') {
    try {
        $project_id = $_GET['project_id'] ?? '';
        
        if (empty($project_id)) {
            sendResponse(false, [], 'Project ID is required');
        }
        
        $sql = "SELECT tf.*, 
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as uploaded_by_name,
                       e.position as uploaded_by_position,
                       DATE_FORMAT(tf.uploaded_at, '%b %e, %Y %l:%i %p') as formatted_date,
                       t.task_title
                FROM task_files tf
                INNER JOIN tasks t ON tf.task_id = t.task_id
                LEFT JOIN employees e ON tf.uploaded_by = e.employee_id
                LEFT JOIN users u ON e.user_id = u.user_id
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE tf.project_id = ?
                ORDER BY tf.uploaded_at DESC
                LIMIT 50";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $files = [];
        while ($row = $result->fetch_assoc()) {
            $files[] = $row;
        }
        
        sendResponse(true, $files, 'Project files fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Download task file
if ($action === 'download_task_file') {
    try {
        $file_id = $_GET['file_id'] ?? '';
        
        if (empty($file_id)) {
            sendResponse(false, [], 'File ID is required');
        }
        
        $sql = "SELECT * FROM task_files WHERE file_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $file_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            sendResponse(false, [], 'File not found');
        }
        
        $file = $result->fetch_assoc();
        
        if (!file_exists($file['file_path'])) {
            sendResponse(false, [], 'File not found on server');
        }
        
        // Set headers for download
        header('Content-Type: ' . $file['file_type']);
        header('Content-Disposition: attachment; filename="' . $file['file_name'] . '"');
        header('Content-Length: ' . $file['file_size']);
        
        readfile($file['file_path']);
        exit;
        
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Delete task file
if ($action === 'delete_task_file') {
    try {
        $data = getRequestData();
        
        if (empty($data['file_id'])) {
            sendResponse(false, [], 'File ID is required');
        }
        
        // First get file info
        $sql = "SELECT * FROM task_files WHERE file_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $data['file_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            sendResponse(false, [], 'File not found');
        }
        
        $file = $result->fetch_assoc();
        
        // Delete file from filesystem
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }
        
        // Delete record from database
        $delete_sql = "DELETE FROM task_files WHERE file_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param('i', $data['file_id']);
        
        if ($delete_stmt->execute()) {
            sendResponse(true, [], 'File deleted successfully');
        } else {
            sendResponse(false, [], 'Failed to delete file record');
        }
        
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Update task details
if ($action === 'update_task') {
    try {
        $data = getRequestData();
        
        if (empty($data['task_id'])) {
            sendResponse(false, [], 'Task ID is required');
        }
        
        $allowed_fields = ['task_title', 'description', 'assigned_to', 'priority', 'status', 'due_date', 'estimated_hours', 'story_points', 'user_story_id'];
        $updates = [];
        $params = [];
        $types = '';
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
                $types .= 's';
            }
        }
        
        if (empty($updates)) {
            sendResponse(false, [], 'No fields to update');
        }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $data['task_id'];
        $types .= 'i';
        
        $sql = "UPDATE tasks SET " . implode(', ', $updates) . " WHERE task_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Task updated successfully');
        } else {
            sendResponse(false, [], 'Failed to update task: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Delete task
if ($action === 'delete_task') {
    try {
        $data = getRequestData();
        
        if (empty($data['task_id'])) {
            sendResponse(false, [], 'Task ID is required');
        }
        
        // First delete associated files and comments
        $delete_files_sql = "DELETE FROM task_files WHERE task_id = ?";
        $delete_files_stmt = $conn->prepare($delete_files_sql);
        $delete_files_stmt->bind_param('i', $data['task_id']);
        $delete_files_stmt->execute();
        
        $delete_comments_sql = "DELETE FROM task_comments WHERE task_id = ?";
        $delete_comments_stmt = $conn->prepare($delete_comments_sql);
        $delete_comments_stmt->bind_param('i', $data['task_id']);
        $delete_comments_stmt->execute();
        
        // Then delete the task
        $sql = "DELETE FROM tasks WHERE task_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $data['task_id']);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Task deleted successfully');
        } else {
            sendResponse(false, [], 'Failed to delete task: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get employees for dropdowns
if ($action === 'get_employees') {
    try {
        $sql = "SELECT e.employee_id, 
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as name, 
                       e.position 
                FROM employees e 
                LEFT JOIN users u ON e.user_id = u.user_id 
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE e.is_active = 1 
                ORDER BY name";
        
        $result = $conn->query($sql);
        
        $employees = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $employees[] = $row;
            }
        }
        
        sendResponse(true, $employees, 'Employees fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get clients for dropdowns
if ($action === 'get_clients') {
    try {
        $sql = "SELECT client_id, company_name FROM clients ORDER BY company_name";
        $result = $conn->query($sql);
        
        $clients = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $clients[] = $row;
            }
        }
        
        sendResponse(true, $clients, 'Clients fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get current user info
if ($action === 'get_current_user') {
    try {
        $employee_id = $_GET['employee_id'] ?? '';
        
        if (empty($employee_id)) {
            sendResponse(false, [], 'Employee ID is required');
        }
        
        $userInfo = getUserInfo($employee_id);
        
        if ($userInfo) {
            sendResponse(true, $userInfo, 'User information fetched successfully');
        } else {
            sendResponse(false, [], 'User not found');
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get project analytics
if ($action === 'get_project_analytics') {
    try {
        $project_id = $_GET['project_id'] ?? '';
        
        if (empty($project_id)) {
            sendResponse(false, [], 'Project ID is required');
        }
        
        // Total tasks
        $total_tasks_sql = "SELECT COUNT(*) as total FROM tasks WHERE project_id = ?";
        $stmt = $conn->prepare($total_tasks_sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $total_tasks = $stmt->get_result()->fetch_assoc()['total'];
        
        // Completed tasks
        $completed_tasks_sql = "SELECT COUNT(*) as completed FROM tasks WHERE project_id = ? AND status = 'done'";
        $stmt = $conn->prepare($completed_tasks_sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $completed_tasks = $stmt->get_result()->fetch_assoc()['completed'];
        
        // Task status distribution
        $status_sql = "SELECT status, COUNT(*) as count FROM tasks WHERE project_id = ? GROUP BY status";
        $stmt = $conn->prepare($status_sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $status_result = $stmt->get_result();
        
        $status_distribution = [];
        while ($row = $status_result->fetch_assoc()) {
            $status_distribution[$row['status']] = $row['count'];
        }
        
        // Priority distribution
        $priority_sql = "SELECT priority, COUNT(*) as count FROM tasks WHERE project_id = ? GROUP BY priority";
        $stmt = $conn->prepare($priority_sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $priority_result = $stmt->get_result();
        
        $priority_distribution = [];
        while ($row = $priority_result->fetch_assoc()) {
            $priority_distribution[$row['priority']] = $row['count'];
        }
        
        $analytics = [
            'total_tasks' => $total_tasks,
            'completed_tasks' => $completed_tasks,
            'progress_percentage' => $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0,
            'status_distribution' => $status_distribution,
            'priority_distribution' => $priority_distribution
        ];
        
        sendResponse(true, $analytics, 'Analytics fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// SCRUM SPECIFIC FUNCTIONS

// Get project epics
if ($action === 'get_project_epics') {
    try {
        $project_id = $_GET['project_id'] ?? '';
        
        if (empty($project_id)) {
            sendResponse(false, [], 'Project ID is required');
        }
        
        $sql = "SELECT e.*, 
                       COUNT(us.story_id) as story_count,
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as created_by_name
                FROM epics e
                LEFT JOIN user_stories us ON e.epic_id = us.epic_id
                LEFT JOIN employees emp ON e.created_by = emp.employee_id
                LEFT JOIN users u ON emp.user_id = u.user_id
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE e.project_id = ?
                GROUP BY e.epic_id
                ORDER BY e.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $epics = [];
        while ($row = $result->fetch_assoc()) {
            $epics[] = $row;
        }
        
        sendResponse(true, $epics, 'Epics fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Create epic
if ($action === 'create_epic') {
    try {
        $data = getRequestData();
        
        if (empty($data['project_id']) || empty($data['epic_name']) || empty($data['description'])) {
            sendResponse(false, [], 'Project ID, epic name and description are required');
        }
        
        $sql = "INSERT INTO epics (project_id, epic_name, description, priority, business_value, color, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $priority = $data['priority'] ?? 'medium';
        $business_value = !empty($data['business_value']) ? $data['business_value'] : null;
        $color = $data['color'] ?? '#8B5CF6';
        $created_by = $data['created_by'] ?? 1;
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isssisi', 
            $data['project_id'],
            $data['epic_name'],
            $data['description'],
            $priority,
            $business_value,
            $color,
            $created_by
        );
        
        if ($stmt->execute()) {
            $epic_id = $conn->insert_id;
            sendResponse(true, ['epic_id' => $epic_id], 'Epic created successfully');
        } else {
            sendResponse(false, [], 'Failed to create epic: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get project user stories
if ($action === 'get_project_user_stories') {
    try {
        $project_id = $_GET['project_id'] ?? '';
        
        if (empty($project_id)) {
            sendResponse(false, [], 'Project ID is required');
        }
        
        $sql = "SELECT us.*, 
                       e.epic_name,
                       s.sprint_name,
                       COALESCE(CONCAT(p1.first_name, ' ', p1.last_name), u1.username) as assigned_to_name,
                       COALESCE(CONCAT(p2.first_name, ' ', p2.last_name), u2.username) as created_by_name
                FROM user_stories us
                LEFT JOIN epics e ON us.epic_id = e.epic_id
                LEFT JOIN sprints s ON us.sprint_id = s.sprint_id
                LEFT JOIN employees emp1 ON us.assigned_to = emp1.employee_id
                LEFT JOIN users u1 ON emp1.user_id = u1.user_id
                LEFT JOIN profiles p1 ON u1.user_id = p1.user_id
                LEFT JOIN employees emp2 ON us.created_by = emp2.employee_id
                LEFT JOIN users u2 ON emp2.user_id = u2.user_id
                LEFT JOIN profiles p2 ON u2.user_id = p2.user_id
                WHERE us.project_id = ?
                ORDER BY 
                    CASE 
                        WHEN us.status = 'todo' THEN 1
                        WHEN us.status = 'in_progress' THEN 2
                        WHEN us.status = 'review' THEN 3
                        WHEN us.status = 'done' THEN 4
                        ELSE 5
                    END,
                    us.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stories = [];
        while ($row = $result->fetch_assoc()) {
            $stories[] = $row;
        }
        
        sendResponse(true, $stories, 'User stories fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Create user story
if ($action === 'create_user_story') {
    try {
        $data = getRequestData();
        
        if (empty($data['project_id']) || empty($data['story_title']) || empty($data['description'])) {
            sendResponse(false, [], 'Project ID, story title and description are required');
        }
        
        $sql = "INSERT INTO user_stories (project_id, epic_id, story_title, description, acceptance_criteria, story_points, priority, business_value, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $epic_id = !empty($data['epic_id']) ? $data['epic_id'] : null;
        $acceptance_criteria = $data['acceptance_criteria'] ?? '';
        $story_points = !empty($data['story_points']) ? $data['story_points'] : null;
        $priority = $data['priority'] ?? 'medium';
        $business_value = !empty($data['business_value']) ? $data['business_value'] : null;
        $created_by = $data['created_by'] ?? 1;
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iissssisi', 
            $data['project_id'],
            $epic_id,
            $data['story_title'],
            $data['description'],
            $acceptance_criteria,
            $story_points,
            $priority,
            $business_value,
            $created_by
        );
        
        if ($stmt->execute()) {
            $story_id = $conn->insert_id;
            sendResponse(true, ['story_id' => $story_id], 'User story created successfully');
        } else {
            sendResponse(false, [], 'Failed to create user story: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get project sprints
if ($action === 'get_project_sprints') {
    try {
        $project_id = $_GET['project_id'] ?? '';
        
        if (empty($project_id)) {
            sendResponse(false, [], 'Project ID is required');
        }
        
        $sql = "SELECT s.*, 
                       COUNT(us.story_id) as total_stories,
                       SUM(us.story_points) as total_points,
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as created_by_name
                FROM sprints s
                LEFT JOIN user_stories us ON s.sprint_id = us.sprint_id
                LEFT JOIN employees emp ON s.created_by = emp.employee_id
                LEFT JOIN users u ON emp.user_id = u.user_id
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE s.project_id = ?
                GROUP BY s.sprint_id
                ORDER BY 
                    CASE 
                        WHEN s.status = 'active' THEN 1
                        WHEN s.status = 'planned' THEN 2
                        WHEN s.status = 'completed' THEN 3
                        ELSE 4
                    END,
                    s.start_date ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $sprints = [];
        while ($row = $result->fetch_assoc()) {
            $sprints[] = $row;
        }
        
        sendResponse(true, $sprints, 'Sprints fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Create sprint
if ($action === 'create_sprint') {
    try {
        $data = getRequestData();
        
        if (empty($data['project_id']) || empty($data['sprint_name']) || empty($data['start_date']) || empty($data['end_date'])) {
            sendResponse(false, [], 'Project ID, sprint name, start date and end date are required');
        }
        
        $sql = "INSERT INTO sprints (project_id, sprint_name, goal, start_date, end_date, velocity, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $goal = $data['goal'] ?? '';
        $velocity = !empty($data['velocity']) ? $data['velocity'] : null;
        $created_by = $data['created_by'] ?? 1;
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('issssii', 
            $data['project_id'],
            $data['sprint_name'],
            $goal,
            $data['start_date'],
            $data['end_date'],
            $velocity,
            $created_by
        );
        
        if ($stmt->execute()) {
            $sprint_id = $conn->insert_id;
            sendResponse(true, ['sprint_id' => $sprint_id], 'Sprint created successfully');
        } else {
            sendResponse(false, [], 'Failed to create sprint: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Start sprint
if ($action === 'start_sprint') {
    try {
        $data = getRequestData();
        
        if (empty($data['sprint_id'])) {
            sendResponse(false, [], 'Sprint ID is required');
        }
        
        // First, check if there's already an active sprint for this project
        $check_sql = "SELECT project_id FROM sprints WHERE sprint_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('i', $data['sprint_id']);
        $check_stmt->execute();
        $sprint = $check_stmt->get_result()->fetch_assoc();
        
        if ($sprint) {
            // Deactivate any other active sprints for this project
            $deactivate_sql = "UPDATE sprints SET status = 'planned' WHERE project_id = ? AND status = 'active'";
            $deactivate_stmt = $conn->prepare($deactivate_sql);
            $deactivate_stmt->bind_param('i', $sprint['project_id']);
            $deactivate_stmt->execute();
            
            // Activate the selected sprint
            $activate_sql = "UPDATE sprints SET status = 'active' WHERE sprint_id = ?";
            $activate_stmt = $conn->prepare($activate_sql);
            $activate_stmt->bind_param('i', $data['sprint_id']);
            
            if ($activate_stmt->execute()) {
                sendResponse(true, [], 'Sprint started successfully');
            } else {
                sendResponse(false, [], 'Failed to start sprint: ' . $activate_stmt->error);
            }
        } else {
            sendResponse(false, [], 'Sprint not found');
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Complete sprint
if ($action === 'complete_sprint') {
    try {
        $data = getRequestData();
        
        if (empty($data['sprint_id'])) {
            sendResponse(false, [], 'Sprint ID is required');
        }
        
        $sql = "UPDATE sprints SET status = 'completed' WHERE sprint_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $data['sprint_id']);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Sprint completed successfully');
        } else {
            sendResponse(false, [], 'Failed to complete sprint: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Add story to sprint
if ($action === 'add_story_to_sprint') {
    try {
        $data = getRequestData();
        
        if (empty($data['story_id']) || empty($data['sprint_id'])) {
            sendResponse(false, [], 'Story ID and Sprint ID are required');
        }
        
        $sql = "UPDATE user_stories SET sprint_id = ?, status = 'todo' WHERE story_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $data['sprint_id'], $data['story_id']);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Story added to sprint successfully');
        } else {
            sendResponse(false, [], 'Failed to add story to sprint: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Remove story from sprint
if ($action === 'remove_story_from_sprint') {
    try {
        $data = getRequestData();
        
        if (empty($data['story_id'])) {
            sendResponse(false, [], 'Story ID is required');
        }
        
        $sql = "UPDATE user_stories SET sprint_id = NULL, status = 'backlog' WHERE story_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $data['story_id']);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Story removed from sprint successfully');
        } else {
            sendResponse(false, [], 'Failed to remove story from sprint: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Update story status
if ($action === 'update_story_status') {
    try {
        $data = getRequestData();
        
        if (empty($data['story_id']) || empty($data['status'])) {
            sendResponse(false, [], 'Story ID and status are required');
        }
        
        $allowed_statuses = ['backlog', 'todo', 'in_progress', 'review', 'done'];
        if (!in_array($data['status'], $allowed_statuses)) {
            sendResponse(false, [], 'Invalid status');
        }
        
        $sql = "UPDATE user_stories SET status = ?, updated_at = NOW() WHERE story_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $data['status'], $data['story_id']);
        
        if ($stmt->execute()) {
            sendResponse(true, [], 'Story status updated successfully');
        } else {
            sendResponse(false, [], 'Failed to update story status: ' . $stmt->error);
        }
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// Get sprint backlog
if ($action === 'get_sprint_backlog') {
    try {
        $sprint_id = $_GET['sprint_id'] ?? '';
        
        if (empty($sprint_id)) {
            sendResponse(false, [], 'Sprint ID is required');
        }
        
        $sql = "SELECT us.*, 
                       e.epic_name,
                       COALESCE(CONCAT(p.first_name, ' ', p.last_name), u.username) as assigned_to_name
                FROM user_stories us
                LEFT JOIN epics e ON us.epic_id = e.epic_id
                LEFT JOIN employees emp ON us.assigned_to = emp.employee_id
                LEFT JOIN users u ON emp.user_id = u.user_id
                LEFT JOIN profiles p ON u.user_id = p.user_id
                WHERE us.sprint_id = ?
                ORDER BY 
                    CASE 
                        WHEN us.status = 'todo' THEN 1
                        WHEN us.status = 'in_progress' THEN 2
                        WHEN us.status = 'review' THEN 3
                        WHEN us.status = 'done' THEN 4
                        ELSE 5
                    END,
                    us.priority DESC,
                    us.story_points DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $sprint_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stories = [];
        while ($row = $result->fetch_assoc()) {
            $stories[] = $row;
        }
        
        sendResponse(true, $stories, 'Sprint backlog fetched successfully');
    } catch (Exception $e) {
        sendResponse(false, [], 'Error: ' . $e->getMessage());
    }
}

// If no valid action specified
sendResponse(false, [], 'Invalid action specified');

// Close database connection
$conn->close();
?>