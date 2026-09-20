<?php
session_start();
include __DIR__ . '/db_connect.php';
include_once __DIR__ . '/ai_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student_teacher') { 
    header("Location: index.php"); 
    exit(); 
}

$user_id = intval($_SESSION['user_id']);
$msg     = isset($_GET['msg']) ? trim($_GET['msg']) : "";
$error   = "";

// -------------------------------------------------------------
// HELPER: Extract text content from submitted files (DOCX, XLSX, PDF, TXT)
// -------------------------------------------------------------
function extractFileTextContent($filePath) {
    if (!file_exists($filePath)) return "";
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    // Plain text, Markdown, CSV
    if (in_array($ext, ['txt', 'md', 'csv', 'html', 'json'])) {
        return substr(file_get_contents($filePath), 0, 7000);
    }
    
    // Microsoft Word (.docx)
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
    
    // Microsoft Excel (.xlsx / .xlxx)
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
    
    // Adobe PDF (.pdf)
    if ($ext === 'pdf') {
        $content = @file_get_contents($filePath);
        if ($content && preg_match_all('/\((.*?)\)\s*T[jJ]/s', $content, $matches)) {
            $extracted = implode(' ', $matches);
            if (!empty(trim($extracted))) {
                return substr($extracted, 0, 7000);
            }
        }
        return "[Attached PDF Document: " . basename($filePath) . "]";
    }
    
    // Image Evidence (.jpg, .png, etc.)
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
        return "[Visual Media Evidence Attached: " . basename($filePath) . "]";
    }
    
    return "[Attached File Artifact: " . basename($filePath) . "]";
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
// 4. FETCH ALL SUBMISSIONS (For the Left History Sub-Drawer)
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

// Default to the first available portfolio if none is specified
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
        $user_msg = "Please evaluate this portfolio submission against the 100-Point PPST Rubric. Evaluate Objectives (20), Content (20), Methodology (30), Assessment (20), and Mechanics (10). Provide an estimated numerical score out of 100, classify as [GREAT: 90-100], [GOOD: 75-89], or [NEEDS REVISION: <75], and highlight actionable next steps.";
    }

    if (!empty($user_msg)) {
        $chats = json_decode($portfolio['chat_transcript'] ?? '[]', true);
        if (!is_array($chats)) { $chats = []; }

        $chats[] = [
            'sender'  => 'user',
            'message' => $user_msg,
            'time'    => date('h:i A')
        ];

        // Extract text from attached artifact file (DOCX, XLSX, PDF, TXT)
        $file_context_str = "";
        if (!empty($portfolio['file_path'])) {
            $extractedText = extractFileTextContent($portfolio['file_path']);
            if (!empty($extractedText)) {
                $file_context_str = "\n\n--- ATTACHED EVIDENCE / ARTIFACT CONTENT (" . basename($portfolio['file_path']) . ") ---\n" 
                                  . $extractedText 
                                  . "\n--- END OF ATTACHMENT ---\n";
            }
        }

        // Construct AI Prompt
        $ai_prompt = "You are an expert Academic Copilot and Teacher Education Mentor evaluating Pre-Service Teachers.\n"
                   . "100-Point PPST Rubric Standards:\n"
                   . "1. Objectives (20 pts): Specific, Measurable, HOTS-aligned (Bloom's Taxonomy).\n"
                   . "2. Content (20 pts): Accurate subject matter aligned with curriculum.\n"
                   . "3. Methodology (30 pts): Active student-centered pedagogy and instructional flow.\n"
                   . "4. Assessment (20 pts): Formative/summative tools aligned with objectives.\n"
                   . "5. Formatting & Mechanics (10 pts): Professional documentation.\n\n"
                   . "Portfolio Title: $current_ttl\n"
                   . "Lesson Content / Procedures:\n$current_desc\n"
                   . $file_context_str . "\n"
                   . "User Query: $user_msg\n\n"
                   . "Provide concise, structured, and pedagogical feedback.";

        $ai_reply = "Hello! I have reviewed your submission. Your lesson flow is clear, and your attachments are linked.";
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

        /* 3-Column Studio Layout */
        .workspace-grid {
            display: grid;
            grid-template-columns: 280px 1fr 380px;
            gap: 20px;
            align-items: stretch;
            min-height: calc(100vh - 160px);
            margin-top: 15px;
        }

        @media (max-width: 1200px) {
            .workspace-grid {
                grid-template-columns: 240px 1fr 340px;
            }
        }

        @media (max-width: 992px) {
            .workspace-grid {
                grid-template-columns: 1fr;
            }
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

        /* Submissions History Drawer (Left) */
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
            max-height: calc(100vh - 230px);
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .drawer-item {
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-color);
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: all 0.15s ease;
            background: var(--card-bg);
        }

        .drawer-item:hover, .drawer-item.active {
            border-color: var(--primary-accent);
            background: rgba(79, 70, 229, 0.04);
        }

        .drawer-item.active {
            border-left: 4px solid var(--primary-accent);
        }

        /* Form Controls (Center) */
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
            font-size: 14px;
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

        .form-control:focus {
            outline: none;
            border-color: var(--primary-accent);
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
            padding: 11px 15px;
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
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .copilot-input-bar {
            padding: 12px 16px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* Badges */
        .card-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-draft { background-color: #fef3c7; color: #92400e; }
        .badge-pending { background-color: #dbeafe; color: #1e40af; }
        .badge-graded { background-color: #dcfce7; color: #166534; }

        /* Full-Screen Loading Screen Overlay */
        .copilot-loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            z-index: 999999;
            display: none;
            justify-content: center;
            align-items: center;
            color: #ffffff;
        }

        .loading-dialog {
            background: var(--card-bg);
            color: var(--text-color);
            padding: 30px 40px;
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

    <!-- Full-screen Copilot Loading Screen -->
    <div id="copilotLoadingOverlay" class="copilot-loading-overlay">
        <div class="loading-dialog">
            <div class="spinner-ring"></div>
            <i class="fas fa-robot" style="font-size: 32px; color: var(--primary-accent);"></i>
            <h3 style="margin: 0; font-size: 18px;">Copilot is Analyzing...</h3>
            <p style="margin: 0; font-size: 13px; opacity: 0.8; line-height: 1.5;">
                Reviewing your lesson objectives and scanning all attached evidence files. Please wait.
            </p>
        </div>
    </div>

    <!-- Main Navigation Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">

        <!-- Notification Banner -->
        <?php if ($msg): ?>
            <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:12px 18px; border-radius:8px; margin-bottom:15px; font-weight:500;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <!-- Sub-Header / Status Bar -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <h2 style="margin:0; font-size:22px; color:var(--text-color);">
                    <i class="fas fa-layer-group" style="color:var(--primary-accent); margin-right:8px;"></i> Student Portfolio Workspace
                </h2>
                <span style="font-size:13px; opacity:0.75;">Draft, attach evidence, and get 100-Point feedback from your AI mentor.</span>
            </div>
            
            <?php if ($portfolio): ?>
                <div>
                    <?php if ($is_graded): ?>
                        <span class="card-status-badge badge-graded"><i class="fas fa-lock"></i> Graded & Locked</span>
                    <?php elseif ($portfolio['status'] === 'pending'): ?>
                        <span class="card-status-badge badge-pending"><i class="fas fa-clock"></i> In Review</span>
                    <?php else: ?>
                        <span class="card-status-badge badge-draft"><i class="fas fa-edit"></i> Draft Mode</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3-Column Studio Frame -->
        <div class="workspace-grid">
            
            <!-- ============================================== -->
            <!-- 1. LEFT COLUMN: Submitted Portfolios History   -->
            <!-- ============================================== -->
            <div class="studio-card">
                <div class="drawer-header">
                    <strong style="font-size:14px;"><i class="fas fa-folder-open"></i> My Submissions</strong>
                    <a href="student_portfolio.php?action=new" style="background:#27ae60; color:white; padding:5px 10px; border-radius:6px; font-size:12px; text-decoration:none;">
                        <i class="fas fa-plus"></i> New Plan
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
                                        <span class="card-status-badge badge-graded"><i class="fas fa-check-circle"></i> <?= $row['competency_score'] ?>/100</span>
                                    <?php elseif ($status === 'pending'): ?>
                                        <span class="card-status-badge badge-pending"><i class="fas fa-clock"></i> Review</span>
                                    <?php else: ?>
                                        <span class="card-status-badge badge-draft"><i class="fas fa-edit"></i> Draft</span>
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
                            No portfolios yet.<br>Click <strong>+ New Plan</strong> to start.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================== -->
            <!-- 2. CENTER COLUMN: Active Portfolio Workspace   -->
            <!-- ============================================== -->
            <div class="studio-card">
                <?php if ($portfolio): ?>
                    <div class="workspace-body">

                        <!-- Official Score Banner if Graded -->
                        <?php if ($is_graded): ?>
                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-left:5px solid #22c55e; padding:16px; border-radius:10px; margin-bottom:20px; color:#14532d;">
                                <h3 style="margin:0 0 6px; font-size:17px; display:flex; align-items:center; gap:8px;">
                                    <i class="fas fa-award"></i> Official Score: <?= $portfolio['competency_score'] ?> / 100
                                </h3>
                                <p style="margin:0 0 6px; font-size:13px;">
                                    <strong>Evaluated by:</strong> <?= htmlspecialchars($portfolio['evaluator_name'] ?? 'Supervisor') ?>
                                </p>
                                <?php if (!empty($portfolio['readiness_notes'])): ?>
                                    <p style="margin:8px 0 0; font-size:13px; line-height:1.5;">
                                        <strong>Feedback:</strong> <?= nl2br(htmlspecialchars($portfolio['readiness_notes'])) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="mainPortfolioForm">
                            <div class="form-group">
                                <label>Portfolio / Lesson Plan Title</label>
                                <input type="text" name="title" class="form-control" 
                                       value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>" 
                                       <?= $is_graded ? 'readonly' : 'required' ?>>
                            </div>

                            <div class="form-group">
                                <label>Lesson Context, Procedures & Objectives</label>
                                <textarea name="description" id="editorContent" class="form-control" rows="12" 
                                          placeholder="Type your lesson plan, objectives, and teaching methodology..." 
                                          <?= $is_graded ? 'readonly' : 'required' ?>><?= htmlspecialchars($portfolio['description'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group" style="background:rgba(0,0,0,0.02); padding:16px; border-radius:8px; border:1px dashed var(--border-color);">
                                <label><i class="fas fa-paperclip"></i> Attached Artifacts / Evidence (.docx, .xlsx, .pdf, images)</label>
                                
                                <?php if (!empty($portfolio['file_path'])): ?>
                                    <div style="margin-bottom:10px; font-size:13px;">
                                        Attached: <a href="<?= htmlspecialchars($portfolio['file_path']) ?>" target="_blank" style="color:var(--primary-accent); font-weight:600;"><i class="fas fa-file-alt"></i> <?= basename($portfolio['file_path']) ?></a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$is_graded): ?>
                                    <input type="file" name="file" class="form-control" style="border:none; padding:4px 0;">
                                <?php endif; ?>
                            </div>

                            <?php if (!$is_graded): ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                    <div>
                                        <button type="submit" name="delete_draft" class="btn" style="background:#ef4444; color:white; border:none; padding:8px 14px; border-radius:6px; font-size:12px; cursor:pointer;" onclick="return confirm('Delete this draft?');">
                                            <i class="fas fa-trash"></i> Delete Draft
                                        </button>
                                    </div>
                                    <div style="display:flex; gap:10px;">
                                        <button type="submit" name="save_portfolio" value="draft" class="btn" style="background:#64748b; color:white; border:none; padding:10px 18px; border-radius:6px; font-size:13px; cursor:pointer;">
                                            <i class="fas fa-save"></i> Save Draft
                                        </button>
                                        <button type="submit" name="save_portfolio" value="submit" class="btn" style="background:#22c55e; color:white; border:none; padding:10px 22px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">
                                            <i class="fas fa-paper-plane"></i> Submit for Review
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="student_portfolio.php?action=revise&orig_id=<?= $portfolio['id'] ?>" class="btn" style="background:var(--primary-accent); color:white; padding:10px 18px; border-radius:6px; text-decoration:none; display:inline-block; font-size:13px;">
                                    <i class="fas fa-copy"></i> Create Revision Copy
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
            <!-- 3. RIGHT COLUMN: 100-Point AI Copilot Studio   -->
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
                            <div>Hello! I am your portfolio mentor. I can analyze your objectives and attached evidence files (.docx, .xlsx, .pdf, images). Click <strong>"Evaluate 100-Pts"</strong> or ask for specific advice below.</div>
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

    <!-- Scripts: Chat Auto-Scroll & Loading Screen -->
    <script>
    (function () {
        const chatBox = document.getElementById('copilotChatBox');
        if (chatBox) {
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        const overlay = document.getElementById('copilotLoadingOverlay');
        const triggerForms = document.querySelectorAll('.copilot-submit-trigger');
        const editorContent = document.getElementById('editorContent');
        const hiddenContext = document.getElementById('hiddenContextDescription');

        triggerForms.forEach(function (form) {
            form.addEventListener('submit', function () {
                // Sync editor content to Copilot hidden payload
                if (editorContent && hiddenContext) {
                    hiddenContext.value = editorContent.value;
                }
                // Show fullscreen loading screen
                if (overlay) {
                    overlay.style.display = 'flex';
                }
            });
        });
    })();
    </script>
</body>
</html>
