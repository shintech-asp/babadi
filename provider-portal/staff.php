<?php
// provider-portal/staff.php — retired. Adding/promoting staff now happens
// entirely on employees.php (Add Employee + Promote/Demote), so there's only
// one page to manage anyone, promoted or not.
if (session_status() === PHP_SESSION_NONE) session_start();
header('Location: employees.php');
exit();
