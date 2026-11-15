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

const STORAGE_FILE = __DIR__ . '/storage/tasks.php';
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
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $wantsJson = wants_json();
    $input = $_POST;
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody === false ? '' : $rawBody, true);

        if (!is_array($decoded)) {
            if ($wantsJson) {
                json_response(['ok' => false, 'error' => 'The request body was not valid JSON.'], 400);
            }
            redirect_to('flash=error');
        }

        $input = $decoded;
    }

    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? '');

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        if ($wantsJson) {
            json_response(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 403);
        }
        redirect_to('flash=expired');
    }

    $action = isset($input['action']) && is_string($input['action']) ? $input['action'] : '';
    $message = 'Task updated.';
    $flashKey = 'updated';
    $createdId = null;

    try {
        $tasks = load_tasks();

        switch ($action) {
            case 'add':
                $title = isset($input['title']) && is_string($input['title']) ? trim($input['title']) : '';

                if ($title === '') {
                    throw new InvalidArgumentException('Give your task a title.');
                }

                if (text_length($title) > MAX_TITLE_LENGTH) {
                    throw new InvalidArgumentException('Keep the title under 120 characters.');
                }

                if (count($tasks) >= MAX_TASKS) {
                    throw new InvalidArgumentException('This list is full. Delete a task first.');
                }

                $priority = isset($input['priority']) && is_string($input['priority'])
                    ? strtolower($input['priority'])
                    : 'medium';

                if (!in_array($priority, PRIORITIES, true)) {
                    $priority = 'medium';
                }

                $due = isset($input['due']) && is_string($input['due']) ? trim($input['due']) : '';

                if ($due === '') {
                    $due = null;
                } elseif (!is_valid_date($due)) {
                    throw new InvalidArgumentException('Enter a valid due date.');
                }

                $createdId = bin2hex(random_bytes(8));
                $tasks[] = [
                    'id' => $createdId,
                    'title' => $title,
                    'priority' => $priority,
                    'due' => $due,
                    'done' => false,
                    'created_at' => date(DATE_ATOM),
                ];
                $message = 'Task added.';
                $flashKey = 'added';
                break;

            case 'toggle':
                $id = isset($input['id']) && is_string($input['id']) ? $input['id'] : '';
                $toggled = false;

                foreach ($tasks as &$task) {
                    if ($task['id'] === $id) {
                        $task['done'] = !$task['done'];
                        $message = $task['done'] ? 'Task completed. Nice.' : 'Task moved back to your list.';
                        $toggled = true;
                        break;
                    }
                }
                unset($task);

                if (!$toggled) {
                    throw new InvalidArgumentException('That task could not be found.');
                }
                break;

            case 'delete':
                $id = isset($input['id']) && is_string($input['id']) ? $input['id'] : '';
                $before = count($tasks);
                $tasks = array_values(array_filter($tasks, static function (array $task) use ($id): bool {
                    return $task['id'] !== $id;
                }));

                if (count($tasks) === $before) {
                    throw new InvalidArgumentException('That task could not be found.');
                }

                $message = 'Task deleted.';
                break;

            case 'clear_completed':
                $before = count($tasks);
                $tasks = array_values(array_filter($tasks, static function (array $task): bool {
                    return empty($task['done']);
                }));

                if (count($tasks) === $before) {
                    throw new InvalidArgumentException('There are no completed tasks to clear.');
                }

                $message = 'Completed tasks cleared.';
                break;

            default:
                throw new InvalidArgumentException('Unknown action.');
        }

        $tasks = sort_tasks($tasks);
        save_tasks($tasks);
        $stats = task_stats($tasks);

        if ($wantsJson) {
            $response = [
                'ok' => true,
                'tasks' => $tasks,
                'stats' => $stats,
                'message' => $message,
            ];

            if ($createdId !== null) {
                $response['created_id'] = $createdId;
            }

            json_response($response);
        }

        redirect_to('flash=' . $flashKey);
    } catch (InvalidArgumentException $exception) {
        if ($wantsJson) {
            json_response(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
        redirect_to('flash=error');
    } catch (Throwable $exception) {
        error_log('TaskFlow: ' . $exception->getMessage());

        if ($wantsJson) {
            json_response(['ok' => false, 'error' => 'Task storage is unavailable. Please try again.'], 500);
        }
        redirect_to('flash=error');
    }
}

$bootError = '';
try {
    $tasks = load_tasks();
} catch (Throwable $exception) {
    error_log('TaskFlow: ' . $exception->getMessage());
    $tasks = [];
    $bootError = 'Task storage is unavailable. Make sure the storage folder is writable.';
}

$stats = task_stats($tasks);

$flashMessages = [
    'added' => ['Task added.', 'success'],
    'updated' => ['Task list updated.', 'success'],
    'error' => ['That action could not be completed.', 'error'],
    'expired' => ['Your session expired. Refresh the page and try again.', 'error'],
];

$flash = null;
if (isset($_GET['flash']) && is_string($_GET['flash']) && isset($flashMessages[$_GET['flash']])) {
    $flash = [
        'message' => $flashMessages[$_GET['flash']][0],
        'type' => $flashMessages[$_GET['flash']][1],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TaskFlow — Exercise #2</title>
  <meta name="description" content="TaskFlow is a one-page task manager built with PHP, HTML, CSS, and JavaScript for Group Exercise #2." />
  <meta name="theme-color" content="#123a2f" />
  <meta name="csrf-token" content="<?= e($csrfToken) ?>" />
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/styles.css" />
  <script src="assets/app.js" defer></script>
</head>
<body>
  <header class="site-header container">
    <a class="brand" href="index.php" aria-label="TaskFlow home">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
          <path d="m5 12 4 4L19 6" />
        </svg>
      </span>
      <span class="brand-name">TaskFlow</span>
    </a>

    <div class="header-meta">
      <span class="today"><?= e(date('D, M j')) ?></span>
      <span class="exercise-pill">Group Exercise #2</span>
      <a class="icon-link" href="https://github.com/Bulbasur123" target="_blank" rel="noopener noreferrer" aria-label="GitHub profile">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 .7a11.5 11.5 0 0 0-3.64 22.4c.58.1.79-.25.79-.56v-2.2c-3.22.7-3.9-1.37-3.9-1.37-.53-1.34-1.29-1.7-1.29-1.7-1.05-.72.08-.71.08-.71 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.57-.29-5.27-1.28-5.27-5.7 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.47.11-3.06 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.77.11 3.06.74.81 1.19 1.84 1.19 3.1 0 4.43-2.7 5.4-5.28 5.69.41.36.78 1.08.78 2.18v3.23c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .7Z" />
        </svg>
      </a>
      <span class="avatar">
        <img src="assets/avatar.jpg" alt="Mark Louis Pamisa" width="38" height="38" />
      </span>
    </div>
  </header>

  <main id="main">
    <?php if ($bootError !== '' || $flash !== null): ?>
      <div class="container flash-wrap">
        <div class="flash <?= $bootError !== '' ? 'is-error' : 'is-' . e($flash['type']) ?>" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v5M12 16.5v.1" />
          </svg>
          <span><?= e($bootError !== '' ? $bootError : $flash['message']) ?></span>
        </div>
      </div>
    <?php endif; ?>

    <section class="hero container" aria-labelledby="hero-title">
      <div class="hero-copy">
        <p class="eyebrow"><span class="eyebrow-dot"></span> One page · Server side</p>
        <h1 id="hero-title">Make room for<br /><em>what matters.</em></h1>
        <p class="hero-lede">
          A small, focused task board for the things worth finishing. Add a task,
          check it off, and let PHP keep the list in sync.
        </p>
      </div>
      <div class="hero-side">
        <div class="tech-stack" aria-label="Technologies used">
          <span>PHP</span>
          <span>HTML</span>
          <span>CSS</span>
          <span>JS</span>
        </div>
        <p class="hero-note">
          No frameworks. No database. Just a hand-rolled JSON store and a clean little interface.
        </p>
      </div>
    </section>

    <section class="dashboard container" aria-label="Task dashboard">
      <aside class="panel summary-panel">
        <div class="summary-top">
          <p class="panel-kicker">Your progress</p>
          <span class="status-pill"><span class="status-dot"></span> Auto-saved</span>
        </div>

        <div class="progress-wrap">
          <div class="progress-ring" id="progress-ring" style="--progress: <?= (int) $stats['percent'] ?>%;" role="img" aria-label="<?= (int) $stats['percent'] ?>% of tasks complete">
            <div class="progress-inner">
              <strong id="progress-value"><?= (int) $stats['percent'] ?>%</strong>
              <span>complete</span>
            </div>
          </div>
          <div class="progress-copy">
            <p><strong id="stat-total"><?= (int) $stats['total'] ?></strong> tasks on your board</p>
            <div class="mini-stats">
              <span><strong id="stat-active"><?= (int) $stats['active'] ?></strong> to do</span>
              <span><strong id="stat-done"><?= (int) $stats['done'] ?></strong> done</span>
            </div>
          </div>
        </div>

        <div class="summary-divider"></div>

        <form id="task-form" class="task-form" method="post" action="index.php">
          <div class="form-heading">
            <h2>Add a task</h2>
            <span>Small steps count.</span>
          </div>

          <label class="field" for="task-title">
            <span>What needs doing?</span>
            <input id="task-title" name="title" type="text" maxlength="120" placeholder="e.g. Review the PHP exercise" required autocomplete="off" />
          </label>

          <div class="form-row">
            <label class="field" for="task-priority">
              <span>Priority</span>
              <select id="task-priority" name="priority">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
              </select>
            </label>

            <label class="field" for="task-due">
              <span>Due date <small>optional</small></span>
              <input id="task-due" name="due" type="date" />
            </label>
          </div>

          <input type="hidden" name="action" value="add" />
          <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>" />
          <button class="add-button" id="add-button" type="submit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 5v14M5 12h14" />
            </svg>
            <span>Add task</span>
          </button>
        </form>
      </aside>

      <section class="panel tasks-panel" aria-labelledby="tasks-title">
        <div class="tasks-head">
          <div>
            <p class="panel-kicker">The list</p>
            <h2 id="tasks-title">Today's focus</h2>
          </div>
          <button class="clear-button" id="clear-completed" type="button" <?= $stats['done'] === 0 ? 'disabled' : '' ?>>
            Clear done
          </button>
        </div>

        <div class="toolbar">
          <div class="filters" role="group" aria-label="Filter tasks">
            <button class="filter is-active" type="button" aria-pressed="true" data-filter="all">All <span data-count="all"><?= (int) $stats['total'] ?></span></button>
            <button class="filter" type="button" aria-pressed="false" data-filter="active">To do <span data-count="active"><?= (int) $stats['active'] ?></span></button>
            <button class="filter" type="button" aria-pressed="false" data-filter="done">Done <span data-count="done"><?= (int) $stats['done'] ?></span></button>
          </div>

          <label class="search" for="search-tasks">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="11" cy="11" r="6.5" />
              <path d="m16 16 4.5 4.5" />
            </svg>
            <input id="search-tasks" type="search" placeholder="Search tasks" autocomplete="off" />
          </label>
        </div>

        <noscript>
          <p class="noscript-note">JavaScript is off. You can view and add tasks, but checking off and deleting need JavaScript.</p>
        </noscript>

        <ul class="task-list" id="task-list" aria-live="polite">
          <?php foreach ($tasks as $task): ?>
            <li class="task<?= $task['done'] ? ' is-done' : '' ?>" data-id="<?= e($task['id']) ?>">
              <button class="task-check" type="button" role="checkbox" aria-checked="<?= $task['done'] ? 'true' : 'false' ?>" aria-label="<?= $task['done'] ? 'Mark as not done' : 'Mark as done' ?>: <?= e($task['title']) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="m5 12 4 4L19 6" />
                </svg>
              </button>

              <div class="task-copy">
                <p class="task-title"><?= e($task['title']) ?></p>
                <div class="task-meta">
                  <span class="priority priority-<?= e($task['priority']) ?>">
                    <span class="priority-dot"></span><?= e(ucfirst($task['priority'])) ?>
                  </span>
                  <?php if ($task['due'] !== null): ?>
                    <span class="due<?= (!$task['done'] && $task['due'] < date('Y-m-d')) ? ' is-overdue' : '' ?>">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4" y="5.5" width="16" height="15" rx="3" />
                        <path d="M8 3.5v4M16 3.5v4M4 10h16" />
                      </svg>
                      <?= e(date('M j', strtotime($task['due']))) ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <button class="task-delete" type="button" aria-label="Delete task: <?= e($task['title']) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M4 7h16M9 7V4.8c0-.5.4-.8.8-.8h4.4c.4 0 .8.3.8.8V7M6.5 7l.8 12.1c0 .5.4.9.9.9h7.6c.5 0 .9-.4.9-.9L17.5 7M10 11v5.5M14 11v5.5" />
                </svg>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>

        <div class="empty-state" id="empty-state" <?= count($tasks) > 0 ? 'hidden' : '' ?>>
          <div class="empty-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M5 12.5 9 16l10-10" />
            </svg>
          </div>
          <h3 id="empty-title">A clear board.</h3>
          <p id="empty-text">Add your first task on the left and it will show up here.</p>
        </div>

        <p class="list-footnote" id="list-footnote">
          <?php if (count($tasks) > 0): ?>
            Showing <?= count($tasks) ?> task<?= count($tasks) === 1 ? '' : 's' ?>. Changes save automatically.
          <?php else: ?>
            Nothing here yet. Add a task to get started.
          <?php endif; ?>
        </p>
      </section>
    </section>
  </main>

  <footer class="site-footer container">
    <div class="footer-left">
      <span class="footer-brand">TaskFlow</span>
      <span class="footer-sep">/</span>
      <span>Group Exercise #2</span>
    </div>
    <div class="footer-right">
      <span>Built by <a href="https://github.com/Bulbasur123" target="_blank" rel="noopener noreferrer">Mark Louis Pamisa</a></span>
      <span class="footer-dot">·</span>
      <span>PHP · HTML · CSS · JS</span>
    </div>
  </footer>

  <div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true">
    <span class="toast-dot" aria-hidden="true"></span>
    <span id="toast-message"></span>
  </div>

  <script type="application/json" id="initial-state"><?= json_encode(
      ['tasks' => $tasks, 'stats' => $stats],
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  ) ?></script>
</body>
</html>
