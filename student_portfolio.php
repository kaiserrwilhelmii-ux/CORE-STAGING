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
        $msg = "Draft portfolio has been deleted.";
    }
}

// -------------------------------------------------------------
// 3. ACTION: Make a Revision Copy of a Submitted Portfolio
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
// 4. LOAD ACTIVE PORTFOLIO (If an ID is passed)
// -------------------------------------------------------------
$portfolio_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$portfolio    = null;
$is_graded    = false;

if ($portfolio_id > 0) {
    // Join evaluations to see if this portfolio has been graded
    $sql_port = "SELECT s.*, e.competency_score, e.readiness_notes, u_eval.fullname as evaluator_name 
                 FROM submissions s 
                 LEFT JOIN evaluations e ON s.id = e.submission_id 
                 LEFT JOIN users u_eval ON e.evaluator_id = u_eval.id 
                 WHERE s.id=$portfolio_id AND s.user_id=$user_id LIMIT 1";
    $p_res = $conn->query($sql_port);
    if ($p_res && $p_res->num_rows > 0) {
        $portfolio = $p_res->fetch_assoc();
        if (!empty($portfolio['competency_score']) || $portfolio['status'] === 'accepted' || $portfolio['status'] === 'graded') {
            $is_graded = true;
        }
    } else {
        $portfolio_id = 0; // Not found or not authorized
    }
}

// -------------------------------------------------------------
// 5. ACTION: Save Draft or Submit Portfolio
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_portfolio']) && $portfolio_id > 0 && !$is_graded) {
    $title_val  = trim($_POST['title'] ?? 'Untitled Portfolio');
    $desc_val   = trim($_POST['description'] ?? '');
    $new_status = ($_POST['save_portfolio'] === 'submit') ? 'pending' : 'draft';
    
    // File attachment handling
    $target_file = $portfolio['file_path'] ?? '';
    if (!empty($_FILES['file']['name'])) {
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $target_file = $target_dir . $user_id . "_evidence_" . time() . "_" . basename($_FILES["file"]["name"]);
        move_uploaded_file($_FILES["file"]["tmp_name"], $target_file);
    }

    $stmt = $conn->prepare("UPDATE submissions SET title=?, description=?, file_path=?, status=? WHERE id=? AND user_id=?");
    $stmt->bind_param("ssssii", $title_val, $desc_val, $target_file, $new_status, $portfolio_id, $user_id);
    if ($stmt->execute()) {
        $msg = ($new_status === 'pending') 
            ? "Your portfolio has been submitted for official review!" 
            : "Draft saved successfully!";
        // Refresh
        $p_res = $conn->query("SELECT s.*, e.competency_score, e.readiness_notes, u_eval.fullname as evaluator_name FROM submissions s LEFT JOIN evaluations e ON s.id = e.submission_id LEFT JOIN users u_eval ON e.evaluator_id = u_eval.id WHERE s.id=$portfolio_id AND s.user_id=$user_id LIMIT 1");
        $portfolio = $p_res->fetch_assoc();
    }
}

// -------------------------------------------------------------
// 6. ACTION: Portfolio-Specific AI Copilot Chat & 100-Point Scoring
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_chat']) && $portfolio_id > 0) {
    $user_msg     = trim($_POST['message'] ?? '');
    $auto_eval    = isset($_POST['run_eval']) && $_POST['run_eval'] == '1';
    $current_desc = trim($_POST['context_description'] ?? ($portfolio['description'] ?? ''));
    $current_ttl  = trim($_POST['context_title'] ?? ($portfolio['title'] ?? ''));

    if ($auto_eval) {
        $user_msg = "Please analyze my submission using the 100-Point PPST Rubric. Evaluate my Objectives (20), Content (20), Methodology (30), Assessment (20), and Formatting (10). Tell me my estimated score out of 100 and whether it is GREAT, GOOD, or NEEDS REVISION.";
    }

    if (!empty($user_msg)) {
        // Decode existing transcript array for THIS specific portfolio
        $chats = json_decode($portfolio['chat_transcript'] ?? '[]', true);
        if (!is_array($chats)) { $chats = []; }

        $chats[] = [
            'sender'  => 'user',
            'message' => $user_msg,
            'time'    => date('h:i A')
        ];

        // Construct evaluation prompt
        $ai_prompt = "You are an expert Academic Copilot and Teacher Education Evaluator.\n"
                   . "100-Point Mark PPST Rubric:\n"
                   . "1. Objectives (20 pts): Clear, measurable, HOTS-aligned.\n"
                   . "2. Content (20 pts): Accurate, curriculum-aligned.\n"
                   . "3. Methodology (30 pts): Engaging pedagogical flow.\n"
                   . "4. Assessment (20 pts): Valid tools aligned with goals.\n"
                   . "5. Formatting & Mechanics (10 pts): Professional standard.\n\n"
                   . "Lesson Title: $current_ttl\n"
                   . "Lesson Content / Description:\n$current_desc\n\n"
                   . "User Inquiry: $user_msg\n"
                   . "Provide an estimated score out of 100, tag it clearly as [GREAT: 90-100], [GOOD: 75-89], or [NEEDS REVISION: <75], and provide actionable improvement advice.";

        $ai_reply = "Hello! I am ready to review your portfolio.";
        if (function_exists('generateAIResponse')) {
            $ai_reply = generateAIResponse($ai_prompt, 'mentor');
        }

        $chats[] = [
            'sender'  => 'ai',
            'message' => $ai_reply,
            'time'    => date('h:i A')
        ];

        // Save updated conversation directly into this portfolio's record
        $updated_json = json_encode($chats);
        $stmt = $conn->prepare("UPDATE submissions SET chat_transcript=? WHERE id=? AND user_id=?");
        $stmt->bind_param("sii", $updated_json, $portfolio_id, $user_id);
        $stmt->execute();

        header("Location: student_portfolio.php?id=" . $portfolio_id);
        exit();
    }
}

// -------------------------------------------------------------
// 7. FETCH ALL STUDENT PORTFOLIOS (For the Dashboard/Hub View)
// -------------------------------------------------------------
$all_submissions = $conn->query("
    SELECT s.*, e.competency_score, e.readiness_notes 
    FROM submissions s 
    LEFT JOIN evaluations e ON s.id = e.submission_id 
    WHERE s.user_id = $user_id 
    ORDER BY s.id DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portfolio Hub | CORE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Portfolio Hub Grid (Library View) */
        .portfolio-hub-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .portfolio-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .portfolio-card-item {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 22px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .portfolio-card-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .card-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-draft { background-color: #fff3cd; color: #856404; }
        .badge-pending { background-color: #cce5ff; color: #004085; }
        .badge-graded { background-color: #d4edda; color: #155724; }

        /* Workspace Studio Layout (Editor + Copilot View) */
        .workspace-studio {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 25px;
            align-items: start;
        }

        @media (max-width: 1100px) {
            .workspace-studio { grid-template-columns: 1fr; }
        }

        /* Copilot Chat Panel */
        .copilot-panel {
            background: var(--card-bg);
            border-radius: 14px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            height: 680px;
            overflow: hidden;
            position: sticky;
            top: 25px;
        }

        .copilot-header {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #ffffff;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .copilot-chat-history {
            flex: 1;
            overflow-y: auto;
            padding: 18px;
            background: rgba(0, 0, 0, 0.015);
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        body.dark-mode .copilot-chat-history {
            background: rgba(255, 255, 255, 0.015);
        }

        .chat-bubble {
            max-width: 85%;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13.5px;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .chat-bubble.user {
            align-self: flex-end;
            background: #4f46e5;
            color: #ffffff;
            border-bottom-right-radius: 2px;
        }

        .chat-bubble.ai {
            align-self: flex-start;
            background: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-bottom-left-radius: 2px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }

        .copilot-input-area {
            padding: 12px 16px;
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .score-display-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-left: 5px solid #22c55e;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #14532d;
        }

        body.dark-mode .score-display-box {
            background: rgba(34, 197, 94, 0.1);
            border-color: rgba(34, 197, 94, 0.3);
            color: #86efac;
        }
    </style>
</head>
<body>

    <!-- Main Navigation Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">

        <!-- Messages -->
        <?php if ($msg): ?>
            <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:14px 18px; border-radius:8px; margin-bottom:20px; font-weight:500;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($portfolio_id === 0): ?>
            <!-- ======================================================= -->
            <!-- VIEW 1: PORTFOLIO LIBRARY & SUBMISSION HUB              -->
            <!-- ======================================================= -->
            
            <div class="portfolio-hub-header">
                <div>
                    <h2 style="margin:0; font-size:24px; color:var(--text-color);">
                        <i class="fas fa-folder-open" style="color:var(--btn-primary); margin-right:8px;"></i> My Portfolios
                    </h2>
                    <p style="margin:5px 0 0; font-size:13px; opacity:0.75; color:var(--text-color);">
                        Create, review, and refine your teaching artifacts with AI Copilot support.
                    </p>
                </div>

                <a href="student_portfolio.php?action=new" class="btn" style="background:#27ae60; color:white; padding:10px 20px;">
                    <i class="fas fa-plus"></i> Create New Portfolio
                </a>
            </div>

            <!-- Portfolio Cards Grid -->
            <?php if ($all_submissions && $all_submissions->num_rows > 0): ?>
                <div class="portfolio-cards-grid">
                    <?php while ($row = $all_submissions->fetch_assoc()): 
                        $status = $row['status'] ?? 'draft';
                        $has_grade = !empty($row['competency_score']);
                    ?>
                    <div class="portfolio-card-item">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <?php if ($has_grade): ?>
                                    <span class="card-status-badge badge-graded"><i class="fas fa-check-circle"></i> Graded (<?= $row['competency_score'] ?>/100)</span>
                                <?php elseif ($status === 'pending'): ?>
                                    <span class="card-status-badge badge-pending"><i class="fas fa-clock"></i> In Review</span>
                                <?php else: ?>
                                    <span class="card-status-badge badge-draft"><i class="fas fa-edit"></i> Draft</span>
                                <?php endif; ?>

                                <small style="color:#888; font-size:11px;">
                                    <?= date("M d, Y", strtotime($row['created_at'] ?? 'now')) ?>
                                </small>
                            </div>

                            <h3 style="margin:0 0 10px; font-size:17px; color:var(--text-color);">
                                <?= htmlspecialchars($row['title']) ?>
                            </h3>

                            <p style="font-size:13px; color:#777; line-height:1.5; margin:0 0 15px;">
                                <?= htmlspecialchars(substr($row['description'] ?? 'No description provided.', 0, 100)) ?>...
                            </p>
                        </div>

                        <!-- Card Action Buttons -->
                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border-color); padding-top:14px;">
                            <?php if ($has_grade): ?>
                                <a href="student_portfolio.php?id=<?= $row['id'] ?>" class="btn btn-view" style="width:100%;">
                                    <i class="fas fa-award"></i> View Evaluation
                                </a>
                            <?php elseif ($status === 'pending'): ?>
                                <a href="student_portfolio.php?id=<?= $row['id'] ?>" class="btn btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <!-- Creates a revision copy leaving original intact -->
                                <a href="student_portfolio.php?action=revise&orig_id=<?= $row['id'] ?>" class="btn btn-edit" title="Edit a copy as a new revision">
                                    <i class="fas fa-copy"></i> Edit Copy
                                </a>
                            <?php else: ?>
                                <a href="student_portfolio.php?id=<?= $row['id'] ?>" class="btn btn-edit">
                                    <i class="fas fa-pen"></i> Edit Draft
                                </a>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this draft?');" style="margin:0;">
                                    <input type="hidden" name="portfolio_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="delete_draft" class="btn btn-remove" title="Delete Draft">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="card" style="text-align:center; padding:50px 20px;">
                    <i class="fas fa-folder-open" style="font-size:48px; color:#bdc3c7; margin-bottom:15px;"></i>
                    <h3 style="margin:0 0 8px;">No Portfolios Yet</h3>
                    <p style="color:#777; margin-bottom:20px;">Start drafting your first lesson plan and get instant guidance from the 100-point AI Copilot.</p>
                    <a href="student_portfolio.php?action=new" class="btn" style="background:#27ae60; color:white; padding:10px 24px;">
                        <i class="fas fa-plus"></i> Create Your First Portfolio
                    </a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- ======================================================= -->
            <!-- VIEW 2: PORTFOLIO STUDIO (EDITOR & COPILOT)             -->
            <!-- ======================================================= -->
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                <a href="student_portfolio.php" class="btn btn-view" style="background:#34495e;">
                    <i class="fas fa-arrow-left"></i> Back to All Portfolios
                </a>
                <div>
                    <?php if ($is_graded): ?>
                        <span class="card-status-badge badge-graded"><i class="fas fa-lock"></i> Graded & Locked</span>
                    <?php elseif ($portfolio['status'] === 'pending'): ?>
                        <span class="card-status-badge badge-pending"><i class="fas fa-clock"></i> Submitted (Under Review)</span>
                    <?php else: ?>
                        <span class="card-status-badge badge-draft"><i class="fas fa-edit"></i> Draft Mode</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Workspace Studio: Form on Left, Copilot on Right -->
            <div class="workspace-studio">
                
                <!-- Left: Portfolio Content & Rubric -->
                <div>
                    <?php if ($is_graded): ?>
                        <!-- Official Evaluation Results Banner -->
                        <div class="score-display-box">
                            <h3 style="margin:0 0 8px; font-size:18px; display:flex; align-items:center; gap:8px;">
                                <i class="fas fa-award"></i> Official Score: <?= $portfolio['competency_score'] ?> / 100
                            </h3>
                            <p style="margin:0 0 6px; font-size:13px;">
                                <strong>Evaluated by:</strong> <?= htmlspecialchars($portfolio['evaluator_name'] ?? 'Supervisor') ?>
                            </p>
                            <?php if (!empty($portfolio['readiness_notes'])): ?>
                                <p style="margin:8px 0 0; font-size:13px; line-height:1.5;">
                                    <strong>Evaluator Feedback:</strong> <?= nl2br(htmlspecialchars($portfolio['readiness_notes'])) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="card">
                        <form method="POST" enctype="multipart/form-data" id="portfolioForm">
                            <div class="form-group" style="margin-bottom:20px;">
                                <label style="font-weight:700;">Portfolio / Lesson Plan Title</label>
                                <input type="text" name="title" class="form-control" 
                                       value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>" 
                                       <?= $is_graded ? 'readonly' : 'required' ?>>
                            </div>

                            <div class="form-group" style="margin-bottom:20px;">
                                <label style="font-weight:700;">Lesson Context, Procedures & Objectives</label>
                                <textarea name="description" id="editorContent" class="form-control" rows="14" 
                                          placeholder="Type your lesson plan, objectives, and teaching methodology..." 
                                          <?= $is_graded ? 'readonly' : 'required' ?>><?= htmlspecialchars($portfolio['description'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom:25px; background:rgba(0,0,0,0.02); padding:16px; border-radius:8px; border:1px dashed var(--border-color);">
                                <label style="font-weight:700;"><i class="fas fa-paperclip"></i> Attached Artifacts / Evidence (.docx, .pdf, images)</label>
                                <?php if (!empty($portfolio['file_path'])): ?>
                                    <div style="margin-bottom:10px; font-size:13px;">
                                        Current File: <a href="<?= htmlspecialchars($portfolio['file_path']) ?>" target="_blank" style="color:var(--btn-primary); font-weight:600;"><i class="fas fa-download"></i> View Attachment</a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$is_graded): ?>
                                    <input type="file" name="file" class="form-control" style="border:none; padding:0;">
                                <?php endif; ?>
                            </div>

                            <?php if (!$is_graded): ?>
                                <div style="display:flex; justify-content:flex-end; gap:12px; flex-wrap:wrap;">
                                    <button type="submit" name="save_portfolio" value="draft" class="btn" style="background:#7f8c8d; color:white;">
                                        <i class="fas fa-save"></i> Save Draft
                                    </button>
                                    <button type="submit" name="save_portfolio" value="submit" class="btn" style="background:#27ae60; color:white; padding:10px 24px;">
                                        <i class="fas fa-paper-plane"></i> Submit for Review
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Right: Dedicated AI Copilot Panel (Tied to THIS portfolio) -->
                <div class="copilot-panel">
                    <div class="copilot-header">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-robot" style="font-size:18px;"></i>
                            <span style="font-weight:700; font-size:14px;">100-Point Copilot</span>
                        </div>
                        
                        <!-- One-Click 100-Point Score Analysis -->
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="run_eval" value="1">
                            <input type="hidden" name="context_title" value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>">
                            <input type="hidden" name="context_description" value="<?= htmlspecialchars($portfolio['description'] ?? '') ?>">
                            <button type="submit" name="send_chat" class="btn" style="background:#ffffff; color:#4f46e5; padding:4px 10px; font-size:11px; border-radius:20px;" title="Evaluate against 100-Point Rubric">
                                <i class="fas fa-chart-bar"></i> Evaluate 100-Pts
                            </button>
                        </form>
                    </div>

                    <!-- Chat History (Specific to this Portfolio) -->
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
                                <div>Hello! I am your portfolio mentor. Click <strong>"Evaluate 100-Pts"</strong> or ask questions below to review your lesson objectives and methodology against PPST standards.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Message Input -->
                    <form method="POST" class="copilot-input-area" id="copilotForm">
                        <input type="hidden" name="context_title" value="<?= htmlspecialchars($portfolio['title'] ?? '') ?>">
                        <input type="hidden" name="context_description" id="hiddenContextDescription" value="<?= htmlspecialchars($portfolio['description'] ?? '') ?>">
                        <input type="text" name="message" class="form-control" placeholder="Ask Copilot for feedback..." required style="border-radius:20px; padding:10px 14px;">
                        <button type="submit" name="send_chat" class="btn" style="background:#4f46e5; color:white; border-radius:50%; width:40px; height:40px; padding:0; flex-shrink:0;">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>

            </div>

        <?php endif; ?>

    </div>

    <!-- Scroll Chat to Bottom -->
    <script>
    (function () {
        const chatBox = document.getElementById('copilotChatBox');
        if (chatBox) {
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        // Sync live editor text to hidden context input before sending chat
        const editor = document.getElementById('editorContent');
        const hiddenContext = document.getElementById('hiddenContextDescription');
        const copilotForm = document.getElementById('copilotForm');
        if (editor && hiddenContext && copilotForm) {
            copilotForm.addEventListener('submit', function () {
                hiddenContext.value = editor.value;
            });
        }
    })();
    </script>
</body>
</html>
