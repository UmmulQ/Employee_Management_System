<?php
require_once 'config.php';

try {
    $sql = "SELECT 
                c.client_id,
                c.company_name,
                c.company_address,
                c.created_at,
                c.updated_at,
                p.first_name,
                p.last_name,
                p.email,
                p.phone,
                u.username
            FROM clients c
            LEFT JOIN profiles p ON c.user_id = p.user_id
            LEFT JOIN users u ON c.user_id = u.user_id
            ORDER BY c.created_at DESC";
    
    $result = $conn->query($sql);
    $clients = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
    }
    
    sendResponse(true, ['clients' => $clients]);
    
} catch (Exception $e) {
    sendResponse(false, null, "Error fetching clients: " . $e->getMessage());
}
?>