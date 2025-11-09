<?php
// --- CORS Configuration for Deployment (Crucial for Netlify/Render) ---
// Note: In production, replace '*' with your Netlify domain for better security.
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests (Browser sends OPTIONS request first)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// ---------------------------------------------------------------------

// Set headers for JSON response
header('Content-Type: application/json');

// --- Configuration and Setup ---
$db_file = '/tmp/connect_app.sqlite';

function getDbConnection() {
    global $db_file;
    try {
        $pdo = new PDO("sqlite:$db_file");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create USERS table (with job_title)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                job_title TEXT DEFAULT 'ConnectApp User'
            )
        ");
        try { $pdo->exec("ALTER TABLE users ADD COLUMN job_title TEXT DEFAULT 'ConnectApp User'"); } catch (PDOException $e) {}

        // Create POSTS table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        
        // Create LIKES table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS post_likes (
                user_id INTEGER NOT NULL,
                post_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, post_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
            )
        ");
        
        return $pdo;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit();
    }
}

$pdo = getDbConnection();
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
    exit();
}

// --- Controller Logic ---

switch ($data['action']) {
    case 'signup':
        handleSignup($pdo, $data); break;
    case 'login':
        handleLogin($pdo, $data); break;
    case 'createPost':
        handleCreatePost($pdo, $data); break;
    case 'getPosts':
        handleGetPosts($pdo, $data); break;
    case 'toggleLike':
        handleToggleLike($pdo, $data); break;
    case 'deletePost':
        handleDeletePost($pdo, $data); break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}

// --- Function Implementations ---

function handleSignup($pdo, $data) {
    if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        return;
    }
    
    $name = trim($data['name']);
    $email = trim(strtolower($data['email']));
    $password = $data['password'];
    $job_title = trim($data['job_title'] ?? 'ConnectApp User'); 

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, job_title) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $password_hash, $job_title]);
        echo json_encode(['success' => true, 'message' => 'User registered successfully.']);
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Signup failed due to a database error.']);
        }
    }
}

function handleLogin($pdo, $data) {
    if (empty($data['email']) || empty($data['password'])) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        return;
    }

    $email = trim(strtolower($data['email']));
    $password = $data['password'];

    $stmt = $pdo->prepare("SELECT id, name, password_hash, job_title FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        echo json_encode([
            'success' => true, 
            'message' => 'Login successful.',
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $email, 'job_title' => $user['job_title']] 
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    }
}

function handleCreatePost($pdo, $data) {
    if (empty($data['userId']) || empty($data['content'])) {
        echo json_encode(['success' => false, 'message' => 'User ID and content are required.']);
        return;
    }

    $userId = (int)$data['userId'];
    $content = trim($data['content']);

    try {
        $stmt = $pdo->prepare("INSERT INTO posts (user_id, content) VALUES (?, ?)");
        $stmt->execute([$userId, $content]);
        echo json_encode(['success' => true, 'message' => 'Post created successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Post creation failed.']);
    }
}

function handleGetPosts($pdo, $data) {
    $currentUserId = $data['currentUserId'];

    try {
        $sql = "
            SELECT 
                p.id, p.user_id, p.content, p.created_at, u.name as user_name, u.job_title,
                (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as like_count,
                CAST(EXISTS(SELECT 1 FROM post_likes WHERE post_id = p.id AND user_id = :currentUserId) AS INTEGER) as is_liked
            FROM posts p
            JOIN users u ON p.user_id = u.id
            ORDER BY p.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':currentUserId', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'posts' => $posts]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch posts.']);
    }
}

function handleToggleLike($pdo, $data) {
    if (empty($data['postId']) || empty($data['userId'])) {
        echo json_encode(['success' => false, 'message' => 'Post ID and User ID are required.']);
        return;
    }

    $postId = (int)$data['postId'];
    $userId = (int)$data['userId'];
    $isLiked = false;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$postId, $userId]);
    $count = $stmt->fetchColumn();

    try {
        if ($count > 0) {
            $stmt = $pdo->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$postId, $userId]);
            $isLiked = false;
        } else {
            $stmt = $pdo->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
            $stmt->execute([$postId, $userId]);
            $isLiked = true;
        }
        
        echo json_encode(['success' => true, 'isLiked' => $isLiked, 'message' => $isLiked ? 'Post liked.' : 'Post unliked.']);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Like action failed.']);
    }
}

function handleDeletePost($pdo, $data) {
    if (empty($data['postId']) || empty($data['userId'])) {
        echo json_encode(['success' => false, 'message' => 'Post ID and User ID are required.']);
        return;
    }

    $postId = (int)$data['postId'];
    $userId = (int)$data['userId'];

    try {
        // Only allow deletion if the user is the post owner
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Post deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'You cannot delete this post (or it does not exist).']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Deletion failed due to a database error.']);
    }
}
?>