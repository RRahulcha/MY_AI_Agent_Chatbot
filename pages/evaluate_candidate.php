<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$userId  = $_SESSION['userId'];
$getUser = mysqli_query($con, "SELECT designation FROM signup WHERE userId='$userId'");
$user    = mysqli_fetch_assoc($getUser);

if (!$user || ($user['designation'] != 'seniorIntern' && $user['designation'] != 'resourceAllocator')) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

if (!isset($_GET['id'])) {
    echo "<script>window.location.href='technical_evaluation.php';</script>";
    exit;
}

$candidate_id   = intval($_GET['id']);
$candidateQuery = mysqli_query($con, "SELECT * FROM candidates WHERE id='$candidate_id'");
$candidate      = mysqli_fetch_assoc($candidateQuery);

if (!$candidate) {
    echo "<script>alert('Candidate not found'); window.location.href='technical_evaluation.php';</script>";
    exit;
}

// ---------- Load existing evaluation (if any — Edit mode) ----------
$evalQuery  = mysqli_query($con, "SELECT * FROM technical_evaluations WHERE candidateId='$candidate_id' LIMIT 1");
$evaluation = mysqli_fetch_assoc($evalQuery);

$existingRatings = [];
if ($evaluation) {
    $ratingsQuery = mysqli_query($con, "SELECT * FROM language_ratings WHERE evaluationId='{$evaluation['id']}'");
    while ($r = mysqli_fetch_assoc($ratingsQuery)) {
        $existingRatings[] = $r;
    }
}

// ---------- Role → default skills map ----------
$roleSkillsMap = [
    'Java Developer'    => ['Java', 'Spring', 'Hibernate'],
    'PHP Developer'     => ['PHP', 'Laravel', 'MySQL'],
    'UI Developer'      => ['HTML', 'CSS', 'JavaScript', 'React'],
    'Python Developer'  => ['Python', 'Django', 'Pandas'],
    'Android Developer' => ['Kotlin', 'Java', 'Android SDK'],
    'Data Analyst'      => ['Python', 'SQL', 'Tableau', 'Excel'],
    '.Net Developer'    => ['C#', 'ASP.NET', 'SQL Server'],
    'Tester'            => ['Selenium', 'JUnit', 'Manual Testing'],
];

// ---------- All available languages ----------
$allLanguages = [
    'Android SDK', 'ASP.NET', 'C#', 'CSS', 'Django', 'Excel',
    'Hibernate', 'HTML', 'Java', 'JavaScript', 'JUnit', 'Kotlin',
    'Laravel', 'Manual Testing', 'MySQL', 'Node.js', 'Pandas',
    'PHP', 'Python', 'React', 'Selenium', 'Spring', 'SQL',
    'SQL Server', 'Tableau'
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Evaluate Candidate</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #4B49AC;
            --primary-light: #eef1ff;
            --success: #28a745;
            --danger: #dc3545;
            --border: #e0e4ef;
            --radius: 8px;
            --shadow: 0 2px 12px rgba(75,73,172,0.1);
        }
        
        .section-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-top: 25px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 8px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-control,
        .form-control:focus,
        select.form-control,
        select.form-control option,
        textarea.form-control {
            color: #111 !important;
            opacity: 1 !important;
            -webkit-text-fill-color: #111111;
            border: 1.5px solid var(--border);
            border-radius: 6px;
            padding: 8px 12px;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(75,73,172,0.1);
        }
        
        .form-control::placeholder { 
            color: #b5b5b5!important; 
             opacity: 1 !important;
        }
        
        .language-block {
            background: #f8f9fd;
            border: 2px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.3s;
        }
        
        .language-block:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(75,73,172,0.15);
        }
        
        .language-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px dashed var(--border);
        }
        
        .language-number {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-remove-lang {
            background: var(--danger);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-remove-lang:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        .score-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .form-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 6px;
            display: block;
        }
        
        .form-group label .required {
            color: var(--danger);
        }
        
        .feedback-textarea {
            min-height: 90px;
            resize: vertical;
            font-family: inherit;
            line-height: 1.6;
        }
        
        .btn-add-language {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            margin-bottom: 20px;
        }
        
        .btn-add-language:hover {
            background: #3d3895;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(75,73,172,0.3);
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid var(--border);
        }
        
        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 28px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary-custom:hover {
            background: #3d3895;
            transform: translateY(-1px);
        }
        
        .btn-secondary-custom {
            background: #f1f3f9;
            color: #555;
            border: none;
            border-radius: 8px;
            padding: 12px 28px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn-secondary-custom:hover {
            background: #e0e4ef;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        
        .card-title {
            font-size: 1.4rem;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .candidate-info-row {
            background: var(--primary-light);
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .hint-text {
            font-size: 0.75rem;
            color: #888;
            margin-top: 4px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
            background: white;
            border-radius: 8px;
            border: 2px dashed var(--border);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #d0d5f0;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
<div class="container-scroller">
<?php include('../partials/_navbar.php'); ?>
<div class="container-fluid page-body-wrapper">
<?php include('../partials/_sidebar.php'); ?>

<div class="main-panel">
<div class="content-wrapper">

<div class="card shadow-sm">
<div class="card-body">

<h4 class="card-title" style="color:#4B49AC; font-weight:700;">
    <i class="fa-solid fa-clipboard-check"></i>
    <?= $evaluation ? 'Edit Evaluation' : 'Technical Evaluation' ?>
</h4>

<form action="evaluate_candidate_process.php" method="POST" id="evaluationForm">

<input type="hidden" name="candidate_id"   value="<?= $candidate_id ?>">
<input type="hidden" name="evaluation_id"  value="<?= $evaluation['id'] ?? '' ?>">
<input type="hidden" name="is_edit"        value="<?= $evaluation ? '1' : '0' ?>">

<!-- Candidate Info -->
<div class="section-title">
    <i class="fa-solid fa-user"></i>
    Candidate Information
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <div class="form-group">
            <label>Name</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($candidate['applicantName']) ?>" readonly>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="form-group">
            <label>Email</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($candidate['email']) ?>" readonly>
        </div>
    </div>
</div>

<!-- Language Ratings -->
<div class="section-title">
    <i class="fa-solid fa-code"></i>
    Language Proficiency Evaluation
</div>

<button type="button" class="btn-add-language" onclick="addLanguageBlock()">
    <i class="fa-solid fa-plus"></i>
    Add Language
</button>

<div id="language-container">
<?php
// Build blocks: use existing ratings if editing, else one blank block
$blocksToRender = !empty($existingRatings) ? $existingRatings : [null];
foreach ($blocksToRender as $idx => $rating):
    $selLang     = $rating['languageName']     ?? '';
    $selConcept  = $rating['conceptualScore']  ?? '';
    $selPract    = $rating['practicalScore']   ?? '';
    $selFeedback = $rating['languageFeedback'] ?? '';
?>
<div class="language-block" data-index="<?= $idx ?>">
    <div class="language-header">
        <div class="language-number">
            <i class="fa-solid fa-laptop-code"></i>
            Language #<span class="lang-num"><?= $idx + 1 ?></span>
        </div>
        <button type="button" class="btn-remove-lang" onclick="removeLanguageBlock(this)">
            <i class="fa-solid fa-trash me-1"></i>Remove
        </button>
    </div>
    
    <div class="form-group mb-3">
        <label>Language/Technology <span class="required">*</span></label>
        <select name="language_name[]" class="form-control" required>
            <option value="">-- Select Language --</option>
            <?php foreach ($allLanguages as $lang): ?>
                <option value="<?= $lang ?>" <?= ($selLang === $lang) ? 'selected' : '' ?>><?= $lang ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="score-grid">
        <div class="form-group">
            <label>Conceptual Knowledge (0-5) <span class="required">*</span></label>
            <select name="conceptual_score[]" class="form-control" required>
                <option value="">Select Score</option>
                <?php for ($s = 0; $s <= 5; $s++): ?>
                    <option value="<?= $s ?>" <?= ((string)$selConcept === (string)$s && $selConcept !== '') ? 'selected' : '' ?>><?= $s ?></option>
                <?php endfor; ?>
            </select>
            <div class="hint-text">Rate theoretical understanding (0 = No knowledge)</div>
        </div>

        <div class="form-group">
            <label>Practical Skills (0-5) <span class="required">*</span></label>
            <select name="practical_score[]" class="form-control" required>
                <option value="">Select Score</option>
                <?php for ($s = 0; $s <= 5; $s++): ?>
                    <option value="<?= $s ?>" <?= ((string)$selPract === (string)$s && $selPract !== '') ? 'selected' : '' ?>><?= $s ?></option>
                <?php endfor; ?>
            </select>
            <div class="hint-text">Rate hands-on ability (0 = No knowledge)</div>
        </div>
    </div>
    
    <div class="form-group">
        <label>Language-Specific Feedback</label>
        <textarea name="language_feedback[]" 
                  class="form-control feedback-textarea"
                  placeholder=""><?= htmlspecialchars($selFeedback) ?></textarea>
        <div class="hint-text">Optional: Add specific comments about performance in this language</div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Overall Evaluation -->
<div class="section-title">
    <i class="fa-solid fa-star"></i>
    Overall Assessment
</div>

<div class="row">
    <div class="col-md-12 mb-3">
        <div class="form-group">
            <label>Overall Feedback <span class="required">*</span></label>
            <textarea name="overall_feedback" 
                      class="form-control feedback-textarea" 
                      style="min-height: 120px;"
                      placeholder=""
                      required><?= htmlspecialchars($evaluation['overallFeedback'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="col-md-6 mb-3">
        <div class="form-group">
            <label>Recommended For <span class="required">*</span></label>
            <select name="recommended_for" class="form-control" required>
                <option value="Mentor-Led" <?= (!$evaluation || $evaluation['internshipMode'] === 'Mentor-Led') ? 'selected' : '' ?>>Mentor-Led</option>
                <?php
                $recOptions = ['Not Selected', 'Free of Cost','Stipend-based Internship'];
                foreach ($recOptions as $opt):
                ?>
                    <option value="<?= $opt ?>" <?= (isset($evaluation['internshipMode']) && $evaluation['internshipMode'] === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="action-buttons">
    <a href="technical_evaluation.php" class="btn-secondary-custom">
        <i class="fa-solid fa-arrow-left me-2"></i>Cancel
    </a>
    <button type="submit" class="btn-primary-custom">
        <i class="fa-solid fa-save me-2"></i>
        <?= $evaluation ? 'Update Evaluation' : 'Submit Evaluation' ?>
    </button>
</div>

</form>
</div>
</div>

</div>
<?php include('../partials/_footer.php'); ?>
</div>
</div>
</div>

<script>
const allLanguages = <?= json_encode($allLanguages) ?>;
let languageBlockIndex = <?= count($blocksToRender) ?>;

function createLanguageBlock(langValue = '', conceptVal = '', practVal = '', feedbackVal = '') {
    const block = document.createElement('div');
    block.className = 'language-block';
    block.setAttribute('data-index', languageBlockIndex);
    
    let langOptions = '<option value="">-- Select Language --</option>';
    allLanguages.forEach(l => {
        langOptions += `<option value="${escapeHtml(l)}" ${l === langValue ? 'selected' : ''}>${escapeHtml(l)}</option>`;
    });

    let scoreOptions = '<option value="">Select Score</option>';
    for (let i = 0; i <= 5; i++) {
        scoreOptions += `<option value="${i}">${i}</option>`;
    }
    
    let conceptOptions = '<option value="">Select Score</option>';
    for (let i = 0; i <= 5; i++) {
        conceptOptions += `<option value="${i}" ${conceptVal !== '' && i == conceptVal ? 'selected' : ''}>${i}</option>`;
    }
    
    let practOptions = '<option value="">Select Score</option>';
    for (let i = 0; i <= 5; i++) {
        practOptions += `<option value="${i}" ${practVal !== '' && i == practVal ? 'selected' : ''}>${i}</option>`;
    }

    block.innerHTML = `
        <div class="language-header">
            <div class="language-number">
                <i class="fa-solid fa-laptop-code"></i>
                Language #<span class="lang-num">${languageBlockIndex + 1}</span>
            </div>
            <button type="button" class="btn-remove-lang" onclick="removeLanguageBlock(this)">
                <i class="fa-solid fa-trash me-1"></i>Remove
            </button>
        </div>
        
        <div class="form-group mb-3">
            <label>Language/Technology <span class="required">*</span></label>
            <select name="language_name[]" class="form-control" required>
                ${langOptions}
            </select>
        </div>

        <div class="score-grid">
            <div class="form-group">
                <label>Conceptual Knowledge (1-5) <span class="required">*</span></label>
                <select name="conceptual_score[]" class="form-control" required>
                    ${conceptOptions}
                </select>
                <div class="hint-text">Rate theoretical understanding</div>
            </div>

            <div class="form-group">
                <label>Practical Skills (1-5) <span class="required">*</span></label>
                <select name="practical_score[]" class="form-control" required>
                    ${practOptions}
                </select>
                <div class="hint-text">Rate hands-on ability</div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Language-Specific Feedback</label>
            <textarea name="language_feedback[]" 
                      class="form-control feedback-textarea"
                      placeholder="">${escapeHtml(feedbackVal)}</textarea>
            <div class="hint-text">Optional: Add specific comments about performance in this language</div>
        </div>
    `;
    
    languageBlockIndex++;
    return block;
}

function addLanguageBlock() {
    const container = document.getElementById('language-container');
    const newBlock = createLanguageBlock();
    container.appendChild(newBlock);
    
    // Scroll to the new block
    newBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Update all language numbers
    updateLanguageNumbers();
}

function removeLanguageBlock(btn) {
    const blocks = document.querySelectorAll('.language-block');
    
    if (blocks.length <= 1) {
        alert('At least one language rating is required.');
        return;
    }
    
    if (confirm('Are you sure you want to remove this language evaluation?')) {
        const block = btn.closest('.language-block');
        block.remove();
        updateLanguageNumbers();
    }
}

function updateLanguageNumbers() {
    const blocks = document.querySelectorAll('.language-block');
    blocks.forEach((block, index) => {
        const numSpan = block.querySelector('.lang-num');
        if (numSpan) {
            numSpan.textContent = index + 1;
        }
        block.setAttribute('data-index', index);
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Form validation
document.getElementById('evaluationForm').addEventListener('submit', function(e) {
    const blocks = document.querySelectorAll('.language-block');
    if (blocks.length === 0) {
        e.preventDefault();
        alert('Please add at least one language evaluation');
        return false;
    }
    
    // Validate that all language selects have values
    let hasEmptyLanguage = false;
    blocks.forEach(block => {
        const langSelect = block.querySelector('select[name="language_name[]"]');
        if (!langSelect.value) {
            hasEmptyLanguage = true;
        }
    });
    
    if (hasEmptyLanguage) {
        e.preventDefault();
        alert('Please select a language for all language blocks');
        return false;
    }
});

// Initialize language numbers on page load
document.addEventListener('DOMContentLoaded', function() {
    updateLanguageNumbers();
});
</script>
</body>
</html>