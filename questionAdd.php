<?php
session_start();
include './db/db_connect.php';
require_once './includes/image_handler.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
        $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    // Ensure user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'User not logged in']);
        exit;
    }

    $userId = $_SESSION['user_id'];

    try {
        // Debug logging
        error_log("POST data received: " . print_r($_POST, true));
        error_log("FILES data received: " . print_r($_FILES, true));
        
        // Validate inputs
        if (!isset($_POST['course']) || !isset($_POST['topic']) || !isset($_POST['content'])) {
            throw new Exception('Missing required fields');
        }

        $courseName = trim($_POST['course']);
        $topicName = trim($_POST['topic']);
        $questionText = trim($_POST['content']);

        if (empty($courseName) || empty($topicName) || empty($questionText)) {
            throw new Exception('Required fields cannot be empty');
        }

        // Handle image upload
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $baseUploadDir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'uploads';
            $typeUploadDir = $baseUploadDir . DIRECTORY_SEPARATOR . 'questions';
            
            // Create directories if they don't exist
            if (!file_exists($baseUploadDir)) {
                mkdir($baseUploadDir, 0777, true);
            }
            if (!file_exists($typeUploadDir)) {
                mkdir($typeUploadDir, 0777, true);
            }
            
            // Generate safe filename
            $extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $newFilename = 'question_' . uniqid() . '.' . $extension;
            $fullPath = $typeUploadDir . DIRECTORY_SEPARATOR . $newFilename;
            
            // Verify file type
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($extension, $allowedTypes)) {
                throw new Exception("Invalid file type. Allowed types: " . implode(', ', $allowedTypes));
            }
            
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $fullPath)) {
                throw new Exception("Failed to save uploaded file");
            }
            
            $imagePath = 'uploads/questions/' . $newFilename;
        }

        // Start transaction
        $conn->begin_transaction();

        // Get course ID
        $stmt = $conn->prepare("SELECT courseId FROM courses WHERE courseName = ?");
        $stmt->bind_param("s", $courseName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result->num_rows) {
            throw new Exception("Invalid course: " . $courseName);
        }
        
        $row = $result->fetch_assoc();
        $courseId = $row['courseId'];

        // Get or create topic
        $stmt = $conn->prepare("SELECT topicId FROM topics WHERE topicName = ? AND courseId = ?");
        $stmt->bind_param("si", $topicName, $courseId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $topicId = $row['topicId'];
        } else {
            $stmt = $conn->prepare("INSERT INTO topics (courseId, topicName) VALUES (?, ?)");
            $stmt->bind_param("is", $courseId, $topicName);
            $stmt->execute();
            $topicId = $conn->insert_id;
        }

        // Insert question with image path
        $stmt = $conn->prepare("INSERT INTO questions (userId, topicId, questionText, image_path, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("iiss", $userId, $topicId, $questionText, $imagePath);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save question: " . $stmt->error);
        }

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Question added successfully'
        ]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction
        if ($conn && $conn->connect_error === null) {
            $conn->rollback();
        }

        // Delete uploaded image if it exists and there was an error
        if (isset($imagePath) && !empty($imagePath)) {
            $fullPath = __DIR__ . '/' . $imagePath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }

        error_log("Error in questionAdd.php: " . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Error adding question: ' . $e->getMessage()
        ]);
        exit;
    }
}
?>