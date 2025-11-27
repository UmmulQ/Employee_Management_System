<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once "connect.php";

$sql = "SELECT * FROM projects ORDER BY created_at DESC";
$result = $conn->query($sql);

$projects = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }

    echo json_encode([
        "success" => true,
        "projects" => $projects
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "No projects found"
    ]);
}

$conn->close();
?>
