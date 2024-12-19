<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug session data
error_log("Dashboard - Session contents: " . print_r($_SESSION, true));
error_log("Dashboard - Cookie contents: " . print_r($_COOKIE, true));


include './db/db_connect.php'; // Include your database connection file

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
   echo json_encode(['success' => false, 'message' => 'User not logged in']);
   exit;
}
$userId = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE userId = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// If user not found, redirect to login
if (!$user) {
    header('Location: login.php');
    exit;
}


// Function to handle uploads (notes, questions, or news)
function handleUpload($type) {
   global $conn;

   // Validate input
   if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php?error=Invalid request method');
    exit;
   }

   // Check if user is logged in
   if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

   $userId = $_SESSION['user_id'];
   try {
       // Start transaction
       $conn->begin_transaction();

       // Handle different upload types
       switch ($type) {
           case 'note':
           case 'question':
               // Sanitize and validate inputs
               $courseName = filter_input(INPUT_POST, 'course', FILTER_SANITIZE_STRING);
               $topicName = filter_input(INPUT_POST, 'topic', FILTER_SANITIZE_STRING);
               $content = trim($_POST['content']); // Keep HTML for notes/questions

               // Validate required fields
               if (empty($courseName) || empty($topicName) || empty($content)) {
                   echo json_encode(['success' => false, 'message' => 'All fields are required']);
                   exit;
               }

               // Fetch or create course
               $stmt = $conn->prepare("SELECT courseId FROM courses WHERE courseName = ?");
               $stmt->bind_param("s", $courseName);
               $stmt->execute();
               $result = $stmt->get_result();
               
               if ($result->num_rows > 0) {
                   $course = $result->fetch_assoc();
                   $courseId = $course['courseId'];
               } else {
                   // If course doesn't exist, return error
                   throw new Exception("Invalid course selected");
               }

               // Check if topic exists for this course
               $stmt = $conn->prepare("SELECT topicId FROM topics WHERE topicName = ? AND courseId = ?");
               $stmt->bind_param("si", $topicName, $courseId);
               $stmt->execute();
               $result = $stmt->get_result();

               if ($result->num_rows > 0) {
                   $topic = $result->fetch_assoc();
                   $topicId = $topic['topicId'];
               } else {
                   // Insert new topic
                   $stmt = $conn->prepare("INSERT INTO topics (courseId, topicName) VALUES (?, ?)");
                   $stmt->bind_param("is", $courseId, $topicName);
                   $stmt->execute();
                   $topicId = $conn->insert_id;
               }

               // Prepare SQL based on upload type
               if ($type === 'note') {
                   $stmt = $conn->prepare("INSERT INTO notes (userId, topicId, noteText, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
               } elseif ($type === 'question') {
                   $stmt = $conn->prepare("INSERT INTO questions (userId, topicId, questionText, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
               }
               
               // Bind parameters and execute
               $stmt->bind_param("iis", $userId, $topicId, $content);
               break;

           case 'news':
               // First, try to add fileAttachment column if it doesn't exist
               try {
                   $conn->query("ALTER TABLE news ADD COLUMN IF NOT EXISTS fileAttachment VARCHAR(255) DEFAULT NULL");
               } catch (Exception $e) {
                   // Column might already exist, continue
               }

               // Sanitize and validate news inputs
               $newsTitle = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
               $newsContent = trim($_POST['content']);

               // Validate required fields
               if (empty($newsTitle) || empty($newsContent)) {
                   throw new Exception('Title and content are required for news');
               }

               // Optional file attachment handling
               $fileAttachment = null;
               if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                   $uploadDir = 'uploads/news/';
                   
                   // Create directory if it doesn't exist
                   if (!is_dir($uploadDir)) {
                       mkdir($uploadDir, 0755, true);
                   }

                   // Generate unique filename
                   $fileExtension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                   $filename = uniqid('news_', true) . '.' . $fileExtension;
                   $uploadPath = $uploadDir . $filename;

                   // Move uploaded file
                   if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadPath)) {
                       $fileAttachment = $uploadPath;
                   } else {
                       throw new Exception('File upload failed');
                   }
               }

               // Prepare SQL for news insertion
               $stmt = $conn->prepare("INSERT INTO news (userId, newsTitle, newsContent, views, created_at, updated_at, fileAttachment) VALUES (?, ?, ?, 0, NOW(), NOW(), ?)");
               $stmt->bind_param("isss", $userId, $newsTitle, $newsContent, $fileAttachment);
               break;

           default:
               throw new Exception("Invalid upload type");
       }

       // Execute the prepared statement
       $stmt->execute();

       // Check for any MySQL errors
       if ($stmt->errno) {
           throw new Exception("MySQL Error: " . $stmt->error);
       }

       // Commit transaction
       $conn->commit();

       // Return success response
       header('Location: dashboard.php?success=' . ucfirst($type) . ' uploaded successfully!');

   } catch (Exception $e) {
       // Rollback transaction in case of error
       $conn->rollback();

       // Return error response
       header('Location: dashboard.php?error=' . $e->getMessage());
       error_log("Upload Error: " . $e->getMessage());
   }

   exit;
}

// Determine upload type and handle accordingly
if (isset($_POST['content'])) {
    error_log("POST data received: " . print_r($_POST, true));
    error_log("FILES data received: " . print_r($_FILES, true));

    if (isset($_POST['title'])) {
        // News upload
        handleUpload('news');
    } elseif (isset($_POST['course']) && isset($_POST['topic'])) {
        // Note or Question upload
        $path = $_SERVER['PHP_SELF'];
        if (strpos($path, 'dashboard.php') !== false) {
            if (isset($_POST['type'])) {
                handleUpload($_POST['type']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>AdminProfile</title>

   <!-- font awesome cdn link -->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.2/css/all.min.css">

   <!-- custom css file link -->
   <link rel="stylesheet" href="css/style.css">
   <style>
      .modal {
         display: none;
         position: fixed;
         z-index: 1000;
         left: 0;
         top: 0;
         width: 100%;
         height: 100%;
         overflow: auto;
         background-color: rgba(0,0,0,0.4);
      }
      .modal-content {
         background-color: #f9f9f9;
         margin: 10% auto;
         padding: 30px;
         border-radius: 10px;
         box-shadow: 0 4px 8px rgba(0,0,0,0.2);
         width: 60%;
         max-width: 600px;
         font-family: Arial, sans-serif;
      }
      .modal-content label {
         display: block;
         font-weight: bold;
         margin: 10px 0 5px;
         color: #333;
      }
      .modal-content select,
      .modal-content input,
      .modal-content textarea,
      .modal-content button {
         width: 100%;
         padding: 10px;
         margin-bottom: 15px;
         border: 1px solid #ccc;
         border-radius: 5px;
         font-size: 16px;
      }
      .modal-content textarea {
         height: 150px;
         resize: none;
      }
      .modal-content button {
         background-color: #4CAF50;
         color: white;
         border: none;
         cursor: pointer;
         font-size: 18px;
      }
      .modal-content button:hover {
         background-color: #45a049;
      }
      .close {
         color: #aaa;
         float: right;
         font-size: 28px;
         font-weight: bold;
      }
      .close:hover,
      .close:focus {
         color: black;
         text-decoration: none;
         cursor: pointer;
      }
      .notification {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            display: none;
      }

      .user-profile {
   padding: 2rem;
   text-align: center;
   background: var(--white);
   border-radius: 1rem;
   margin: 2rem;
   box-shadow: 0 .5rem 1rem rgba(0,0,0,.1);
}

.user-profile .info {
   margin-bottom: 2rem;
}

.user-profile .user {
   padding: 2rem;
   background: linear-gradient(to right, #FF6B6B, #ff8585);
   border-radius: 1rem;
   color: var(--white);
}

.user-profile .user h2 {
   font-size: 3rem;
   margin-bottom: 1rem;
   font-weight: 700;
   letter-spacing: 0.1rem;
   text-transform: uppercase;
}

.user-profile .user h3 {
   font-size: 2.5rem;
   margin-bottom: 1rem;
   color: var(--white);
   font-weight: 600;
}

.user-profile .user p {
   font-size: 1.8rem;
   background: rgba(255, 255, 255, 0.2);
   display: inline-block;
   padding: .5rem 2rem;
   border-radius: 2rem;
   margin-top: 0.5rem;
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
         <a href="dashboard.html" class="btn">view profile</a>
         <div class="flex-btn">
            <a href="login.php" class="option-btn">login</a>
            <a href="register.php" class="option-btn">register</a>
         </div>
      </div>
      <div class="profile">
            <a href="dashboard.html" class="btn">view profile</a>
            <div class="flex-btn">
               <a href="logout.php" class="option-btn">logout</a>
            </div>
         </div>
   </section>
</header>   

<section class="user-profile">
      <div class="info">
         <div class="user">
            <h2>Welcome,</h2>
            <h3><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></h3>
            <p><?php echo ($user['roleId'] == 2) ? 'Admin' : 'Student'; ?></p>
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

<div class="side-bar">
   <div id="close-btn">
      <i class="fas fa-times"></i>
   </div>
   <div class="profile">
      <a href="dashboard.html" class="btn">view profile</a>
   </div>
   <nav class="navbar">
      <a href="index.html"><i class="fas fa-home"></i><span>Home</span></a>
      <a href="about.html"><i class="fas fa-question"></i><span>About</span></a>
      <a href="courses.html"><i class="fas fa-graduation-cap"></i><span>Courses</span></a>
      <a href="news.php"><i class="fas fa-chalkboard-user"></i><span>What's New?</span></a>
      <a href="manaUsers.php"><i class="fas fa-users-cog"></i><span>Mang. Users</span></a>
        <a href="manaNotes.php"><i class="fas fa-file-alt"></i><span>Mang. Notes</span></a>
        <a href="manaQuestions.php"><i class="fas fa-question-circle"></i><span>Mang. Question</span></a>
        <a href="manaNews.php"><i class="fas fa-newspaper"></i><span>Mang. News</span></a>
        </nav>
</div>



<!-- Include CKEditor -->
<script src="https://cdn.ckeditor.com/4.21.0/standard/ckeditor.js"></script>

<!-- Modal for Note Upload -->
<div id="noteModal" class="modal">
   <div class="modal-content">
      <span class="close" onclick="closeModal('noteModal')">&times;</span>
      <h2>Upload Note</h2>
      <form id="noteForm" enctype="multipart/form-data">
         <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
         <input type="hidden" name="type" value="note">
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
         <input type="hidden" name="type" value="question">
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
         <label for="news-content">Content:</label>
         <textarea id="news-content" name="content" required placeholder="Write your news content here..."></textarea>
         <label for="news-image">Upload Image (optional):</label>
         <input type="file" id="news-image" name="image" accept="image/*">
         <button type="submit">Add News</button>
      </form>
   </div>
</div>

<script>
// Function to open modal
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

// Function to close modal
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Handle form submissions
const formHandlers = {
    'noteForm': {
        url: 'noteAdd.php',
        successMessage: 'Note uploaded successfully'
    },
    'questionForm': {
        url: 'questionAdd.php',
        successMessage: 'Question uploaded successfully'
    },
    'newsForm': {
        url: 'newsAdd.php',
        successMessage: 'News added successfully'
    }
};

Object.entries(formHandlers).forEach(([formId, config]) => {
    document.getElementById(formId).addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        try {
            const response = await fetch(config.url, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert(config.successMessage);
                closeModal(formId.replace('Form', 'Modal'));
                this.reset();
            } else {
                alert(result.message || 'An error occurred');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred while uploading');
        }
    });
});
</script>

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

/* Box container styles */
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
    border: none;
    cursor: pointer;
}

.inline-btn:hover {
    background-color: #45a049;
}
</style>
</body>
</html>
