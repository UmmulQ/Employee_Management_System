<?php
require_once 'config.php';

try {
    $sql = "SELECT 
                t.task_id,
                t.task_title,
                t.description,
                t.priority,
                t.status,
                t.due_date,
                t.created_at,
                t.updated_at,
                p.project_name,
                assigned_profile.first_name as assigned_first_name,
                assigned_profile.last_name as assigned_last_name,
                assigned_by_profile.first_name as assigned_by_first_name,
                assigned_by_profile.last_name as assigned_by_last_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.project_id
            LEFT JOIN employees assigned_emp ON t.assigned_to = assigned_emp.employee_id
            LEFT JOIN profiles assigned_profile ON assigned_emp.user_id = assigned_profile.user_id
            LEFT JOIN employees assigned_by_emp ON t.assigned_by = assigned_by_emp.employee_id
            LEFT JOIN profiles assigned_by_profile ON assigned_by_emp.user_id = assigned_by_profile.user_id
            ORDER BY t.created_at DESC";
    
    $result = $conn->query($sql);
    $tasks = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
    }
    
    sendResponse(true, ['tasks' => $tasks]);
    
} catch (Exception $e) {
    sendResponse(false, null, "Error fetching tasks: " . $e->getMessage());
}
?>