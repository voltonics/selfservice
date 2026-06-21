<?php
require_once __DIR__ . '/auth_helper.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
