<?php
session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "connect.php";

$response = ["success" => false, "message" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $response["message"] = "Username and password are required";
        echo json_encode($response);
        exit;
    }

    // ✅ Fetch both role_id and role_name
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, u.password_hash, u.is_active, u.created_at, 
               u.role_id, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.username = ? LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password_hash'])) {
            if ($user['is_active'] == 1) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['role_name'] = $user['role_name'];

                $_SESSION['user'] = [
                    "user_id" => $user['user_id'],
                    "username" => $user['username'],
                    "role_id" => $user['role_id'], // ✅ Added
                    "role" => $user['role_name'],
                    "is_active" => $user['is_active'],
                    "created_at" => $user['created_at']
                ];

                $response["success"] = true;
                $response["message"] = "Login successful";
                $response["user"] = $_SESSION['user'];
            } else {
                $response["message"] = "Account is not active";
            }
        } else {
            $response["message"] = "Invalid password";
        }
    } else {
        $response["message"] = "User not found";
    }

    $stmt->close();
    $conn->close();
} else {
    $response["message"] = "Invalid request method";
}

echo json_encode($response);
