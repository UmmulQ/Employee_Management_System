<?php
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once "connect.php";

$response = ["success" => false, "message" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'] ?? '';

    if (empty($username)) {
        $response["message"] = "Username is required";
        echo json_encode($response);
        exit;
    }

    // Get email from profiles
    $stmt = $conn->prepare("
        SELECT u.user_id, p.email 
        FROM users u
        LEFT JOIN profiles p ON u.user_id = p.user_id
        WHERE u.username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (!empty($user['email'])) {
            // Generate reset token
            $token = bin2hex(random_bytes(16));
            $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

            // Store token in DB
            $stmt2 = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt2->bind_param("iss", $user['user_id'], $token, $expiry);
            $stmt2->execute();

            // TODO: send email with reset link (requires mail setup)
            $resetLink = "http://localhost/EMS/Backend/reset_password.php?token=" . $token;

            $response["success"] = true;
            $response["message"] = "Password reset link has been sent to " . $user['email'];
            $response["reset_link"] = $resetLink; // For testing
        } else {
            $response["message"] = "No email found for this user.";
        }
    } else {
        $response["message"] = "User not found.";
    }
} else {
    $response["message"] = <?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once "connect.php"; 
require 'vendor/autoload.php'; // load PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$response = ["success" => false, "message" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $username = $_POST['username'] ?? '';

        if (empty($username)) {
            $response["message"] = "Username is required";
            echo json_encode($response);
            exit;
        }

        // Find user
        $stmt = $conn->prepare("SELECT u.user_id, p.email 
                                FROM users u 
                                LEFT JOIN profiles p ON u.user_id = p.user_id 
                                WHERE u.username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $email = $user['email'];

            if (!$email) {
                $response["message"] = "No email found for this user.";
                echo json_encode($response);
                exit;
            }

            // Generate reset token
            $token = bin2hex(random_bytes(16));
            $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

            // Save token in DB
            $stmt2 = $conn->prepare("INSERT INTO password_resets (user_id, token, expiry) VALUES (?, ?, ?)");
            $stmt2->bind_param("iss", $user['user_id'], $token, $expiry);
            $stmt2->execute();

            $resetLink = "http://localhost/EMS/Backend/reset_password.php?token=" . $token;

            // Send Email with PHPMailer
            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host = "smtp.gmail.com"; // Gmail SMTP server
                $mail->SMTPAuth = true;
                $mail->Username = "yourgmail@gmail.com"; // your Gmail
                $mail->Password = "your-app-password"; // Gmail App Password
                $mail->SMTPSecure = "tls";
                $mail->Port = 587;

                $mail->setFrom("yourgmail@gmail.com", "EMS Support");
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = "Password Reset Request";
                $mail->Body    = "<p>Hi,</p>
                                  <p>Click the link below to reset your password:</p>
                                  <a href='$resetLink'>$resetLink</a>
                                  <p>This link expires in 1 hour.</p>";

                $mail->send();
                $response["success"] = true;
                $response["message"] = "Password reset link sent to your email.";
            } catch (Exception $e) {
                $response["message"] = "Mailer Error: " . $mail->ErrorInfo;
            }
        } else {
            $response["message"] = "No user found with this username.";
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
"Invalid request method.";
}

echo json_encode($response);
