<?php
// db.php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "u950794707_ems"; // change to your DB name

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "DB connection error: " . $conn->connect_error]));
}
$conn->set_charset("utf8mb4");
?>
