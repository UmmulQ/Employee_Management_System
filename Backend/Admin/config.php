<?php
require_once __DIR__ . '/../connect.php';

function sendResponse($success, $data = null, $message = '') {
    $response = ['success' => $success];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($message) {
        $response['message'] = $message;
    }
    
    echo json_encode($response);
    exit;
}

if (!isset($conn) || $conn->connect_error) {
    sendResponse(false, null, "Database connection failed");
}
?>