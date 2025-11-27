<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once __DIR__ . '/../connect.php';

class TaskManagement {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    // Get all tasks with user details
    public function getTasks() {
        try {
            $query = "SELECT 
                        t.*, 
                        u1.username as assigned_to_name,
                        u2.username as assigned_by_name,
                        p.project_name
                      FROM tasks t
                      LEFT JOIN users u1 ON t.assigned_to = u1.user_id
                      LEFT JOIN users u2 ON t.assigned_by = u2.user_id
                      LEFT JOIN projects p ON t.project_id = p.project_id
                      ORDER BY t.created_at DESC";
            
            $result = $this->conn->query($query);
            
            if (!$result) {
                return ['error' => 'Database query failed: ' . $this->conn->error];
            }
            
            $tasks = [];
            while ($row = $result->fetch_assoc()) {
                $tasks[] = $row;
            }
            
            return $tasks;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Get task by ID
    public function getTaskById($task_id) {
        try {
            $query = "SELECT 
                        t.*, 
                        u1.username as assigned_to_name,
                        u2.username as assigned_by_name,
                        p.project_name
                      FROM tasks t
                      LEFT JOIN users u1 ON t.assigned_to = u1.user_id
                      LEFT JOIN users u2 ON t.assigned_by = u2.user_id
                      LEFT JOIN projects p ON t.project_id = p.project_id
                      WHERE t.task_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $task_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get default project ID (first active project)
    public function getDefaultProjectId() {
        try {
            $query = "SELECT project_id FROM projects WHERE status = 'active' ORDER BY project_id LIMIT 1";
            $result = $this->conn->query($query);
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                return $row['project_id'];
            }
            
            // If no active projects, get any project
            $query = "SELECT project_id FROM projects ORDER BY project_id LIMIT 1";
            $result = $this->conn->query($query);
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                return $row['project_id'];
            }
            
            return 1; // Default fallback
            
        } catch (Exception $e) {
            return 1; // Default fallback
        }
    }

    // Get available projects for dropdown
    public function getAvailableProjects() {
        try {
            $query = "SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name";
            $result = $this->conn->query($query);
            
            if (!$result) {
                return [];
            }
            
            $projects = [];
            while ($row = $result->fetch_assoc()) {
                $projects[] = $row;
            }
            
            return $projects;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    // Create new task
    public function createTask($data) {
        try {
            // Validate required fields
            $required = ['task_title', 'description', 'assigned_to'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['error' => "Field '$field' is required"];
                }
            }

            // Set default project_id if not provided
            if (empty($data['project_id'])) {
                $data['project_id'] = $this->getDefaultProjectId();
            }

            // Set assigned_by to current admin (you can get this from session)
            // For now, we'll set it to 1 (admin user) or from the request
            if (empty($data['assigned_by'])) {
                $data['assigned_by'] = 1; // Default admin user ID
            }
            
            $query = "INSERT INTO tasks 
                      (task_title, description, project_id, assigned_to, assigned_by, priority, status, due_date, created_at, updated_at) 
                      VALUES 
                      (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = $this->conn->prepare($query);
            
            // Set default values if not provided
            $priority = isset($data['priority']) ? $data['priority'] : 'medium';
            $status = isset($data['status']) ? $data['status'] : 'pending';
            $due_date = isset($data['due_date']) ? $data['due_date'] : date('Y-m-d', strtotime('+7 days'));
            
            $stmt->bind_param(
                "ssiiisss", 
                $data['task_title'],
                $data['description'],
                $data['project_id'],
                $data['assigned_to'],
                $data['assigned_by'],
                $priority,
                $status,
                $due_date
            );
            
            if ($stmt->execute()) {
                $task_id = $this->conn->insert_id;
                return [
                    'success' => 'Task created successfully',
                    'task_id' => $task_id,
                    'task' => $this->getTaskById($task_id)
                ];
            } else {
                return ['error' => 'Failed to create task: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Update task
    public function updateTask($data) {
        try {
            if (empty($data['task_id'])) {
                return ['error' => 'Task ID is required'];
            }
            
            // Check if task exists
            $existingTask = $this->getTaskById($data['task_id']);
            if (!$existingTask) {
                return ['error' => 'Task not found'];
            }
            
            $query = "UPDATE tasks SET 
                      task_title = ?,
                      description = ?,
                      project_id = ?,
                      assigned_to = ?,
                      priority = ?,
                      status = ?,
                      due_date = ?,
                      updated_at = NOW()
                      WHERE task_id = ?";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->bind_param(
                "ssiissii",
                $data['task_title'],
                $data['description'],
                $data['project_id'],
                $data['assigned_to'],
                $data['priority'],
                $data['status'],
                $data['due_date'],
                $data['task_id']
            );
            
            if ($stmt->execute()) {
                return [
                    'success' => 'Task updated successfully',
                    'task' => $this->getTaskById($data['task_id'])
                ];
            } else {
                return ['error' => 'Failed to update task: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Delete task
    public function deleteTask($data) {
        try {
            if (empty($data['task_id'])) {
                return ['error' => 'Task ID is required'];
            }
            
            $query = "DELETE FROM tasks WHERE task_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $data['task_id']);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    return ['success' => 'Task deleted successfully'];
                } else {
                    return ['error' => 'Task not found'];
                }
            } else {
                return ['error' => 'Failed to delete task: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Update task status only
    public function updateTaskStatus($task_id, $status) {
        try {
            // Check if task exists
            $existingTask = $this->getTaskById($task_id);
            if (!$existingTask) {
                return ['error' => 'Task not found'];
            }
            
            $valid_statuses = ['pending', 'in_progress', 'completed'];
            if (!in_array($status, $valid_statuses)) {
                return ['error' => 'Invalid status'];
            }
            
            $query = "UPDATE tasks SET 
                      status = ?,
                      updated_at = NOW()
                      WHERE task_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("si", $status, $task_id);
            
            if ($stmt->execute()) {
                return [
                    'success' => 'Task status updated successfully',
                    'task' => $this->getTaskById($task_id)
                ];
            } else {
                return ['error' => 'Failed to update task status: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Handle requests
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

function sendResponse($success, $data = null, $message = '') {
    http_response_code($success ? 200 : 400);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

$taskManager = new TaskManagement($conn);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check if requesting projects
        if (isset($_GET['action']) && $_GET['action'] === 'projects') {
            $result = $taskManager->getAvailableProjects();
            sendResponse(true, $result, 'Projects retrieved successfully');
        } else {
            $result = $taskManager->getTasks();
            if (isset($result['error'])) {
                sendResponse(false, null, $result['error']);
            } else {
                sendResponse(true, $result, 'Tasks retrieved successfully');
            }
        }
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $taskManager->createTask($input);
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, $result['success']);
        }
        break;
        
    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Check if it's a status update only
        if (isset($input['task_id']) && isset($input['status']) && count($input) == 2) {
            $result = $taskManager->updateTaskStatus($input['task_id'], $input['status']);
        } else {
            $result = $taskManager->updateTask($input);
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, $result['success']);
        }
        break;
        
    case 'DELETE':
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $taskManager->deleteTask($input);
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, null, $result['success']);
        }
        break;
        
    case 'OPTIONS':
        http_response_code(200);
        exit();
        
    default:
        http_response_code(405);
        sendResponse(false, null, 'Method not allowed');
        break;
}
?>