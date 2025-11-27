<?php
session_start();
require_once "connect.php"; // ✅ use the connection here

$user_id = $_SESSION['user_id'] ?? 1; // assume logged in user for now

// Example: fetch profile
$sql = "SELECT u.username, p.first_name, p.last_name, p.profile_picture_url 
        FROM users u 
        JOIN profiles p ON u.user_id = p.user_id 
        WHERE u.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
  <title>User Dashboard</title>
</head>
<body>
  <h1>Welcome <?= htmlspecialchars($profile['first_name']) ?></h1>
  <img src="<?= $profile['profile_picture_url'] ?: 'default-avatar.png' ?>" width="60">
</body>
</html>
