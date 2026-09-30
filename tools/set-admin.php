<?php
declare(strict_types=1);

/**
 * Creates or rotates the admin credentials.
 *
 * Usage:
 *   php tools/set-admin.php you@example.com
 *
 * The password is read from stdin so it never lands in the shell history or in
 * the process list. Only a bcrypt hash is written, to api/data/auth.json.
 */

require_once __DIR__ . '/../api/lib/Store.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$email = $argv[1] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Χρήση: php tools/set-admin.php you@example.com\n");
    exit(1);
}

fwrite(STDOUT, 'Κωδικός πρόσβασης: ');
$password = readPassword();
fwrite(STDOUT, "\n");

if (strlen($password) < 10) {
    fwrite(STDERR, "Ο κωδικός πρέπει να έχει τουλάχιστον 10 χαρακτήρες.\n");
    exit(1);
}

$store = new Store(__DIR__ . '/../api/data', __DIR__ . '/../api/public.json');
$store->setCredentials($email, password_hash($password, PASSWORD_DEFAULT));

fwrite(STDOUT, "Αποθηκεύτηκε. Σύνδεση στο /admin/ με: {$email}\n");
exit(0);

/** Read a line from the terminal without echoing it. */
function readPassword(): string
{
    $isTty = function_exists('posix_isatty') && @posix_isatty(STDIN);
    if ($isTty) {
        @shell_exec('stty -echo');
    }
    $line = (string) fgets(STDIN);
    if ($isTty) {
        @shell_exec('stty echo');
    }

    return rtrim($line, "\r\n");
}
