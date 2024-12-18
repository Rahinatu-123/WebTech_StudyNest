<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db/db_connect.php';

// Get entity type ID for news
try {
    // First try to get the entity type
    $stmt = $conn->prepare("SELECT entityTypeId FROM entitytypes WHERE entityTypeName = 'news'");
    $stmt->execute();
    $result = $stmt->get_result();
    $entityType = $result->fetch_assoc();
    
    // If it doesn't exist, create it
    if (!$entityType) {
        $stmt = $conn->prepare("INSERT INTO entitytypes (entityTypeName) VALUES ('news')");
        $stmt->execute();
        $newsEntityTypeId = $conn->insert_id;
        error_log("Created new entity type with ID: " . $newsEntityTypeId);
    } else {
        $newsEntityTypeId = $entityType['entityTypeId'];
        error_log("Found existing entity type with ID: " . $newsEntityTypeId);
    }

    if (!$newsEntityTypeId) {
        throw new Exception("Failed to get or create news entity type");
    }
} catch(Exception $e) {
    error_log("Error with entity type: " . $e->getMessage());
    die("Error with entity type: " . $e->getMessage());
}

// Fetch news with user information
try {
    $stmt = $conn->prepare("
        SELECT n.*, u.firstName as author_name,
        (SELECT COUNT(*) FROM Likes WHERE entityId = n.newsId AND entityTypeId = ?) as likes_count,
        (SELECT COUNT(*) FROM Comments WHERE entityId = n.newsId AND entityTypeId = ?) as comments_count
        FROM News n
        JOIN Users u ON n.userId = u.userId
        ORDER BY n.created_at DESC
    ");
    $stmt->bind_param("ii", $newsEntityTypeId, $newsEntityTypeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $news_items = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $news_items = [];
}

// Handle POST requests for likes and comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Please log in first']);
        exit;
    }

    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'like') {
            $newsId = filter_input(INPUT_POST, 'newsId', FILTER_VALIDATE_INT);
            
            // Check if already liked
            $stmt = $conn->prepare("SELECT * FROM Likes WHERE entityId = ? AND userId = ? AND entityTypeId = ?");
            $stmt->bind_param("iii", $newsId, $_SESSION['user_id'], $newsEntityTypeId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Unlike
                $stmt = $conn->prepare("DELETE FROM Likes WHERE entityId = ? AND userId = ? AND entityTypeId = ?");
                $stmt->bind_param("iii", $newsId, $_SESSION['user_id'], $newsEntityTypeId);
                $stmt->execute();
                echo json_encode(['status' => 'success', 'action' => 'unliked']);
            } else {
                // Like
                $stmt = $conn->prepare("INSERT INTO Likes (entityId, userId, entityTypeId) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $newsId, $_SESSION['user_id'], $newsEntityTypeId);
                $stmt->execute();
                echo json_encode(['status' => 'success', 'action' => 'liked']);
            }
            exit;
        }
        
        if ($_POST['action'] === 'comment') {
            $newsId = filter_input(INPUT_POST, 'newsId', FILTER_VALIDATE_INT);
            $commentText = trim($_POST['commentText']);
            
            if (empty($commentText)) {
                throw new Exception("Comment cannot be empty");
            }
            
            $stmt = $conn->prepare("
                INSERT INTO Comments (entityId, userId, commentText, created_at, entityTypeId) 
                VALUES (?, ?, ?, NOW(), ?)
            ");
            $stmt->bind_param("iisi", $newsId, $_SESSION['user_id'], $commentText, $newsEntityTypeId);
            $stmt->execute();
            
            // Get the user's first name
            $stmt = $conn->prepare("SELECT firstName FROM Users WHERE userId = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            echo json_encode([
                'status' => 'success',
                'comment' => [
                    'commentId' => $conn->insert_id,
                    'commentText' => $commentText,
                    'firstName' => $user['firstName'],
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit;
        }
        
        if ($_POST['action'] === 'fetch_comments') {
            $newsId = filter_input(INPUT_POST, 'newsId', FILTER_VALIDATE_INT);
            
            $stmt = $conn->prepare("
                SELECT c.*, u.firstName 
                FROM Comments c
                JOIN Users u ON c.userId = u.userId
                WHERE c.entityId = ? AND c.entityTypeId = ?
                ORDER BY c.created_at DESC
            ");
            $stmt->bind_param("ii", $newsId, $newsEntityTypeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $comments = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode(['status' => 'success', 'comments' => $comments]);
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
    <title>News - StudyNest</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .page-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 20px;
            background-color: #FF6B6B;
            color: #34495E;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            margin: 0;
            font-size: 2.5em;
            font-weight: bold;
            color: #34495E;
        }

        .page-header p {
            margin: 10px 0 0;
            font-size: 1.1em;
            color: #34495E;
            opacity: 0.9;
        }

        .news-container {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .news-card {
            background-color: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            margin-bottom: 30px;
        }

        .news-card:hover {
            transform: translateY(-5px);
        }

        .news-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .news-title {
            color: #34495E;
            margin: 0 0 15px 0;
            font-size: 1.5em;
        }

        .news-image {
            width: 100%;
            max-height: 400px;
            object-fit: cover;
            border-radius: 8px;
            margin: 15px 0;
        }

        .news-content {
            color: #555;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .news-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }

        .like-btn {
            color: #dc3545;
        }

        .like-btn.liked {
            background-color: #dc3545;
            color: white;
        }

        .comment-btn {
            color: #0056b3;
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .comments-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .comment {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9em;
            color: #666;
        }

        .commenter-name {
            font-weight: bold;
            color: #34495E;
        }

        .comment-text {
            color: #333;
            line-height: 1.4;
        }

        .comment-form {
            margin-top: 15px;
        }

        .comment-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 10px;
            resize: vertical;
        }

        .comment-form button {
            padding: 8px 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .comment-form button:hover {
            background-color: #0056b3;
        }

        .no-news {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-comments {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body class="<?php echo isset($_SESSION['user_id']) ? 'logged-in' : ''; ?>">
    <div class="page-header">
        <h1>What's New?</h1>
        <p>Stay updated with the latest news and announcements</p>
    </div>

    <div class="news-container">
        <?php if (empty($news_items)): ?>
            <div class="no-news">No news articles available at the moment.</div>
        <?php else: ?>
            <?php foreach ($news_items as $news): ?>
                <div class="news-card" data-news-id="<?php echo $news['newsId']; ?>">
                    <div class="news-header">
                        <span class="author-name">Posted by: <?php echo htmlspecialchars($news['author_name'] ?? ''); ?></span>
                        <span class="timestamp"><?php echo date('M j, Y g:i A', strtotime($news['created_at'])); ?></span>
                    </div>
                    
                    <h2 class="news-title"><?php echo htmlspecialchars($news['title'] ?? ''); ?></h2>
                    
                    <?php if (!empty($news['image_path'])): ?>
                        <img class="news-image" src="<?php echo htmlspecialchars($news['image_path']); ?>" alt="News Image">
                    <?php endif; ?>
                    
                    <div class="news-content">
                        <?php echo nl2br(htmlspecialchars($news['newsContent'] ?? '')); ?>
                    </div>
                    
                    <div class="news-actions">
                        <button class="action-btn like-btn <?php 
                            if (isset($_SESSION['user_id'])) {
                                $stmt = $conn->prepare("SELECT * FROM Likes WHERE entityId = ? AND userId = ? AND entityTypeId = ?");
                                $stmt->bind_param("iii", $news['newsId'], $_SESSION['user_id'], $newsEntityTypeId);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                echo $result->num_rows > 0 ? 'liked' : '';
                            }
                        ?>" onclick="toggleLike(<?php echo $news['newsId']; ?>)">
                            <i class="fas fa-heart"></i>
                            <span class="action-label">Likes:</span>
                            <span class="likes-count"><?php echo $news['likes_count']; ?></span>
                        </button>
                        <button class="action-btn comment-btn" onclick="toggleComments(<?php echo $news['newsId']; ?>)">
                            <i class="fas fa-comment"></i>
                            <span class="action-label">Comments:</span>
                            <span class="comments-count"><?php echo $news['comments_count']; ?></span>
                        </button>
                    </div>
                    
                    <div id="comments-section-<?php echo $news['newsId']; ?>" class="comments-section" style="display: none;">
                        <div id="comments-<?php echo $news['newsId']; ?>" class="comments-container"></div>
                        <div class="comment-form">
                            <textarea id="comment-text-<?php echo $news['newsId']; ?>" placeholder="Write a comment..."></textarea>
                            <button onclick="submitComment(<?php echo $news['newsId']; ?>)">Submit Comment</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function checkLoginStatus(action) {
            if (!document.body.classList.contains('logged-in')) {
                alert('Please log in to ' + action);
                return false;
            }
            return true;
        }

        function toggleLike(newsId) {
            if (!checkLoginStatus('like this news')) return;

            const likeBtn = document.querySelector(`[data-news-id="${newsId}"] .like-btn`);
            const likesCount = likeBtn.querySelector('.likes-count');
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=like&newsId=${newsId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    const currentCount = parseInt(likesCount.textContent);
                    if (data.action === 'liked') {
                        likeBtn.classList.add('liked');
                        likesCount.textContent = currentCount + 1;
                    } else {
                        likeBtn.classList.remove('liked');
                        likesCount.textContent = currentCount - 1;
                    }
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function toggleComments(newsId) {
            const commentsSection = document.querySelector(`#comments-section-${newsId}`);
            if (!commentsSection) return;

            const isVisible = commentsSection.style.display === 'block';
            
            if (!isVisible) {
                fetchComments(newsId);
                commentsSection.style.display = 'block';
            } else {
                commentsSection.style.display = 'none';
            }
        }

        function fetchComments(newsId) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=fetch_comments&newsId=${newsId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    const commentsDisplay = document.querySelector(`#comments-${newsId}`);
                    commentsDisplay.innerHTML = '';
                    
                    if (data.comments.length === 0) {
                        commentsDisplay.innerHTML = '<div class="no-comments">No comments yet</div>';
                        return;
                    }

                    data.comments.forEach(comment => {
                        const commentDiv = document.createElement('div');
                        commentDiv.className = 'comment';
                        commentDiv.innerHTML = `
                            <div class="comment-header">
                                <span class="commenter-name">${comment.firstName}</span>
                                <span class="timestamp">${new Date(comment.created_at).toLocaleString()}</span>
                            </div>
                            <div class="comment-text">${comment.commentText}</div>
                        `;
                        commentsDisplay.appendChild(commentDiv);
                    });
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function submitComment(newsId) {
            if (!checkLoginStatus('comment')) return;

            const commentText = document.querySelector(`#comment-text-${newsId}`).value.trim();
            if (!commentText) {
                alert('Please enter a comment');
                return;
            }

            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=comment&newsId=${newsId}&commentText=${encodeURIComponent(commentText)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    const commentsDisplay = document.querySelector(`#comments-${newsId}`);
                    const commentDiv = document.createElement('div');
                    commentDiv.className = 'comment';
                    commentDiv.innerHTML = `
                        <div class="comment-header">
                            <span class="commenter-name">${data.comment.firstName}</span>
                            <span class="timestamp">${new Date(data.comment.created_at).toLocaleString()}</span>
                        </div>
                        <div class="comment-text">${data.comment.commentText}</div>
                    `;
                    commentsDisplay.insertBefore(commentDiv, commentsDisplay.firstChild);
                    
                    // Clear the textarea
                    document.querySelector(`#comment-text-${newsId}`).value = '';
                    
                    // Update comments count
                    const commentsCount = document.querySelector(`[data-news-id="${newsId}"] .comments-count`);
                    commentsCount.textContent = parseInt(commentsCount.textContent) + 1;
                } else {
                    alert(data.message || 'Failed to submit comment');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to submit comment. Please try again.');
            });
        }
    </script>
</body>
</html>
