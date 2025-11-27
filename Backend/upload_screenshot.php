<?php
// upload_screenshot.php
header("Content-Type: application/json");

$targetDir = "uploads/screenshots/";
if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

if (!empty($_FILES["screenshot"]["name"])) {
    $filename = time() . "_" . basename($_FILES["screenshot"]["name"]);
    $targetFile = $targetDir . $filename;

    if (move_uploaded_file($_FILES["screenshot"]["tmp_name"], $targetFile)) {
        echo json_encode([
            "success" => true,
            "screenshot_url" => $targetFile
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Upload failed"]);
    }
} else {
    echo json_encode(["success" => false, "message" => "No file uploaded"]);
}
