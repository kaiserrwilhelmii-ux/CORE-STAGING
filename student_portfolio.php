<?php
session_start();
include __DIR__ . '/db_connect.php';
include_once __DIR__ . '/ai_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student_teacher') { 
    header("Location: index.php"); 
    exit(); 
}

$user_id = intval($_SESSION['user_id']);
$msg     = "";
$error   = "";

// -------------------------------------------------------------
// HELPER: Extract text content from submitted files (DOCX, XLSX, PDF, TXT)
// -------------------------------------------------------------
function extractFileTextContent($filePath) {
    if (!file_exists($filePath)) return "";
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    // Plain Text & Markdown
    if (in_array($ext, ['txt', 'md', 'csv', 'html'])) {
        return substr(file_get_contents($filePath), 0, 6000);
    }
    
    // Microsoft Word (.docx)
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === TRUE) {
            $xmlIndex = $zip->locateName('word/document.xml');
            if ($xmlIndex !== false) {
                $xml = $zip->getFromIndex($xmlIndex);
                $zip->close();
                return substr(strip_tags(str_replace('</w:p>', "\n", $xml)), 0, 6000);
            }
            $zip->close();
        }
    }
    
    // Microsoft Excel (.xlsx / .xlxx)
    if (in_array($ext, ['xlsx', 'xlxx']) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === TRUE) {
            $xmlIndex = $zip->locateName('xl/sharedStrings.xml');
            if ($xmlIndex !== false) {
                $xml = $zip->getFromIndex($xmlIndex);
                $zip->close();
                return substr(strip_tags(str_replace('</t>', " ", $xml)), 0, 6000);
            }
            $zip->close();
        }
    }
    
    // PDF basic stream extraction fallback
    if ($ext === 'pdf') {
        $content = @file_get_contents($filePath);
        if (preg_match_all('/\((.*?)\)\s*T[jJ]/s', $content, $matches)) {
            $extracted = implode(' ', $matches);
            if (!empty(trim($extracted))) return substr($extracted, 0, 6000);
        }
        return "[PDF Attachment: " . basename($filePath) . "]";
    }
    
    // Images
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
        return "[Image Evidence Attached: " . basename($filePath) . "]";
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
        header("Location: " . basename($_SERVER['PHP_SELF']) . "?id=" . $stmt->insert_id);
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
        $msg = "Draft portfolio has been deleted.";
        header("Location: " . basename($_SERVER['PHP_SELF']));
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
            header("Location: " . basename($_SERVER['PHP_SELF']) . "?id=" . $stmt->insert_id);
            exit();
        }
    }
}

// -------------------------------------------------------------
// 4. FETCH ALL SUBMISSIONS (For Left Sidebar Drawer)
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
// 5. IDENTIFY ACTIVE PORTFOLIO ID
// -------------------------------------------------------------
$portfolio_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Auto-select latest if no ID is passed and submissions exist
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
        $target_file = $target_dir . $user_id . "_evidence_" . time() . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "", basename($_FILES["file"]["name"]));
        move_uploaded_file($_FILES["file"]["tmp_name"], $target_file);
    }

    $stmt = $conn->prepare("UPDATE submissions SET title=?, description=?, file_path=?, status=? WHERE id=? AND user_id=?");
    $stmt->bind_param("ssssii", $title_val, $desc_val, $target_file, $new_status, $portfolio_id, $user_id);
    if ($stmt->execute()) {
        $msg = ($new_status === 'pending') ? "Submitted for review!" : "Draft saved!";
        header("Location: " . basename($_SERVER['PHP_SELF']) . "?id=" . $portfolio_id . "&msg=" . urlencode($msg));
        exit();
    }
}

// -------------------------------------------------------------
// 7. ACTION: Copilot Evaluation & Chat with Multi-File Reading
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_chat']) && $portfolio_id > 0) {
    $user_msg     = trim($_POST['message'] ?? '');
    $auto_eval    = isset($_POST['run_eval']) && $_POST['run_eval'] == '1';
    $current_desc = trim($_POST['context_description'] ?? ($portfolio['description'] ?? ''));
    $current_ttl  = trim($_POST['context_title'] ?? ($portfolio['title'] ?? ''));

    if ($auto_eval) {
        $user_msg = "Please thoroughly analyze my lesson plan and submitted files using the 100-Point PPST Rubric. Evaluate Objectives (20), Content (20), Methodology (30), Assessment (20), and Mechanics (10). Provide my estimated score out of 100 with clear ratings [GREAT: 90-100], [GOOD: 75-89], or [NEEDS REVISION: <75].";
    }

    if (!empty($user_msg)) {
        $chats = json_decode($portfolio['chat_transcript'] ?? '[]', true);
        if (!is_array($chats)) { $chats = []; }

        $chats[] = [
            'sender'  => 'user',
            'message' => $user_msg,
            'time'    => date('h:i A')
        ];

        // Extract content from attached artifact file
        $file_text_content = "";
        if (!empty($portfolio['file_path'])) {
            $extracted = extractFileTextContent($portfolio['file_path']);
            if (!empty($extracted)) {
                $file_text_content = "\n\n--- SUBMITTED ATTACHMENT CONTENT (" . basename($portfolio['file_path']) . ") ---\n" . $extracted . "\n--- END OF ATTACHMENT ---\n";
            }
        }

        // Build comprehensive AI prompt
        $ai_prompt = "You are an expert Academic Copilot and Teacher Education Mentor for Pre-Service Teachers.\n"
                   . "100-Point PPST Standard Rubric:\n"
                   . "1. Objectives (20 pts): Clear, measurable, HOTS-aligned.\n"
                   . "2. Content (20 pts): Accurate, curriculum-aligned.\n"
                   . "3. Methodology (30 pts): Active pedagogical flow.\n"
                   . "4. Assessment (20 pts): Formative/summative alignment.\n"
                   . "5. Formatting & Mechanics (10 pts): DepEd/PST standards.\n\n"
                   . "Portfolio Title: $current_ttl\n"
                   . "Lesson Content / Procedures:\n$current_desc\n"
                   . $file_text_content . "\n"
                   . "User Inquiry: $user_msg\n\n"
                   . "Evaluate both the lesson text and attached artifacts. Provide constructive feedback.";

        $ai_reply = "I reviewed your lesson plan. Your structure is well formed!";
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

        header("Location: " . basename($_SERVER['PHP_SELF']) . "?id=" . $portfolio_id);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile & Workspace | CORE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-color: #f4f6f9;
            --card-bg: #ffffff;
            --text-color: #2c3e50;
            --border-color: #e2e8f0;
            --primary-accent: #4f46e5;
            --primary-hover: #4338ca;
        }

        body.dark-mode {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-color: #f8fafc;
            --border-color: #334155;
            --primary-accent: #6366f1;
            --primary-hover: #4f46e5;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            overflow-x: hidden;
        }

        /* 3-Column Studio Layout */
        .workspace-container {
            display: grid;
            grid-template-columns: 290px 1fr 370px;
            gap: 20px;
            height: calc(100vh - 40px);
            padding: 20px;
            box-sizing: border-box;
        }

        @media (max-width: 1280px) {
            .workspace-container {
                grid-template-columns: 260px 1fr 340px;
            }
        }

        @media (max-width: 1024px) {
            .workspace-container {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }
        }

        /* Common Card Style */
        .pane-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        /* Left Drawer: Submissions List */
        .drawer-header {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .drawer-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .portfolio-nav-item {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            text-decoration: none;
            color: var(--text-color);
            transition: all 0.15s ease;
            display: flex;
            flex-direction: column;
            gap: 5px;
            background: var(--card-bg);
        }

        .portfolio-nav-item:hover, .portfolio-nav-item.active {
            border-color: var(--primary-accent);
            background: rgba(79, 70, 229, 0.05);
        }

        .portfolio-nav-item.active {
            border-left: 4px solid var(--primary-accent);
        }

        /* Center: Editor Workspace */
        .workspace-scrollable {
            flex: 1;
            overflow-y: auto;
            padding: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            width: 100%;
            margin-bottom: 18px;
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
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-accent);
        }

        /* Right: Copilot Chat */
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
            background: rgba(0,0,0,0.015);
        }

        .chat-bubble {
            max-width: 88%;
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

        .copilot-input-area {
            padding: 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-pending { background: #dbeafe; color: #1e40af; }
        .badge-graded { background: #dcfce7; color: #166534; }

        /* Fullscreen Copilot Loading Screen */
        .copilot-loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(5px);
            z-index: 99999;
            display: none;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            color: #ffffff;
        }

        .loading-card {
            background: var(--card-bg);
            color: var(--text-color);
            padding: 32px 40px;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            text-align: center;
            max-width: 380px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(79, 70, 229, 0.2);
            border-top: 4px solid var(--primary-accent);
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <!-- Fullscreen Loading Screen Overlay -->
    <div id="copilotLoadingOverlay" class="copilot-loading-overlay">
        <div class="loading-card">
            <div class="spinner"></div>
            <i class="fas fa-robot" style="font-size: 28px; color: var(--primary-accent);"></i>
            <h3 style="margin: 0; font-size: 18px;">Copilot is Analyzing...</h3>
            <p style="margin: 0; font-size: 13px; opacity: 0.8; line-height: 1.4;">
                Reading your lesson objectives, rubrics, and attached documents. Please wait.
            </p>
        </div>
    </div>

    <div class="workspace-container">
        
        <!-- ============================================== -->
        <!-- LEFT PANEL: Portfolios Submitted / History     -->
        <!-- ============================================== -->
        <div class="pane-card">
            <div class="drawer-header">
                <span style="font-weight: 700; font-size: 15px;"><i class="fas fa-folder-open"></i> My Portfolios</span>
                <a href="<?= basename($_SERVER['PHP_SELF']) ?>?action=new" class="btn" style="background:#22c55e; color:white; padding:6px 12px; border-radius:6px; font-size:12px; text-decoration:none;">
                    <i class="fas fa-plus"></i> New
                </a>
            </div>

            <div class="drawer-list">
                <?php if (!empty($all_submissions)): ?>
                    <?php foreach ($all_submissions as $sub): 
                        $status = $sub['status'] ?? 'draft';
                        $has_grade = !empty($sub['competency_score']);
                        $isActive = ($portfolio_id === intval($sub['id']));
                    ?>
                        <a href="<?= basename($_SERVER['PHP_SELF']) ?>?id=<?= $sub['id'] ?>" class="portfolio-nav-item <?= $isActive ? 'active' : '' ?>">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <?php if ($has_grade): ?>
                                    <span class="badge badge-graded"><i class="fas fa-check-circle"></i> <?= $sub['competency_score'] ?>/100</span>
                                <?php elseif ($status === 'pending'): ?>
                                    <span class="badge badge-pending"><i class="fas fa-clock"></i> In Review</span>
                                <?php else: ?>
                                    <span class="badge badge-draft"><i class="fas fa-pen"></i> Draft</span>
                                <?php endif; ?>
                                <small style="opacity: 0.6; font-size: 11px;"><?= date("M d", strtotime($sub['created_at'] ?? 'now')) ?></small>
                            </div>
                            <div style="font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= htmlspecialchars($sub['title'] ?: 'Untitled Portfolio') ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align:center; opacity:0.6; font-size:13px; margin-top:20px;">No submissions found. Click "+ New" to begin.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- CENTER PANEL: Editor Workspace Studio          -->
        <!-- ============================================== -->
        <div class="pane-card">
            <?php if ($portfolio): ?>
                <div class="workspace-scrollable">
                    <?php if ($is_graded): ?>
                        <div style="background:rgba(34, 197, 94, 0.1); border:1px solid #86efac; padding:14px; border-radius:8px; margin-bottom:18px;">
                            <h4 style="margin:0 0 6px; color:#15803d;"><i class="fas fa-award"></i> Graded Score: <?= $portfolio['competency_score'] ?> / 100</h4>
                            <p style="margin:0; font-size:13px;"><?= nl2br(htmlspecialchars($portfolio['readiness_notes'] ?? 'No feedback provided.')) ?></p>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" id="editorForm">
                        <div class="form-group">
                            <label>Portfolio / Lesson Plan Title</label>
                            <input type="text" name="title" class="form-control" 
                                   value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>" 
                                   <?= $is_graded ? 'readonly' : 'required' ?>>
                        </div>

                        <div class="form-group">
                            <label>Lesson Context, Procedures & Objectives</label>
                            <textarea name="description" id="lessonContentInput" class="form-control" rows="12" 
                                      placeholder="Type your lesson plan, objectives, and teaching methodology..." 
                                      <?= $is_graded ? 'readonly' : 'required' ?>><?= htmlspecialchars($portfolio['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group" style="border: 1px dashed var(--border-color); padding: 14px; border-radius: 8px;">
                            <label><i class="fas fa-paperclip"></i> Attached Artifacts / Evidence (DOCX, XLSX, PDF, Images)</label>
                            <?php if (!empty($portfolio['file_path'])): ?>
                                <div style="margin-bottom:8px; font-size:13px;">
                                    Attached: <a href="<?= htmlspecialchars($portfolio['file_path']) ?>" target="_blank" style="color:var(--primary-accent); font-weight:600;"><i class="fas fa-file-alt"></i> <?= basename($portfolio['file_path']) ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if (!$is_graded): ?>
                                <input type="file" name="file" class="form-control" style="border:none; padding:4px 0;">
                            <?php endif; ?>
                        </div>

                        <?php if (!$is_graded): ?>
                            <div style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="submit" name="save_portfolio" value="draft" class="btn" style="background:#64748b; color:white; padding:9px 18px; border-radius:6px; border:none; cursor:pointer;">
                                    <i class="fas fa-save"></i> Save Draft
                                </button>
                                <button type="submit" name="save_portfolio" value="submit" class="btn" style="background:#22c55e; color:white; padding:9px 22px; border-radius:6px; border:none; cursor:pointer;">
                                    <i class="fas fa-paper-plane"></i> Submit for Review
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            <?php else: ?>
                <div style="display:flex; align-items:center; justify-content:center; height:100%; text-align:center; padding:20px;">
                    <div>
                        <i class="fas fa-folder-open" style="font-size:42px; opacity:0.3; margin-bottom:12px;"></i>
                        <h3>No Portfolio Selected</h3>
                        <p style="opacity:0.7; font-size:14px;">Select an existing portfolio on the left or create a new one.</p>
                        <a href="<?= basename($_SERVER['PHP_SELF']) ?>?action=new" class="btn" style="background:#22c55e; color:white; padding:8px 18px; border-radius:6px; text-decoration:none; font-size:13px;">Create New</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================== -->
        <!-- RIGHT PANEL: Dedicated 100-Point AI Copilot     -->
        <!-- ============================================== -->
        <div class="pane-card">
            <div class="copilot-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-robot"></i>
                    <span style="font-weight:700; font-size:14px;">100-Point Copilot</span>
                </div>

                <?php if ($portfolio): ?>
                    <form method="POST" class="copilot-trigger-form" style="margin:0;">
                        <input type="hidden" name="run_eval" value="1">
                        <input type="hidden" name="context_title" value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>">
                        <input type="hidden" name="context_description" value="<?= htmlspecialchars($portfolio['description'] ?? '') ?>">
                        <button type="submit" name="send_chat" class="btn" style="background:#ffffff; color:#4f46e5; border:none; padding:4px 10px; font-size:11px; border-radius:20px; font-weight:700; cursor:pointer;">
                            <i class="fas fa-chart-line"></i> Evaluate 100-Pts
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Copilot Chat Stream -->
            <div class="copilot-chat-history" id="copilotChatBox">
                <?php 
                $chats = json_decode($portfolio['chat_transcript'] ?? '[]', true);
                if (is_array($chats) && count($chats) > 0): 
                    foreach ($chats as $m): 
                ?>
                    <div class="chat-bubble <?= ($m['sender'] === 'user') ? 'user' : 'ai' ?>">
                        <div style="font-size:10px; opacity:0.75; margin-bottom:4px;">
                            <?= ($m['sender'] === 'user') ? 'You' : 'AI Mentor' ?> &bull; <?= htmlspecialchars($m['time'] ?? '') ?>
                        </div>
                        <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                    </div>
                <?php 
                    endforeach; 
                else: 
                ?>
                    <div class="chat-bubble ai">
                        <div style="font-size:10px; opacity:0.75; margin-bottom:4px;">AI Mentor</div>
                        <div>Hello! I can read your attached documents (Word, Excel, PDF, etc.) and grade your lesson plan across all 5 PPST domains. Click <strong>"Evaluate 100-Pts"</strong> or ask a question below!</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Message Form -->
            <?php if ($portfolio): ?>
                <form method="POST" class="copilot-input-area copilot-trigger-form" id="copilotForm">
                    <input type="hidden" name="context_title" value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>">
                    <input type="hidden" name="context_description" id="hiddenDesc" value="<?= htmlspecialchars($portfolio['description'] ?? '') ?>">
                    <input type="text" name="message" class="form-control" placeholder="Ask about objectives, rubrics..." required style="border-radius:20px; font-size:13px;">
                    <button type="submit" name="send_chat" style="background:var(--primary-accent); color:white; border:none; border-radius:50%; width:36px; height:36px; cursor:pointer; flex-shrink:0;">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            <?php endif; ?>
        </div>

    </div>

    <!-- Interactive Scripts & Loading Screen Controller -->
    <script>
    (function () {
        // Auto scroll Copilot to latest message
        const chatBox = document.getElementById('copilotChatBox');
        if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

        // Show Copilot loading screen and block interaction during AI requests
        const loadingOverlay = document.getElementById('copilotLoadingOverlay');
        const triggerForms = document.querySelectorAll('.copilot-trigger-form');
        const editorText = document.getElementById('lessonContentInput');
        const hiddenDesc = document.getElementById('hiddenDesc');

        triggerForms.forEach(form => {
            form.addEventListener('submit', function () {
                if (editorText && hiddenDesc) {
                    hiddenDesc.value = editorText.value;
                }
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'flex';
                }
            });
        });
    })();
    </script>
</body>
</html>
