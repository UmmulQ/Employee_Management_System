<?php
require_once 'config.php';

try {
    $sql = "SELECT 
                t.task_id,
                t.task_title,
                t.status,
                t.priority,
                t.due_date,
                p.project_name,
                p.project_id
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.project_id
            ORDER BY t.created_at DESC 
            LIMIT 10";
    
    $result = $conn->query($sql);
    $tasks = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
    }
    
    sendResponse(true, ['tasks' => $tasks]);
    
} catch (Exception $e) {
    sendResponse(false, null, "Error fetching tasks with projects: " . $e->getMessage());
}
?>