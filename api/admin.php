<?php
declare(strict_types=1);

/**
 * Admin endpoint: session login plus CRUD over records, jobs and settings.
 *
 * Contract expected by the bundled admin screen:
 *   GET                     -> 200 when signed in, 401 otherwise
 *   POST {action:"login"}   -> sets a session cookie
 *   POST {action:"record"}  -> create or update a record
 *   POST {action:"delete"}  -> remove a record
 *   POST {action:"job"}     -> publish a job
 *   POST {action:"jobstatus"} -> open/close a job
 *   POST {action:"settings"}  -> update contact details and social links
 *   GET ?pdf=<id>           -> stream the application PDF
 */

require_once __DIR__ . '/lib/Store.php';

const LOGIN_MAX_ATTEMPTS = 6;
const LOGIN_WINDOW = 900;          // 15 minutes
const SESSION_IDLE_LIMIT = 7200;   // 2 hours

$store = new Store(__DIR__ . '/data', __DIR__ . '/public.json');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Reject state-changing requests that did not originate from this site. */
function assertSameOrigin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;   // same-origin fetches may omit Origin
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
    $originPort = parse_url($origin, PHP_URL_PORT);
    $expected = $originHost . ($originPort ? ':' . $originPort : '');
    if (!hash_equals($host, $expected)) {
        respond(403, ['error' => 'Μη επιτρεπτή προέλευση αιτήματος.']);
    }
}

function requireSession(): void
{
    if (empty($_SESSION['admin'])) {
        respond(401, ['error' => 'Απαιτείται σύνδεση.']);
    }
    if (isset($_SESSION['seen']) && time() - (int) $_SESSION['seen'] > SESSION_IDLE_LIMIT) {
        session_destroy();
        respond(401, ['error' => 'Η συνεδρία έληξε. Συνδέσου ξανά.']);
    }
    $_SESSION['seen'] = time();
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Strict',
]);
session_name('HFSADMIN');
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = $method === 'POST' ? (file_get_contents('php://input') ?: '') : '';
$input = $raw === '' ? [] : (json_decode($raw, true) ?: []);
if ($raw !== '' && !is_array($input)) {
    respond(400, ['error' => 'Μη έγκυρα δεδομένα.']);
}

$action = (string) ($input['action'] ?? $_GET['action'] ?? '');

// ---------------------------------------------------------------- PDF stream
if ($method === 'GET' && isset($_GET['pdf'])) {
    requireSession();
    $record = $store->record((string) $_GET['pdf']);
    if ($record === null) {
        respond(404, ['error' => 'Η εγγραφή δεν βρέθηκε.']);
    }
    $path = $store->pdfPath($record['pdf'] ?? null);
    if ($path === null) {
        respond(404, ['error' => 'Το PDF δεν βρέθηκε.']);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// --------------------------------------------------------------------- login
if ($method === 'POST' && $action === 'login') {
    assertSameOrigin();
    $ip = clientIp();
    if (!$store->throttle('login:' . ($ip !== '' ? $ip : 'unknown'), LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW)) {
        respond(429, ['error' => 'Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε λίγα λεπτά.']);
    }
    $email = (string) ($input['email'] ?? '');
    $password = (string) ($input['password'] ?? '');
    if (!$store->verifyCredentials($email, $password)) {
        respond(401, ['error' => 'Λάθος email ή κωδικός.']);
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['seen'] = time();
    respond(200, ['ok' => true]);
}

// -------------------------------------------------------------------- logout
if ($method === 'POST' && $action === 'logout') {
    assertSameOrigin();
    $_SESSION = [];
    session_destroy();
    respond(200, ['ok' => true]);
}

// ------------------------------------------------------------------- session
if ($method === 'GET' && $action === '') {
    if (empty($_SESSION['admin'])) {
        respond(401, ['error' => 'Απαιτείται σύνδεση.']);
    }
    requireSession();
    // The admin screen loads its whole dataset from this one call.
    respond(200, [
        'ok' => true,
        'records' => $store->records(),
        'jobs' => $store->jobs(),
        'settings' => $store->settings(),
    ]);
}

requireSession();
assertSameOrigin();

// --------------------------------------------------------------------- admin
switch ($action) {
    case 'record':
        $id = trim((string) ($input['id'] ?? ''));
        $patch = [
            'kind' => ($input['kind'] ?? 'candidate') === 'employer' ? 'employer' : 'candidate',
            'name' => clean($input['name'] ?? '', 150),
            'area' => clean($input['area'] ?? '', 150),
            'email' => clean($input['email'] ?? '', 200),
            'phone' => clean($input['phone'] ?? '', 40),
            'company' => clean($input['company'] ?? '', 150),
            'count' => clean($input['count'] ?? '', 10),
            'details' => clean($input['details'] ?? '', 1800),
            'status' => clean($input['status'] ?? 'Νέο', 40),
            'notes' => clean($input['notes'] ?? '', 2500),
        ];

        if ($id !== '') {
            $updated = $store->updateRecord($id, $patch);
            if ($updated === null) {
                respond(404, ['error' => 'Η εγγραφή δεν βρέθηκε.']);
            }
            respond(200, ['ok' => true, 'record' => $updated]);
        }

        if ($patch['name'] === '') {
            respond(422, ['error' => 'Το όνομα είναι υποχρεωτικό.']);
        }
        $created = $store->addRecord($patch + ['consent' => true, 'source' => 'admin']);
        respond(200, ['ok' => true, 'record' => $created]);

    case 'delete':
        $id = trim((string) ($input['id'] ?? ''));
        if ($id === '' || !$store->deleteRecord($id)) {
            respond(404, ['error' => 'Η εγγραφή δεν βρέθηκε.']);
        }
        respond(200, ['ok' => true]);

    case 'job':
        $title = clean($input['title'] ?? '', 150);
        $area = clean($input['area'] ?? '', 100);
        $type = clean($input['type'] ?? '', 100);
        $description = clean($input['description'] ?? '', 2500);
        if ($title === '' || $area === '' || $description === '') {
            respond(422, ['error' => 'Τίτλος, περιοχή και περιγραφή είναι υποχρεωτικά.']);
        }
        $job = $store->addJob([
            'title' => $title,
            'area' => $area,
            'type' => $type,
            'salary' => clean($input['salary'] ?? '', 100),
            'description' => $description,
            'status' => 'open',
        ]);
        respond(200, ['ok' => true, 'job' => $job]);

    case 'jobstatus':
        $id = trim((string) ($input['id'] ?? ''));
        $status = (string) ($input['status'] ?? 'open');
        if ($id === '' || !$store->setJobStatus($id, $status)) {
            respond(404, ['error' => 'Η αγγελία δεν βρέθηκε.']);
        }
        respond(200, ['ok' => true]);

    case 'settings':
        // Only touch the fields the form actually submitted; a partial update
        // must not blank the others.
        $patch = [];
        foreach (['phone' => 40, 'email' => 200, 'address' => 300] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $patch[$key] = clean($input[$key], $max);
            }
        }
        foreach (['google', 'facebook', 'instagram', 'linkedin'] as $key) {
            if (array_key_exists($key, $input)) {
                $patch[$key] = cleanUrl($input[$key]);
            }
        }
        $settings = $store->updateSettings($patch);
        respond(200, ['ok' => true, 'settings' => $settings]);

    default:
        respond(400, ['error' => 'Άγνωστη ενέργεια.']);
}

function clean(mixed $value, int $max): string
{
    $value = is_scalar($value) ? (string) $value : '';
    $value = str_replace(["\r\n", "\r"], "\n", $value);

    return substr(trim(strip_tags($value)), 0, $max);
}

function cleanUrl(mixed $value): string
{
    $url = trim(is_scalar($value) ? (string) $value : '');
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }

    return filter_var($url, FILTER_VALIDATE_URL) ? substr($url, 0, 300) : '';
}
