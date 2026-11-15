<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/taskflow.php';

/*
 * TaskFlow API — GET returns the current board for the page;
 * POST handles add, toggle, delete, and clear actions.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        $tasks = load_tasks();
        json_response([
            'ok' => true,
            'tasks' => $tasks,
            'stats' => task_stats($tasks),
            'csrf_token' => $csrfToken,
        ]);
    } catch (Throwable $exception) {
        error_log('TaskFlow: ' . $exception->getMessage());
        json_response(['ok' => false, 'error' => 'Task storage is unavailable. Please try again.'], 500);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: GET, POST');
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

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
