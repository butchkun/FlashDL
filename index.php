<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$p = (string)($_GET['p'] ?? 'home');

switch ($p) {
    case 'home':     require __DIR__ . '/pages/home.php'; break;
    case 'login':    require __DIR__ . '/pages/login.php'; break;
    case 'logout':   require __DIR__ . '/pages/logout.php'; break;
    case 'register': require __DIR__ . '/pages/register.php'; break;
    case 'upload':   require __DIR__ . '/pages/upload.php'; break;
    case 'download': require __DIR__ . '/pages/download.php'; break;
    case 'admin':    require __DIR__ . '/pages/admin.php'; break;
    case 'api_upload': require __DIR__ . '/pages/api_upload.php'; break;
	case 'my_uploads': require __DIR__ . '/pages/my_uploads.php'; break;
    default:
        http_response_code(404);
        echo "Not found";
}
