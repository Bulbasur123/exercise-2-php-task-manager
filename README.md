# TaskFlow — Exercise #2

A personal one-page task manager for **Exercise #2**, built with **PHP, HTML, CSS, and JavaScript**. No framework, no database, and no build step — just a small server-side app that keeps its task list in a JSON file.

**Repository:** https://github.com/Bulbasur123/exercise-2-php-task-manager

## Features

- Add tasks with a title, priority, and optional due date
- Complete or reopen tasks with a single click
- Delete one task or clear all completed tasks
- Filter by **All**, **To do**, or **Done**
- Search tasks by title as you type
- Live progress ring, counters, and filter totals
- Server-side validation and CSRF protection
- PHP-guarded JSON storage with file locking
- Responsive layout from wide desktop to small phones
- Sample tasks on first run so the page is not empty

## Built with

| Layer | Technology |
| --- | --- |
| Server | PHP 7.4+ |
| Markup | HTML5 |
| Styling | CSS3 (custom properties, grid, flexbox) |
| Behavior | Vanilla JavaScript (fetch + DOM APIs) |
| Storage | JSON file in `storage/` |

## Run it

### Option 1 — PHP built-in server (no XAMPP needed)

```bash
cd exercise-2-php-task-manager
php -S localhost:8000
```

Then open `http://localhost:8000/`.

No PHP installed? Download a portable Windows build from [windows.php.net/download](https://windows.php.net/download/), extract it, and use its `php.exe` for the command above. XAMPP is **not** required.

### Option 2 — XAMPP (optional)

1. Copy this folder into your XAMPP `htdocs` folder.
2. Start **Apache** in the XAMPP Control Panel.
3. Open `http://localhost/exercise-2-php-task-manager/` in your browser.

**Requirements:** PHP 7.4 or newer with the standard JSON and session support. No database setup is required.

## How it works

`index.html` is a static page with no PHP inside it. On load, `assets/app.js` requests the current tasks, stats, and CSRF token from `api.php` (GET). `api.php` also handles the POST actions used by the frontend, while shared storage, validation, and session helpers live in `includes/taskflow.php`. The browser sends JSON requests with an `X-CSRF-Token` header, and PHP responds with the updated task list and stats. The page updates without reloading.

Tasks are stored in `storage/tasks.php`. The first line of that file is a PHP guard (`<?php exit; ?>`), so requesting the file over HTTP returns nothing. PHP strips the guard when reading, then rewrites the JSON after every change. The storage file is ignored by Git because it is runtime data.

The first time the app runs, it creates a few sample tasks. To reset the board, delete `storage/tasks.php` and refresh the page.

## Project structure

```
exercise-2-php-task-manager/
├── index.html             ← static page shell
├── api.php                ← JSON bootstrap (GET) and task actions (POST)
├── includes/
│   └── taskflow.php       ← storage, validation, session, and CSRF helpers
├── assets/
│   ├── app.js             ← loads initial state and handles interactions
│   ├── styles.css         ← responsive visual design
│   ├── avatar.jpg         ← profile photo in the header
│   └── favicon.svg        ← browser tab icon
├── storage/
│   └── .htaccess          ← blocks direct web access to task data
├── .gitignore
└── README.md
```

## Notes

- `storage/tasks.php` is created automatically and is **not** committed — each checkout starts with a fresh demo board.
- The page is static HTML; JavaScript is required to load and update the board.
- The app is designed for a single shared task list, which fits this exercise. For a real multi-user product, add user accounts and a database.
- Replace `assets/avatar.jpg` if you want a different header photo.

## Author

**Mark Louis Pamisa** — [github.com/Bulbasur123](https://github.com/Bulbasur123)
