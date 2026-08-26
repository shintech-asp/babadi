<?php
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

if (!($portal_role === 'owner' || $portal_dept === 'hr' || $portal_dept === 'all')) {
    header('Location: dashboard.php'); exit;
}

require_once 'includes/portal-tier.php';
if (!$tier_is_paid) { echo _tierLockedPage('Recruitment'); exit; }

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}
function safeCount($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Exception $e){return 0;}}

$success = $error = '';
$view = $_GET['view']??'jobs'; // jobs | applicants

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_job') {
        $title = trim($_POST['job_title']??'');
        $dept = trim($_POST['department']??'');
        $desc = trim($_POST['job_description']??'');
        $req = trim($_POST['requirements']??'');
        $min = (float)($_POST['salary_min']??0);
        $max = (float)($_POST['salary_max']??0);
        $etype = $_POST['employment_type']??'full_time';
        $loc = trim($_POST['location']??'');
        $close = $_POST['closing_date']??null;
        try {
            $db->prepare("INSERT INTO recruitment (provider_id,job_title,department,job_description,requirements,salary_range_min,salary_range_max,employment_type,location,posted_date,closing_date,status) VALUES (:p,:t,:d,:jd,:r,:min,:max,:et,:loc,CURDATE(),:cd,'open')")
               ->execute([':p'=>$pid,':t'=>$title,':d'=>$dept,':jd'=>$desc,':r'=>$req,':min'=>$min,':max'=>$max,':et'=>$etype,':loc'=>$loc,':cd'=>$close]);
            header('Location: hr-dashboard.php?job_posted=1');
            exit();
        } catch(Exception $ex){ $error = $ex->getMessage(); }
    } elseif ($_POST['action'] === 'update_job_status') {
        $jid = (int)($_POST['job_id']??0);
        $st = $_POST['job_status']??'open';
        $db->prepare("UPDATE recruitment SET status=:s WHERE id=:id AND provider_id=:p")->execute([':s'=>$st,':id'=>$jid,':p'=>$pid]);
        $success = "Job status updated.";
    } elseif ($_POST['action'] === 'update_applicant') {
        $aid = (int)($_POST['applicant_id']??0);
        $st = $_POST['app_status']??'pending';
        $notes = trim($_POST['notes']??'');
        $db->prepare("UPDATE applicants SET status=:s,notes=:n,updated_at=NOW() WHERE id=:id")->execute([':s'=>$st,':n'=>$notes,':id'=>$aid]);
        $success = "Applicant status updated.";
    }
}

$jobs = safeAll($db,"SELECT r.*, (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id=r.id) AS applicant_count FROM recruitment WHERE provider_id=:p ORDER BY created_at DESC",[':p'=>$pid]);
$selected_job = (int)($_GET['job']??0);
$applicants = $selected_job ? safeAll($db,"SELECT * FROM applicants WHERE recruitment_id=:rid ORDER BY application_date DESC",[':rid'=>$selected_job]) : [];
$job_detail = $selected_job ? safeAll($db,"SELECT * FROM recruitment WHERE id=:id AND provider_id=:p",[':id'=>$selected_job,':p'=>$pid]) : [];
$job_detail = $job_detail[0]??null;

$open_jobs = safeCount($db,"SELECT COUNT(*) FROM recruitment WHERE provider_id=:p AND status='open'",[':p'=>$pid]);
$total_applicants = safeCount($db,"SELECT COUNT(*) FROM applicants a JOIN recruitment r ON a.recruitment_id=r.id WHERE r.provider_id=:p",[':p'=>$pid]);
$pending_apps = safeCount($db,"SELECT COUNT(*) FROM applicants a JOIN recruitment r ON a.recruitment_id=r.id WHERE r.provider_id=:p AND a.status='pending'",[':p'=>$pid]);

$active_menu='recruitment';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recruitment - <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#1abc9c;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}
.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;padding:18px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.stat-icon{width:44px;height:44px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff}
.si-teal{background:linear-gradient(135deg,#1abc9c,#16a085)}
.si-blue{background:linear-gradient(135deg,#3498db,#2980b9)}
.si-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.stat-info h3{font-size:20px;font-weight:700;color:var(--dark)}
.stat-info p{font-size:11px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;color:var(--dark);border:1px solid var(--border)}
.btn-sm{padding:5px 10px;font-size:11px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.layout{display:grid;grid-template-columns:300px 1fr;gap:18px}
.jobs-list{display:flex;flex-direction:column;gap:10px}
.job-card{background:#fff;border-radius:10px;border:1px solid var(--border);padding:14px 16px;cursor:pointer;transition:all .2s;text-decoration:none;display:block}
.job-card:hover,.job-card.active{border-color:var(--primary);box-shadow:0 4px 12px rgba(26,188,156,.15)}
.job-card.active{background:linear-gradient(135deg,rgba(26,188,156,.05),rgba(22,160,133,.05))}
.job-card h4{font-size:14px;font-weight:700;color:var(--dark)}
.job-card p{font-size:12px;color:var(--muted);margin-top:3px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.badge{padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
.badge-green{background:#c6f6d5;color:#276749}
.badge-orange{background:#feebc8;color:#c05621}
.badge-red{background:#fed7d7;color:#c53030}
.badge-gray{background:#e2e8f0;color:#4a5568}
.badge-blue{background:#bee3f8;color:#2b6cb0}
.badge-teal{background:#ccfbf1;color:#065f46}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.active{display:flex}
.modal{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.empty-state{text-align:center;padding:50px;color:var(--muted)}
.no-select-msg{text-align:center;padding:64px 22px;color:var(--muted)}
.no-select-msg i{font-size:48px;opacity:.15;display:block;margin-bottom:14px}
.no-select-msg p{font-size:22px;color:#cbd5e1;line-height:1}
.no-select-msg .post-job-help{display:block;margin-top:10px;font-size:12px;color:#94a3b8}
.no-select-msg .post-job-cta{
    margin-top:18px;
    padding:12px 22px;
    border-radius:999px;
    font-size:13px;
    font-weight:700;
    letter-spacing:.2px;
    border:1px solid rgba(26,188,156,.45);
    background:linear-gradient(135deg,#1abc9c,#16a085);
    color:#fff;
    box-shadow:0 10px 24px rgba(22,160,133,.3);
}
.no-select-msg .post-job-cta:hover{
    transform:translateY(-2px);
    box-shadow:0 14px 28px rgba(22,160,133,.38);
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main">
<div class="main-content">

<div class="page-header">
    <div>
        <h1><i class="fas fa-user-plus"></i> Recruitment</h1>
        <p><?= count($jobs) ?> job posting(s)</p>
    </div>
</div>

<?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon si-teal"><i class="fas fa-briefcase"></i></div><div class="stat-info"><h3><?= $open_jobs ?></h3><p>Open Positions</p></div></div>
    <div class="stat-card"><div class="stat-icon si-blue"><i class="fas fa-users"></i></div><div class="stat-info"><h3><?= $total_applicants ?></h3><p>Total Applicants</p></div></div>
    <div class="stat-card"><div class="stat-icon si-orange"><i class="fas fa-hourglass-half"></i></div><div class="stat-info"><h3><?= $pending_apps ?></h3><p>Pending Review</p></div></div>
</div>

<div class="layout">
    <!-- Jobs sidebar -->
    <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.7px;margin-bottom:10px">Job Postings</div>
        <div class="jobs-list">
        <?php if(empty($jobs)): ?>
        <div style="text-align:center;padding:30px;color:var(--muted);background:#fff;border-radius:10px;border:1px dashed var(--border)">No job postings yet.</div>
        <?php endif; ?>
        <?php foreach($jobs as $j): ?>
        <a href="recruitment.php?job=<?= $j['id'] ?>" class="job-card <?= $selected_job==$j['id']?'active':'' ?>">
            <h4><?= htmlspecialchars($j['job_title']) ?></h4>
            <p><?= htmlspecialchars($j['department']??'—') ?> · <?= ucfirst(str_replace('_',' ',$j['employment_type'])) ?></p>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px">
                <span class="badge <?= $j['status']==='open'?'badge-teal':($j['status']==='on_hold'?'badge-orange':'badge-gray') ?>"><?= ucfirst(str_replace('_',' ',$j['status'])) ?></span>
                <span style="font-size:11px;color:var(--muted)"><i class="fas fa-users" style="margin-right:3px"></i><?= $j['applicant_count'] ?></span>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Applicants area -->
    <div>
    <?php if(!$selected_job): ?>
    <div class="card">
        <div class="no-select-msg">
            <i class="fas fa-hand-point-left"></i>
            <p>Select a job posting to view applicants.</p>
            <span class="post-job-help">No posting yet? Create one now.</span>
            <button type="button" onclick="openAddJobModal()" class="btn post-job-cta">
                <i class="fas fa-plus"></i> Post Job
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <h2><i class="fas fa-briefcase"></i> <?= htmlspecialchars($job_detail['job_title']??'') ?></h2>
            <div style="display:flex;gap:8px">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="update_job_status">
                    <input type="hidden" name="job_id" value="<?= $selected_job ?>">
                    <select name="job_status" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--border);border-radius:7px;font-size:12px;font-family:inherit">
                        <option value="open" <?= ($job_detail['status']??'')==='open'?'selected':'' ?>>Open</option>
                        <option value="on_hold" <?= ($job_detail['status']??'')==='on_hold'?'selected':'' ?>>On Hold</option>
                        <option value="closed" <?= ($job_detail['status']??'')==='closed'?'selected':'' ?>>Closed</option>
                    </select>
                </form>
            </div>
        </div>
        <div style="padding:14px 20px;font-size:12px;color:var(--muted);display:flex;gap:24px;flex-wrap:wrap">
            <span><i class="fas fa-building" style="margin-right:5px"></i><?= htmlspecialchars($job_detail['department']??'—') ?></span>
            <span><i class="fas fa-map-marker-alt" style="margin-right:5px"></i><?= htmlspecialchars($job_detail['location']??'—') ?></span>
            <?php if($job_detail['salary_range_min']): ?><span><i class="fas fa-money-bill" style="margin-right:5px"></i>₱<?= number_format($job_detail['salary_range_min'],0) ?> – ₱<?= number_format($job_detail['salary_range_max'],0) ?></span><?php endif; ?>
            <span><i class="fas fa-calendar" style="margin-right:5px"></i>Closes <?= $job_detail['closing_date'] ? date('M d, Y', strtotime($job_detail['closing_date'])) : 'N/A' ?></span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-users"></i> Applicants (<?= count($applicants) ?>)</h2>
        </div>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Name</th><th>Contact</th><th>Applied</th><th>Interview</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if(empty($applicants)): ?>
            <tr><td colspan="6"><div class="empty-state"><i class="fas fa-users" style="font-size:36px;opacity:.2;display:block;margin-bottom:10px"></i><p>No applicants yet.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach($applicants as $a): ?>
            <tr>
                <td><strong><?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($a['email']) ?></small></td>
                <td style="font-size:12px"><?= htmlspecialchars($a['phone']??'—') ?></td>
                <td style="font-size:12px"><?= date('M d, Y', strtotime($a['application_date'])) ?></td>
                <td style="font-size:12px"><?= $a['interview_date'] ? date('M d h:i A', strtotime($a['interview_date'])) : '—' ?></td>
                <td><span class="badge <?= $a['status']==='hired'?'badge-green':($a['status']==='rejected'?'badge-red':($a['status']==='interviewed'?'badge-blue':($a['status']==='reviewed'?'badge-orange':'badge-gray'))) ?>"><?= ucfirst($a['status']) ?></span></td>
                <td><button onclick="openAppModal(<?= htmlspecialchars(json_encode($a)) ?>)" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- Add Job Modal -->
<div class="modal-overlay" id="addJobModal">
<div class="modal">
    <h3><i class="fas fa-briefcase" style="color:var(--primary)"></i> Post a Job</h3>
    <form method="POST" id="addJobForm">
    <input type="hidden" name="action" value="add_job">
    <div class="form-grid">
        <div class="form-group full"><label>Job Title *</label><input type="text" name="job_title" required></div>
        <div class="form-group"><label>Department</label><input type="text" name="department"></div>
        <div class="form-group full"><label>Job Description</label><textarea name="job_description" rows="3" style="resize:vertical"></textarea></div>
        <div class="form-group full"><label>Requirements</label><textarea name="requirements" rows="3" style="resize:vertical"></textarea></div>
        <div class="form-group"><label>Min Salary (₱)</label><input type="number" name="salary_min" min="0"></div>
        <div class="form-group"><label>Max Salary (₱)</label><input type="number" name="salary_max" min="0"></div>
        <div class="form-group"><label>Employment Type</label>
            <select name="employment_type">
                <option value="full_time">Full Time</option>
                <option value="part_time">Part Time</option>
                <option value="contract">Contract</option>
                <option value="internship">Internship</option>
            </select>
        </div>
        <div class="form-group"><label>Closing Date</label><input type="date" name="closing_date"></div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="closeAddJobModal()" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Post Job</button>
    </div>
    </form>
</div>
</div>

<!-- Edit Applicant Modal -->
<div class="modal-overlay" id="appModal">
<div class="modal">
    <h3><i class="fas fa-user" style="color:var(--primary)"></i> Update Applicant</h3>
    <form method="POST">
    <input type="hidden" name="action" value="update_applicant">
    <input type="hidden" name="applicant_id" id="app_id">
    <div class="form-group"><label>Status</label>
        <select name="app_status" id="app_status">
            <option value="pending">Pending</option>
            <option value="reviewed">Reviewed</option>
            <option value="interviewed">Interviewed</option>
            <option value="hired">Hired</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>
    <div class="form-group"><label>Notes</label><textarea name="notes" id="app_notes" rows="3" style="resize:vertical"></textarea></div>
    <div class="modal-footer">
        <button type="button" onclick="document.getElementById('appModal').classList.remove('active')" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
    </div>
    </form>
</div>
</div>

<script>
const addJobModal = document.getElementById('addJobModal');
const addJobForm = document.getElementById('addJobForm');
const addJobDraftKey = 'pestify_recruitment_job_draft_<?= (int)$pid ?>';

function saveAddJobDraft(forceOpenState = null) {
    if (!addJobForm) return;
    const payload = {};
    addJobForm.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(el) {
        if (el.name === 'action') return;
        payload[el.name] = el.value;
    });
    payload.__open = forceOpenState !== null ? !!forceOpenState : !!(addJobModal && addJobModal.classList.contains('active'));
    localStorage.setItem(addJobDraftKey, JSON.stringify(payload));
}

function restoreAddJobDraft() {
    if (!addJobForm) return;
    const raw = localStorage.getItem(addJobDraftKey);
    if (!raw) return;
    try {
        const payload = JSON.parse(raw);
        addJobForm.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(el) {
            if (el.name === 'action') return;
            if (Object.prototype.hasOwnProperty.call(payload, el.name)) {
                el.value = payload[el.name];
            }
        });
        if (payload.__open && addJobModal) {
            addJobModal.classList.add('active');
        }
    } catch (e) {}
}

function clearAddJobDraft() {
    localStorage.removeItem(addJobDraftKey);
}

function openAddJobModal() {
    if (!addJobModal) return;
    addJobModal.classList.add('active');
    saveAddJobDraft(true);
}

function closeAddJobModal() {
    if (!addJobModal) return;
    addJobModal.classList.remove('active');
    saveAddJobDraft(false);
}

function openAppModal(a){
    document.getElementById('app_id').value=a.id;
    document.getElementById('app_status').value=a.status;
    document.getElementById('app_notes').value=a.notes||'';
    document.getElementById('appModal').classList.add('active');
}

restoreAddJobDraft();

if (addJobForm) {
    addJobForm.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(el) {
        if (el.name === 'action') return;
        el.addEventListener('input', function() { saveAddJobDraft(); });
        el.addEventListener('change', function() { saveAddJobDraft(); });
    });
    addJobForm.addEventListener('submit', clearAddJobDraft);
}

document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){
    if(e.target===this){
        this.classList.remove('active');
        if (this.id === 'addJobModal') saveAddJobDraft(false);
    }
}));
</script>
</body></html>
