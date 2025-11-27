<?php
require_once 'config.php';

try {
    $stats = [];
    
    // Total Employees (from employees table)
    $result = $conn->query("SELECT COUNT(*) as count FROM employees WHERE is_active = 1");
    $stats['employees'] = $result->fetch_assoc()['count'];
    
    // Total Clients
    $result = $conn->query("SELECT COUNT(*) as count FROM clients");
    $stats['clients'] = $result->fetch_assoc()['count'];
    
    // Total Users
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
    $stats['total_users'] = $result->fetch_assoc()['count'];
    
    // Total Tasks
    $result = $conn->query("SELECT COUNT(*) as count FROM tasks");
    $stats['total_tasks'] = $result->fetch_assoc()['count'];
    
    // Total Projects
    $result = $conn->query("SELECT COUNT(*) as count FROM projects");
    $stats['total_projects'] = $result->fetch_assoc()['count'];
    
    // Active Tasks
    $result = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'in_progress'");
    $stats['active_tasks'] = $result->fetch_assoc()['count'];
    
    // Pending Tasks
    $result = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'pending'");
    $stats['pending_tasks'] = $result->fetch_assoc()['count'];
    
    // Active Users (simplified, since last_active_time doesn't exist)
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
    $stats['active_users'] = $result->fetch_assoc()['count'];
    
    // Upcoming Interviews (placeholder)
    $stats['upcoming_interviews'] = 0;
    
    // New Leave Requests
    $result = $conn->query("SELECT COUNT(*) as count FROM leaves WHERE status = 'pending'");
    $stats['new_leave_requests'] = $result->fetch_assoc()['count'];
    
    // Total Departments
    $result = $conn->query("SELECT COUNT(DISTINCT department) as count FROM employees WHERE department IS NOT NULL AND department != ''");
    $stats['total_departments'] = $result->fetch_assoc()['count'];
    
    sendResponse(true, $stats);
    
} catch (Exception $e) {
    sendResponse(false, null, "Error fetching statistics: " . $e->getMessage());
}
?>
