<?php
session_start();
include __DIR__ . '/db_connect.php';
include_once __DIR__ . '/ai_helper.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit(); 
}

$user_id = intval($_SESSION['user_id']);
$role    = $_SESSION['role'] ?? 'student_teacher'; 
$msg     = "";
$error   = "";

// --------------------------------------------------------------------------
// 1. MULTIFORMAT FILE CONTENT EXTRACTOR FOR COPILOT
// --------------------------------------------------------------------------
function extractFileContentForAI($filepath) {
    if (empty($filepath) || !file_exists($filepath)) {
        return "No external file attached.";
    }

    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $filename = basename($filepath);
    $filesize = round(filesize($filepath) / 1024, 1) . " KB";

    // A. Plain Text & Data Files (.txt, .csv, .json, .md, .html)
    if (in_array($ext, ['txt', 'csv', 'json', 'md', 'html'])) {
        $content = @file_get_contents($filepath);
        return "=== FILE: $filename ($filesize) ===\n" . substr($content, 0, 15000);
    }

    // B. Word Documents (.docx)
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filepath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml) {
                $text = strip_tags(str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $xml));
                return "=== DOCX FILE CONTENT: $filename ($filesize) ===\n" . substr(trim($text), 0, 15000);
            }
        }
    }

    // C. Excel Spreadsheets (.xlsx)
    if ($ext === 'xlsx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filepath) === true) {
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            $zip->close();
            if ($xml) {
                $text = strip_tags(str_replace('</t>', ' ', $xml));
                return "=== SPREADSHEET CONTENT: $filename ($filesize) ===\n" . substr(trim($text), 0, 15000);
            }
        }
    }

    // D. PDF Documents (.pdf)
    if ($ext === 'pdf') {
        $content = @file_get_contents($filepath);
        if ($content) {
            preg_match_all('/BT[\s\S]*?ET/s', $content, $matches);
            $pdf_text = '';
            if (!empty($matches[0])) {
                foreach ($matches[0] as $match) {
                    preg_match_all('/\((.*?)\)\s*T[jJ]/s', $match, $strings);
                    if (!empty($strings)) {
                        $pdf_text .= implode(' ', $strings) . "\n";
                    }
                }
            }
            if (trim($pdf_text) !== '') {
                return "=== PDF CONTENT: $filename ($filesize) ===\n" . substr(trim($pdf_text), 0, 15000);
            }
        }
        return "=== PDF DOCUMENT: $filename ($filesize) ===\n[Standard PDF formatted artifact]";
    }

    // E. Images (.png, .jpg, .jpeg, .webp)
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
        $img_info = @getimagesize($filepath);
        $dims = $img_info ? ($img_info[0] . 'x' . $img_info . ' pixels, ' . $img_info['mime']) : 'Image';
        return "=== IMAGE EVIDENCE ARTIFACT: $filename ($filesize) ===\nVisual evidence uploaded by the student. Resolution: $dims.";
    }

    return "=== ATTACHED EVIDENCE: $filename ($filesize, ." . strtoupper($ext) . ") ===";
}

// --------------------------------------------------------------------------
// 2. AJAX COPILOT ENDPOINT (Instant chat without reloading the page)
// --------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_copilot'])) {
    header('Content-Type: application/json');
    $sub_id    = intval($_POST['portfolio_id'] ?? 0);
    $user_msg  = trim($_POST['message'] ?? '');
    $auto_eval = isset($_POST['run_eval']) && $_POST['run_eval'] == '1';

    $p_q = $conn->query("SELECT * FROM submissions WHERE id=$sub_id AND user_id=$user_id LIMIT 1");
    if (!$p_q || $p_q->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'Portfolio not found']);
        exit();
    }
    $p_data = $p_q->fetch_assoc();

    if ($auto_eval) {
        $user_msg = "Please analyze my submission and attached file evidence using the 100-Point PPST Rubric. Evaluate Objectives (20), Content (20), Methodology (30), Assessment (20), and Formatting (10). Give me an estimated score out of 100 and categorize it as GREAT, GOOD, or NEEDS REVISION.";
    }

    // Extract file content from whatever file extension was uploaded
    $file_extracted_text = extractFileContentForAI($p_data['file_path'] ?? '');

    $ai_prompt = "You are an expert Academic Copilot and Teacher Education Evaluator.\n"
               . "100-Point PPST Evaluation Rubric:\n"
               . "1. Objectives (20 pts): Clear, measurable, HOTS-aligned.\n"
               . "2. Content (20 pts): Accurate, curriculum-aligned.\n"
               . "3. Methodology (30 pts): Engaging pedagogical flow.\n"
               . "4. Assessment (20 pts): Valid tools aligned with goals.\n"
               . "5. Formatting & Mechanics (10 pts): Professional standard.\n\n"
               . "Portfolio Title: " . $p_data['title'] . "\n"
               . "Lesson Content & Notes:\n" . $p_data['description'] . "\n\n"
               . "Attached File Evidence:\n" . $file_extracted_text . "\n\n"
               . "Student Inquiry: " . $user_msg . "\n\n"
               . "Provide an estimated score out of 100, label it clearly as [GREAT: 90-100], [GOOD: 75-89], or [NEEDS REVISION: <75], and provide specific improvement advice.";

    $ai_reply = "Hello! I have reviewed your submission and file evidence.";
    if (function_exists('generateAIResponse')) {
        $ai_reply = generateAIResponse($ai_prompt, 'mentor');
    }

    // Save conversation to this specific portfolio's chat_transcript JSON
    $chats = json_decode($p_data['chat_transcript'] ?? '[]', true);
    if (!is_array($chats)) { $chats = []; }

    $time_now = date('h:i A');
    $chats[] = ['sender' => 'user', 'message' => $user_msg, 'time' => $time_now];
    $chats[] = ['sender' => 'ai',   'message' => $ai_reply, 'time' => $time_now];

    $updated_json = json_encode($chats);
    $stmt = $conn->prepare("UPDATE submissions SET chat_transcript=? WHERE id=? AND user_id=?");
    $stmt->bind_param("sii", $updated_json, $sub_id, $user_id);
    $stmt->execute();

    echo json_encode([
        'success'  => true,
        'user_msg' => $user_msg,
        'ai_reply' => $ai_reply,
        'time'     => $time_now
    ]);
    exit();
}

// --------------------------------------------------------------------------
// 3. ACTIONS: New Draft, Delete Draft, Edit Copy
// --------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'new_draft') {
    $default_title = "Untitled Portfolio - " . date("M d, Y");
    $stmt = $conn->prepare("INSERT INTO submissions (user_id, title, description, status, chat_transcript) VALUES (?, ?, '', 'draft', '[]')");
    $stmt->bind_param("is", $user_id, $default_title);
    if ($stmt->execute()) {
        header("Location: profile.php?view=workspace&p_id=" . $stmt->insert_id);
        exit();
    }
}

if (isset($_POST['delete_draft'])) {
    $del_id = intval($_POST['portfolio_id']);
    $stmt = $conn->prepare("DELETE FROM submissions WHERE id=? AND user_id=? AND status='draft'");
    $stmt->bind_param("ii", $del_id, $user_id);
    if ($stmt->execute()) {
        header("Location: profile.php?view=workspace&msg=deleted");
        exit();
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'edit_copy') {
    $orig_id = intval($_GET['orig_id']);
    $q = $conn->query("SELECT * FROM submissions WHERE id=$orig_id AND user_id=$user_id");
    if ($q && $q->num_rows > 0) {
        $orig = $q->fetch_assoc();
        $rev_title = $orig['title'] . " (Revision)";
        $stmt = $conn->prepare("INSERT INTO submissions (user_id, title, description, file_path, status, chat_transcript) VALUES (?, ?, ?, ?, 'draft', ?)");
        $stmt->bind_param("issss", $user_id, $rev_title, $orig['description'], $orig['file_path'], $orig['chat_transcript']);
        if ($stmt->execute()) {
            header("Location: profile.php?view=workspace&p_id=" . $stmt->insert_id);
            exit();
        }
    }
}

// --------------------------------------------------------------------------
// 4. ACTION: Save Portfolio Changes (Draft or Submit)
// --------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_portfolio'])) {
    $p_id = intval($_POST['portfolio_id']);
    $title_val  = trim($_POST['title'] ?? 'Untitled Portfolio');
    $desc_val   = trim($_POST['description'] ?? '');
    $new_status = ($_POST['save_portfolio'] === 'submit') ? 'pending' : 'draft';

    $cur_q = $conn->query("SELECT file_path, status FROM submissions WHERE id=$p_id AND user_id=$user_id");
    $cur_row = $cur_q->fetch_assoc();
    $target_file = $cur_row['file_path'] ?? '';

    // Handle any uploaded file format
    if (!empty($_FILES['file']['name'])) {
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $target_file = $target_dir . $user_id . "_artifact_" . time() . "_" . basename($_FILES["file"]["name"]);
        move_uploaded_file($_FILES["file"]["tmp_name"], $target_file);
    }

    $stmt = $conn->prepare("UPDATE submissions SET title=?, description=?, file_path=?, status=? WHERE id=? AND user_id=?");
    $stmt->bind_param("ssssii", $title_val, $desc_val, $target_file, $new_status, $p_id, $user_id);
    $stmt->execute();
    header("Location: profile.php?view=workspace&p_id=$p_id&msg=saved");
    exit();
}

// --------------------------------------------------------------------------
// 5. ACTION: Account Profile Details Update (Photo, Name, Address)
// --------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_account_info'])) {
    $fullname  = trim($_POST['fullname'] ?? '');
    $birthdate = !empty($_POST['birthdate']) ? $_POST['birthdate'] : NULL;
    $gender    = $_POST['gender'] ?? '';
    $address   = trim($_POST['address'] ?? '');
    
    $age = !empty($_POST['age']) ? intval($_POST['age']) : 0;
    if (!empty($birthdate)) {
        try {
            $dob = new DateTime($birthdate);
            $now = new DateTime('today');
            $age = $dob->diff($now)->y;
        } catch(Exception $e) {
            $age = intval($_POST['age'] ?? 0);
        }
    }
    
    $u_curr = $conn->query("SELECT profile_pic FROM users WHERE id=$user_id")->fetch_assoc();
    $profile_pic_path = $u_curr['profile_pic'] ?? NULL; 
    
    // Photo Removal
    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
        if (!empty($profile_pic_path) && file_exists($profile_pic_path)) { 
            unlink($profile_pic_path); 
        }
        $profile_pic_path = NULL; 
    } 
    // Webcam Snapshot
    elseif (!empty($_POST['captured_image_data'])) {
        $img = $_POST['captured_image_data'];
        $img = str_replace('data:image/png;base64,', '', $img);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img);
        $target_dir = "uploads/profiles/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $target_file = $target_dir . "user_" . $user_id . "_" . time() . ".png";
        if (file_put_contents($target_file, $data)) {
            if (!empty($profile_pic_path) && file_exists($profile_pic_path)) { unlink($profile_pic_path); }
            $profile_pic_path = $target_file;
        }
    }
    // File Upload
    elseif (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $target_dir = "uploads/profiles/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $file_ext = pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION);
        $target_file = $target_dir . "user_" . $user_id . "_" . time() . "." . $file_ext;
        if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
            if (!empty($profile_pic_path) && file_exists($profile_pic_path)) { unlink($profile_pic_path); }
            $profile_pic_path = $target_file;
        }
    }

    if ($role == 'student_teacher') {
        $course     = trim($_POST['course'] ?? '');
        $college    = trim($_POST['college'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $section    = trim($_POST['section'] ?? '');
        $stmt = $conn->prepare("UPDATE users SET fullname=?, age=?, gender=?, birthdate=?, address=?, course=?, college=?, year_level=?, section=?, profile_pic=? WHERE id=?");
        $stmt->bind_param("sissssssssi", $fullname, $age, $gender, $birthdate, $address, $course, $college, $year_level, $section, $profile_pic_path, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullname=?, age=?, gender=?, birthdate=?, address=?, profile_pic=? WHERE id=?");
        $stmt->bind_param("sissssi", $fullname, $age, $gender, $birthdate, $address, $profile_pic_path, $user_id);
    }
    $stmt->execute();
    $_SESSION['fullname'] = $fullname;
    header("Location: profile.php?view=account&msg=profile_updated");
    exit();
}

// --------------------------------------------------------------------------
// 6. FETCH USER & PORTFOLIO DATA
// --------------------------------------------------------------------------
$sql_user = "SELECT u.*, s.fullname as supervisor_name 
             FROM users u 
             LEFT JOIN users s ON u.assigned_supervisor_id = s.id 
             WHERE u.id = $user_id";
$user = $conn->query($sql_user)->fetch_assoc();
$initials = strtoupper(substr($user['fullname'] ?? 'U', 0, 1));

// Fetch all user portfolios for Left Library Frame
$all_subs = $conn->query("
    SELECT s.*, e.competency_score, e.readiness_notes 
    FROM submissions s 
    LEFT JOIN evaluations e ON s.id = e.submission_id 
    WHERE s.user_id = $user_id 
    ORDER BY s.id DESC
");

// Determine selected portfolio
$selected_p_id = isset($_GET['p_id']) ? intval($_GET['p_id']) : 0;
$active_portfolio = null;
$portfolio_list = [];

if ($all_subs && $all_subs->num_rows > 0) {
    while ($r = $all_subs->fetch_assoc()) {
        $portfolio_list[] = $r;
        if ($selected_p_id === intval($r['id'])) {
            $active_portfolio = $r;
        }
    }
    // Default to most recent if none explicitly chosen
    if (!$active_portfolio && count($portfolio_list) > 0) {
        $active_portfolio = $portfolio_list[0];
        $selected_p_id = intval($active_portfolio['id']);
    }
}

$active_view = $_GET['view'] ?? (($role === 'student_teacher') ? 'workspace' : 'account');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile & Workspace | CORE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* View Mode Switcher Pills */
        .portal-mode-switch {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .mode-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-color);
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            transition: all 0.2s ease;
        }

        .mode-pill-btn.active {
            background-color: var(--btn-primary);
            color: #ffffff;
            border-color: var(--btn-primary);
        }

        /* Workday 2-Frame Workspace Layout */
        .workspace-frames-grid {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 25px;
            align-items: start;
        }

        /* Left Frame: Portfolio Library */
        .portfolio-library-panel {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 800px;
        }

        .library-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(0, 0, 0, 0.015);
        }

        .library-list {
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            padding: 10px;
            gap: 8px;
        }

        .library-item-card {
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            text-decoration: none;
            color: var(--text-color);
            background: var(--card-bg);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .library-item-card:hover {
            border-color: var(--btn-primary);
            transform: translateX(3px);
        }

        .library-item-card.active {
            background: rgba(52, 152, 219, 0.08);
            border-color: var(--btn-primary);
            border-left: 4px solid var(--btn-primary);
        }

        /* Right Frame: Active Workspace & Tabs */
        .workspace-main-panel {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }

        .workspace-tab-nav {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            background: rgba(0,0,0,0.015);
        }

        .ws-tab-btn {
            padding: 16px 24px;
            border: none;
            background: transparent;
            font-size: 14px;
            font-weight: 600;
            color: #888;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ws-tab-btn.active {
            color: var(--btn-primary);
            border-bottom-color: var(--btn-primary);
            background: var(--card-bg);
        }

        .ws-pane {
            padding: 30px;
            display: none;
        }

        .ws-pane.active {
            display: block;
        }

        /* Copilot Chat Screen */
        .copilot-chat-container {
            display: flex;
            flex-direction: column;
            height: 520px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            background: rgba(0,0,0,0.01);
        }

        .chat-stream-box {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .chat-bubble {
            max-width: 82%;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 13.5px;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .chat-bubble.user {
            align-self: flex-end;
            background: var(--btn-primary);
            color: #ffffff;
            border-bottom-right-radius: 2px;
        }

        .chat-bubble.ai {
            align-self: flex-start;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            border-bottom-left-radius: 2px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }

        .chat-input-bar {
            padding: 14px 18px;
            border-top: 1px solid var(--border-color);
            background: var(--card-bg);
            display: flex;
            gap: 10px;
        }

        /* ==================================================================
           FULLSCREEN COPILOT ANALYZING OVERLAY (Stops reload, shows loading)
           ================================================================== */
        .copilot-loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.82);
            z-index: 999999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }

        .copilot-loading-card {
            background: #1e1f20;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 18px;
            padding: 35px 45px;
            text-align: center;
            color: #ffffff;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            max-width: 450px;
            width: 90%;
            animation: pulseCard 1.8s infinite ease-in-out;
        }

        @keyframes pulseCard {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }

        .copilot-pulse-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 20px;
            box-shadow: 0 0 25px rgba(168, 85, 247, 0.6);
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .st-draft { background: #fef9c3; color: #854d0e; }
        .st-pending { background: #dbeafe; color: #1e40af; }
        .st-graded { background: #dcfce7; color: #166534; }

        @media (max-width: 1024px) {
            .workspace-frames-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Fullscreen Copilot Analyzing Loading Overlay -->
    <div id="copilotLoadingOverlay" class="copilot-loading-overlay">
        <div class="copilot-loading-card">
            <div class="copilot-pulse-icon">
                <i class="fas fa-robot"></i>
            </div>
            <h3 style="margin:0 0 10px; font-size:20px;">Copilot is Analyzing Your Submission...</h3>
            <p style="margin:0; font-size:13px; opacity:0.8; line-height:1.5;">
                Reading attached file evidence (.docx, .xlsx, .pdf, images) and evaluating against the 100-point rubric.
            </p>
        </div>
    </div>

    <!-- Main Left Sidebar & Top Header -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Frame -->
    <div class="main-content">

        <!-- Mode Switcher Pills -->
        <div class="portal-mode-switch">
            <?php if ($role === 'student_teacher'): ?>
            <a href="profile.php?view=workspace<?= $selected_p_id ? '&p_id='.$selected_p_id : '' ?>" 
               class="mode-pill-btn <?= ($active_view === 'workspace') ? 'active' : '' ?>">
                <i class="fas fa-laptop-code"></i> Portfolio Workspace & Copilot
            </a>
            <?php endif; ?>

            <a href="profile.php?view=account" 
               class="mode-pill-btn <?= ($active_view === 'account') ? 'active' : '' ?>">
                <i class="fas fa-user-cog"></i> Account Details & Photo
            </a>
        </div>

        <?php if ($active_view === 'workspace' && $role === 'student_teacher'): ?>
            <!-- =============================================================== -->
            <!-- WORKSPACE FRAME ARCHITECTURE (PORTFOLIO LIBRARY & WORKSPACE)    -->
            <!-- =============================================================== -->
            <div class="workspace-frames-grid">
                
                <!-- LEFT FRAME: All Submitted Portfolios & Drafts -->
                <aside class="portfolio-library-panel">
                    <div class="library-header">
                        <div>
                            <strong style="font-size:14px;"><i class="fas fa-folder"></i> My Submissions</strong>
                            <div style="font-size:11px; color:#888;"><?= count($portfolio_list) ?> Total Portfolios</div>
                        </div>
                        <a href="profile.php?action=new_draft" class="btn" style="background:#27ae60; color:white; padding:6px 12px; font-size:12px;" title="Create New Portfolio">
                            <i class="fas fa-plus"></i> New
                        </a>
                    </div>

                    <div class="library-list">
                        <?php if (count($portfolio_list) > 0): ?>
                            <?php foreach ($portfolio_list as $p): 
                                $is_active = ($selected_p_id === intval($p['id']));
                                $status = $p['status'] ?? 'draft';
                                $has_score = !empty($p['competency_score']);
                            ?>
                            <a href="profile.php?view=workspace&p_id=<?= $p['id'] ?>" class="library-item-card <?= $is_active ? 'active' : '' ?>">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <?php if ($has_score): ?>
                                        <span class="badge-status st-graded"><i class="fas fa-check-circle"></i> <?= $p['competency_score'] ?>/100</span>
                                    <?php elseif ($status === 'pending'): ?>
                                        <span class="badge-status st-pending"><i class="fas fa-clock"></i> In Review</span>
                                    <?php else: ?>
                                        <span class="badge-status st-draft"><i class="fas fa-pen"></i> Draft</span>
                                    <?php endif; ?>

                                    <small style="color:#888; font-size:11px;"><?= date("M d", strtotime($p['created_at'] ?? 'now')) ?></small>
                                </div>
                                <strong style="font-size:14px; line-height:1.3; margin-top:2px;">
                                    <?= htmlspecialchars($p['title']) ?>
                                </strong>
                                <?php if (!empty($p['file_path'])): ?>
                                    <span style="font-size:11px; color:var(--btn-primary);"><i class="fas fa-paperclip"></i> <?= htmlspecialchars(basename($p['file_path'])) ?></span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="padding:20px; text-align:center; color:#888; font-size:13px;">No portfolios created yet. Click "+ New" to begin.</p>
                        <?php endif; ?>
                    </div>
                </aside>

                <!-- RIGHT FRAME: Interactive Workspace & Copilot -->
                <main class="workspace-main-panel">
                    <?php if ($active_portfolio): 
                        $p_status = $active_portfolio['status'] ?? 'draft';
                        $is_graded = !empty($active_portfolio['competency_score']);
                    ?>
                    <!-- Workspace Tabs -->
                    <div class="workspace-tab-nav">
                        <button type="button" class="ws-tab-btn active" onclick="switchWsTab('tab-editor', this)">
                            <i class="fas fa-edit"></i> Portfolio Content & Artifacts
                        </button>
                        <button type="button" class="ws-tab-btn" onclick="switchWsTab('tab-copilot', this)">
                            <i class="fas fa-robot"></i> AI Copilot & 100-Pt Check
                        </button>
                    </div>

                    <!-- PANE 1: Editor & File Upload Workspace -->
                    <div class="ws-pane active" id="tab-editor">
                        <?php if ($is_graded): ?>
                            <!-- Official Graded Results Box -->
                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-left:5px solid #22c55e; padding:16px 20px; border-radius:10px; margin-bottom:20px; color:#14532d;">
                                <h3 style="margin:0 0 6px; font-size:18px;"><i class="fas fa-award"></i> Official Score: <?= $active_portfolio['competency_score'] ?> / 100</h3>
                                <p style="margin:0; font-size:13px;">
                                    <strong>Evaluator Feedback:</strong> <?= nl2br(htmlspecialchars($active_portfolio['readiness_notes'] ?? 'Great work!')) ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="portfolio_id" value="<?= $active_portfolio['id'] ?>">

                            <div class="form-group" style="margin-bottom:18px;">
                                <label style="font-weight:700;">Portfolio / Lesson Plan Title</label>
                                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($active_portfolio['title']) ?>" <?= $is_graded ? 'readonly' : 'required' ?>>
                            </div>

                            <div class="form-group" style="margin-bottom:18px;">
                                <label style="font-weight:700;">Context, Objectives & Lesson Plan Procedures</label>
                                <textarea name="description" id="portfolioDesc" class="form-control" rows="12" placeholder="Detail your lesson procedures and teaching context..." <?= $is_graded ? 'readonly' : 'required' ?>><?= htmlspecialchars($active_portfolio['description']) ?></textarea>
                            </div>

                            <!-- Multiformat File Evidence Upload (DOCX, XLSX, PDF, Images) -->
                            <div class="form-group" style="margin-bottom:22px; background:rgba(0,0,0,0.02); padding:16px; border-radius:8px; border:1px dashed var(--border-color);">
                                <label style="font-weight:700;"><i class="fas fa-paperclip"></i> Attached File Evidence (.docx, .xlsx, .pdf, .png, .jpg, .txt)</label>
                                <?php if (!empty($active_portfolio['file_path'])): ?>
                                    <div style="margin:6px 0 10px; font-size:13px;">
                                        Current File: <a href="<?= htmlspecialchars($active_portfolio['file_path']) ?>" target="_blank" style="color:var(--btn-primary); font-weight:600;"><i class="fas fa-file-download"></i> <?= htmlspecialchars(basename($active_portfolio['file_path'])) ?></a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$is_graded): ?>
                                    <input type="file" name="file" class="form-control" style="border:none; padding:0;">
                                <?php endif; ?>
                            </div>

                            <!-- Workflow Buttons -->
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                <div>
                                    <?php if ($p_status === 'draft'): ?>
                                        <button type="submit" formaction="profile.php" name="delete_draft" class="btn btn-remove" onclick="return confirm('Delete this draft permanently?');">
                                            <i class="fas fa-trash"></i> Delete Draft
                                        </button>
                                    <?php elseif ($p_status === 'pending' && !$is_graded): ?>
                                        <a href="profile.php?action=edit_copy&orig_id=<?= $active_portfolio['id'] ?>" class="btn btn-edit" title="Create a new revision without altering the submitted copy">
                                            <i class="fas fa-copy"></i> Edit as New Revision
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div style="display:flex; gap:10px;">
                                    <?php if (!$is_graded): ?>
                                        <button type="submit" name="save_portfolio" value="draft" class="btn" style="background:#7f8c8d; color:white;">
                                            <i class="fas fa-save"></i> Save Draft
                                        </button>
                                        <button type="submit" name="save_portfolio" value="submit" class="btn" style="background:#27ae60; color:white; padding:10px 22px;">
                                            <i class="fas fa-paper-plane"></i> Submit for Review
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- PANE 2: Copilot AI Guidance & 100-Point Evaluator -->
                    <div class="ws-pane" id="tab-copilot">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                            <div>
                                <h3 style="margin:0; font-size:18px; color:var(--text-color);"><i class="fas fa-robot" style="color:var(--btn-primary);"></i> AI Copilot</h3>
                                <small style="color:#888;">Reads your lesson plan and attached files (.docx, .xlsx, .pdf, images) to provide 100-point feedback.</small>
                            </div>
                            
                            <!-- One-Click 100-Point Rubric Button -->
                            <button type="button" class="btn" style="background:linear-gradient(135deg, #6366f1, #8b5cf6); color:white; border-radius:20px; font-size:12px;" onclick="runCopilotEvaluation()">
                                <i class="fas fa-chart-line"></i> Run 100-Point Evaluation
                            </button>
                        </div>

                        <!-- Chat Screen -->
                        <div class="copilot-chat-container">
                            <div class="chat-stream-box" id="copilotStreamBox">
                                <?php 
                                $chats = json_decode($active_portfolio['chat_transcript'] ?? '[]', true);
                                if (is_array($chats) && count($chats) > 0): 
                                    foreach ($chats as $msg_item):
                                ?>
                                    <div class="chat-bubble <?= ($msg_item['sender'] === 'user') ? 'user' : 'ai' ?>">
                                        <div style="font-size:10px; opacity:0.75; margin-bottom:3px;">
                                            <?= ($msg_item['sender'] === 'user') ? 'You' : 'Copilot' ?> &bull; <?= htmlspecialchars($msg_item['time'] ?? '') ?>
                                        </div>
                                        <div><?= nl2br(htmlspecialchars($msg_item['message'])) ?></div>
                                    </div>
                                <?php 
                                    endforeach; 
                                else: 
                                ?>
                                    <div class="chat-bubble ai">
                                        <div style="font-size:10px; opacity:0.75; margin-bottom:3px;">Copilot</div>
                                        <div>Hello! I am ready to review your portfolio and all attached evidence (.docx, .xlsx, .pdf, images). Ask me anything or click <strong>"Run 100-Point Evaluation"</strong>.</div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Chat Input Bar -->
                            <div class="chat-input-bar">
                                <input type="text" id="copilotUserPrompt" class="form-control" placeholder="Ask Copilot about your lesson plan or attached files..." style="border-radius:20px; padding:10px 16px;">
                                <button type="button" class="btn" style="background:var(--btn-primary); color:white; border-radius:50%; width:42px; height:42px; padding:0; flex-shrink:0;" onclick="submitCopilotMessage()">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                        <div style="padding:40px; text-align:center;">
                            <h3>No Portfolio Selected</h3>
                            <p style="color:#888;">Select a portfolio from the left or create a new draft to begin.</p>
                        </div>
                    <?php endif; ?>
                </main>
            </div>

        <?php else: ?>
            <!-- =============================================================== -->
            <!-- ACCOUNT DETAILS & PHOTO MANAGEMENT MODE                         -->
            <!-- =============================================================== -->
            <div class="card" style="padding:35px;">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_account_info" value="1">
                    <input type="file" name="profile_pic" id="profilePicInput" accept="image/*" style="display:none;" onchange="previewProfilePic(event)">
                    <input type="hidden" name="remove_photo" id="removePhotoFlag" value="0">
                    <input type="hidden" name="captured_image_data" id="capturedImageData">

                    <h3 style="margin-top:0; border-bottom:1px solid var(--border-color); padding-bottom:12px;"><i class="fas fa-user-edit"></i> Edit Profile Information</h3>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;">
                        <div class="form-group" style="grid-column: span 2;">
                            <label style="font-weight:700;">Full Legal Name</label>
                            <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($user['fullname'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label style="font-weight:700;">Birthdate</label>
                            <input type="date" name="birthdate" class="form-control" value="<?= htmlspecialchars($user['birthdate'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label style="font-weight:700;">Gender</label>
                            <select name="gender" class="form-control">
                                <option value="Male" <?= ($user['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($user['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= ($user['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label style="font-weight:700;">Complete Residential Address</label>
                            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($user['address'] ?? '') ?>">
                        </div>

                        <?php if ($role === 'student_teacher'): ?>
                        <div class="form-group">
                            <label style="font-weight:700;">College / Department</label>
                            <input type="text" name="college" class="form-control" value="<?= htmlspecialchars($user['college'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label style="font-weight:700;">Course / Degree Program</label>
                            <input type="text" name="course" class="form-control" value="<?= htmlspecialchars($user['course'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label style="font-weight:700;">Year Level</label>
                            <input type="text" name="year_level" class="form-control" value="<?= htmlspecialchars($user['year_level'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label style="font-weight:700;">Class Section</label>
                            <input type="text" name="section" class="form-control" value="<?= htmlspecialchars($user['section'] ?? '') ?>">
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Photo Management Buttons -->
                    <div style="margin-top:25px; display:flex; gap:12px; align-items:center;">
                        <button type="button" class="btn btn-view" onclick="document.getElementById('profilePicInput').click();">
                            <i class="fas fa-upload"></i> Upload New Photo
                        </button>
                        <button type="button" class="btn btn-remove" onclick="removeMyPhoto()">
                            <i class="fas fa-trash"></i> Remove Photo
                        </button>
                        <button type="submit" class="btn" style="background:#27ae60; color:white; margin-left:auto; padding:10px 24px;">
                            <i class="fas fa-save"></i> Save Account Info
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <!-- Scripts: Tabs, AJAX Copilot & Fullscreen Loading Overlay -->
    <script>
    function switchWsTab(paneId, btn) {
        document.querySelectorAll('.ws-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.ws-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const target = document.getElementById(paneId);
        if (target) target.classList.add('active');
    }

    // --- AJAX Copilot Messaging (Stops web reload, shows overlay) ---
    async function sendCopilotAJAX(isAutoEval = false) {
        const inputEl = document.getElementById('copilotUserPrompt');
        const promptText = isAutoEval ? '' : (inputEl ? inputEl.value.trim() : '');
        if (!isAutoEval && !promptText) return;

        const activePID = <?= json_encode($selected_p_id) ?>;
        if (!activePID) return;

        // 1. Show fullscreen loading overlay & lock page
        const overlay = document.getElementById('copilotLoadingOverlay');
        if (overlay) overlay.style.display = 'flex';

        // 2. Prepare payload
        const formData = new FormData();
        formData.append('ajax_copilot', '1');
        formData.append('portfolio_id', activePID);
        if (isAutoEval) {
            formData.append('run_eval', '1');
        } else {
            formData.append('message', promptText);
        }

        try {
            const res = await fetch('profile.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                const streamBox = document.getElementById('copilotStreamBox');
                if (streamBox) {
                    // Append User Message
                    const userDiv = document.createElement('div');
                    userDiv.className = 'chat-bubble user';
                    userDiv.innerHTML = `<div style="font-size:10px; opacity:0.75; margin-bottom:3px;">You &bull; ${data.time}</div><div>${escapeHtml(data.user_msg)}</div>`;
                    streamBox.appendChild(userDiv);

                    // Append AI Response
                    const aiDiv = document.createElement('div');
                    aiDiv.className = 'chat-bubble ai';
                    aiDiv.innerHTML = `<div style="font-size:10px; opacity:0.75; margin-bottom:3px;">Copilot &bull; ${data.time}</div><div>${escapeHtml(data.ai_reply).replace(/\n/g, '<br>')}</div>`;
                    streamBox.appendChild(aiDiv);

                    streamBox.scrollTop = streamBox.scrollHeight;
                }
                if (inputEl) inputEl.value = '';
            }
        } catch (err) {
            alert("Error contacting Copilot. Please check your connection.");
        } finally {
            // 3. Hide loading overlay
            if (overlay) overlay.style.display = 'none';
        }
    }

    function submitCopilotMessage() {
        sendCopilotAJAX(false);
    }

    function runCopilotEvaluation() {
        sendCopilotAJAX(true);
    }

    // Listen for Enter key on prompt input
    const promptInput = document.getElementById('copilotUserPrompt');
    if (promptInput) {
        promptInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitCopilotMessage();
            }
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function previewProfilePic(e) {
        if (e.target.files && e.target.files[0]) {
            document.getElementById('removePhotoFlag').value = '0';
        }
    }

    function removeMyPhoto() {
        document.getElementById('removePhotoFlag').value = '1';
        alert("Profile photo marked for removal. Click 'Save Account Info' to confirm.");
    }
    </script>
</body>
</html>
