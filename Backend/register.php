<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

require_once "connect.php";

$response = ["success" => false, "message" => ""];

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        // Inputs
        $firstName = $_POST['first_name'] ?? '';
        $lastName  = $_POST['last_name'] ?? '';
        $username  = $_POST['username'] ?? '';
        $email     = $_POST['email'] ?? '';
        $phone     = $_POST['phone'] ?? '';
        $address   = $_POST['address'] ?? '';
        $dob       = $_POST['date_of_birth'] ?? '';
        $role      = $_POST['role'] ?? ''; // e.g. admin, hr, employee
        $password  = $_POST['password'] ?? '';

        if (empty($username) || empty($password) || empty($role)) {
            $response["message"] = "Username, password and role are required";
            echo json_encode($response);
            exit;
        }

        // ✅ Check duplicate username
        $checkUser = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $checkUser->bind_param("s", $username);
        $checkUser->execute();
        $checkUser->store_result();
        if ($checkUser->num_rows > 0) {
            $response["message"] = "Username already exists";
            echo json_encode($response);
            exit;
        }
        $checkUser->close();

        // ✅ Check duplicate email
        if (!empty($email)) {
            $checkEmail = $conn->prepare("SELECT profile_id FROM profiles WHERE email = ?");
            $checkEmail->bind_param("s", $email);
            $checkEmail->execute();
            $checkEmail->store_result();
            if ($checkEmail->num_rows > 0) {
                $response["message"] = "Email already exists";
                echo json_encode($response);
                exit;
            }
            $checkEmail->close();
        }

        // ✅ Get role_id from roles table
        $getRole = $conn->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
        $getRole->bind_param("s", $role);
        $getRole->execute();
        $getRole->bind_result($roleId);

        if (!$getRole->fetch()) {
            $response["message"] = "Invalid role provided";
            echo json_encode($response);
            exit;
        }
        $getRole->close();

        // ✅ Handle profile picture upload
        $profilePictureUrl = null;
        if (isset($_FILES['profile_picture_url']) && $_FILES['profile_picture_url']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . "/uploads/";
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = uniqid("profile_") . "_" . basename($_FILES["profile_picture_url"]["name"]);
            $targetFile = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES["profile_picture_url"]["tmp_name"], $targetFile)) {
                $profilePictureUrl = "uploads/" . $fileName;
            } else {
                $response["message"] = "Failed to upload profile picture";
                echo json_encode($response);
                exit;
            }
        }

        // ✅ Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // ✅ Insert into users (created_at auto-filled by MySQL)
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role_id, is_active) 
                                VALUES (?, ?, ?, 1)");
        $stmt->bind_param("ssi", $username, $hashedPassword, $roleId);

        if ($stmt->execute()) {
            $userId = $stmt->insert_id;

            // ✅ Insert into profiles
            $stmt2 = $conn->prepare("
                INSERT INTO profiles (user_id, first_name, last_name, email, phone, profile_picture_url, date_of_birth, address) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt2->bind_param("isssssss", $userId, $firstName, $lastName, $email, $phone, $profilePictureUrl, $dob, $address);

            if ($stmt2->execute()) {
                // ✅ If role = employee, create employee record
                if (strtolower($role) === "employee") {
                    $stmt3 = $conn->prepare("
                        INSERT INTO employees (user_id, created_at) 
                        VALUES (?, NOW())
                    ");
                    $stmt3->bind_param("i", $userId);
                    $stmt3->execute();
                    $stmt3->close();
                }

                $response["success"] = true;
                $response["message"] = "User registered successfully";
                $response["user_id"] = $userId;
            } else {
                $response["message"] = "Failed to insert profile: " . $stmt2->error;
            }
            $stmt2->close();
        } else {
            if ($conn->errno == 1062) { // Duplicate entry
                $response["message"] = "Username already exists";
            } else {
                $response["message"] = "Failed to insert user: " . $stmt->error;
            }
        }

        $stmt->close();
        $conn->close();

    } catch (Exception $e) {
        $response["message"] = "Exception: " . $e->getMessage();
    }
} else {
    $response["message"] = "Invalid request method";
}

echo json_encode($response);
