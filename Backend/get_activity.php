<?php
// get_activity.php
header("Content-Type: application/json");
require_once "db.php";

$employee_id = isset($_GET["employee_id"]) ? intval($_GET["employee_id"]) : 0;
$limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 20;

$sql = "SELECT * FROM employee_activity 
        WHERE employee_id = '$employee_id' 
        ORDER BY activity_time DESC 
        LIMIT $limit";

$result = $conn->query($sql);

$activities = [];
while ($row = $result->fetch_assoc()) {
    $activities[] = $row;
}

echo json_encode([
    "success" => true,
    "activities" => $activities
]);
