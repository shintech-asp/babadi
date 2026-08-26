<?php
// admin/hr/recruitment.php
$require_dept = 'hr';
require_once '../includes/admin-auth.php';
require_once '../../config/config.php';
require_once '../../config/database.php';
$database = new Database(); $db = $database->getConnection();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_job') {
        $pos   = trim($_POST['position']??'');
        $dept  = trim($_POST['department']??'');
        $desc  = trim($_POST['description']??'');
        $req   = trim($_POST['requirements']??'');
        $slots = (int)($_POST['slots']??1);
        $dead  = $_POST['deadline'] ?? null;
        if (!$pos) { $error='Position is required.'; }
        else {
            $db->prepare("INSERT INTO recruitment (position,department,description,requirements,slots,deadline,posted_by,status) VALUES(:p,:d,:de,:r,:s,:dl,:by,'open')")
            ->execute([':p'=>$pos,':d'=>$dept,':de'=>$desc,':r'=>$req,':s'=>$slots,':dl'=>$dead,':by'=>$_SESSION['admin_id']]);
            $success = "Job posting created.";
        }
    } elseif ($action === 'add_applicant') {
        $rid  = (int)$_POST['recruitment_id'];
        $name = trim($_POST['full_name']??'');
        $em   = trim($_POST['email']??'');
        $ph   = trim($_POST['phone']??'');
        if (!$name) { $error='Applicant name required.'; }
        else {
            $db->prepare("INSERT INTO applicants (recruitment_id,full_name,email,phone) VALUES(:r,:n,:e,:p)")
            ->execute([':r'=>$rid,':n'=>$name,':e'=>$em,':p'=>$ph]);
            $success = "Applicant added.";
        }
    } elseif ($action === 'update_stage') {
        $db->prepare("UPDATE applicants SET stage=:s WHERE id=:id")->execute([':s'=>$_POST['stage'],':id'=>(int)$_POST['app_id']]);
        $success = 'Stage updated.';
    } elseif ($action === 'close_job') {
        $db->prepare("UPDATE recruitment SET status=:s WHERE id=:id")->execute([':s'=>$_POST['job_status'],':id'=>(int)$_POST['job_id']]);
        $success = 'Job status updated.';
    }
}

$jobs = $db->query("SELECT r.*, (SELECT COUNT(*) FROM applicants a WHERE a.recruitment_id=r.id) as applicant_count FROM recruitment r ORDER BY r.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$selected_job = (int)($_GET['job'] ?? 0);
$applicants   = [];
$job_detail   = null;
if ($selected_job) {
    $js = $db->prepare("SELECT * FROM recruitment WHERE id=:id"); $js->execute([':id'=>$selected_job]);
    $job_detail = $js->fetch(PDO::FETCH_ASSOC);
    $as = $db->prepare("SELECT * FROM applicants WHERE recruitment_id=:id ORDER BY created_at DESC"); $as->execute([':id'=>$selected_job]);
    $applicants = $as->fetchAll(PDO::FETCH_ASSOC);
}

$active_menu = 'recruitment';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recruitment - Pestify HR</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#9b59b6;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-container{display:flex;min-height:100vh}.main-content{flex:1;padding:32px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.two-col{display:grid;grid-template-columns:1fr 1.4fr;gap:20px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}.btn-sm{padding:5px 11px;font-size:12px}
.alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#e9d8fd;color:#6b46c1;border-left:4px solid var(--primary)}
.alert-error{background:#fed7d7;color:#c53030;border-left:4px solid #e53e3e}
.card{background:var(--white);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-header h2{font-size:14px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:7px}
.card-header h2 i{color:var(--primary)}
.job-item{padding:16px 20px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s}
.job-item:last-child{border-bottom:none}
.job-item:hover,.job-item.active{background:#f0e6ff}
.job-title{font-weight:700;font-size:14px;color:var(--dark);margin-bottom:3px}
.job-meta{font-size:12px;color:var(--muted);display:flex;gap:12px;flex-wrap:wrap}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.b-open{background:#c6f6d5;color:#276749}.b-closed{background:#e2e8f0;color:#4a5568}.b-on_hold{background:#feebc8;color:#c05621}
.stage-badge{padding:3px 9px;border-radius:999px;font-size:10px;font-weight:700}
.s-applied{background:#e2e8f0;color:#4a5568}.s-screening{background:#bee3f8;color:#2b6cb0}
.s-interview{background:#feebc8;color:#c05621}.s-exam{background:#e9d8fd;color:#6b46c1}
.s-hired{background:#c6f6d5;color:#276749}.s-rejected{background:#fed7d7;color:#c53030}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);border-bottom:2px solid var(--border);text-align:left}
tbody td{padding:11px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:20px}.modal-foot{padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
.form-group{margin-bottom:13px}.form-label{display:block;font-size:12px;font-weight:600;color:#2d3748;margin-bottom:4px}
.form-control{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:900px){.two-col{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">
<div class="page-header">
    <h1><i class="fas fa-user-plus"></i> Recruitment</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addJobModal').classList.add('open')"><i class="fas fa-plus"></i> Post Job</button>
</div>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?=$success?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?=$error?></div><?php endif; ?>

<div class="two-col">
    <!-- Job Postings -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-briefcase"></i> Job Postings</h2><span style="font-size:12px;color:var(--muted)"><?=count($jobs)?> positions</span></div>
        <?php if (empty($jobs)): ?><div style="padding:40px;text-align:center;color:var(--muted)">No job postings yet</div><?php endif; ?>
        <?php foreach ($jobs as $j): ?>
        <a href="?job=<?=$j['id']?>" class="job-item <?=$selected_job==$j['id']?'active':''?>" style="display:block;text-decoration:none;color:inherit">
            <div class="job-title"><?=htmlspecialchars($j['position'])?></div>
            <div class="job-meta">
                <span><i class="fas fa-building"></i> <?=htmlspecialchars($j['department']??'—')?></span>
                <span><i class="fas fa-users"></i> <?=$j['applicant_count']?> applicant(s)</span>
                <span><i class="fas fa-user-tie"></i> <?=$j['slots']?> slot(s)</span>
                <?php if ($j['deadline']): ?><span><i class="fas fa-clock"></i> <?=date('M d',strtotime($j['deadline']))?></span><?php endif; ?>
            </div>
            <div style="margin-top:6px"><span class="badge b-<?=$j['status']?>"><?=ucfirst($j['status'])?></span></div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Applicants -->
    <div>
    <?php if ($job_detail): ?>
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-users"></i> <?=htmlspecialchars($job_detail['position'])?></h2>
            <div style="display:flex;gap:8px">
                <button class="btn btn-sm" style="background:#f0e6ff;color:#6b46c1" onclick="document.getElementById('addAppModal').classList.add('open')"><i class="fas fa-plus"></i> Add Applicant</button>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="close_job">
                    <input type="hidden" name="job_id" value="<?=$job_detail['id']?>">
                    <input type="hidden" name="job_status" value="<?=$job_detail['status']==='open'?'closed':'open'?>">
                    <button class="btn btn-sm" style="background:#e2e8f0;color:#4a5568" type="submit"><?=$job_detail['status']==='open'?'Close Job':'Re-open'?></button>
                </form>
            </div>
        </div>
        <?php if (!empty($job_detail['description'])): ?><div style="padding:14px 20px;font-size:13px;color:var(--muted);border-bottom:1px solid var(--border)"><?=nl2br(htmlspecialchars($job_detail['description']))?></div><?php endif; ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Stage</th><th>Date</th></tr></thead>
            <tbody>
            <?php if (empty($applicants)): ?><tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">No applicants yet</td></tr><?php endif; ?>
            <?php foreach ($applicants as $ap): ?>
            <tr>
                <td style="font-weight:600"><?=htmlspecialchars($ap['full_name'])?></td>
                <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($ap['email']??'—')?></td>
                <td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars($ap['phone']??'—')?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="update_stage">
                        <input type="hidden" name="app_id" value="<?=$ap['id']?>">
                        <select name="stage" class="stage-badge s-<?=$ap['stage']?>" onchange="this.form.submit()" style="border:none;cursor:pointer;font-family:inherit;background:transparent">
                            <?php foreach(['applied','screening','interview','exam','hired','rejected'] as $st): ?>
                            <option value="<?=$st?>" <?=$ap['stage']===$st?'selected':''?>><?=ucfirst($st)?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td style="font-size:12px;color:var(--muted)"><?=date('M d, Y',strtotime($ap['created_at']))?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:80px;color:var(--muted)"><i class="fas fa-hand-pointer" style="font-size:40px;display:block;margin-bottom:12px;opacity:.3"></i>Select a job posting to view applicants</div>
    <?php endif; ?>
    </div>
</div>

</div></div>

<!-- Add Job Modal -->
<div class="modal-overlay" id="addJobModal">
<div class="modal-box">
    <div class="modal-head"><h3><i class="fas fa-briefcase" style="color:var(--primary);margin-right:8px"></i>Post Job</h3>
    <button style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('addJobModal').classList.remove('open')">&times;</button></div>
    <form method="POST"><input type="hidden" name="action" value="add_job">
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Position *</label><input type="text" name="position" class="form-control" required></div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Department</label><input type="text" name="department" class="form-control" placeholder="e.g. Operations"></div>
            <div class="form-group"><label class="form-label">Slots</label><input type="number" name="slots" class="form-control" min="1" value="1"></div>
        </div>
        <div class="form-group"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-control"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div class="form-group"><label class="form-label">Requirements</label><textarea name="requirements" class="form-control" rows="3"></textarea></div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addJobModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post</button>
    </div></form>
</div>
</div>

<!-- Add Applicant Modal -->
<div class="modal-overlay" id="addAppModal">
<div class="modal-box">
    <div class="modal-head"><h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px"></i>Add Applicant</h3>
    <button style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('addAppModal').classList.remove('open')">&times;</button></div>
    <form method="POST"><input type="hidden" name="action" value="add_applicant"><input type="hidden" name="recruitment_id" value="<?=$selected_job?>">
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
            <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn" style="background:#f0f0f0;color:#555" onclick="document.getElementById('addAppModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add</button>
    </div></form>
</div>
</div>
<script>
['addJobModal','addAppModal'].forEach(id=>document.getElementById(id).addEventListener('click',e=>{if(e.target===document.getElementById(id))e.target.classList.remove('open')}));
</script>
</body></html>