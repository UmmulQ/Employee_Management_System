<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include config file
require_once __DIR__ . '/config.php';

class ProjectManagement {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }

    // Get all projects with related data
    public function getProjects($filters = []) {
        try {
            $whereConditions = [];
            $params = [];
            $types = "";

            // Build WHERE conditions based on filters
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $whereConditions[] = "p.status = ?";
                $params[] = $filters['status'];
                $types .= "s";
            }

            if (!empty($filters['search'])) {
                $whereConditions[] = "(p.project_name LIKE ? OR p.description LIKE ?)";
                $params[] = "%" . $filters['search'] . "%";
                $params[] = "%" . $filters['search'] . "%";
                $types .= "ss";
            }

            $whereClause = "";
            if (!empty($whereConditions)) {
                $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            }

            // Build ORDER BY clause
            $orderBy = "ORDER BY p.created_at DESC";
            if (!empty($filters['sort_by'])) {
                $sortOrder = (!empty($filters['sort_order']) && strtoupper($filters['sort_order']) === 'ASC') ? 'ASC' : 'DESC';
                
                switch($filters['sort_by']) {
                    case 'project_name':
                        $orderBy = "ORDER BY p.project_name $sortOrder";
                        break;
                    case 'deadline':
                        $orderBy = "ORDER BY p.deadline $sortOrder";
                        break;
                    case 'created_at':
                        $orderBy = "ORDER BY p.created_at $sortOrder";
                        break;
                    default:
                        $orderBy = "ORDER BY p.created_at DESC";
                }
            }

            $query = "SELECT 
                        p.*,
                        -- tl.first_name as team_lead_first_name,
                        -- tl.last_name as team_lead_last_name,
                        tl.position as team_lead_position,
                        -- c.client_name,
                        c.company_name,
                        COUNT(DISTINCT pm.employee_id) as team_members_count,
                        COUNT(DISTINCT t.task_id) as total_tasks,
                        COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.task_id END) as completed_tasks,
                        CASE 
                            WHEN COUNT(DISTINCT t.task_id) = 0 THEN 0
                            ELSE ROUND((COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.task_id END) * 100.0 / COUNT(DISTINCT t.task_id)), 0)
                        END as progress
                      FROM projects p
                      LEFT JOIN employees tl ON p.team_lead_id = tl.employee_id
                      LEFT JOIN clients c ON p.client_id = c.client_id
                      LEFT JOIN project_members pm ON p.project_id = pm.project_id
                      LEFT JOIN tasks t ON p.project_id = t.project_id
                      $whereClause
                      GROUP BY p.project_id
                      $orderBy";

            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $projects = [];
            while ($row = $result->fetch_assoc()) {
                $projects[] = $row;
            }
            
            return $projects;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get project by ID
    public function getProjectById($project_id) {
        try {
            $query = "SELECT 
                        p.*,
                        tl.first_name as team_lead_first_name,
                        tl.last_name as team_lead_last_name,
                        tl.position as team_lead_position,
                        c.client_name,
                        c.company_name
                      FROM projects p
                      LEFT JOIN employees tl ON p.team_lead_id = tl.employee_id
                      LEFT JOIN clients c ON p.client_id = c.client_id
                      WHERE p.project_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Create new project
    public function createProject($data) {
        try {
            // Validate required fields
            $required = ['project_name', 'team_lead_id', 'start_date', 'deadline'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['error' => "Field '$field' is required"];
                }
            }

            $query = "INSERT INTO projects 
                      (project_name, description, client_id, team_lead_id, start_date, deadline, status, created_at, updated_at) 
                      VALUES 
                      (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = $this->conn->prepare($query);
            
            // Set default status if not provided
            $status = isset($data['status']) ? $data['status'] : 'active';
            $client_id = !empty($data['client_id']) ? $data['client_id'] : null;
            
            $stmt->bind_param(
                "ssiisss", 
                $data['project_name'],
                $data['description'],
                $client_id,
                $data['team_lead_id'],
                $data['start_date'],
                $data['deadline'],
                $status
            );
            
            if ($stmt->execute()) {
                $project_id = $this->conn->insert_id;
                return [
                    'success' => 'Project created successfully',
                    'project_id' => $project_id,
                    'project' => $this->getProjectById($project_id)
                ];
            } else {
                return ['error' => 'Failed to create project: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Update project
    public function updateProject($data) {
        try {
            if (empty($data['project_id'])) {
                return ['error' => 'Project ID is required'];
            }
            
            // Check if project exists
            $existingProject = $this->getProjectById($data['project_id']);
            if (!$existingProject) {
                return ['error' => 'Project not found'];
            }

            $query = "UPDATE projects SET 
                      project_name = ?,
                      description = ?,
                      client_id = ?,
                      team_lead_id = ?,
                      start_date = ?,
                      deadline = ?,
                      status = ?,
                      updated_at = NOW()
                      WHERE project_id = ?";
            
            $stmt = $this->conn->prepare($query);
            
            $client_id = !empty($data['client_id']) ? $data['client_id'] : null;
            
            $stmt->bind_param(
                "ssiisssi",
                $data['project_name'],
                $data['description'],
                $client_id,
                $data['team_lead_id'],
                $data['start_date'],
                $data['deadline'],
                $data['status'],
                $data['project_id']
            );
            
            if ($stmt->execute()) {
                return [
                    'success' => 'Project updated successfully',
                    'project' => $this->getProjectById($data['project_id'])
                ];
            } else {
                return ['error' => 'Failed to update project: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Delete project
    public function deleteProject($data) {
        try {
            if (empty($data['project_id'])) {
                return ['error' => 'Project ID is required'];
            }
            
            // Start transaction
            $this->conn->begin_transaction();

            try {
                // Delete project members first
                $deleteMembersQuery = "DELETE FROM project_members WHERE project_id = ?";
                $stmt1 = $this->conn->prepare($deleteMembersQuery);
                $stmt1->bind_param("i", $data['project_id']);
                $stmt1->execute();

                // Delete project tasks
                $deleteTasksQuery = "DELETE FROM tasks WHERE project_id = ?";
                $stmt2 = $this->conn->prepare($deleteTasksQuery);
                $stmt2->bind_param("i", $data['project_id']);
                $stmt2->execute();

                // Delete project
                $deleteProjectQuery = "DELETE FROM projects WHERE project_id = ?";
                $stmt3 = $this->conn->prepare($deleteProjectQuery);
                $stmt3->bind_param("i", $data['project_id']);
                $stmt3->execute();

                if ($stmt3->affected_rows > 0) {
                    $this->conn->commit();
                    return ['success' => 'Project deleted successfully'];
                } else {
                    $this->conn->rollback();
                    return ['error' => 'Project not found'];
                }
                
            } catch (Exception $e) {
                $this->conn->rollback();
                return ['error' => 'Error deleting project: ' . $e->getMessage()];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get project statistics
    public function getProjectStats() {
        try {
            $query = "SELECT 
                        COUNT(*) as total_projects,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_projects,
                        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_projects,
                        COUNT(CASE WHEN status = 'on_hold' THEN 1 END) as on_hold_projects,
                        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_projects,
                        COUNT(CASE WHEN deadline < CURDATE() AND status NOT IN ('completed', 'cancelled') THEN 1 END) as overdue_projects,
                        CASE 
                            WHEN COUNT(*) = 0 THEN 0
                            ELSE ROUND((COUNT(CASE WHEN status = 'completed' THEN 1 END) * 100.0 / COUNT(*)), 0)
                        END as completion_rate
                      FROM projects";
            
            $result = $this->conn->query($query);
            $stats = $result->fetch_assoc();

            // Get status counts
            $statusQuery = "SELECT status, COUNT(*) as count FROM projects GROUP BY status";
            $statusResult = $this->conn->query($statusQuery);
            
            $status_counts = [];
            while ($row = $statusResult->fetch_assoc()) {
                $status_counts[] = $row;
            }

            $stats['status_counts'] = $status_counts;
            
            return $stats;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get all employees (for team lead and member selection)
    public function getEmployees() {
        try {
            $query = "SELECT 
                        employee_id,
                        first_name,
                        last_name,
                        email,
                        position,
                        department
                      FROM employees 
                      ORDER BY first_name, last_name";
            
            $result = $this->conn->query($query);
            
            $employees = [];
            while ($row = $result->fetch_assoc()) {
                $employees[] = $row;
            }
            
            return $employees;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get all clients
    public function getClients() {
        try {
            $query = "SELECT 
                        client_id,
                        client_name,
                        company_name,
                        email,
                        phone
                      FROM clients 
                      ORDER BY company_name";
            
            $result = $this->conn->query($query);
            
            $clients = [];
            while ($row = $result->fetch_assoc()) {
                $clients[] = $row;
            }
            
            return $clients;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get project members
    public function getProjectMembers($project_id) {
        try {
            $query = "SELECT 
                        pm.project_member_id,
                        pm.role,
                        pm.assigned_date,
                        e.employee_id,
                        e.first_name,
                        e.last_name,
                        e.email,
                        e.position,
                        e.department
                      FROM project_members pm
                      JOIN employees e ON pm.employee_id = e.employee_id
                      WHERE pm.project_id = ?
                      ORDER BY pm.assigned_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $members = [];
            while ($row = $result->fetch_assoc()) {
                $members[] = $row;
            }
            
            return $members;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Add project member
    public function addProjectMember($data) {
        try {
            // Validate required fields
            $required = ['project_id', 'employee_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['error' => "Field '$field' is required"];
                }
            }

            // Check if member already exists in project
            $checkQuery = "SELECT project_member_id FROM project_members WHERE project_id = ? AND employee_id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bind_param("ii", $data['project_id'], $data['employee_id']);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                return ['error' => 'Employee is already a member of this project'];
            }

            $query = "INSERT INTO project_members
                      (project_id, employee_id, role, assigned_date) 
                      VALUES 
                      (?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            
            $role = isset($data['role']) ? $data['role'] : 'developer';
            
            $stmt->bind_param(
                "iis", 
                $data['project_id'],
                $data['employee_id'],
                $role
            );
            
            if ($stmt->execute()) {
                $project_member_id = $this->conn->insert_id;
                return [
                    'success' => 'Team member added successfully',
                    'project_member_id' => $project_member_id
                ];
            } else {
                return ['error' => 'Failed to add team member: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Remove project member
    public function removeProjectMember($data) {
        try {
            if (empty($data['project_member_id'])) {
                return ['error' => 'Project Member ID is required'];
            }
            
            $query = "DELETE FROM project_members WHERE project_member_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $data['project_member_id']);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    return ['success' => 'Team member removed successfully'];
                } else {
                    return ['error' => 'Team member not found'];
                }
            } else {
                return ['error' => 'Failed to remove team member: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get project tasks
    public function getProjectTasks($project_id) {
        try {
            $query = "SELECT 
                        t.task_id,
                        t.task_name,
                        t.description,
                        t.priority,
                        t.status,
                        t.due_date,
                        t.created_at,
                        a.first_name as assigned_first_name,
                        a.last_name as assigned_last_name
                      FROM tasks t
                      LEFT JOIN employees a ON t.assigned_to = a.employee_id
                      WHERE t.project_id = ?
                      ORDER BY t.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $tasks = [];
            while ($row = $result->fetch_assoc()) {
                $tasks[] = $row;
            }
            
            return $tasks;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

$projectManager = new ProjectManagement($conn);
$method = $_SERVER['REQUEST_METHOD'];

// Handle different endpoints and parameters
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$query_string = parse_url($request_uri, PHP_URL_QUERY);
$segments = explode('/', $path);
$endpoint = end($segments);

// Parse query parameters
$query_params = [];
if ($query_string) {
    parse_str($query_string, $query_params);
}

// Get input data for POST, PUT, DELETE
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        $action = $query_params['action'] ?? '';
        
        switch ($action) {
            case 'get_projects':
                $filters = [
                    'status' => $query_params['status'] ?? 'all',
                    'search' => $query_params['search'] ?? '',
                    'sort_by' => $query_params['sort_by'] ?? 'created_at',
                    'sort_order' => $query_params['sort_order'] ?? 'DESC'
                ];
                $result = $projectManager->getProjects($filters);
                break;
                
            case 'get_project_stats':
                $result = $projectManager->getProjectStats();
                break;
                
            case 'get_employees':
                $result = $projectManager->getEmployees();
                break;
                
            case 'get_clients':
                $result = $projectManager->getClients();
                break;
                
            case 'get_project_members':
                if (isset($query_params['project_id'])) {
                    $result = $projectManager->getProjectMembers($query_params['project_id']);
                } else {
                    $result = ['error' => 'Project ID is required'];
                }
                break;
                
            case 'get_project_tasks':
                if (isset($query_params['project_id'])) {
                    $result = $projectManager->getProjectTasks($query_params['project_id']);
                } else {
                    $result = ['error' => 'Project ID is required'];
                }
                break;
                
            default:
                // If no action specified, return projects by default
                $filters = [
                    'status' => $query_params['status'] ?? 'all',
                    'search' => $query_params['search'] ?? '',
                    'sort_by' => $query_params['sort_by'] ?? 'created_at',
                    'sort_order' => $query_params['sort_order'] ?? 'DESC'
                ];
                $result = $projectManager->getProjects($filters);
                break;
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, 'Data retrieved successfully');
        }
        break;
        
    case 'POST':
        $action = $query_params['action'] ?? '';
        
        switch ($action) {
            case 'create_project':
                $result = $projectManager->createProject($input);
                break;
                
            case 'add_project_member':
                $result = $projectManager->addProjectMember($input);
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
        
    case 'PUT':
        $action = $query_params['action'] ?? '';
        
        switch ($action) {
            case 'update_project':
                $result = $projectManager->updateProject($input);
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
        
    case 'DELETE':
        $action = $query_params['action'] ?? '';
        
        switch ($action) {
            case 'delete_project':
                $result = $projectManager->deleteProject($input);
                break;
                
            case 'remove_project_member':
                $result = $projectManager->removeProjectMember($input);
                break;
                
            default:
                $result = ['error' => 'Invalid action'];
                break;
        }
        
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