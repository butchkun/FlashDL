<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

$u = current_user();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($u) log_event('logout', (int)$u['id'], $ip, '');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
session_destroy();

header('Location: /?p=login');
exit;
