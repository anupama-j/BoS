<?php
/** JSON endpoints used by the form and the right-hand panel. */
require_once __DIR__ . '/data_source.php';
require_once __DIR__ . '/auth.php';
require_login(true);

header('Content-Type: application/json; charset=utf-8');

switch ($_GET['action'] ?? '') {
    case 'programmes':
        echo json_encode(src_programmes(), JSON_UNESCAPED_UNICODE);
        break;
    case 'subjects':
        echo json_encode(src_subjects($_GET['programme'] ?? ''), JSON_UNESCAPED_UNICODE);
        break;
    case 'colleges':
        echo json_encode(src_colleges(), JSON_UNESCAPED_UNICODE);
        break;
    case 'session':    // the current exam session (shown locked on the form)
        $r = src_sessions();
        echo json_encode(['current' => src_current_session(), 'source' => $r['source'], 'error' => $r['error']], JSON_UNESCAPED_UNICODE);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
