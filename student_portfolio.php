<?php
session_start();
include __DIR__ . '/db_connect.php';
include_once __DIR__ . '/ai_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student_teacher') { 
    header("Location: index.php"); 
    exit(); 
}

$user_id  = intval($_SESSION['user_id']);
$fullname = $_SESSION['fullname'] ?? 'Student Teacher';
$username = $_SESSION['username'] ?? 'student';
$email    = $_SESSION['email'] ?? '';
$msg      = isset($_GET['msg']) ? trim($_GET['msg']) : "";
$error    = "";

// -------------------------------------------------------------
// HELPER: Extract text content from submitted files (DOCX, XLSX, PDF, TXT)
// -------------------------------------------------------------
function extractFileTextContent($filePath) {
    if (!file_exists($filePath)) return "";
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if (in_array($ext, ['txt', 'md', 'csv', 'html', 'json'])) {
        return substr(file_get_contents($filePath), 0, 7000);
    }
    
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === TRUE) {
            $xmlIndex = $zip->locateName('word/document.xml');
            if ($xmlIndex !== false) {
                $xml = $zip->getFromIndex($xmlIndex);
                $zip->close();
                $cleanText = str_replace(['</w:p>', '</w:r>', '<w:tab/>'], ["\n", "", "\t"], $xml);
                return substr(strip_tags($cleanText), 0, 7000);
            }
            $zip->close();
        }
    }
    
    if (in_array($ext, ['xlsx', 'xlxx']) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === TRUE) {
            $xmlIndex = $zip->locateName('xl/sharedStrings.xml');
            if ($xmlIndex !== false) {
                $xml = $zip->getFromIndex($xmlIndex);
                $zip->close();
                $cleanText = str_replace('</t>', " ", $xml);
                return substr(strip_tags($cleanText), 0, 7000);
            }
            $zip->close();
        }
    }
    
    if ($ext === 'pdf') {
        $content = @file_get_contents($filePath);
        if ($content && preg_match_all('/\((.*?)\)\s*T[jJ]/s', $content, $matches)) {
            $extracted = implode(' ', $matches);
            if (!empty(trim($extracted))) {
                return substr($extracted, 0, 7000);
            }
        }
        return "[Attached PDF: " . basename($filePath) . "]";
    }
    
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
        return "[Visual Media Evidence Attached: " . basename($filePath) . "]";
    }
    
    return "[Attached File: " . basename($filePath) . "]";
}

// -------------------------------------------------------------
// 1. ACTION: Create New Portfolio Draft
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'new') {
    $default_title = "Untitled Lesson Plan - " . date("M d, Y");
    $stmt = $conn->prepare("INSERT INTO submissions (user_id, title, description, status, chat_transcript) VALUES (?, ?, '', 'draft', '[]')");
    $stmt->bind_param("is", $user_id, $default_title);
    if ($stmt->execute()) {
        header("Location: student_portfolio.php?id=" . $stmt->insert_id);
        exit();
    }
}

// -------------------------------------------------------------
// 2. ACTION: Delete Draft Portfolio
// -------------------------------------------------------------
if (isset($_POST['delete_draft'])) {
    $del_id = intval($_POST['portfolio_id']);
    $stmt = $conn->prepare("DELETE FROM submissions WHERE id=? AND user_id=? AND status='draft'");
    $stmt->bind_param("ii", $del_id, $user_id);
    if ($stmt->execute()) {
        header("Location: student_portfolio.php?msg=" . urlencode("Draft portfolio deleted."));
        exit();
    }
}

// -------------------------------------------------------------
// 3. ACTION: Make a Revision Copy
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'revise') {
    $orig_id = intval($_GET['orig_id']);
    $q = $conn->query("SELECT * FROM submissions WHERE id=$orig_id AND user_id=$user_id");
    if ($q && $q->num_rows > 0) {
        $orig = $q->fetch_assoc();
        $rev_title = $orig['title'] . " (Revision)";
        $stmt = $conn->prepare("INSERT INTO submissions (user_id, title, description, file_path, status, chat_transcript) VALUES (?, ?, ?, ?, 'draft', ?)");
        $stmt->bind_param("issss", $user_id, $rev_title, $orig['description'], $orig['file_path'], $orig['chat_transcript']);
        if ($stmt->execute()) {
            header("Location: student_portfolio.php?id=" . $stmt->insert_id);
            exit();
        }
    }
}

// -------------------------------------------------------------
// 4. FETCH ALL SUBMISSIONS (For Drawer)
// -------------------------------------------------------------
$all_submissions = [];
$res = $conn->query("
    SELECT s.*, e.competency_score, e.readiness_notes 
    FROM submissions s 
    LEFT JOIN evaluations e ON s.id = e.submission_id 
    WHERE s.user_id = $user_id 
    ORDER BY s.id DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $all_submissions[] = $row;
    }
}

// -------------------------------------------------------------
// 5. LOAD ACTIVE PORTFOLIO
// -------------------------------------------------------------
$portfolio_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($portfolio_id === 0 && !empty($all_submissions)) {
    $portfolio_id = intval($all_submissions[0]['id']);
}

$portfolio = null;
$is_graded = false;

if ($portfolio_id > 0) {
    $sql_port = "SELECT s.*, e.competency_score, e.readiness_notes, u_eval.fullname as evaluator_name 
                 FROM submissions s 
                 LEFT JOIN evaluations e ON s.id = e.submission_id 
                 LEFT JOIN users u_eval ON e.evaluator_id = u_eval.id 
                 WHERE s.id=$portfolio_id AND s.user_id=$user_id LIMIT 1";
    $p_res = $conn->query($sql_port);
    if ($p_res && $p_res->num_rows > 0) {
        $portfolio = $p_res->fetch_assoc();
        if (!empty($portfolio['competency_score']) || in_array($portfolio['status'], ['accepted', 'graded'])) {
            $is_graded = true;
        }
    } else {
        $portfolio_id = 0;
    }
}

// -------------------------------------------------------------
// 6. ACTION: Save Draft or Submit Portfolio
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_portfolio']) && $portfolio_id > 0 && !$is_graded) {
    $title_val  = trim($_POST['title'] ?? 'Untitled Portfolio');
    $desc_val   = trim($_POST['description'] ?? '');
    $new_status = ($_POST['save_portfolio'] === 'submit') ? 'pending' : 'draft';
    
    $target_file = $portfolio['file_path'] ?? '';
    if (!empty($_FILES['file']['name'])) {
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $clean_name = preg_replace("/[^a-zA-Z0-9._-]/", "", basename($_FILES["file"]["name"]));
        $target_file = $target_dir . $user_id . "_evidence_" . time() . "_" . $clean_name;
        move_uploaded_file($_FILES["file"]["tmp_name"], $target_file);
    }

    $stmt = $conn->prepare("UPDATE submissions SET title=?, description=?, file_path=?, status=? WHERE id=? AND user_id=?");
    $stmt->bind_param("ssssii", $title_val, $desc_val, $target_file, $new_status, $portfolio_id, $user_id);
    if ($stmt->execute()) {
        $notif = ($new_status === 'pending') ? "Portfolio submitted for official review!" : "Draft saved successfully!";
        header("Location: student_portfolio.php?id=" . $portfolio_id . "&msg=" . urlencode($notif));
        exit();
    }
}

// -------------------------------------------------------------
// 7. ACTION: Dedicated AI Copilot Evaluation & Chat
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_chat']) && $portfolio_id > 0) {
    $user_msg     = trim($_POST['message'] ?? '');
    $auto_eval    = isset($_POST['run_eval']) && $_POST['run_eval'] == '1';
    $current_desc = trim($_POST['context_description'] ?? ($portfolio['description'] ?? ''));
    $current_ttl  = trim($_POST['context_title'] ?? ($portfolio['title'] ?? ''));

    if ($auto_eval) {
        $user_msg = "Please evaluate this portfolio submission against the 100-Point PPST Rubric. Evaluate Objectives (20), Content (20), Methodology (30), Assessment (20), and Mechanics (10). Provide an estimated score out of 100 with clear marks [GREAT: 90-100], [GOOD: 75-89], or [NEEDS REVISION: <75].";
    }

    if (!empty($user_msg)) {
        $chats = json_decode($portfolio['chat_transcript'] ?? '[]', true);
        if (!is_array($chats)) { $chats = []; }

        $chats[] = [
            'sender'  => 'user',
            'message' => $user_msg,
            'time'    => date('h:i A')
        ];

        $file_context_str = "";
        if (!empty($portfolio['file_path'])) {
            $extractedText = extractFileTextContent($portfolio['file_path']);
            if (!empty($extractedText)) {
                $file_context_str = "\n\n--- ATTACHED EVIDENCE / ARTIFACT CONTENT (" . basename($portfolio['file_path']) . ") ---\n" 
                                  . $extractedText 
                                  . "\n--- END OF ATTACHMENT ---\n";
            }
        }

        $ai_prompt = "You are an expert Academic Copilot and Teacher Education Mentor for Pre-Service Teachers.\n"
                   . "100-Point PPST Rubric Standards:\n"
                   . "1. Objectives (20 pts): Specific, Measurable, HOTS-aligned (Bloom's Taxonomy).\n"
                   . "2. Content (20 pts): Accurate subject matter aligned with curriculum.\n"
                   . "3. Methodology (30 pts): Active student-centered pedagogy and instructional flow.\n"
                   . "4. Assessment (20 pts): Formative/summative tools aligned with objectives.\n"
                   . "5. Formatting & Mechanics (10 pts): Professional documentation.\n\n"
                   . "Portfolio Title: $current_ttl\n"
                   . "Lesson Content / Procedures:\n$current_desc\n"
                   . $file_context_str . "\n"
                   . "User Inquiry: $user_msg\n\n"
                   . "Provide structured, constructive feedback.";

        $ai_reply = "Hello! I reviewed your submission and attached materials.";
        if (function_exists('generateAIResponse')) {
            $ai_reply = generateAIResponse($ai_prompt, 'mentor');
        }

        $chats[] = [
            'sender'  => 'ai',
            'message' => $ai_reply,
            'time'    => date('h:i A')
        ];

        $updated_json = json_encode($chats);
        $stmt = $conn->prepare("UPDATE submissions SET chat_transcript=? WHERE id=? AND user_id=?");
        $stmt->bind_param("sii", $updated_json, $portfolio_id, $user_id);
        $stmt->execute();

        header("Location: student_portfolio.php?id=" . $portfolio_id);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portfolio Hub | CORE</title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-color: #f4f6f9;
            --card-bg: #ffffff;
            --text-color: #2c3e50;
            --border-color: #e2e8f0;
            --primary-accent: #4f46e5;
            --primary-hover: #4338ca;
            --sidebar-bg: #2c3e50;
            --sidebar-text: #ecf0f1;
        }

        body.dark-mode {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-color: #f8fafc;
            --border-color: #334155;
            --primary-accent: #6366f1;
            --primary-hover: #4f46e5;
            --sidebar-bg: #090d16;
            --sidebar-text: #94a3b8;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Navigation */
        .sidebar {
            width: 250px;
            height: 100vh;
            background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
            color: #ecf0f1;
            position: fixed;
            top: 0;
            left: 0;
            padding: 20px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar h2 {
            font-size: 18px;
            color: #fff;
            margin: 0 0 25px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar a {
            padding: 12px 16px;
            color: #cbd5e1;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
        }

        .sidebar a:hover, .sidebar a.active {
            background-color: var(--primary-accent);
            color: #ffffff;
            transform: translateX(4px);
        }

        .logout-btn {
            margin-top: auto;
            background-color: #dc2626 !important;
            color: white !important;
            justify-content: center;
        }

        /* Content Container */
        .main-content {
            margin-left: 250px;
            padding: 24px 30px;
            width: calc(100% - 250px);
            box-sizing: border-box;
            min-height: 100vh;
        }

        /* Top Header */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            padding: 18px 24px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }

        /* 3-Column Studio Layout */
        .workspace-grid {
            display: grid;
            grid-template-columns: 270px 1fr 370px;
            gap: 20px;
            align-items: stretch;
            min-height: calc(100vh - 220px);
        }

        @media (max-width: 1280px) {
            .workspace-grid { grid-template-columns: 240px 1fr 340px; }
        }

        @media (max-width: 1024px) {
            .workspace-grid { grid-template-columns: 1fr; }
        }

        .studio-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Drawer (Left) */
        .drawer-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .drawer-list {
            flex: 1;
            overflow-y: auto;
            max-height: calc(100vh - 280px);
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .drawer-item {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-color);
            display: flex;
            flex-direction: column;
            gap: 4px;
            background: var(--card-bg);
            transition: all 0.15s ease;
        }

        .drawer-item:hover, .drawer-item.active {
            border-color: var(--primary-accent);
            background: rgba(79, 70, 229, 0.05);
        }

        .drawer-item.active {
            border-left: 4px solid var(--primary-accent);
        }

        /* Center Workspace */
        .workspace-body {
            padding: 24px;
            overflow-y: auto;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            width: 100%;
            margin-bottom: 20px;
        }

        .form-group label {
            font-weight: 700;
            font-size: 13.5px;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            box-sizing: border-box;
            display: block;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            font-family: inherit;
        }

        /* ========================================================= */
        /* STYLED CONTENT CONTAINER ("Add files or type in your files") */
        /* ========================================================= */
        .content-builder-container {
            background: linear-gradient(180deg, rgba(79, 70, 229, 0.02) 0%, rgba(0, 0, 0, 0.02) 100%);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-top: 4px;
        }

        body.dark-mode .content-builder-container {
            background: linear-gradient(180deg, rgba(99, 102, 241, 0.05) 0%, rgba(15, 23, 42, 0.5) 100%);
        }

        .builder-tab-switch {
            display: flex;
            gap: 12px;
            margin-bottom: 18px;
        }

        .tab-choice-btn {
            flex: 1;
            padding: 12px 16px;
            border-radius: 10px;
            border: 2px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .tab-choice-btn:hover {
            border-color: var(--primary-accent);
        }

        .tab-choice-btn.active {
            border-color: var(--primary-accent);
            background: var(--primary-accent);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
        }

        /* Lined Paper Textarea */
        .lined-paper-wrapper {
            background: #fdfbf7;
            border-radius: 10px;
            padding: 16px;
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.04), 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #e2dcd0;
        }

        body.dark-mode .lined-paper-wrapper {
            background: #1e293b;
            border-color: #334155;
        }

        .lined-paper-textarea {
            width: 100%;
            box-sizing: border-box;
            border: none;
            outline: none;
            resize: vertical;
            font-size: 14.5px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1e293b;
            background-color: transparent;
            /* Aesthetic Paper Margin and Horizontal Ruled Lines */
            background-image: 
                linear-gradient(90deg, transparent 48px, #f87171 48px, #f87171 50px, transparent 50px),
                repeating-linear-gradient(transparent, transparent 31px, #cbd5e1 31px, #cbd5e1 32px);
            background-attachment: local;
            line-height: 32px;
            padding: 8px 18px 8px 65px;
            min-height: 350px;
        }

        body.dark-mode .lined-paper-textarea {
            color: #f8fafc;
            background-image: 
                linear-gradient(90deg, transparent 48px, #ef4444 48px, #ef4444 50px, transparent 50px),
                repeating-linear-gradient(transparent, transparent 31px, #334155 31px, #334155 32px);
        }

        /* Upload & File Viewer */
        .dropzone-box {
            border: 2px dashed var(--border-color);
            background: var(--card-bg);
            border-radius: 10px;
            padding: 24px;
            text-align: center;
            transition: border-color 0.2s ease;
        }

        .dropzone-box:hover {
            border-color: var(--primary-accent);
        }

        .file-viewer-display {
            margin-top: 15px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            padding: 16px;
        }

        /* Copilot Sidebar (Right) */
        .copilot-header {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #ffffff;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .copilot-chat-history {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 520px;
            background: rgba(0,0,0,0.01);
        }

        .chat-bubble {
            max-width: 85%;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .chat-bubble.user {
            align-self: flex-end;
            background: var(--primary-accent);
            color: #ffffff;
            border-bottom-right-radius: 2px;
        }

        .chat-bubble.ai {
            align-self: flex-start;
            background: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-bottom-left-radius: 2px;
        }

        .copilot-input-bar {
            padding: 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* Status Badges */
        .card-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-draft { background-color: #fff3cd; color: #856404; }
        .badge-pending { background-color: #cce5ff; color: #004085; }
        .badge-graded { background-color: #d4edda; color: #155724; }

        /* Fullscreen Copilot Loading Screen */
        .copilot-loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(5px);
            z-index: 999999;
            display: none;
            justify-content: center;
            align-items: center;
            color: #ffffff;
        }

        .loading-dialog {
            background: var(--card-bg);
            color: var(--text-color);
            padding: 32px 40px;
            border-radius: 16px;
            text-align: center;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }

        .spinner-ring {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(79, 70, 229, 0.15);
            border-top: 4px solid var(--primary-accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <!-- Fullscreen Copilot Loading Screen -->
    <div id="copilotLoadingOverlay" class="copilot-loading-overlay">
        <div class="loading-dialog">
            <div class="spinner-ring"></div>
            <i class="fas fa-robot" style="font-size: 32px; color: var(--primary-accent);"></i>
            <h3 style="margin: 0; font-size: 18px;">Copilot is Analyzing...</h3>
            <p style="margin: 0; font-size: 13px; opacity: 0.8; line-height: 1.5;">
                Reviewing your lesson objectives and scanning submitted evidence files. Please wait.
            </p>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- MAIN NAVIGATION SIDEBAR                        -->
    <!-- ============================================== -->
    <div class="sidebar">
        <h2><i class="fas fa-cube" style="color:var(--primary-accent);"></i> CORE Evaluation</h2>
        
        <a href="dashboard.php">
            <i class="fas fa-chart-line"></i> Dashboard
        </a>
        
        <a href="user_evaluation.php">
            <i class="fas fa-clipboard-check"></i> My Evaluations
        </a>

        <a href="student_portfolio.php" class="active">
            <i class="fas fa-folder"></i> My Portfolio
        </a>

        <a href="user_profile.php">
            <i class="fas fa-user"></i> My Profile
        </a>

        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <!-- ============================================== -->
    <!-- MAIN CONTENT AREA                              -->
    <!-- ============================================== -->
    <div class="main-content">

        <!-- Top Header Card -->
        <div class="top-header">
            <div style="display:flex; align-items:center; gap:14px;">
                <div style="width:46px; height:46px; border-radius:50%; background:var(--primary-accent); color:white; display:flex; align-items:center; justify-content:center; font-size:18px;">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <h3 style="margin:0; font-size:17px;"><?= htmlspecialchars($fullname) ?></h3>
                        <span style="background:#e0f2fe; color:#0369a1; font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px; text-transform:uppercase;">Student Teacher</span>
                    </div>
                    <div style="font-size:12px; opacity:0.7; margin-top:3px;">
                        <i class="fas fa-id-badge"></i> <?= htmlspecialchars($username) ?> &bull; <?= htmlspecialchars($email) ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:16px;">
                <div style="text-align:right;">
                    <div id="liveClock" style="font-weight:700; font-size:14px;"><i class="far fa-clock"></i> --:--:--</div>
                    <div id="liveDate" style="font-size:12px; opacity:0.7;">Loading date...</div>
                </div>
                <button id="themeToggle" class="theme-toggle" onclick="toggleDarkMode()" style="background:transparent; border:1px solid var(--border-color); color:var(--text-color); padding:8px 14px; border-radius:20px; font-weight:600; cursor:pointer;">
                    <i class="fas fa-moon"></i> Dark Mode
                </button>
            </div>
        </div>

        <!-- Navigation Bar: Back Button & Status Badge -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:10px;">
            <a href="student_portfolio.php" class="btn" style="background:#334155; color:white; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-arrow-left"></i> Back to All Portfolios
            </a>

            <div>
                <?php if ($is_graded): ?>
                    <span class="card-status-badge badge-graded"><i class="fas fa-check-circle"></i> Graded (<?= $portfolio['competency_score'] ?>/100)</span>
                <?php elseif ($portfolio && $portfolio['status'] === 'pending'): ?>
                    <span class="card-status-badge badge-pending"><i class="fas fa-clock"></i> Under Review</span>
                <?php else: ?>
                    <span class="card-status-badge badge-draft"><i class="fas fa-edit"></i> Draft Mode</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alert Notification -->
        <?php if ($msg): ?>
            <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:500;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <!-- 3-Column Studio Grid -->
        <div class="workspace-grid">
            
            <!-- ============================================== -->
            <!-- 1. LEFT PANE: Portfolio Submissions History    -->
            <!-- ============================================== -->
            <div class="studio-card">
                <div class="drawer-header">
                    <strong style="font-size:14px;"><i class="fas fa-folder-open"></i> My Portfolios</strong>
                    <a href="student_portfolio.php?action=new" style="background:#27ae60; color:white; padding:5px 10px; border-radius:6px; font-size:12px; text-decoration:none;">
                        <i class="fas fa-plus"></i> New
                    </a>
                </div>

                <div class="drawer-list">
                    <?php if (!empty($all_submissions)): ?>
                        <?php foreach ($all_submissions as $row): 
                            $status = $row['status'] ?? 'draft';
                            $has_grade = !empty($row['competency_score']);
                            $isActive = ($portfolio_id === intval($row['id']));
                        ?>
                            <a href="student_portfolio.php?id=<?= $row['id'] ?>" class="drawer-item <?= $isActive ? 'active' : '' ?>">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <?php if ($has_grade): ?>
                                        <span class="card-status-badge badge-graded" style="font-size:10px; padding:2px 6px;">Score: <?= $row['competency_score'] ?></span>
                                    <?php elseif ($status === 'pending'): ?>
                                        <span class="card-status-badge badge-pending" style="font-size:10px; padding:2px 6px;">Review</span>
                                    <?php else: ?>
                                        <span class="card-status-badge badge-draft" style="font-size:10px; padding:2px 6px;">Draft</span>
                                    <?php endif; ?>
                                    <small style="opacity:0.6; font-size:11px;"><?= date("M d", strtotime($row['created_at'] ?? 'now')) ?></small>
                                </div>
                                <div style="font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <?= htmlspecialchars($row['title'] ?: 'Untitled Portfolio') ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center; padding:30px 10px; opacity:0.6; font-size:13px;">
                            No portfolios yet.<br>Click <strong>+ New</strong> to start.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================== -->
            <!-- 2. CENTER PANE: Workspace                      -->
            <!-- ============================================== -->
            <div class="studio-card">
                <?php if ($portfolio): ?>
                    <div class="workspace-body">

                        <!-- Graded Score Banner -->
                        <?php if ($is_graded): ?>
                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-left:5px solid #22c55e; padding:16px; border-radius:10px; margin-bottom:20px; color:#14532d;">
                                <h3 style="margin:0 0 6px; font-size:17px; display:flex; align-items:center; gap:8px;">
                                    <i class="fas fa-award"></i> Official Score: <?= $portfolio['competency_score'] ?> / 100
                                </h3>
                                <p style="margin:0 0 4px; font-size:13px;">
                                    <strong>Evaluator:</strong> <?= htmlspecialchars($portfolio['evaluator_name'] ?? 'Supervisor') ?>
                                </p>
                                <?php if (!empty($portfolio['readiness_notes'])): ?>
                                    <p style="margin:8px 0 0; font-size:13px; line-height:1.5;">
                                        <strong>Notes:</strong> <?= nl2br(htmlspecialchars($portfolio['readiness_notes'])) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="mainPortfolioForm">
                            <!-- Portfolio Title -->
                            <div class="form-group">
                                <label>Portfolio / Lesson Plan Title</label>
                                <input type="text" name="title" class="form-control" 
                                       value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>" 
                                       placeholder="e.g., Computer Systems Servicing - Module 1"
                                       <?= $is_graded ? 'readonly' : '' ?>>
                            </div>

                            <!-- "Add files or type in your files" Container -->
                            <div class="form-group">
                                <label style="display:flex; justify-content:space-between; align-items:center;">
                                    <span>Add files or type in your files</span>
                                    <small style="font-weight:normal; opacity:0.7;">(Choose either option to provide your lesson context)</small>
                                </label>

                                <div class="content-builder-container">
                                    <!-- Option Selectors -->
                                    <div class="builder-tab-switch">
                                        <button type="button" class="tab-choice-btn active" id="btnModeUpload" onclick="setContextOption('upload')">
                                            <i class="fas fa-file-upload"></i> Add Files (Upload & Preview)
                                        </button>
                                        <button type="button" class="tab-choice-btn" id="btnModeType" onclick="setContextOption('type')">
                                            <i class="fas fa-pen-nib"></i> Type in your files
                                        </button>
                                    </div>

                                    <!-- Option 1: File Upload & In-Page Document Viewer -->
                                    <div id="sectionUploadOption" style="display:block;">
                                        <div class="dropzone-box">
                                            <i class="fas fa-cloud-upload-alt" style="font-size:36px; color:var(--primary-accent); margin-bottom:10px;"></i>
                                            <h4 style="margin:0 0 6px;">Upload Lesson Artifacts or Evidence</h4>
                                            <p style="margin:0 0 14px; font-size:12.5px; opacity:0.75;">Supports DOCX, XLSX, PDF, Images, or TXT.</p>
                                            
                                            <?php if (!$is_graded): ?>
                                                <input type="file" name="file" id="portfolioFileInput" onchange="handleFileSelected(this)" class="form-control" style="max-width:360px; margin:0 auto; padding:6px;">
                                            <?php endif; ?>
                                        </div>

                                        <!-- Live File Viewer Container -->
                                        <div id="workViewerContainer" class="file-viewer-display" style="<?= empty($portfolio['file_path']) ? 'display:none;' : '' ?>">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                                <div style="font-weight:700; font-size:13.5px;"><i class="fas fa-eye"></i> Document Preview</div>
                                                <?php if (!empty($portfolio['file_path'])): ?>
                                                    <a href="<?= htmlspecialchars($portfolio['file_path']) ?>" target="_blank" style="color:var(--primary-accent); font-weight:600; font-size:12px;">
                                                        <i class="fas fa-external-link-alt"></i> Open Full File
                                                    </a>
                                                <?php endif; ?>
                                            </div>

                                            <div id="filePreviewTarget">
                                                <?php 
                                                if (!empty($portfolio['file_path'])) {
                                                    $ext = strtolower(pathinfo($portfolio['file_path'], PATHINFO_EXTENSION));
                                                    if ($ext === 'pdf') {
                                                        echo '<iframe src="' . htmlspecialchars($portfolio['file_path']) . '" style="width:100%; height:380px; border:none; border-radius:6px;"></iframe>';
                                                    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                                                        echo '<img src="' . htmlspecialchars($portfolio['file_path']) . '" style="max-width:100%; max-height:380px; border-radius:6px; display:block; margin:0 auto;">';
                                                    } else {
                                                        echo '<div style="background:var(--card-bg); padding:16px; border-radius:6px; border:1px solid var(--border-color); font-size:13px;">'
                                                           . '<i class="fas fa-file-alt" style="font-size:24px; color:var(--primary-accent); margin-right:8px;"></i>'
                                                           . '<strong>' . htmlspecialchars(basename($portfolio['file_path'])) . '</strong>'
                                                           . '<p style="margin:8px 0 0; opacity:0.75;">Attached file ready for review. Copilot extracts and analyzes this file during scoring.</p>'
                                                           . '</div>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Option 2: Lined Notebook Paper Editor -->
                                    <div id="sectionTypeOption" style="display:none;">
                                        <div class="lined-paper-wrapper">
                                            <textarea name="description" id="editorContent" class="lined-paper-textarea" rows="14" 
                                                      placeholder="Start typing your lesson plan, objectives, teaching methodology, and evaluation here..."
                                                      <?= $is_graded ? 'readonly' : '' ?>><?= htmlspecialchars($portfolio['description'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Submission Actions -->
                            <?php if (!$is_graded): ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:24px;">
                                    <button type="submit" name="delete_draft" class="btn" style="background:#ef4444; color:white; border:none; padding:9px 16px; border-radius:6px; font-size:13px; cursor:pointer;" onclick="return confirm('Are you sure you want to delete this draft?');">
                                        <i class="fas fa-trash"></i> Delete Draft
                                    </button>
                                    
                                    <div style="display:flex; gap:10px;">
                                        <button type="submit" name="save_portfolio" value="draft" class="btn" style="background:#64748b; color:white; border:none; padding:10px 18px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">
                                            <i class="fas fa-save"></i> Save Draft
                                        </button>
                                        <button type="submit" name="save_portfolio" value="submit" class="btn" onclick="return confirmPortfolioSubmission()" style="background:#22c55e; color:white; border:none; padding:10px 24px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">
                                            <i class="fas fa-paper-plane"></i> Submit for Review
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="student_portfolio.php?action=revise&orig_id=<?= $portfolio['id'] ?>" class="btn" style="background:var(--primary-accent); color:white; padding:10px 20px; border-radius:6px; text-decoration:none; display:inline-block; font-size:13px; font-weight:600;">
                                    <i class="fas fa-copy"></i> Edit a Copy (Revision)
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="display:flex; align-items:center; justify-content:center; height:100%; text-align:center; padding:40px;">
                        <div>
                            <i class="fas fa-folder-plus" style="font-size:48px; opacity:0.3; margin-bottom:12px;"></i>
                            <h3>No Portfolio Selected</h3>
                            <p style="opacity:0.7; font-size:14px; margin-bottom:20px;">Pick a portfolio from the left or create your first lesson plan.</p>
                            <a href="student_portfolio.php?action=new" style="background:#27ae60; color:white; padding:10px 20px; border-radius:6px; text-decoration:none;">
                                <i class="fas fa-plus"></i> Create New Plan
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================================== -->
            <!-- 3. RIGHT PANE: 100-Point AI Copilot Studio     -->
            <!-- ============================================== -->
            <div class="studio-card">
                <div class="copilot-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-robot"></i>
                        <span style="font-weight:700; font-size:14px;">100-Point Copilot</span>
                    </div>

                    <?php if ($portfolio): ?>
                        <form method="POST" class="copilot-submit-trigger" style="margin:0;">
                            <input type="hidden" name="run_eval" value="1">
                            <input type="hidden" name="context_title" value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>">
                            <input type="hidden" name="context_description" value="<?= htmlspecialchars($portfolio['description'] ?? '') ?>">
                            <button type="submit" name="send_chat" style="background:#ffffff; color:#4f46e5; border:none; padding:4px 10px; font-size:11px; border-radius:20px; font-weight:700; cursor:pointer;" title="Run full 100-point rubric evaluation">
                                <i class="fas fa-chart-bar"></i> Evaluate 100-Pts
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Chat Feed -->
                <div class="copilot-chat-history" id="copilotChatBox">
                    <?php 
                    $chats = json_decode($portfolio['chat_transcript'] ?? '[]', true);
                    if (is_array($chats) && count($chats) > 0): 
                        foreach ($chats as $msg_item): 
                    ?>
                        <div class="chat-bubble <?= ($msg_item['sender'] === 'user') ? 'user' : 'ai' ?>">
                            <div style="font-size:10px; opacity:0.75; margin-bottom:4px;">
                                <?= ($msg_item['sender'] === 'user') ? 'You' : 'AI Copilot' ?> &bull; <?= htmlspecialchars($msg_item['time'] ?? '') ?>
                            </div>
                            <div><?= nl2br(htmlspecialchars($msg_item['message'])) ?></div>
                        </div>
                    <?php 
                        endforeach; 
                    else: 
                    ?>
                        <div class="chat-bubble ai">
                            <div style="font-size:10px; opacity:0.75; margin-bottom:4px;">AI Copilot</div>
                            <div>Hello! I am your portfolio mentor. I can read your attached documents (.docx, .xlsx, .pdf, images) and evaluate your lesson against the 100-point PPST rubric. Click <strong>"Evaluate 100-Pts"</strong> or ask any question!</div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Copilot Input Field -->
                <?php if ($portfolio): ?>
                    <form method="POST" class="copilot-input-bar copilot-submit-trigger" id="copilotForm">
                        <input type="hidden" name="context_title" value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>">
                        <input type="hidden" name="context_description" id="hiddenContextDescription" value="<?= htmlspecialchars($portfolio['description'] ?? '') ?>">
                        <input type="text" name="message" class="form-control" placeholder="Ask Copilot for feedback..." required style="border-radius:20px; padding:10px 14px; font-size:13px;">
                        <button type="submit" name="send_chat" style="background:var(--primary-accent); color:white; border-radius:50%; width:36px; height:36px; border:none; cursor:pointer; flex-shrink:0;">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

        </div>

    </div>

    <!-- Interactive Scripts -->
    <script>
    // 1. Live Clock and Date
    function updateClock() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        
        const clockEl = document.getElementById('liveClock');
        const dateEl = document.getElementById('liveDate');
        if (clockEl) clockEl.innerHTML = '<i class="far fa-clock"></i> ' + timeStr;
        if (dateEl) dateEl.innerText = dateStr;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Dark Mode Toggle
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('core_dark_mode', isDark ? '1' : '0');
    }
    if (localStorage.getItem('core_dark_mode') === '1') {
        document.body.classList.add('dark-mode');
    }

    // 3. Option Toggle: Add Files vs Type in your files
    function setContextOption(option) {
        const uploadSec = document.getElementById('sectionUploadOption');
        const typeSec   = document.getElementById('sectionTypeOption');
        const btnUpload = document.getElementById('btnModeUpload');
        const btnType   = document.getElementById('btnModeType');

        if (option === 'upload') {
            uploadSec.style.display = 'block';
            typeSec.style.display   = 'none';
            btnUpload.classList.add('active');
            btnType.classList.remove('active');
        } else {
            uploadSec.style.display = 'none';
            typeSec.style.display   = 'block';
            btnType.classList.add('active');
            btnUpload.classList.remove('active');
            
            // Focus into the lined paper
            const editor = document.getElementById('editorContent');
            if (editor) editor.focus();
        }
    }

    // Auto-detect initial option tab
    <?php if (!empty($portfolio['description']) && empty($portfolio['file_path'])): ?>
        setContextOption('type');
    <?php endif; ?>

    // 4. Pre-submission Local File Viewer
    function handleFileSelected(input) {
        const file = input.files[0];
        if (!file) return;

        const viewerCard = document.getElementById('workViewerContainer');
        const previewTarget = document.getElementById('filePreviewTarget');
        viewerCard.style.display = 'block';

        const ext = file.name.split('.').pop().toLowerCase();
        
        if (ext === 'pdf') {
            const blobURL = URL.createObjectURL(file);
            previewTarget.innerHTML = '<iframe src="' + blobURL + '" style="width:100%; height:380px; border:none; border-radius:6px;"></iframe>';
        } else if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext)) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewTarget.innerHTML = '<img src="' + e.target.result + '" style="max-width:100%; max-height:380px; border-radius:6px; display:block; margin:0 auto;">';
            };
            reader.readAsDataURL(file);
        } else {
            previewTarget.innerHTML = '<div style="background:var(--card-bg); padding:16px; border-radius:6px; border:1px solid var(--border-color); font-size:13px;">'
                                    + '<i class="fas fa-file-alt" style="font-size:24px; color:var(--primary-accent); margin-right:8px;"></i>'
                                    + '<strong>Selected Document: ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)</strong>'
                                    + '<p style="margin:8px 0 0; opacity:0.75;">File loaded. It will be submitted and analyzed by the Copilot upon review.</p>'
                                    + '</div>';
        }
    }

    // 5. Submit Confirmation Dialog
    function confirmPortfolioSubmission() {
        return confirm("Are you sure you want to submit this portfolio for official review?\n\nOnce submitted, your lesson plan and attached files will be queued for evaluator/supervisor scoring.");
    }

    // 6. Copilot Loading Screen Trigger
    (function () {
        const chatBox = document.getElementById('copilotChatBox');
        if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

        const overlay = document.getElementById('copilotLoadingOverlay');
        const triggerForms = document.querySelectorAll('.copilot-submit-trigger');
        const editorText = document.getElementById('editorContent');
        const hiddenDesc = document.getElementById('hiddenContextDescription');

        triggerForms.forEach(function (form) {
            form.addEventListener('submit', function () {
                if (editorText && hiddenDesc) {
                    hiddenDesc.value = editorText.value;
                }
                if (overlay) {
                    overlay.style.display = 'flex';
                }
            });
        });
    })();
    </script>
</body>
</html>
