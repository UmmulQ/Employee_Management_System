<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("
        SELECT 
            e.employee_id,
            e.employee_number,
            e.department,
            e.position,
            e.is_active,
            e.last_active_time,
            p.first_name,
            p.last_name,
            p.profile_picture_url
        FROM employees e
        JOIN profiles p ON e.user_id = p.user_id
        WHERE e.is_active = TRUE
        ORDER BY e.last_active_time DESC
    ");
    
    $stmt->execute();
    $active_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'active_employees' => $active_employees,
        'count' => count($active_employees)
    ]);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>