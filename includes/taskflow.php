<?php
declare(strict_types=1);

/* -------------------------------------------------------------------------
   TaskFlow — Exercise #2
   A one-page task manager built with PHP, HTML, CSS, and JavaScript.

   Data is stored in a small, PHP-guarded JSON file. No database required.
   ------------------------------------------------------------------------- */

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isSecure,
]);
session_start();

define('STORAGE_FILE', dirname(__DIR__) . '/storage/tasks.php');
const MAX_TITLE_LENGTH = 120;
const MAX_TASKS = 200;
const PRIORITIES = ['low', 'medium', 'high'];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function is_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function seed_tasks(): array
{
    $today = new DateTimeImmutable('today');
    $now = new DateTimeImmutable('now');

    $make = static function (string $title, string $priority, DateTimeImmutable $due, bool $done) use ($now): array {
        return [
            'id' => bin2hex(random_bytes(8)),
            'title' => $title,
            'priority' => $priority,
            'due' => $due->format('Y-m-d'),
            'done' => $done,
            'created_at' => $now->format(DATE_ATOM),
        ];
    };

    return [
        $make('Read the Exercise #2 brief', 'high', $today, true),
        $make('Sketch the one-page layout', 'medium', $today->modify('+1 day'), false),
        $make('Wire up PHP storage and AJAX', 'high', $today->modify('+2 days'), false),
        $make('Take screenshots for the README', 'low', $today->modify('+3 days'), false),
    ];
}

function sort_tasks(array $tasks): array
{
    usort($tasks, static function (array $a, array $b): int {
        if ($a['done'] !== $b['done']) {
            return $a['done'] ? 1 : -1;
        }

        $dueA = $a['due'] ?? '9999-12-31';
        $dueB = $b['due'] ?? '9999-12-31';

        if ($dueA !== $dueB) {
            return strcmp($dueA, $dueB);
        }

        return strcmp($b['created_at'], $a['created_at']);
    });

    return $tasks;
}

function load_tasks(): array
{
    if (!is_file(STORAGE_FILE)) {
        $tasks = seed_tasks();
        save_tasks($tasks);
        return $tasks;
    }

    $raw = file_get_contents(STORAGE_FILE);
    if ($raw === false) {
        throw new RuntimeException('Could not read the task file.');
    }

    $json = preg_replace('/^<\?php\s+exit;\s*\?>\s*/', '', $raw, 1);
    if ($json === null) {
        $json = '';
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $tasks = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
        $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';

        if ($id === '' || $title === '') {
            continue;
        }

        $priority = isset($item['priority'])
            && is_string($item['priority'])
            && in_array($item['priority'], PRIORITIES, true)
            ? $item['priority']
            : 'medium';

        $due = isset($item['due'])
            && is_string($item['due'])
            && is_valid_date($item['due'])
            ? $item['due']
            : null;

        $tasks[] = [
            'id' => $id,
            'title' => $title,
            'priority' => $priority,
            'due' => $due,
            'done' => !empty($item['done']),
            'created_at' => isset($item['created_at']) && is_string($item['created_at'])
                ? $item['created_at']
                : date(DATE_ATOM),
        ];
    }

    return sort_tasks($tasks);
}

function save_tasks(array $tasks): void
{
    $directory = dirname(STORAGE_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the storage folder.');
    }

    $json = json_encode(
        array_values($tasks),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('Could not encode the task list.');
    }

    $payload = "<?php exit; ?>\n" . $json . "\n";
    $handle = fopen(STORAGE_FILE, 'c');

    if ($handle === false) {
        throw new RuntimeException('Could not open the task file.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the task file.');
        }

        ftruncate($handle, 0);
        rewind($handle);

        if (fwrite($handle, $payload) === false) {
            throw new RuntimeException('Could not write the task file.');
        }

        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function task_stats(array $tasks): array
{
    $total = count($tasks);
    $done = 0;

    foreach ($tasks as $task) {
        if (!empty($task['done'])) {
            $done++;
        }
    }

    $active = $total - $done;
    $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;

    return [
        'total' => $total,
        'done' => $done,
        'active' => $active,
        'percent' => $percent,
    ];
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return stripos($accept, 'application/json') !== false
        || strtolower($requestedWith) === 'xmlhttprequest';
}

function redirect_to(string $query = ''): void
{
    header('Location: index.html' . ($query !== '' ? '?' . $query : ''));
    exit;
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
