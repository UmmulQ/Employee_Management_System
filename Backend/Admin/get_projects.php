<?php
require_once 'config.php';

try {
    $sql = "SELECT 
                p.project_id,
                p.project_name,
                p.description,
                p.status,
                p.start_date,
                p.deadline,
                p.created_at,
                p.updated_at,
                c.company_name,
                team_lead_profile.first_name as team_lead_first_name,
                team_lead_profile.last_name as team_lead_last_name
            FROM projects p
            LEFT JOIN clients c ON p.client_id = c.client_id
            LEFT JOIN employees team_lead ON p.team_lead_id = team_lead.employee_id
            LEFT JOIN profiles team_lead_profile ON team_lead.user_id = team_lead_profile.user_id
            ORDER BY p.created_at DESC";
    
    $result = $conn->query($sql);
    $projects = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }
    
    sendResponse(true, ['projects' => $projects]);
    
} catch (Exception $e) {
    sendResponse(false, null, "Error fetching projects: " . $e->getMessage());
}
?>