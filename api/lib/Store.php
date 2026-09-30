<?php
declare(strict_types=1);

/**
 * File-backed store for applications, jobs and settings.
 *
 * The site is a static bundle, so there is no database. Everything lives under
 * api/data/ as JSON plus one PDF per application. Writes go through flock() so
 * concurrent submissions cannot clobber each other.
 */
final class Store
{
    private string $dir;
    private string $pdfDir;
    private string $publicJsonPath;

    public function __construct(string $dir, ?string $publicJsonPath = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->pdfDir = $this->dir . '/pdfs';
        $this->publicJsonPath = $publicJsonPath ?? ($this->dir . '/public.json');
        foreach ([$this->dir, $this->pdfDir] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create data directory: ' . $path);
            }
        }
        $publicDir = dirname($this->publicJsonPath);
        if (!is_dir($publicDir) && !@mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
            throw new RuntimeException('Cannot create public directory: ' . $publicDir);
        }
    }

    public function pdfDir(): string
    {
        return $this->pdfDir;
    }

    // ---------------------------------------------------------------- records

    /** @return array<int,array<string,mixed>> */
    public function records(): array
    {
        return $this->readJson('records.json', []);
    }

    public function record(string $id): ?array
    {
        foreach ($this->records() as $record) {
            if (($record['id'] ?? '') === $id) {
                return $record;
            }
        }

        return null;
    }

    public function addRecord(array $record): array
    {
        $record['id'] = $record['id'] ?? $this->newId('HFS');
        $record['createdAt'] = $record['createdAt'] ?? gmdate('c');
        // The bundled admin reads created_at, so keep both spellings in sync.
        $record['created_at'] = $record['created_at'] ?? $record['createdAt'];
        $record['status'] = $record['status'] ?? 'Νέο';
        $record['notes'] = $record['notes'] ?? '';

        $this->mutate('records.json', function (array $rows) use ($record): array {
            array_unshift($rows, $record);

            return $rows;
        });
        $this->refreshPublicSnapshot();

        return $record;
    }

    public function updateRecord(string $id, array $patch): ?array
    {
        $updated = null;
        $this->mutate('records.json', function (array $rows) use ($id, $patch, &$updated): array {
            foreach ($rows as $i => $row) {
                if (($row['id'] ?? '') !== $id) {
                    continue;
                }
                $allowed = ['status', 'notes', 'name', 'area', 'email', 'phone', 'company', 'count', 'details', 'pdf'];
                foreach ($allowed as $key) {
                    if (array_key_exists($key, $patch)) {
                        $rows[$i][$key] = $patch[$key];
                    }
                }
                $rows[$i]['updatedAt'] = gmdate('c');
                $updated = $rows[$i];
            }

            return $rows;
        });

        return $updated;
    }

    public function deleteRecord(string $id): bool
    {
        $found = false;
        $pdfName = null;
        $this->mutate('records.json', function (array $rows) use ($id, &$found, &$pdfName): array {
            $kept = [];
            foreach ($rows as $row) {
                if (($row['id'] ?? '') === $id) {
                    $found = true;
                    $pdfName = $row['pdf'] ?? null;
                    continue;
                }
                $kept[] = $row;
            }

            return $kept;
        });

        if ($found && $pdfName !== null) {
            $path = $this->pdfPath($pdfName);
            if ($path !== null) {
                @unlink($path);
            }
        }
        if ($found) {
            $this->refreshPublicSnapshot();
        }

        return $found;
    }

    // ------------------------------------------------------------------- jobs

    /** @return array<int,array<string,mixed>> */
    public function jobs(): array
    {
        return $this->readJson('jobs.json', []);
    }

    public function addJob(array $job): array
    {
        $job['id'] = $job['id'] ?? $this->newId('JOB');
        $job['createdAt'] = gmdate('c');
        $job['status'] = $job['status'] ?? 'open';
        $this->mutate('jobs.json', function (array $rows) use ($job): array {
            array_unshift($rows, $job);

            return $rows;
        });
        $this->refreshPublicSnapshot();

        return $job;
    }

    public function setJobStatus(string $id, string $status): bool
    {
        $status = in_array($status, ['open', 'closed'], true) ? $status : 'open';
        $found = false;
        $this->mutate('jobs.json', function (array $rows) use ($id, $status, &$found): array {
            foreach ($rows as $i => $row) {
                if (($row['id'] ?? '') === $id) {
                    $rows[$i]['status'] = $status;
                    $found = true;
                }
            }

            return $rows;
        });
        if ($found) {
            $this->refreshPublicSnapshot();
        }

        return $found;
    }

    // --------------------------------------------------------------- settings

    public function settings(): array
    {
        return $this->readJson('settings.json', [
            'phone' => '+30 2107499385',
            'email' => 'contact@humanforcesolutions.com',
            'address' => '',
            'google' => '',
            'facebook' => '',
            'instagram' => '',
            'linkedin' => '',
        ]);
    }

    public function updateSettings(array $patch): array
    {
        $allowed = ['phone', 'email', 'address', 'google', 'facebook', 'instagram', 'linkedin'];
        $settings = $this->settings();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $patch)) {
                $settings[$key] = (string) $patch[$key];
            }
        }
        $this->writeJson('settings.json', $settings);
        $this->refreshPublicSnapshot();

        return $settings;
    }

    // ------------------------------------------------------------------- auth

    public function credentials(): ?array
    {
        $file = $this->dir . '/auth.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    public function setCredentials(string $email, string $passwordHash): void
    {
        $this->writeJson('auth.json', [
            'email' => $email,
            'hash' => $passwordHash,
            'updatedAt' => gmdate('c'),
        ]);
    }

    public function verifyCredentials(string $email, string $password): bool
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return false;
        }
        $emailOk = hash_equals(strtolower((string) $creds['email']), strtolower(trim($email)));

        // Always run the hash check so a wrong email and a wrong password cost
        // the same amount of time.
        $passwordOk = password_verify($password, (string) $creds['hash']);

        return $emailOk && $passwordOk;
    }

    // -------------------------------------------------------------- rate limit

    /** Returns false once $max hits happen inside $windowSeconds for $key. */
    public function throttle(string $key, int $max, int $windowSeconds): bool
    {
        $file = $this->dir . '/throttle.json';
        $now = time();
        $allow = true;
        $this->withLock($file, function (string $contents) use (&$allow, $key, $max, $windowSeconds, $now): string {
            $data = json_decode($contents, true);
            $data = is_array($data) ? $data : [];
            $hits = array_values(array_filter(
                $data[$key] ?? [],
                static fn ($ts): bool => is_int($ts) && $ts > $now - $windowSeconds
            ));
            if (count($hits) >= $max) {
                $allow = false;
            } else {
                $hits[] = $now;
            }
            $data[$key] = $hits;

            // Drop buckets that have fully aged out.
            foreach ($data as $bucket => $stamps) {
                $live = array_filter($stamps, static fn ($ts): bool => is_int($ts) && $ts > $now - $windowSeconds);
                if ($live === []) {
                    unset($data[$bucket]);
                } else {
                    $data[$bucket] = array_values($live);
                }
            }

            return (string) json_encode($data);
        });

        return $allow;
    }

    // -------------------------------------------------------------------- pdf

    public function savePdf(string $id, string $bytes): string
    {
        $name = $this->safeName($id) . '.pdf';
        if (@file_put_contents($this->pdfDir . '/' . $name, $bytes) === false) {
            throw new RuntimeException('Cannot write PDF.');
        }
        @chmod($this->pdfDir . '/' . $name, 0640);

        return $name;
    }

    public function pdfPath(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $safe = $this->safeName($name);
        $path = $this->pdfDir . '/' . $safe;

        return is_file($path) ? $path : null;
    }

    // -------------------------------------------------------------- public API

    /**
     * Rewrite api/public.json, the snapshot the static bundle reads for jobs and
     * settings. Keeping it a plain file means the front end needs no changes.
     */
    public function refreshPublicSnapshot(): void
    {
        $jobs = array_values(array_filter($this->jobs(), static fn (array $j): bool => ($j['status'] ?? 'open') === 'open'));
        $this->writeJsonFile($this->publicJsonPath, [
            'jobs' => array_map(static fn (array $j): array => [
                'id' => $j['id'] ?? '',
                'title' => $j['title'] ?? '',
                'area' => $j['area'] ?? '',
                'type' => $j['type'] ?? '',
                'salary' => $j['salary'] ?? '',
                'description' => $j['description'] ?? '',
            ], $jobs),
            'settings' => $this->settings(),
        ]);
    }

    public function publicJsonPath(): string
    {
        return $this->publicJsonPath;
    }

    // ----------------------------------------------------------------- helpers

    private function newId(string $prefix): string
    {
        return sprintf(
            '%s-%s-%s',
            $prefix,
            gmdate('Ymd-His'),
            strtoupper(bin2hex(random_bytes(3)))
        );
    }

    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? '';

        return $clean === '' ? 'unknown' : $clean;
    }

    /** @return array<int|string,mixed> */
    private function readJson(string $file, array $fallback): array
    {
        $path = $this->dir . '/' . $file;
        if (!is_file($path)) {
            return $fallback;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : $fallback;
    }

    private function writeJson(string $file, array $data): void
    {
        $this->writeJsonFile($this->dir . '/' . $file, $data);
    }

    private function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Cannot encode JSON for ' . basename($path));
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Cannot write ' . basename($path));
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace ' . basename($path));
        }
    }

    /** Read-modify-write under an exclusive lock. */
    private function mutate(string $file, callable $fn): void
    {
        $path = $this->dir . '/' . $file;
        $this->withLock($path, function (string $contents) use ($fn, $path): string {
            $rows = json_decode($contents, true);
            $rows = is_array($rows) ? $rows : [];
            $rows = $fn($rows);
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new RuntimeException('Cannot encode ' . $path);
            }

            return $json;
        });
    }

    private function withLock(string $path, callable $fn): void
    {
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open ' . basename($path));
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock ' . basename($path));
            }
            $contents = (string) stream_get_contents($handle);
            $result = $fn($contents);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $result);
            fflush($handle);
            @chmod($path, 0640);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
