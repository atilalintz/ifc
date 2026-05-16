<?php
// auth/logout.php
require_once __DIR__ . '/../config/oauth.php';
require_once __DIR__ . '/../config/session.php';

logout();
session_destroy();

header('Location: ' . APP_PATH . '/auth/login');
exit;
