<?php
session_start();

// Verify user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header("Location: expired");
    exit();
}

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$username = $_SESSION['username'];
$userid = $_SESSION['user_id'];

// Current folder ID
$current_folder_id = isset($_GET['folder_id']) ? intval($_GET['folder_id']) : null;

// Create folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_folder_name'])) {
    $folder_name = $conn->real_escape_string(trim($_POST['new_folder_name']));
    
    if (!empty($folder_name)) {
        // Create folder
        $query = "INSERT INTO folders (user_id, folder_name, parent_folder_id) VALUES ($userid, '$folder_name', ";
        $query .= $current_folder_id ? $current_folder_id : "NULL";
        $query .= ")";
        
        if ($conn->query($query)) {
            echo "<p style='color:green;'>Folder created successfully.</p>";
        } else {
            echo "<p style='color:red;'>Error creating folder: " . $conn->error . "</p>";
        }
    }
}

// File Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
    $filename = $conn->real_escape_string($_FILES['file']['name']);
    $file_tmp = $_FILES['file']['tmp_name'];
    $file_size = intval($_FILES['file']['size']);
    $file_type = $conn->real_escape_string($_FILES['file']['type']);
    
    // Check if file already exists
    $check_query = "SELECT id FROM files WHERE user_id = $userid AND filename = '$filename'";
    if ($current_folder_id) {
        $check_query .= " AND folder_id = $current_folder_id";
    } else {
        $check_query .= " AND folder_id IS NULL";
    }
    
    $check = $conn->query($check_query);
    
    if ($check->num_rows > 0) {
        echo "<p style='color:red;'>File already exists in this location.</p>";
    } else {
        // Read file content
        $file_content = file_get_contents($file_tmp);
        
        // Insert file metadata
        $insert_query = "INSERT INTO files (user_id, filename, file_size, file_type";
        $insert_query .= ", folder_id) VALUES ($userid, '$filename', $file_size, '$file_type'";
        $insert_query .= ", " . ($current_folder_id ? $current_folder_id : "NULL") . ")";
        
        if ($conn->query($insert_query)) {
            $file_id = $conn->insert_id;
            
            // Insert file content
            $content_insert = $conn->query("INSERT INTO file_content (file_id, content) VALUES ($file_id, '" . $conn->real_escape_string($file_content) . "')");
            
            if ($content_insert) {
                echo "<p style='color:green;'>File uploaded successfully.</p>";
            } else {
                echo "<p style='color:red;'>Error saving file content: " . $conn->error . "</p>";
            }
        } else {
            echo "<p style='color:red;'>Error saving file metadata: " . $conn->error . "</p>";
        }
    }
}

// Delete folder
if (isset($_GET['delete_folder']) && is_numeric($_GET['delete_folder'])) {
    $folder_id = intval($_GET['delete_folder']);
    
    // Check if folder belongs to user
    $check = $conn->query("SELECT id FROM folders WHERE id = $folder_id AND user_id = $userid");
    if ($check->num_rows > 0) {
        if ($conn->query("DELETE FROM folders WHERE id = $folder_id")) {
            echo "<p style='color:green;'>Folder deleted successfully.</p>";
            
            // Redirect if current folder was deleted
            if ($folder_id == $current_folder_id) {
                $parent = $conn->query("SELECT parent_folder_id FROM folders WHERE id = $folder_id")->fetch_assoc();
                $parent_id = $parent ? $parent['parent_folder_id'] : null;
                
                header("Location: home.php" . ($parent_id ? "?folder_id=$parent_id" : ""));
                exit();
            }
        } else {
            echo "<p style='color:red;'>Error deleting folder: " . $conn->error . "</p>";
        }
    }
}

// Delete file
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $file_id = intval($_GET['delete_id']);
    
    // Check if file belongs to user
    $check = $conn->query("SELECT id FROM files WHERE id = $file_id AND user_id = $userid");
    if ($check->num_rows > 0) {
        if ($conn->query("DELETE FROM files WHERE id = $file_id")) {
            echo "<p style='color:green;'>File deleted successfully.</p>";
        } else {
            echo "<p style='color:red;'>Error deleting file: " . $conn->error . "</p>";
        }
    }
}

// Get current folder info
$current_folder_name = "Root";
$parent_folder_id = null;

if ($current_folder_id) {
    $folder_info = $conn->query("SELECT folder_name, parent_folder_id FROM folders WHERE id = $current_folder_id AND user_id = $userid");
    if ($folder_info->num_rows > 0) {
        $folder = $folder_info->fetch_assoc();
        $current_folder_name = $folder['folder_name'];
        $parent_folder_id = $folder['parent_folder_id'];
    } else {
        // Invalid folder ID, redirect to root
        header("Location: home.php");
        exit();
    }
}

// Get subfolders
$folders = [];
$query = "SELECT id, folder_name FROM folders WHERE user_id = $userid AND ";
$query .= $current_folder_id ? "parent_folder_id = $current_folder_id" : "parent_folder_id IS NULL";
$query .= " ORDER BY folder_name";

$result = $conn->query($query);
while ($folder = $result->fetch_assoc()) {
    $folders[] = $folder;
}

// Get files in current folder
$files = [];
$query = "SELECT id, filename, file_size, file_type FROM files WHERE user_id = $userid AND ";
$query .= $current_folder_id ? "folder_id = $current_folder_id" : "folder_id IS NULL";
$query .= " ORDER BY filename";

$result = $conn->query($query);
while ($file = $result->fetch_assoc()) {
    $files[] = $file;
}

// Get breadcrumb
function getBreadcrumb($conn, $folder_id, $userid) {
    $path = [];
    $current = $folder_id;
    
    while ($current) {
        $result = $conn->query("SELECT id, folder_name, parent_folder_id FROM folders WHERE id = $current AND user_id = $userid");
        if ($result->num_rows > 0) {
            $folder = $result->fetch_assoc();
            array_unshift($path, ['id' => $folder['id'], 'name' => $folder['folder_name']]);
            $current = $folder['parent_folder_id'];
        } else {
            break;
        }
    }
    
    return $path;
}

$breadcrumb = $current_folder_id ? getBreadcrumb($conn, $current_folder_id, $userid) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudBOX - Files and Folders</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .item {
            background-color: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .folder-icon {
            color: #4f46e5;
        }
        
        .file-icon {
            color: #60a5fa;
        }
        
        .name {
            text-align: center;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            width: 100%;
        }
        
        .actions {
            display: flex;
            margin-top: 10px;
            gap: 5px;
        }
        
        .actions a {
            padding: 5px 10px;
            background-color: #f3f4f6;
            border-radius: 4px;
            text-decoration: none;
            color: #374151;
            font-size: 14px;
        }
        
        .actions a:hover {
            background-color: #e5e7eb;
        }
        
        .actions a.delete {
            color: #ef4444;
        }
        
        .actions a.delete:hover {
            background-color: #fee2e2;
        }
        
        .breadcrumb {
            background-color: #f9fafb;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            overflow-x: auto;
        }
        
        .breadcrumb a {
            color: #4f46e5;
            text-decoration: none;
            margin: 0 5px;
            white-space: nowrap;
        }
        
        .breadcrumb span {
            color: #9ca3af;
            margin: 0 5px;
        }
        
        .forms-container {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .form-box {
            flex: 1;
            min-width: 250px;
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #e5e7eb;
        }
        
        .form-box h3 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .form-box form {
            display: flex;
            flex-direction: column;
        }
        
        .form-box input[type="text"],
        .form-box input[type="file"] {
            margin-bottom: 10px;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }
        
        .form-box button {
            background-color: #4f46e5;
            color: white;
            border: none;
            padding: 8px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .form-box button:hover {
            background-color: #4338ca;
        }
        
        .section-title {
            margin-top: 30px;
            margin-bottom: 15px;
            color: #1f2937;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .section-title span {
            margin-right: 10px;
        }
        
        .file-details {
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            margin-top: 5px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="logo">
            <img src="logo.png" alt="CloudBOX Logo" height="40">
        </div>
        <h1>CloudBOX</h1>
        <div class="search-bar">
            <input type="text" placeholder="Search here...">
        </div>
    </div>
    
    <nav class="dashboard-nav">
        <a href="home">📊 Dashboard</a>
        <a href="drive">📁 My Drive</a>
        <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
        <a href="admin">👑 Admin Panel</a>
        <?php endif; ?>
        <a href="shared">🔄 Shared Files</a>
        <a href="monitoring">📈 Monitoring</a>
        <a href="logout">🚪 Logout</a>
    </nav>

    <main>
        <h1>Welcome, <?= htmlspecialchars($username) ?>!</h1>
        
        <!-- Breadcrumb navigation -->
        <div class="breadcrumb">
            <a href="home.php">📁 Root</a>
            <?php foreach ($breadcrumb as $folder): ?>
                <span>›</span>
                <a href="home.php?folder_id=<?= $folder['id'] ?>"><?= htmlspecialchars($folder['name']) ?></a>
            <?php endforeach; ?>
        </div>
        
        <h2>Current folder: <?= htmlspecialchars($current_folder_name) ?></h2>
        
        <!-- Forms for creating folders and uploading files -->
        <div class="forms-container">
            <div class="form-box">
                <h3>Create New Folder</h3>
                <form method="POST">
                    <input type="text" name="new_folder_name" placeholder="Folder name" required>
                    <button type="submit">Create Folder</button>
                </form>
            </div>
            
            <div class="form-box">
                <h3>Upload File</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="file" required>
                    <button type="submit">Upload File</button>
                </form>
            </div>
        </div>
        
        <!-- Folders section -->
        <?php if (!empty($folders)): ?>
        <div class="section-title">
            <span>📁</span> Folders
        </div>
        <div class="container-grid">
            <?php foreach ($folders as $folder): ?>
                <div class="item">
                    <div class="icon folder-icon">📁</div>
                    <div class="name"><?= htmlspecialchars($folder['folder_name']) ?></div>
                    <div class="actions">
                        <a href="home.php?folder_id=<?= $folder['id'] ?>">Open</a>
                        <a href="home.php?delete_folder=<?= $folder['id'] ?><?= $current_folder_id ? '&folder_id='.$current_folder_id : '' ?>" 
                           class="delete" 
                           onclick="return confirm('Are you sure you want to delete this folder?');">Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Files section -->
        <?php if (!empty($files)): ?>
        <div class="section-title">
            <span>📄</span> Files
        </div>
        <div class="container-grid">
            <?php foreach ($files as $file): ?>
                <div class="item">
                    <?php
                    // Determine file icon based on type
                    $icon = '📄'; // Default
                    if (strpos($file['file_type'], 'image/') === 0) {
                        $icon = '🖼️'; // Image
                    } elseif (strpos($file['file_type'], 'video/') === 0) {
                        $icon = '🎬'; // Video
                    } elseif (strpos($file['file_type'], 'audio/') === 0) {
                        $icon = '🎵'; // Audio
                    } elseif (strpos($file['file_type'], 'application/pdf') === 0) {
                        $icon = '📕'; // PDF
                    } elseif (strpos($file['file_type'], 'text/') === 0) {
                        $icon = '📝'; // Text
                    } elseif (strpos($file['file_type'], 'application/zip') === 0 || 
                             strpos($file['file_type'], 'application/x-rar') === 0) {
                        $icon = '🗜️'; // Archive
                    }
                    ?>
                    <div class="icon file-icon"><?= $icon ?></div>
                    <div class="name"><?= htmlspecialchars($file['filename']) ?></div>
                    <div class="file-details"><?= number_format($file['file_size'] / 1024, 2) ?> KB</div>
                    <div class="actions">
                        <a href="download.php?id=<?= $file['id'] ?>">Download</a>
                        <a href="home.php?delete_id=<?= $file['id'] ?><?= $current_folder_id ? '&folder_id='.$current_folder_id : '' ?>" 
                           class="delete" 
                           onclick="return confirm('Are you sure you want to delete this file?');">Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (empty($folders) && empty($files)): ?>
            <p>This folder is empty. Upload files or create folders to get started.</p>
        <?php endif; ?>
    </main>
</body>
</html>
