<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../db/db_connect.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get entity type ID for questions
try {
    // First try to get the entity type
    $stmt = $conn->prepare("SELECT entityTypeId FROM entitytypes WHERE entityTypeName = 'question'");
    $stmt->execute();
    $result = $stmt->get_result();
    $entityType = $result->fetch_assoc();
    
    // If it doesn't exist, create it
    if (!$entityType) {
        $stmt = $conn->prepare("INSERT INTO entitytypes (entityTypeName) VALUES ('question')");
        $stmt->execute();
        $questionEntityTypeId = $conn->insert_id;
        error_log("Created new entity type with ID: " . $questionEntityTypeId);
    } else {
        $questionEntityTypeId = $entityType['entityTypeId'];
        error_log("Found existing entity type with ID: " . $questionEntityTypeId);
    }

    if (!$questionEntityTypeId) {
        throw new Exception("Failed to get or create question entity type");
    }
} catch(Exception $e) {
    error_log("Error with entity type: " . $e->getMessage());
    die("Error with entity type: " . $e->getMessage());
}

// Fetch topics for calculus (courseId = 1)
$stmt = $conn->prepare("SELECT * FROM topics WHERE courseId = 6");
$stmt->execute();
$result = $stmt->get_result();
$topics = $result->fetch_all(MYSQLI_ASSOC);

// Fetch questions with their answers
$stmt = $conn->prepare("
    SELECT q.*, t.topicName, 
           a.answerId, a.answerText, a.created_at as answer_created_at,
           u.firstName as answerer_name
    FROM questions q 
    LEFT JOIN topics t ON q.topicId = t.topicId 
    LEFT JOIN answers a ON q.questionId = a.questionId
    LEFT JOIN users u ON a.userId = u.userId
    WHERE t.courseId = 6
    ORDER BY q.created_at DESC, a.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
$questions = $result->fetch_all(MYSQLI_ASSOC);

// Group questions and their answers
$grouped_questions = [];
foreach ($questions as $row) {
    $questionId = $row['questionId'];
    if (!isset($grouped_questions[$questionId])) {
        $grouped_questions[$questionId] = [
            'questionId' => $row['questionId'],
            'questionText' => $row['questionText'],
            'topicId' => $row['topicId'],
            'topicName' => $row['topicName'],
            'image_path' => $row['image_path'],
            'created_at' => $row['created_at'],
            'answers' => []
        ];
    }
    if ($row['answerId']) {
        $grouped_questions[$questionId]['answers'][] = [
            'answerId' => $row['answerId'],
            'answerText' => $row['answerText'],
            'created_at' => $row['answer_created_at'],
            'answerer_name' => $row['answerer_name']
        ];
    }
}

// Function to fetch comments
function fetchComments($conn, $questionId) {
    $stmt = $conn->prepare("
        SELECT c.*, u.firstName 
        FROM comments c 
        JOIN users u ON c.userId = u.userId 
        WHERE c.entityId = ? AND c.entityTypeId = ? 
        ORDER BY c.created_at DESC
    ");
    global $questionEntityTypeId;
    $stmt->bind_param("ii", $questionId, $questionEntityTypeId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Please log in to perform this action']);
        exit;
    }

    try {
        if ($_POST['action'] === 'fetch_comments') {
            $questionId = filter_input(INPUT_POST, 'questionId', FILTER_VALIDATE_INT);
            if (!$questionId) {
                throw new Exception("Invalid question ID");
            }

            $comments = fetchComments($conn, $questionId);
            echo json_encode([
                'status' => 'success',
                'comments' => $comments
            ]);
            exit;
        } elseif ($_POST['action'] === 'submit_comment') {
            $questionId = filter_input(INPUT_POST, 'questionId', FILTER_VALIDATE_INT);
            $commentText = trim($_POST['commentText']);

            if (!$questionId || empty($commentText)) {
                throw new Exception("Invalid input");
            }

            // Insert comment
            $stmt = $conn->prepare("
                INSERT INTO comments (userId, entityTypeId, entityId, commentText, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("iiis", $_SESSION['user_id'], $questionEntityTypeId, $questionId, $commentText);
            $stmt->execute();

            echo json_encode(['status' => 'success', 'message' => 'Comment added successfully']);
            exit;
        } elseif ($_POST['action'] === 'submit_answer') {
            $questionId = filter_input(INPUT_POST, 'questionId', FILTER_VALIDATE_INT);
            $answerText = trim($_POST['answerText']);

            if (!$questionId || empty($answerText)) {
                throw new Exception("Invalid input");
            }

            // Insert answer
            $stmt = $conn->prepare("
                INSERT INTO answers (questionId, userId, answerText, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param("iis", $questionId, $_SESSION['user_id'], $answerText);
            $stmt->execute();

            echo json_encode(['status' => 'success', 'message' => 'Answer submitted successfully']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculus Questions</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.2/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        /* General Styles */
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            margin: 0;
            padding: 0;
        }
        .calculus-header {
            background-color: #FF6B6B;
            color: #34495E;
            text-align: center;
            padding: 20px;
        }
        .calculus-header h1 {
            color: #34495E;
            margin: 0;
            font-size: 24px;
        }
        .layout {
            display: flex;
            flex-direction: column; /* Vertical container */
            padding: 20px;
        }
        .filter-section {
            background-color: #fff;
            padding: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .filter-section ul {
            list-style-type: none;
            padding: 0;
        }
        .filter-section li {
            margin: 10px 0;
            cursor: pointer;
            padding: 10px;
            border-radius: 5px;
            background-color: #f2f2f2;
            transition: background-color 0.3s;
        }
        .filter-section li.active {
            background-color: #FF6B6B;
            color: white;
        }
        .questions-container {
            display: flex;
            flex-direction: column; /* Stack questions vertically */
            gap: 20px;
        }
        .question-container {
            background-color: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            box-sizing: border-box;
            transition: transform 0.3s ease-in-out;
        }
        .question-container:hover {
            transform: translateY(-5px);
        }
        .question-header {
            font-size: 20px;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 15px;
        }
        .question-content {
            display: flex;
            flex-direction: column;
        }
        .question-content img {
            width: 100%;
            max-height: 300px;
            object-fit: cover;
            border-radius: 5px;
        }
        .question-actions {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
        }
        .question-actions .download-btn {
            background-color: #FF6B6B;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .question-actions .download-btn:hover {
            background-color: #ff5252;
        }
        .question-actions .answer-btn {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .question-actions .answer-btn:hover {
            background-color: #45a049;
        }
        .question-actions .comments-btn {
            background-color: #34495E;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .question-actions .comments-btn:hover {
            background-color: #2c3e50;
        }
        .answer-section {
            margin-top: 15px;
            display: none;
        }
        .answer-input {
            width: 100%;
            min-height: 100px;
            margin-bottom: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            resize: vertical;
        }
        .answer-upload-section {
            margin-bottom: 10px;
        }
        .answer-submit {
            background-color: #0056b3;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .answer-submit:hover {
            background-color: #003d82;
        }
        .comment-section {
            margin-top: 15px;
            display: none;
        }
        .comment-textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .submit-comment {
            background-color: #0056b3;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .submit-comment:hover {
            background-color: #003d82;
        }
        .comments-display {
            margin-top: 10px;
        }
        .comments-display .comment {
            background-color: #e9e9e9;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .comments-display .comment .username {
            font-weight: bold;
            margin-bottom: 5px;
            color: #34495E;
        }
        .comments-display .comment .timestamp {
            font-size: 0.8em;
            color: #666;
            margin-left: 10px;
        }
        
        .answers-container {
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        .answers-container h3 {
            color: #34495E;
            margin-bottom: 15px;
        }
        .answer {
            background-color: #f9f9f9;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .answer-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            color: #666;
        }
        .answerer {
            font-weight: bold;
            color: #34495E;
        }
        .answer-text {
            white-space: pre-wrap;
            line-height: 1.5;
        }
    </style>
</head>
<body class="<?php echo isset($_SESSION['user_id']) ? 'logged-in' : ''; ?>">
    <header class="calculus-header">
        <h1>Python Programming Questions</h1>
    </header>

    <div class="layout">
        <div class="filter-section">
            <ul id="topicsList">
                <li class="active" data-topic-id="all">All Topics</li>
                <?php foreach ($topics as $topic): ?>
                    <li data-topic-id="<?php echo $topic['topicId']; ?>">
                        <?php echo $topic['topicName']; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="questions-container" id="questionsContainer">
            <?php foreach ($grouped_questions as $question): ?>
                <div class="question-container" data-question-id="<?php echo $question['questionId']; ?>" data-topic-id="<?php echo $question['topicId']; ?>">
                    <div class="question-header"><?php echo $question['topicName']; ?></div>
                    <div class="question-content">
                        <?php if ($question['image_path']): ?>
                            <img src="<?php echo "../" . htmlspecialchars($question['image_path']); ?>" alt="Question Image">
                        <?php endif; ?>
                        <p><?php echo $question['questionText']; ?></p>
                        <div class="question-actions">
                            <button class="download-btn" data-topic-name="<?php echo $question['topicName']; ?>" data-question-id="<?php echo $question['questionId']; ?>">Download</button>
                            <button class="answer-btn">Add Answer</button>
                            <button class="comments-btn">View Comments</button>
                        </div>

                        <div class="answer-section" style="display: none;">
                            <form class="answer-form">
                                <textarea class="answer-textarea" placeholder="Write your answer here..."></textarea>
                                <button type="submit" class="submit-answer">Submit Answer</button>
                            </form>
                        </div>

                        <div class="comments-section" style="display: none;">
                            <div class="comments-display"></div>
                            <form class="comment-form">
                                <textarea class="comment-textarea" placeholder="Write your comment here..."></textarea>
                                <button type="submit" class="submit-comment">Submit Comment</button>
                            </form>
                        </div>

                        <div class="answers-container">
                            <?php if (!empty($question['answers'])): ?>
                                <h3>Answers:</h3>
                                <?php foreach ($question['answers'] as $answer): ?>
                                    <div class="answer">
                                        <div class="answer-header">
                                            <span class="answerer"><?php echo htmlspecialchars($answer['answerer_name']); ?></span>
                                            <span class="timestamp"><?php echo date('M d, Y, g:i A', strtotime($answer['created_at'])); ?></span>
                                        </div>
                                        <div class="answer-text"><?php echo nl2br(htmlspecialchars($answer['answerText'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle answer section
            document.querySelectorAll('.answer-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const questionContainer = this.closest('.question-container');
                    const answerSection = questionContainer.querySelector('.answer-section');
                    if (answerSection) {
                        answerSection.style.display = answerSection.style.display === 'none' ? 'block' : 'none';
                    }
                });
            });

            // Toggle comments section
            document.querySelectorAll('.comments-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const questionContainer = this.closest('.question-container');
                    const commentsSection = questionContainer.querySelector('.comments-section');
                    if (commentsSection) {
                        const isHidden = commentsSection.style.display === 'none';
                        commentsSection.style.display = isHidden ? 'block' : 'none';
                        
                        if (isHidden) {
                            const questionId = questionContainer.dataset.questionId;
                            fetchComments(questionId, commentsSection.querySelector('.comments-display'));
                        }
                    }
                });
            });

            // Handle answer submission
            document.querySelectorAll('.answer-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const questionId = this.closest('.question-container').dataset.questionId;
                    const answerText = this.querySelector('.answer-textarea').value;
                    
                    if (!answerText.trim()) {
                        alert('Please enter an answer');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'submit_answer');
                    formData.append('questionId', questionId);
                    formData.append('answerText', answerText);

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            const answersContainer = this.closest('.question-container').querySelector('.answers-container');
                            const answerDiv = document.createElement('div');
                            answerDiv.className = 'answer';
                            
                            const formattedDate = new Date().toLocaleString('en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            });

                            answerDiv.innerHTML = `
                                <div class="answer-header">
                                    <span class="answerer">You</span>
                                    <span class="timestamp">${formattedDate}</span>
                                </div>
                                <div class="answer-text">${answerText}</div>
                            `;
                            
                            answersContainer.insertBefore(answerDiv, answersContainer.firstChild);
                            this.querySelector('.answer-textarea').value = '';
                            this.style.display = 'none';
                        } else {
                            alert(data.message || 'Failed to submit answer');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to submit answer. Please try again.');
                    });
                });
            });

            // Handle comment submission
            document.querySelectorAll('.comment-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const questionId = this.closest('.question-container').dataset.questionId;
                    const commentText = this.querySelector('.comment-textarea').value;
                    
                    if (!commentText.trim()) {
                        alert('Please enter a comment');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'submit_comment');
                    formData.append('questionId', questionId);
                    formData.append('commentText', commentText);

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            const commentsDisplay = this.closest('.comments-section').querySelector('.comments-display');
                            const commentDiv = document.createElement('div');
                            commentDiv.className = 'comment';
                            
                            const formattedDate = new Date().toLocaleString('en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            });

                            commentDiv.innerHTML = `
                                <div class="username">You</div>
                                <div class="comment-text">${commentText}</div>
                                <div class="timestamp">${formattedDate}</div>
                            `;
                            
                            commentsDisplay.insertBefore(commentDiv, commentsDisplay.firstChild);
                            this.querySelector('.comment-textarea').value = '';
                        } else {
                            alert(data.message || 'Failed to submit comment');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to submit comment. Please try again.');
                    });
                });
            });

            // Function to fetch comments
            function fetchComments(questionId, commentsDisplay) {
                const formData = new FormData();
                formData.append('action', 'fetch_comments');
                formData.append('questionId', questionId);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        commentsDisplay.innerHTML = '';
                        data.comments.forEach(comment => {
                            const commentDiv = document.createElement('div');
                            commentDiv.className = 'comment';
                            
                            const formattedDate = new Date(comment.created_at).toLocaleString('en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            });

                            commentDiv.innerHTML = `
                                <div class="username">${comment.firstName}</div>
                                <div class="comment-text">${comment.commentText}</div>
                                <div class="timestamp">${formattedDate}</div>
                            `;
                            commentsDisplay.appendChild(commentDiv);
                        });
                    } else {
                        console.error('Failed to fetch comments:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            }
        });
    </script>
</body>
</html>
