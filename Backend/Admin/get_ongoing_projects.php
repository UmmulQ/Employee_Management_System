<?php
require_once 'config.php';

try {
    $sql = "SELECT 
                p.project_id,
                p.project_name,
                p.status,
                p.start_date,
                p.deadline,
                c.company_name
            FROM projects p
            LEFT JOIN clients c ON p.client_id = c.client_id
            WHERE p.status IN ('in_progress', 'pending', 'active')
            ORDER BY p.start_date DESC 
            LIMIT 10";
    
    $result = $conn->query($sql);
    $projects = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }
    
    sendResponse(true, ['projects' => $projects]);
    
} catch (Exception $e) {
    sendResponse(false, null, "Error fetching ongoing projects: " . $e->getMessage());
}
?>