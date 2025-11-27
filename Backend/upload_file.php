<?php
// Include your existing connect.php
include 'connect.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$employee_id = $_SESSION['employee_id'] ?? 1;
$uploadDir = '../uploads/files/';

// Create upload directory if not exists
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        sendJsonResponse(false, 'Could not create upload directory');
    }
}

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        sendJsonResponse(false, 'Please select a valid file to upload');
    }

    $file = $_FILES['file'];
    
    // Validate file size (10MB limit)
    if ($file['size'] > 10 * 1024 * 1024) {
        sendJsonResponse(false, 'File size too large. Maximum size is 10MB.');
    }

    // Basic file type validation
    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'application/zip'
    ];
    
    if (!in_array($file['type'], $allowedTypes)) {
        sendJsonResponse(false, 'File type not allowed. Please upload images, PDFs, documents, or text files.');
    }

    // Sanitize filename
    $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $filePath = $uploadDir . $fileName;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        sendJsonResponse(false, 'Failed to save file to server.');
    }

    // Insert file record
    $stmt = $conn->prepare("
        INSERT INTO files (name, type, file_path, file_size, mime_type, employee_id, access_level) 
        VALUES (?, 'file', ?, ?, ?, ?, 'private')
    ");
    
    $stmt->bind_param("ssiss", 
        $file['name'],
        $filePath,
        $file['size'],
        $file['type'],
        $employee_id
    );
    
    if ($stmt->execute()) {
        sendJsonResponse(true, 'File uploaded successfully!', [
            'file_id' => $stmt->insert_id,
            'file_name' => $file['name']
        ]);
    } else {
        sendJsonResponse(false, 'Failed to save file record: ' . $conn->error);
    }

} catch (Exception $e) {
    sendJsonResponse(false, 'Upload failed: ' . $e->getMessage());
}

// Helper function for responses
function sendJsonResponse($success, $message = '', $data = null) {
    $response = ['success' => $success];
    if ($message) $response['message'] = $message;
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}
?>