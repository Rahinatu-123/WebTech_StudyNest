<?php
session_start();
include './db/db_connect.php';

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    if (!isset($_POST['title']) || !isset($_POST['content'])) {
        throw new Exception('Missing required fields');
    }

    $title = trim($_POST['title']);
    $content = trim($_POST['content']);

    if (empty($title) || empty($content)) {
        throw new Exception('Required fields cannot be empty');
    }

    // Handle image upload
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $baseUploadDir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'uploads';
        $typeUploadDir = $baseUploadDir . DIRECTORY_SEPARATOR . 'news';
        
        // Create directories if they don't exist
        if (!file_exists($baseUploadDir)) {
            mkdir($baseUploadDir, 0777, true);
        }
        if (!file_exists($typeUploadDir)) {
            mkdir($typeUploadDir, 0777, true);
        }
        
        // Generate safe filename
        $extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $newFilename = 'news_' . uniqid() . '.' . $extension;
        $fullPath = $typeUploadDir . DIRECTORY_SEPARATOR . $newFilename;
        
        // Verify file type
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($extension, $allowedTypes)) {
            throw new Exception("Invalid file type. Allowed types: " . implode(', ', $allowedTypes));
        }
        
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $fullPath)) {
            throw new Exception("Failed to save uploaded file");
        }
        
        $imagePath = '../uploads/news/' . $newFilename;
    }

    $conn->begin_transaction();

    // Insert news
    $stmt = $conn->prepare("INSERT INTO news (userId, newsTitle, newsContent, image_path, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isss", $userId, $title, $content, $imagePath);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to save news: " . $stmt->error);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'News added successfully']);

} catch (Exception $e) {
    if ($conn->connect_errno === 0) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
