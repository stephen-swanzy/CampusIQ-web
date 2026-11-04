<?php
require_once __DIR__ . '/includes/auth.php';

logoutUser();
redirect('/login.php');
