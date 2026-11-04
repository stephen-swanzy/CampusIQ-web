<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    $role = $_SESSION['campusiq_user']['role'];
    if ($role === 'super_admin' || $role === 'school_admin') {
        redirect('/admin/index.php');
    }
    if ($role === 'teacher') {
        redirect('/teacher/index.php');
    }
    if ($role === 'student') {
        redirect('/student/index.php');
    }
    if ($role === 'parent') {
        redirect('/parent/index.php');
    }
    if ($role === 'accountant') {
        redirect('/accountant/index.php');
    }
}

redirect('/login.php');
