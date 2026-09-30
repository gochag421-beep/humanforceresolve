<?php
declare(strict_types=1);

/**
 * Public endpoint: accepts candidate/employer applications.
 *
 * Every submission is written to api/data/records.json and rendered as a PDF in
 * api/data/pdfs/ so the office has a printable, timestamped sheet per request.
 */

require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/ApplicationPdf.php';

const MAX_FIELD_LENGTH = 1800;
const RATE_LIMIT_MAX = 8;          // submissions
const RATE_LIMIT_WINDOW = 600;     // per 10 minutes per IP

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    respond(200, ['ok' => true, 'service' => 'human-force-applications']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'Μέθοδος μη επιτρεπτή.']);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 64 * 1024) {
    respond(413, ['error' => 'Το αίτημα είναι πολύ μεγάλο.']);
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(400, ['error' => 'Μη έγκυρα δεδομένα.']);
}

$store = new Store(__DIR__ . '/data', __DIR__ . '/public.json');

$ip = clientIp();
if (!$store->throttle('apply:' . ($ip !== '' ? $ip : 'unknown'), RATE_LIMIT_MAX, RATE_LIMIT_WINDOW)) {
    respond(429, ['error' => 'Πολλές υποβολές σε σύντομο διάστημα. Δοκίμασε ξανά σε λίγα λεπτά.']);
}

// Honeypot: a real user never fills a hidden field. Pretend success.
if (trim((string) ($input['website'] ?? '')) !== '') {
    respond(200, ['ok' => true, 'id' => 'HFS-IGNORED']);
}

$kind = ($input['kind'] ?? '') === 'employer' ? 'employer' : 'candidate';
$clean = static function ($value, int $max = MAX_FIELD_LENGTH): string {
    $value = is_scalar($value) ? (string) $value : '';
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = trim(strip_tags($value));

    return substr($value, 0, $max);
};

$name = $clean($input['name'] ?? '', 150);
$area = $clean($input['area'] ?? '', 150);
$email = $clean($input['email'] ?? '', 200);
$phone = $clean($input['phone'] ?? '', 40);
$details = $clean($input['details'] ?? '', MAX_FIELD_LENGTH);
$consent = !empty($input['consent']);

$errors = [];
if ($name === '') {
    $errors['name'] = 'Συμπλήρωσε το ονοματεπώνυμό σου.';
}
if ($area === '') {
    $errors['area'] = 'Συμπλήρωσε την περιοχή σου.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Το email δεν είναι έγκυρο.';
}
if (strlen(preg_replace('/\D/', '', $phone) ?? '') < 8) {
    $errors['phone'] = 'Το τηλέφωνο δεν είναι έγκυρο.';
}
if (!$consent) {
    $errors['consent'] = 'Απαιτείται συναίνεση για την επεξεργασία των στοιχείων.';
}

$company = '';
$count = '';
if ($kind === 'employer') {
    $company = $clean($input['company'] ?? '', 150);
    $count = $clean($input['count'] ?? '', 10);
    if ($company === '') {
        $errors['company'] = 'Συμπλήρωσε την επωνυμία της επιχείρησης.';
    }
    if (!preg_match('/^\d{1,3}$/', $count) || (int) $count < 1 || (int) $count > 500) {
        $errors['count'] = 'Δώσε αριθμό ατόμων από 1 έως 500.';
    }
}

if ($errors !== []) {
    respond(422, ['error' => 'Έλεγξε τα πεδία που σημειώνονται.', 'fields' => $errors]);
}

$record = [
    'id' => null,
    'kind' => $kind,
    'name' => $name,
    'area' => $area,
    'email' => $email,
    'phone' => $phone,
    'company' => $company,
    'count' => $count,
    'details' => $details,
    'consent' => $consent,
    'ip' => $ip,
    'userAgent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
    'createdAt' => gmdate('c'),
    'status' => 'Νέο',
    'notes' => '',
];

try {
    $stored = $store->addRecord($record);
    $pdf = new ApplicationPdf(__DIR__ . '/fonts/DejaVuSans.ttf', __DIR__ . '/fonts/DejaVuSans-Bold.ttf');
    $pdfName = $store->savePdf((string) $stored['id'], $pdf->render($stored));
    $store->updateRecord((string) $stored['id'], ['pdf' => $pdfName]);
} catch (Throwable $e) {
    error_log('application failed: ' . $e->getMessage());
    respond(500, ['error' => 'Η φόρμα δεν είναι διαθέσιμη αυτή τη στιγμή. Κάλεσε στο +30 2107499385 ή στείλε email στο contact@humanforcesolutions.com.']);
}

respond(200, [
    'ok' => true,
    'id' => $stored['id'],
    'pdf' => $pdfName,
    'receivedAt' => $stored['createdAt'],
]);
