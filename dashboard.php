<?php
session_start();

include './db/db_connect.php';

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Verify user exists in the database
$stmt = $conn->prepare("SELECT * FROM users WHERE userId = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: login.php');
    exit;
}

// Store user data in $user variable
$user = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudyNest - Dashboard</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.2/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    
    <style>
    /* Modal styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1050;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }

    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 600px;
        border-radius: 8px;
    }

    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover {
        color: black;
    }

    /* Form styles */
    form {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    label {
        font-weight: bold;
        margin-bottom: 5px;
    }

    select, input[type="text"], textarea {
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        width: 100%;
    }

    button[type="submit"] {
        background-color: #4CAF50;
        color: white;
        padding: 10px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    button[type="submit"]:hover {
        background-color: #45a049;
    }

    /* Alert styles */
    .alert {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1060;
        min-width: 300px;
    }

    .box-container {
        display: flex;
        gap: 20px;
        justify-content: center;
        margin-top: 20px;
    }

    .box {
        text-align: center;
    }

    .inline-btn {
        display: inline-block;
        padding: 10px 30px;
        border-radius: 5px;
        color: #fff;
        background-color: #4CAF50;
        text-decoration: none;
        transition: background-color 0.3s;
    }

    .inline-btn:hover {
        background-color: #45a049;
        color: #fff;
    }

    /* Sidebar styles */
    .side-bar .navbar a {
        text-decoration: none;
        border-bottom: none !important;
        margin-bottom: 0 !important;
        padding: 1.5rem 2rem;
    }

    .side-bar .navbar a:hover {
        background-color: rgba(0,0,0,0.1);
    }

    .side-bar .navbar a i {
        margin-right: 1rem;
    }

    /* Header styles */
    .header {
        border-bottom: none !important;
    }

    .header .flex {
        border-bottom: none !important;
        padding: 1.5rem 2rem;
    }

    .header .flex .logo {
        text-decoration: none;
    }

    .header .flex .option-btn {
        text-decoration: none;
        border: none !important;
    }
    </style>
</head>
<body>
    <header class="header">
        <section class="flex">
            <a href="index.html" class="logo">StudyNest</a>
            <div class="icons">
                <div id="menu-btn" class="fas fa-bars"></div>
                <div id="search-btn" class="fas fa-search"></div>
                <div id="toggle-btn" class="fas fa-sun"></div>
            </div>
            <div class="profile">
                <div class="flex-btn">
                    <a href="login.php" class="option-btn">login</a>
                    <a href="register.php" class="option-btn">register</a>
                </div>
            </div>
        </section>
    </header>   

    <div class="side-bar">
        <div id="close-btn">
            <i class="fas fa-times"></i>
        </div>
        <nav class="navbar">
            <a href="index.html"><i class="fas fa-home"></i><span>Home</span></a>
            <a href="about.html"><i class="fas fa-info-circle"></i><span>About</span></a>
            <a href="courses.html"><i class="fas fa-graduation-cap"></i><span>Courses</span></a>
            <a href="news.php"><i class="fas fa-chalkboard-user"></i><span>What's New?</span></a>
            <a href="contact.html"><i class="fas fa-headset"></i><span>Contact Us</span></a>
        </nav>
    </div>

    <section class="user-profile">
        <div class="info">
            <div class="user">
                <h2>Welcome,</h2>
                <h3><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></h3>
                <p><?php echo ($user['roleId'] == 1) ? 'Student' : 'Admin'; ?></p>
            </div>

            <div class="box-container">
                <div class="box">
                    <button class="inline-btn" onclick="openModal('noteModal')">Upload Note</button>
                </div>
                <div class="box">
                    <button class="inline-btn" onclick="openModal('questionModal')">Upload Questions</button>
                </div>
                <div class="box">
                    <button class="inline-btn" onclick="openModal('newsModal')">Add News</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Modal for Note Upload -->
    <div id="noteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('noteModal')">&times;</span>
            <h2>Upload Note</h2>
            <form id="noteForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <label for="note-course">Select a Course:</label>
                <select id="note-course" name="course" required>
                    <option value="" disabled selected>Select Course</option>
                    <option value="Calculus">Calculus</option>
                    <option value="Linear Algebra">Linear Algebra</option>
                    <option value="Statistics">Statistics</option>
                    <option value="Database Management System">Database Management System</option>
                    <option value="Principles of Economics">Principles of Economics</option>
                    <option value="Python Programming">Python Programming</option>
                </select>
                <label for="note-topic">Topic:</label>
                <input type="text" id="note-topic" name="topic" required placeholder="Enter topic">
                <label for="note-content">Note Content:</label>
                <textarea id="note-content" name="content" required placeholder="Write your note here..."></textarea>
                <label for="note-image">Upload Image (optional):</label>
                <input type="file" id="note-image" name="image" accept="image/*">
                <button type="submit">Upload Note</button>
            </form>
        </div>
    </div>

    <!-- Modal for Question Upload -->
    <div id="questionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('questionModal')">&times;</span>
            <h2>Upload Question</h2>
            <form id="questionForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <label for="question-course">Select a Course:</label>
                <select id="question-course" name="course" required>
                    <option value="" disabled selected>Select Course</option>
                    <option value="Calculus">Calculus</option>
                    <option value="Linear Algebra">Linear Algebra</option>
                    <option value="Statistics">Statistics</option>
                    <option value="Database Management System">Database Management System</option>
                    <option value="Principles of Economics">Principles of Economics</option>
                    <option value="Python Programming">Python Programming</option>
                </select>
                <label for="question-topic">Topic:</label>
                <input type="text" id="question-topic" name="topic" required placeholder="Enter topic">
                <label for="question-content">Question Content:</label>
                <textarea id="question-content" name="content" required placeholder="Write your question here..."></textarea>
                <label for="question-image">Upload Image (optional):</label>
                <input type="file" id="question-image" name="image" accept="image/*">
                <button type="submit">Upload Question</button>
            </form>
        </div>
    </div>

    <!-- Modal for News Upload -->
    <div id="newsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('newsModal')">&times;</span>
            <h2>Add News</h2>
            <form id="newsForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <label for="news-title">Title:</label>
                <input type="text" id="news-title" name="title" required placeholder="Enter news title">
                <label for="news-content">News Content:</label>
                <textarea id="news-content" name="content" required placeholder="Write your news content here..."></textarea>
                <label for="news-file">Upload File (optional):</label>
                <input type="file" id="news-file" name="image" accept="image/*">
                <button type="submit">Add News</button>
            </form>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- CKEditor -->
    <script src="https://cdn.ckeditor.com/4.21.0/standard/ckeditor.js"></script>

    <script>
    // Initialize CKEditor
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('note-content')) {
            CKEDITOR.replace('note-content');
        }
        if (document.getElementById('question-content')) {
            CKEDITOR.replace('question-content');
        }
        if (document.getElementById('news-content')) {
            CKEDITOR.replace('news-content');
        }
    });

    // Function to show messages
    function showMessage(message, isSuccess = true) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `alert alert-${isSuccess ? 'success' : 'danger'} alert-dismissible fade show`;
        messageDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        document.body.appendChild(messageDiv);
        
        // Auto dismiss after 5 seconds
        setTimeout(() => {
            messageDiv.remove();
        }, 5000);
    }

    // Function to open modal
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'block';
        }
    }

    // Function to close modal
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }

    // Handle form submissions
    const formHandlers = {
        'note': {
            url: 'noteAdd.php',
            successMessage: 'Note uploaded successfully'
        },
        'question': {
            url: 'questionAdd.php',
            successMessage: 'Question uploaded successfully'
        },
        'news': {
            url: 'newsAdd.php',
            successMessage: 'News added successfully'
        }
    };

    Object.entries(formHandlers).forEach(([type, config]) => {
        const form = document.getElementById(`${type}Form`);
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log(`${type} form submission started`);
                
                const formData = new FormData(this);
                
                // If using CKEditor, update the content
                if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances[`${type}-content`]) {
                    const content = CKEDITOR.instances[`${type}-content`].getData();
                    formData.set('content', content);
                }
                
                showMessage(`Uploading ${type}...`, true);
                
                fetch(config.url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message || config.successMessage, true);
                        closeModal(`${type}Modal`);
                        form.reset();
                        if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances[`${type}-content`]) {
                            CKEDITOR.instances[`${type}-content`].setData('');
                        }
                    } else {
                        throw new Error(data.message || `Failed to upload ${type}`);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage(error.message || `An error occurred while uploading the ${type}`, false);
                });
            });
        }
    });
    </script>
</body>
</html>
