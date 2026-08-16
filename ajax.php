<?php
// E:\PAYROLL\ajax.php
// Centralized JSON API. Every authenticated page form funnels its save/edit/
// delete through here: POST the form fields (with csrf_token) to
// `ajax.php` (+ the current query string, which carries site_id/date/
// payroll_id) and read `{ok, type, msg, render, data}` back.
//
// Handlers live in config/actions.php; this file only handles bootstrap,
// auth, CSRF and JSON (de)serialization.

ini_set('display_errors', '0');
error_reporting(0);
ob_start();

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/actions.php';

function json_out($payload, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

payroll_session_start();

if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'type' => 'danger', 'msg' => 'Not logged in.', 'render' => null, 'data' => []], 401);
}

$is_admin = currentUserRole() === 'admin';
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

// State-changing calls (everything except the read-only DTR day fetch)
// must carry a valid CSRF token; the login page's token comes from the
// session that inc/header.php established.
if ($method === 'POST' && $action !== 'dtr.get_day') {
    $token = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($token, (string)($_POST['csrf_token'] ?? ''))) {
        json_out(['ok' => false, 'type' => 'danger', 'msg' => 'Session expired or invalid request token.', 'render' => null, 'data' => []], 419);
    }
}

$ctx = [
    'post'       => $_POST,
    'get'        => $_GET,
    'method'     => $method,
    'is_admin'   => $is_admin,
    'user_id'    => (int)($_SESSION['user_id'] ?? 0),
    'site_id'    => (int)($_GET['site_id'] ?? 0),
    'payroll_id' => (int)($_GET['payroll_id'] ?? 0),
    'date'       => (string)($_GET['date'] ?? ''),
];

$result = run_action($action, $ctx);
json_out($result);