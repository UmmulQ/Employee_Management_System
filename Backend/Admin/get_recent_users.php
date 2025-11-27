<?php
require_once 'config.php';

try {
    $sql = "SELECT 
                u.user_id,
                u.username,
                u.role_id,
                u.created_at,
                p.first_name,
                p.last_name,
                p.email,
                CASE 
                    WHEN e.employee_id IS NOT NULL THEN 'employee'
                    WHEN c.client_id IS NOT NULL THEN 'client'
                    ELSE 'user'
                END as user_type
            FROM users u
            LEFT JOIN profiles p ON u.user_id = p.user_id
            LEFT JOIN employees e ON u.user_id = e.user_id
            LEFT JOIN clients c ON u.user_id = c.user_id
            WHERE u.is_active = 1
            ORDER BY u.created_at DESC 
            LIMIT 10";
    
    $result = $conn->query($sql);
    $recentUsers = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $recentUsers[] = $row;
        }
    }
    
    sendResponse(true, ['users' => $recentUsers]);
    
} catch (Exception $e) {
    sendResponse(false, null, "Error fetching recent users: " . $e->getMessage());
}
?>