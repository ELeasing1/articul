<?php
/*
 * ARTIKUL — сохранение сайта из админки (admin.html) прямо на хостинг.
 *
 * Как включить:
 *   1. Впишите свой пароль в ADMIN_PASSWORD ниже (любой длинный, не короче 10 символов).
 *   2. Загрузите на хостинг рядом с index.html файлы admin.html и api.php.
 *   3. Откройте https://ваш-домен/admin.html — кнопка «Сохранить на сайт» появится сама.
 *
 * Пока пароль пустой, сохранение на сервер выключено, сайт при этом работает как обычно.
 * Перед каждой записью index.html прежняя версия копируется в папку backups/.
 */

const ADMIN_PASSWORD = '';

const SITE_DIR = __DIR__;
const BACKUP_DIR = __DIR__ . '/backups';
const KEEP_BACKUPS = 30;
const MAX_FILE = 200 * 1024 * 1024;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail($msg, $code = 400) {
    out(['ok' => false, 'error' => $msg], $code);
}

function ensure_dir($dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('Не удалось создать папку ' . basename($dir), 500);
}

function protect_backups() {
    ensure_dir(BACKUP_DIR);
    $ht = BACKUP_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    $ix = BACKUP_DIR . '/index.html';
    if (!file_exists($ix)) @file_put_contents($ix, '');
}

// разрешённые пути: index.html, служебные файлы для поисковиков и медиа в папке media/
function safe_name($name) {
    if (in_array($name, ['index.html', 'robots.txt', 'sitemap.xml', 'llms.txt', 'og.jpg'], true)) return $name;
    if (preg_match('~^media/[A-Za-z0-9_-]{1,80}\.(webp|jpe?g|png|gif|svg|mp4|webm)$~', $name)) return $name;
    return null;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'ping') {
    out(['ok' => true, 'enabled' => ADMIN_PASSWORD !== '', 'php' => PHP_VERSION, 'writable' => is_writable(SITE_DIR)]);
}

if (ADMIN_PASSWORD === '') fail('Сохранение на сервер выключено: впишите пароль в api.php', 403);
if (strlen(ADMIN_PASSWORD) < 10) fail('Пароль в api.php слишком короткий: нужно не меньше 10 символов', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST-запрос', 405);

$pass = isset($_SERVER['HTTP_X_ADMIN_PASSWORD']) ? rawurldecode($_SERVER['HTTP_X_ADMIN_PASSWORD']) : '';
if (!hash_equals(ADMIN_PASSWORD, $pass)) {
    sleep(2);
    fail('Неверный пароль', 401);
}

switch ($action) {
    case 'auth':
        out(['ok' => true, 'writable' => is_writable(SITE_DIR)]);

    case 'list': // какие медиафайлы уже лежат на сервере
        $files = [];
        foreach (glob(SITE_DIR . '/media/*') ?: [] as $f) {
            if (is_file($f)) $files['media/' . basename($f)] = filesize($f);
        }
        out(['ok' => true, 'files' => $files]);

    case 'put': // загрузка файла частями: n=0,1,2…, last=1 у последней части
        $name = safe_name(isset($_GET['name']) ? $_GET['name'] : '');
        if (!$name) fail('Недопустимое имя файла');
        $n = isset($_GET['n']) ? (int)$_GET['n'] : 0;
        $last = !empty($_GET['last']);
        $target = SITE_DIR . '/' . $name;
        ensure_dir(dirname($target));
        protect_backups();
        $part = BACKUP_DIR . '/.part-' . str_replace('/', '_', $name);
        $data = file_get_contents('php://input');
        if ($data === false) fail('Пустой запрос');
        if (@file_put_contents($part, $data, $n === 0 ? 0 : FILE_APPEND) === false) fail('Нет прав на запись в папку сайта', 500);
        clearstatcache();
        if (filesize($part) > MAX_FILE) { @unlink($part); fail('Файл слишком большой', 413); }
        if (!$last) out(['ok' => true, 'size' => filesize($part)]);

        if ($name === 'index.html') {
            $head = (string)file_get_contents($part, false, null, 0, 300);
            if (stripos($head, '<!doctype html') === false) { @unlink($part); fail('Это не похоже на страницу сайта'); }
            if (file_exists($target)) {
                @copy($target, BACKUP_DIR . '/index-' . date('Ymd-His') . '.html');
                $old = glob(BACKUP_DIR . '/index-*.html') ?: [];
                sort($old);
                while (count($old) > KEEP_BACKUPS) @unlink(array_shift($old));
            }
        }
        if (!@rename($part, $target)) {
            if (!@copy($part, $target)) fail('Не удалось записать ' . $name, 500);
            @unlink($part);
        }
        out(['ok' => true, 'name' => $name, 'size' => filesize($target)]);

    case 'backups':
        $list = [];
        foreach (glob(BACKUP_DIR . '/index-*.html') ?: [] as $f) $list[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
        usort($list, function ($a, $b) { return $b['time'] - $a['time']; });
        out(['ok' => true, 'backups' => $list]);

    case 'restore':
        $b = isset($_GET['name']) ? basename($_GET['name']) : '';
        if (!preg_match('~^index-\d{8}-\d{6}\.html$~', $b) || !is_file(BACKUP_DIR . '/' . $b)) fail('Копия не найдена', 404);
        $target = SITE_DIR . '/index.html';
        if (file_exists($target)) @copy($target, BACKUP_DIR . '/index-' . date('Ymd-His') . '.html');
        if (!@copy(BACKUP_DIR . '/' . $b, $target)) fail('Не удалось восстановить', 500);
        out(['ok' => true]);

    case 'cleanup': // удалить медиафайлы, которые сайт больше не использует
        $keep = json_decode(file_get_contents('php://input'), true);
        if (!is_array($keep)) fail('Нужен список файлов');
        $removed = 0;
        foreach (glob(SITE_DIR . '/media/*') ?: [] as $f) {
            if (is_file($f) && !in_array('media/' . basename($f), $keep, true)) { @unlink($f); $removed++; }
        }
        out(['ok' => true, 'removed' => $removed]);
}

fail('Неизвестное действие');
