<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include config file
require_once __DIR__ . '/config.php';

class ClientManagement {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    // Get all clients with user details
    public function getClients() {
        try {
            $query = "SELECT 
                        c.*, 
                        u.username,
                        
                        u.role_id
                      FROM clients c
                      LEFT JOIN users u ON c.user_id = u.user_id
                      ORDER BY c.created_at DESC";
            
            $result = $this->conn->query($query);
            
            if (!$result) {
                return ['error' => 'Database query failed: ' . $this->conn->error];
            }
            
            $clients = [];
            while ($row = $result->fetch_assoc()) {
                $clients[] = $row;
            }
            
            return $clients;
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Get client by ID
    public function getClientById($client_id) {
        try {
            $query = "SELECT 
                        c.*, 
                        u.username,
                        u.email,
                        u.role_id
                      FROM clients c
                      LEFT JOIN users u ON c.user_id = u.user_id
                      WHERE c.client_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get client by user ID
    public function getClientByUserId($user_id) {
        try {
            $query = "SELECT 
                        c.*, 
                        u.username,
                        u.email,
                        u.role_id
                      FROM clients c
                      LEFT JOIN users u ON c.user_id = u.user_id
                      WHERE c.user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Create new client
    public function createClient($data) {
        try {
            // Validate required fields
            $required = ['user_id', 'company_name', 'company_address'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['error' => "Field '$field' is required"];
                }
            }

            // Check if user already has a client profile
            $existingClient = $this->getClientByUserId($data['user_id']);
            if ($existingClient) {
                return ['error' => 'User already has a client profile'];
            }
            
            $query = "INSERT INTO clients 
                      (user_id, company_name, company_address, created_at, updated_at) 
                      VALUES 
                      (?, ?, ?, NOW(), NOW())";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->bind_param(
                "iss", 
                $data['user_id'],
                $data['company_name'],
                $data['company_address']
            );
            
            if ($stmt->execute()) {
                $client_id = $this->conn->insert_id;
                return [
                    'success' => 'Client created successfully',
                    'client_id' => $client_id,
                    'client' => $this->getClientById($client_id)
                ];
            } else {
                return ['error' => 'Failed to create client: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Update client
    public function updateClient($data) {
        try {
            if (empty($data['client_id'])) {
                return ['error' => 'Client ID is required'];
            }
            
            // Check if client exists
            $existingClient = $this->getClientById($data['client_id']);
            if (!$existingClient) {
                return ['error' => 'Client not found'];
            }

            // Check if user_id is being changed and if new user already has a client profile
            if ($existingClient['user_id'] != $data['user_id']) {
                $existingClientWithNewUser = $this->getClientByUserId($data['user_id']);
                if ($existingClientWithNewUser) {
                    return ['error' => 'The selected user already has a client profile'];
                }
            }
            
            $query = "UPDATE clients SET 
                      user_id = ?,
                      company_name = ?,
                      company_address = ?,
                      updated_at = NOW()
                      WHERE client_id = ?";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->bind_param(
                "issi",
                $data['user_id'],
                $data['company_name'],
                $data['company_address'],
                $data['client_id']
            );
            
            if ($stmt->execute()) {
                return [
                    'success' => 'Client updated successfully',
                    'client' => $this->getClientById($data['client_id'])
                ];
            } else {
                return ['error' => 'Failed to update client: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // Delete client
    public function deleteClient($data) {
        try {
            if (empty($data['client_id'])) {
                return ['error' => 'Client ID is required'];
            }
            
            $query = "DELETE FROM clients WHERE client_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $data['client_id']);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    return ['success' => 'Client deleted successfully'];
                } else {
                    return ['error' => 'Client not found'];
                }
            } else {
                return ['error' => 'Failed to delete client: ' . $this->conn->error];
            }
            
        } catch (Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    // Get users without client profiles (for dropdown)
    public function getUsersWithoutClients() {
        try {
            $query = "SELECT 
                        u.user_id, 
                        u.username,
                        u.email,
                        u.role_id
                      FROM users u
                      LEFT JOIN clients c ON u.user_id = c.user_id
                      WHERE c.user_id IS NULL
                      ORDER BY u.username";
            
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
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}

$clientManager = new ClientManagement($conn);
$method = $_SERVER['REQUEST_METHOD'];

// Handle different endpoints
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', $path);
$endpoint = end($segments);

switch ($method) {
    case 'GET':
        if ($endpoint === 'available-users') {
            $result = $clientManager->getUsersWithoutClients();
        } else {
            $result = $clientManager->getClients();
        }
        
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, 'Clients retrieved successfully');
        }
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $clientManager->createClient($input);
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, $result['success']);
        }
        break;
        
    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $clientManager->updateClient($input);
        if (isset($result['error'])) {
            sendResponse(false, null, $result['error']);
        } else {
            sendResponse(true, $result, $result['success']);
        }
        break;
        
    case 'DELETE':
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $clientManager->deleteClient($input);
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