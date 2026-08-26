<?php
// includes/welcome_flash.php
// Drop <?php include 'includes/welcome_flash.php'; ?> right after <body> on any page
// The toast shows once after login then disappears automatically.

if (!empty($_SESSION['welcome_flash'])):
    $name = htmlspecialchars($_SESSION['welcome_flash']);
    unset($_SESSION['welcome_flash']); // consume — shows only once
?>
<style>
    #welcomeToast {
        position: fixed;
        top: 24px;
        right: 24px;
        z-index: 999999;
        display: flex;
        align-items: center;
        gap: 14px;
        background: #ffffff;
        border-radius: 16px;
        padding: 18px 22px;
        min-width: 300px;
        max-width: 400px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05);
        animation: wfSlideIn 0.45s cubic-bezier(0.34,1.56,0.64,1) forwards,
                   wfFadeOut 0.5s ease 4.5s forwards;
        border-left: 5px solid #1e2d40;
    }
    .wf-icon {
        width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0;
        background: linear-gradient(135deg, #1e2d40, #2d4a6b);
        display: flex; align-items: center; justify-content: center;
        font-size: 20px; color: white;
    }
    .wf-body { flex: 1; }
    .wf-title {
        font-family: 'Plus Jakarta Sans', 'DM Sans', sans-serif;
        font-size: 15px; font-weight: 800; color: #1e2d40;
        margin-bottom: 3px;
    }
    .wf-sub {
        font-family: 'Plus Jakarta Sans', 'DM Sans', sans-serif;
        font-size: 13px; color: #64748b; line-height: 1.5;
    }
    .wf-close {
        background: none; border: none; cursor: pointer;
        color: #94a3b8; font-size: 16px; padding: 4px;
        flex-shrink: 0; transition: color 0.2s;
    }
    .wf-close:hover { color: #1e2d40; }
    /* Progress bar */
    .wf-progress {
        position: absolute; bottom: 0; left: 0;
        height: 3px; background: #1e2d40; border-radius: 0 0 0 16px;
        animation: wfProgress 5s linear forwards;
        width: 100%;
    }
    @keyframes wfSlideIn {
        from { opacity: 0; transform: translateX(80px) scale(0.92); }
        to   { opacity: 1; transform: translateX(0) scale(1); }
    }
    @keyframes wfFadeOut {
        from { opacity: 1; transform: translateX(0); }
        to   { opacity: 0; transform: translateX(60px); pointer-events: none; }
    }
    @keyframes wfProgress {
        from { width: 100%; }
        to   { width: 0%; }
    }
</style>

<div id="welcomeToast">
    <div class="wf-icon">👋</div>
    <div class="wf-body">
        <div class="wf-title">Welcome back, <?php echo $name; ?>!</div>
        <div class="wf-sub">Great to see you again. You're all set! 🎉</div>
    </div>
    <button class="wf-close" onclick="document.getElementById('welcomeToast').remove()">
        <i class="fas fa-times"></i>
    </button>
    <div class="wf-progress"></div>
</div>

<script>
    setTimeout(function() {
        const t = document.getElementById('welcomeToast');
        if (t) t.remove();
    }, 5000);
</script>

<?php endif; ?>