<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['welcome_flash'])):
    $welcomeName = (string)$_SESSION['welcome_flash'];
    unset($_SESSION['welcome_flash']);
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Swal === 'undefined') return;

        Swal.fire({
            icon: 'success',
            title: 'Login successful',
            text: <?php echo json_encode('Welcome back, ' . $welcomeName . '!'); ?>,
            timer: 2200,
            timerProgressBar: true,
            showConfirmButton: false
        });
    });
</script>
<?php endif; ?>
