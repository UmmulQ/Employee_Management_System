<?php
require_once 'config.php';

try {
    $sql = "SELECT 
                task_id,
                task_title,
                status,
                priority,
                due_date
            FROM tasks 
            WHERE status = 'in_progress'
            ORDER BY due_date ASC 
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
    sendResponse(false, null, "Error fetching active tasks: " . $e->getMessage());
}
?>