<?php
// ============================================================
//  Telegram-бот для рассылок студентам (PHP)
//  Запуск: php bot.php
// ============================================================

set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

function out(string $s): void
{
    echo '[' . date('H:i:s') . '] ' . $s . PHP_EOL;
}

// ---------- Проверка PHP и расширений ----------
if (PHP_VERSION_ID < 80000) {
    out('ERROR: PHP 8.0 or newer is required. You have ' . PHP_VERSION);
    exit(1);
}
foreach (['curl', 'mbstring', 'openssl', 'pdo_sqlite'] as $ext) {
    if (!extension_loaded($ext)) {
        out("ERROR: PHP extension '$ext' is not enabled in php.ini.");
        exit(1);
    }
}
mb_internal_encoding('UTF-8');

// ---------- Настройки из файла .env ----------
define('BASE_DIR', __DIR__);
define('EXPIRED', '⚠️ Это сообщение уже обработано или бот перезапускали. Отправьте его заново.');

function loadEnv(string $path): array
{
    $env = [];
    if (!is_file($path)) {
        return $env;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
        if ($i === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        }
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
    return $env;
}

$env = loadEnv(BASE_DIR . '/.env');
define('TOKEN', trim($env['TOKEN'] ?? ''));
define('GROUP_CODE', trim($env['GROUP_CODE'] ?? ''));
define('ADMIN_ID', (int)($env['ADMIN_ID'] ?? 0));
define('API_BASE', rtrim($env['API_BASE'] ?? 'https://api.telegram.org', '/'));
if (!@date_default_timezone_set($env['TIMEZONE'] ?? 'Asia/Bishkek')) {
    date_default_timezone_set('Asia/Bishkek');
}

if (TOKEN === '' || !preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', TOKEN)) {
    out('ERROR: TOKEN in the .env file is missing or wrong. Copy it again from @BotFather.');
    exit(1);
}

// ---------- Оперативная память процесса ----------
final class State
{
    public static array $albums = [];        // media_group_id => части альбома
    public static array $pending = [];       // key => рассылка, ждущая подтверждения
    public static array $awaitingTime = [];  // teacher_id => [key, group_id]
    public static array $awaitingInput = []; // teacher_id => 'newgroup' | 'find' | 'addteacher'
}

// ---------- Связь с Telegram Bot API ----------
class TgError extends Exception
{
    public int $tgCode;

    public function __construct(string $msg, int $tgCode = 0)
    {
        parent::__construct($msg);
        $this->tgCode = $tgCode;
    }
}

function tg(string $method, array $params = [], int $timeout = 30)
{
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
    }

    $post = [];
    $hasFile = false;
    foreach ($params as $k => $v) {
        if ($v instanceof CURLFile) {
            $post[$k] = $v;
            $hasFile = true;
        } elseif (is_array($v)) {
            $post[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($v)) {
            $post[$k] = $v ? 'true' : 'false';
        } elseif ($v !== null) {
            $post[$k] = $v;
        }
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
        curl_reset($ch);
        curl_setopt_array($ch, [
            CURLOPT_URL            => API_BASE . '/bot' . TOKEN . '/' . $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $hasFile ? $post : http_build_query($post),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if (is_file(BASE_DIR . '/cacert.pem')) {
            curl_setopt($ch, CURLOPT_CAINFO, BASE_DIR . '/cacert.pem');
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $no = curl_errno($ch);
            $msg = "network error ($no): " . curl_error($ch);
            if ($no === 60 || $no === 77) {
                $msg .= ' | SSL certificates problem: make sure cacert.pem is next to bot.php';
            }
            throw new TgError($msg, 0);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new TgError('bad response from Telegram', 0);
        }
        if (!empty($data['ok'])) {
            return $data['result'];
        }
        $code = (int)($data['error_code'] ?? 0);
        if ($code === 429 && $attempt === 0) {
            sleep(min(max((int)($data['parameters']['retry_after'] ?? 1), 1), 60));
            continue;
        }
        throw new TgError($data['description'] ?? 'unknown error', $code);
    }
    throw new TgError('request failed', 0);
}

// ---------- База данных SQLite (students.db) ----------
function db(): PDO
{
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . BASE_DIR . '/students.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 5000');
    }
    return $db;
}

function q(string $sql, array $p = []): PDOStatement
{
    $s = db()->prepare($sql);
    foreach (array_values($p) as $i => $v) {
        $type = is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $s->bindValue($i + 1, is_float($v) ? (string)$v : $v, $type);
    }
    $s->execute();
    return $s;
}

function rows(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll(PDO::FETCH_NUM);
}

function row(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r;
}

function val(string $sql, array $p = [])
{
    $r = row($sql, $p);
    return $r === null ? null : $r[0];
}

function run(string $sql, array $p = []): void
{
    q($sql, $p);
}

// Инициализация таблиц
run('CREATE TABLE IF NOT EXISTS students (id INTEGER PRIMARY KEY, name TEXT, full_name TEXT, group_id INTEGER)');
run('CREATE TABLE IF NOT EXISTS groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, code TEXT UNIQUE)');
run('CREATE TABLE IF NOT EXISTS awaiting_name (user_id INTEGER PRIMARY KEY, group_id INTEGER)');
run('CREATE TABLE IF NOT EXISTS requests (student_id INTEGER PRIMARY KEY, name TEXT, full_name TEXT, group_id INTEGER, username TEXT)');
run('CREATE TABLE IF NOT EXISTS teachers (id INTEGER PRIMARY KEY, name TEXT)');
run('CREATE TABLE IF NOT EXISTS questions (teacher_id INTEGER, message_id INTEGER, student_id INTEGER)');
run('CREATE TABLE IF NOT EXISTS history (id INTEGER PRIMARY KEY AUTOINCREMENT, sent_at TEXT, preview TEXT, delivered INTEGER, total INTEGER, by_name TEXT, group_name TEXT, ts REAL, deleted INTEGER DEFAULT 0)');
run('CREATE TABLE IF NOT EXISTS sent (history_id INTEGER, chat_id INTEGER, message_id INTEGER)');
run('CREATE TABLE IF NOT EXISTS scheduled (id INTEGER PRIMARY KEY AUTOINCREMENT, send_at TEXT, from_id INTEGER, msg_ids TEXT, preview TEXT, group_id INTEGER, by_name TEXT)');
run('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');

foreach ([
    'ALTER TABLE students ADD COLUMN full_name TEXT',
    'ALTER TABLE students ADD COLUMN group_id INTEGER',
    'ALTER TABLE history ADD COLUMN by_name TEXT',
    'ALTER TABLE history ADD COLUMN group_name TEXT',
    'ALTER TABLE history ADD COLUMN ts REAL',
    'ALTER TABLE history ADD COLUMN deleted INTEGER DEFAULT 0',
] as $sql) {
    try {
        run($sql);
    } catch (PDOException $e) {
        // Колонка уже существует
    }
}

function newCode(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (val('SELECT 1 FROM groups WHERE code = ?', [$code]) !== null);
    return $code;
}

if (val('SELECT 1 FROM groups') === null) {
    run('INSERT INTO groups (name, code) VALUES (?, ?)', ['Основная', GROUP_CODE !== '' ? GROUP_CODE : newCode()]);
}
$firstGroup = (int)val('SELECT id FROM groups ORDER BY id LIMIT 1');
run('UPDATE students SET group_id = ? WHERE group_id IS NULL', [$firstGroup]);
run("UPDATE students SET full_name = name WHERE full_name IS NULL OR full_name = ''");

// ---------- Роли и помощники ----------
function isOwner(int $uid): bool
{
    return ADMIN_ID !== 0 && $uid === ADMIN_ID;
}

function isTeacher(int $uid): bool
{
    if (isOwner($uid)) {
        return true;
    }
    return val('SELECT 1 FROM teachers WHERE id = ?', [$uid]) !== null;
}

function teacherIds(): array
{
    $ids = array_map(fn($r) => (int)$r[0], rows('SELECT id FROM teachers'));
    if (ADMIN_ID !== 0 && !in_array(ADMIN_ID, $ids, true)) {
        array_unshift($ids, ADMIN_ID);
    }
    return $ids;
}

function fullName(array $u): string
{
    return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
}

function countStudents(int $gid = 0): int
{
    if ($gid > 0) {
        return (int)val('SELECT COUNT(*) FROM students WHERE group_id = ?', [$gid]);
    }
    return (int)val('SELECT COUNT(*) FROM students');
}

function groupLabel(int $gid): string
{
    if ($gid === 0) {
        return 'все группы';
    }
    $name = val('SELECT name FROM groups WHERE id = ?', [$gid]);
    return $name ?? '?';
}

function startLink(string $code): string
{
    return 'https://t.me/' . BOT_USERNAME . '?start=' . $code;
}

function nowStr(): string
{
    return date('d.m.Y H:i');
}

function namesLine(array $names): string
{
    $shown = implode(', ', array_slice($names, 0, 10));
    if (count($names) > 10) {
        $shown .= ' и ещё ' . (count($names) - 10);
    }
    return $shown;
}

function describe(array $m): string
{
    $text = $m['text'] ?? $m['caption'] ?? '';
    if ($text !== '') {
        $text = str_replace("\n", ' ', $text);
        return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '...' : $text;
    }
    $kinds = [
        'animation'  => 'GIF',
        'photo'      => '📷 Фото',
        'video'      => '🎥 Видео',
        'video_note' => '⭕ Кружок',
        'voice'      => '🎤 Голосовое',
        'audio'      => '🎵 Аудио',
        'document'   => '📎 Файл',
        'sticker'    => 'Стикер',
        'poll'       => '📊 Опрос',
        'location'   => '📍 Геопозиция',
        'contact'    => '👤 Контакт',
    ];
    foreach ($kinds as $key => $label) {
        if (isset($m[$key])) {
            return $label;
        }
    }
    return 'Сообщение';
}

function btn(string $text, string $data): array
{
    return ['text' => $text, 'callback_data' => $data];
}

function kb(array $rows): array
{
    return ['inline_keyboard' => $rows];
}

function send($chat, string $text, ?array $markup = null): array
{
    $p = ['chat_id' => $chat, 'text' => $text];
    if ($markup !== null) {
        $p['reply_markup'] = $markup;
    }
    return tg('sendMessage', $p);
}

function answer(array $m, string $text, ?array $markup = null): void
{
    send($m['chat']['id'], $text, $markup);
}

function replyTo(array $m, string $text, ?array $markup = null): array
{
    $p = [
        'chat_id'          => $m['chat']['id'],
        'text'             => $text,
        'reply_parameters' => ['message_id' => $m['message_id'], 'allow_sending_without_reply' => true],
    ];
    if ($markup !== null) {
        $p['reply_markup'] = $markup;
    }
    return tg('sendMessage', $p);
}

function edit(array $cb, string $text, ?array $markup = null): void
{
    $p = [
        'chat_id'    => $cb['message']['chat']['id'],
        'message_id' => $cb['message']['message_id'],
        'text'       => $text,
    ];
    if ($markup !== null) {
        $p['reply_markup'] = $markup;
    }
    try {
        tg('editMessageText', $p);
    } catch (TgError $e) {
    }
}

function ack(array $cb, string $text = '', bool $alert = false): void
{
    $p = ['callback_query_id' => $cb['id']];
    if ($text !== '') {
        $p['text'] = $text;
        $p['show_alert'] = $alert;
    }
    try {
        tg('answerCallbackQuery', $p);
    } catch (TgError $e) {
    }
}

function sendLong(array $m, string $text): void
{
    while ($text !== '') {
        $chunk = mb_substr($text, 0, 3500);
        if (mb_strlen($text) > 3500) {
            $cut = mb_strrpos($chunk, "\n");
            if ($cut !== false && $cut > 0) {
                $chunk = mb_substr($chunk, 0, $cut);
            }
        }
        answer($m, $chunk);
        $text = ltrim(mb_substr($text, mb_strlen($chunk)), "\n");
    }
}

function parseCommand(string $text): ?array
{
    if ($text === '' || $text[0] !== '/') {
        return null;
    }
    $parts = preg_split('/\s+/', $text, 2);
    $name = strtolower(substr($parts[0], 1));
    $at = strpos($name, '@');
    if ($at !== false) {
        if (strcasecmp(substr($name, $at + 1), BOT_USERNAME) !== 0) {
            return null;
        }
        $name = substr($name, 0, $at);
    }
    return [$name, trim($parts[1] ?? '')];
}

function ownsKey(string $key, int $uid): bool
{
    return strpos($key, $uid . '_') === 0;
}

// ---------- Меню команд Telegram (кнопка "/") ----------
function teacherMenu(int $uid): void
{
    $cmds = [
        ['command' => 'start',     'description' => 'Помощь и ссылки на группы'],
        ['command' => 'groups',    'description' => 'Группы, коды и ссылки'],
        ['command' => 'newgroup',  'description' => 'Создать группу'],
        ['command' => 'requests',  'description' => 'Заявки студентов'],
        ['command' => 'list',      'description' => 'Список студентов'],
        ['command' => 'find',      'description' => 'Найти студента'],
        ['command' => 'remove',    'description' => 'Убрать студента'],
        ['command' => 'export',    'description' => 'Список файлом (Excel)'],
        ['command' => 'history',   'description' => 'Последние рассылки'],
        ['command' => 'delete',    'description' => 'Удалить рассылку у студентов'],
        ['command' => 'scheduled', 'description' => 'Отложенные рассылки'],
        ['command' => 'stats',     'description' => 'Статистика'],
    ];
    if (isOwner($uid)) {
        $cmds[] = ['command' => 'teachers',   'description' => 'Список преподавателей'];
        $cmds[] = ['command' => 'addteacher', 'description' => 'Добавить преподавателя'];
        $cmds[] = ['command' => 'backup',     'description' => 'Копия базы данных'];
    }
    try {
        tg('setMyCommands', ['commands' => $cmds, 'scope' => ['type' => 'chat', 'chat_id' => $uid]]);
    } catch (TgError $e) {
    }
}

function resetUserMenu(int $uid): void
{
    try {
        tg('deleteMyCommands', ['scope' => ['type' => 'chat', 'chat_id' => $uid]]);
    } catch (TgError $e) {
    }
}

function setupMenusOnStartup(): void
{
    try {
        tg('setMyCommands', ['commands' => [
            ['command' => 'start', 'description' => 'Подписаться'],
            ['command' => 'stop',  'description' => 'Отписаться'],
            ['command' => 'id',    'description' => 'Узнать свой ID'],
        ]]);
    } catch (TgError $e) {
    }
    foreach (teacherIds() as $tid) {
        teacherMenu((int)$tid);
    }
}

// ---------- Основные команды (/start, /stop, /cancel) ----------
function cmdStart(array $m, string $args): void
{
    $uid = (int)$m['from']['id'];

    if (isTeacher($uid)) {
        $text = "👨‍🏫 Вы преподаватель.\n\n"
            . "Напишите мне сообщение (текст, фото, альбом, файл, голосовое) — "
            . "я спрошу подтверждение и разошлю его студентам.\n\n"
            . "📚 /groups — группы, коды и ссылки\n"
            . "➕ /newgroup — создать новую группу\n"
            . "👤 /requests — заявки студентов на подписку\n"
            . "📋 /list — список студентов\n"
            . "🔎 /find — найти студента по имени\n"
            . "🗑 /remove — убрать студента из рассылки\n"
            . "📄 /export — выгрузить список в Excel (CSV)\n"
            . "🕘 /history — последние рассылки\n"
            . "❌ /delete — удалить рассылку у студентов (до 48 ч)\n"
            . "⏰ /scheduled — отложенные рассылки\n"
            . "📊 /stats — статистика\n\n"
            . "💬 Вопросы студентов приходят сюда: ответьте на них через Reply (Ответить), и ответ уйдёт студенту.\n";

        if (isOwner($uid)) {
            $text .= "\n👑 Вы главный преподаватель:\n"
                . "➕ /addteacher — назначить преподавателя (по ID или пересланному сообщению)\n"
                . "👥 /teachers — список преподавателей и управление ими\n"
                . "💾 /backup — скачать копию базы данных\n";
        }

        $text .= "\n";
        foreach (rows('SELECT name, code FROM groups ORDER BY id') as [$name, $code]) {
            $text .= "📚 $name\n🔑 Код: $code\n🔗 Ссылка для студентов:\n" . startLink($code) . "\n\n";
        }
        sendLong($m, rtrim($text));
        teacherMenu($uid);
        return;
    }

    $group = $args !== '' ? row('SELECT id, name FROM groups WHERE code = ?', [$args]) : null;
    if ($group === null) {
        answer($m, "🔒 Чтобы подписаться, откройте ссылку, которую дал преподаватель, или отправьте:\n/start КОД");
        return;
    }
    run('INSERT OR REPLACE INTO awaiting_name (user_id, group_id) VALUES (?, ?)', [$uid, (int)$group[0]]);
    answer($m, "👋 Группа: {$group[1]}\n\n✍️ Напишите ваши настоящие имя и фамилию (например: Айбек Тураров).");
}

function cmdStop(array $m): void
{
    $uid = (int)$m['from']['id'];
    run('DELETE FROM awaiting_name WHERE user_id = ?', [$uid]);
    run('DELETE FROM requests WHERE student_id = ?', [$uid]);
    run('DELETE FROM students WHERE id = ?', [$uid]);
    answer($m, '👋 Вы отписались. Чтобы вернуться, откройте ссылку от преподавателя.');
}

function cmdCancel(array $m): void
{
    $uid = (int)$m['from']['id'];
    $had = isset(State::$awaitingTime[$uid]) || isset(State::$awaitingInput[$uid]);
    unset(State::$awaitingTime[$uid], State::$awaitingInput[$uid]);
    answer($m, $had ? '❌ Действие отменено.' : 'ℹ️ Сейчас нет активного действия для отмены.');
}

// ---------- Группы (/groups, /newgroup) ----------
function groupsView(): array
{
    $lines = [];
    $buttons = [];
    foreach (rows('SELECT id, name, code FROM groups ORDER BY id') as [$gid, $name, $code]) {
        $lines[] = "📚 $name — студентов: " . countStudents((int)$gid) . "\n🔑 Код: $code\n🔗 " . startLink($code);
        $buttons[] = [
            btn('🔄 Новый код: ' . mb_substr($name, 0, 18), "gc:$gid"),
            btn('🗑', "gd:$gid"),
        ];
    }
    return [implode("\n\n", $lines), kb($buttons)];
}

function cmdGroups(array $m): void
{
    [$text, $markup] = groupsView();
    send($m['chat']['id'], $text . "\n\n➕ Новая группа: /newgroup", $markup);
}

function createGroup(array $m, string $rawName): void
{
    $name = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $rawName)), 0, 40);
    if ($name === '') {
        answer($m, '⚠️ Название группы не может быть пустым.');
        return;
    }
    $code = newCode();
    run('INSERT INTO groups (name, code) VALUES (?, ?)', [$name, $code]);
    answer($m, "✅ Группа «$name» создана.\n🔑 Код: $code\n🔗 Ссылка для студентов:\n" . startLink($code));
}

function cmdNewGroup(array $m, string $args): void
{
    $uid = (int)$m['from']['id'];
    if ($args === '') {
        State::$awaitingInput[$uid] = 'newgroup';
        answer($m, "✍️ Напишите название новой группы (например: ПОВТ-1-24).\n\nОтмена: /cancel");
        return;
    }
    createGroup($m, $args);
}

function cbGroupCode(array $cb, array $p): void
{
    run('UPDATE groups SET code = ? WHERE id = ?', [newCode(), (int)($p[1] ?? 0)]);
    [$text, $markup] = groupsView();
    edit($cb, "🔄 Код обновлён, старая ссылка больше не работает.\n\n" . $text, $markup);
    ack($cb);
}

function cbGroupDelete(array $cb, array $p): void
{
    $gid = (int)($p[1] ?? 0);
    if (countStudents($gid) > 0) {
        ack($cb, 'В группе есть студенты. Сначала удалите или переведите их (/remove).', true);
        return;
    }
    if ((int)val('SELECT COUNT(*) FROM groups') <= 1) {
        ack($cb, 'Нельзя удалить последнюю группу.', true);
        return;
    }
    run('DELETE FROM groups WHERE id = ?', [$gid]);
    run('DELETE FROM requests WHERE group_id = ?', [$gid]);
    run('DELETE FROM awaiting_name WHERE group_id = ?', [$gid]);
    [$text, $markup] = groupsView();
    edit($cb, "🗑 Группа удалена.\n\n" . $text, $markup);
    ack($cb);
}

// ---------- Преподаватели (только для главного преподавателя) ----------
function teachersKeyboard(): array
{
    $buttons = [];
    foreach (rows('SELECT id, name FROM teachers ORDER BY name') as [$tid, $name]) {
        $buttons[] = [btn('❌ Убрать: ' . mb_substr($name ?: "ID $tid", 0, 28), "tr:$tid")];
    }
    return kb($buttons);
}

function addTeacherById(array $m, int $tid, string $fallbackName = ''): void
{
    if ($tid <= 0) {
        answer($m, '⚠️ Некорректный ID пользователя.');
        return;
    }
    if ($tid === ADMIN_ID) {
        answer($m, '👑 Вы и так главный преподаватель.');
        return;
    }
    $name = $fallbackName;
    try {
        $chat = tg('getChat', ['chat_id' => $tid]);
        $chatName = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
        if ($chatName === '') {
            $chatName = (string)($chat['title'] ?? '');
        }
        if ($chatName !== '') {
            $name = $chatName;
        }
    } catch (TgError $e) {
        if ($name === '') {
            answer($m, "⚠️ Не нашёл такого пользователя (ID $tid).\nПусть он сначала запустит бота и напишет /id, после чего повторите попытку.");
            return;
        }
    }
    if ($name === '') {
        $name = "ID $tid";
    }

    run('INSERT OR REPLACE INTO teachers (id, name) VALUES (?, ?)', [$tid, $name]);
    run('DELETE FROM students WHERE id = ?', [$tid]);
    run('DELETE FROM requests WHERE student_id = ?', [$tid]);
    run('DELETE FROM awaiting_name WHERE user_id = ?', [$tid]);

    teacherMenu($tid);
    answer($m, "✅ Преподаватель назначен: $name (ID $tid)");
    try {
        send($tid, "👨‍🏫 Главный преподаватель назначил вас преподавателем!\nНажмите /start, чтобы открыть панель управления.");
    } catch (TgError $e) {
    }
}

function cmdAddTeacher(array $m, string $args): void
{
    $uid = (int)$m['from']['id'];
    if ($args === '') {
        State::$awaitingInput[$uid] = 'addteacher';
        answer(
            $m,
            "👨‍🏫 Назначение преподавателя:\n\n"
            . "Отправьте мне числовой ID человека (он может узнать свой ID, написав боту /id) "
            . "или просто перешлите сюда любое сообщение от него.\n\n"
            . "Отмена: /cancel"
        );
        return;
    }
    if (!preg_match('/^\d+$/', $args)) {
        answer($m, "ℹ️ Напишите ID цифрами, например:\n/addteacher 123456789\n\nИли просто нажмите /addteacher и перешлите сообщение от нужного человека.");
        return;
    }
    addTeacherById($m, (int)$args);
}

function cmdTeachers(array $m): void
{
    $list = rows('SELECT id, name FROM teachers ORDER BY name');
    if (!$list) {
        answer($m, "👑 Сейчас вы единственный преподаватель.\n\nЧтобы назначить ещё одного, нажмите /addteacher");
        return;
    }
    $lines = ['👥 Назначенные преподаватели:'];
    foreach ($list as $i => [$tid, $name]) {
        $lines[] = ($i + 1) . '. ' . ($name ?: "ID $tid") . " (ID $tid)";
    }
    $lines[] = "\nНажмите на кнопку ниже, чтобы снять статус преподавателя, или /addteacher чтобы добавить нового:";
    send($m['chat']['id'], implode("\n", $lines), teachersKeyboard());
}

function cbRemoveTeacher(array $cb, array $p): void
{
    $tid = (int)($p[1] ?? 0);
    $name = val('SELECT name FROM teachers WHERE id = ?', [$tid]) ?? "ID $tid";
    run('DELETE FROM teachers WHERE id = ?', [$tid]);
    unset(State::$awaitingTime[$tid], State::$awaitingInput[$tid]);
    resetUserMenu($tid);
    try {
        send($tid, 'ℹ️ С вас снят статус преподавателя в этом боте.');
    } catch (TgError $e) {
    }
    if (val('SELECT 1 FROM teachers') === null) {
        edit($cb, "🗑 Преподаватель $name убран.\n👑 Других преподавателей больше нет.");
    } else {
        edit($cb, "🗑 Преподаватель $name убран. Кого ещё убрать?", teachersKeyboard());
    }
    ack($cb);
}

// ---------- Заявки студентов (/requests) ----------
function validName(string $full): bool
{
    $words = preg_split('/\s+/u', $full, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return count($words) >= 2 && preg_match('/^[\p{L}\s\'’.\-]+$/u', $full) === 1;
}

function cmdRequests(array $m): void
{
    $list = rows('SELECT student_id, full_name, group_id, username FROM requests ORDER BY full_name');
    if (!$list) {
        answer($m, '📭 Новых заявок от студентов нет.');
        return;
    }
    foreach ($list as [$sid, $full, $gid, $username]) {
        $u = $username ? " ($username)" : '';
        send(
            $m['chat']['id'],
            "👤 Заявка: $full$u\n📚 Группа: " . groupLabel((int)$gid),
            kb([[btn('✅ Принять', "ra:$sid"), btn('❌ Отклонить', "rr:$sid")]])
        );
    }
}

function cbAcceptRequest(array $cb, array $p): void
{
    $sid = (int)($p[1] ?? 0);
    $r = row('SELECT name, full_name, group_id FROM requests WHERE student_id = ?', [$sid]);
    if ($r === null) {
        edit($cb, 'ℹ️ Эта заявка уже обработана.');
        ack($cb);
        return;
    }
    run(
        'INSERT OR REPLACE INTO students (id, name, full_name, group_id) VALUES (?, ?, ?, ?)',
        [$sid, $r[0], $r[1], (int)$r[2]]
    );
    run('DELETE FROM requests WHERE student_id = ?', [$sid]);
    edit($cb, "✅ Принят: {$r[1]} (" . groupLabel((int)$r[2]) . ')');
    ack($cb);
    try {
        send(
            $sid,
            '✅ Ваша заявка подтверждена! Вы подписаны на рассылку группы «' . groupLabel((int)$r[2]) . "».\n\n"
            . "💬 Если у вас появится вопрос к преподавателю — просто напишите его сюда.\n"
            . '/stop — отписаться'
        );
    } catch (TgError $e) {
    }
}

function cbRejectRequest(array $cb, array $p): void
{
    $sid = (int)($p[1] ?? 0);
    $r = row('SELECT full_name FROM requests WHERE student_id = ?', [$sid]);
    if ($r === null) {
        edit($cb, 'ℹ️ Эта заявка уже обработана.');
        ack($cb);
        return;
    }
    run('DELETE FROM requests WHERE student_id = ?', [$sid]);
    edit($cb, "❌ Отклонён: {$r[0]}");
    ack($cb);
    try {
        send($sid, '❌ Заявка отклонена преподавателем. Проверьте правильность имени и фамилии и перейдите по ссылке группы заново.');
    } catch (TgError $e) {
    }
}

// ---------- Студенты: список, поиск, удаление, экспорт ----------
function cmdList(array $m): void
{
    $list = rows('SELECT g.name, s.full_name FROM students s LEFT JOIN groups g ON g.id = s.group_id ORDER BY g.id, s.full_name');
    if (!$list) {
        answer($m, '📭 Пока никто не подписался.');
        return;
    }
    $lines = ['📋 Всего студентов: ' . count($list)];
    $current = false;
    $n = 0;
    foreach ($list as [$gname, $full]) {
        if ($gname !== $current) {
            $current = $gname;
            $n = 0;
            $lines[] = "\n📚 " . ($gname ?? 'Без группы') . ':';
        }
        $n++;
        $lines[] = "$n. " . ($full ?: '?');
    }
    sendLong($m, implode("\n", $lines));
}

function runFindStudent(array $m, string $query): void
{
    $q = trim($query);
    if ($q === '') {
        answer($m, '⚠️ Введите имя или фамилию для поиска.');
        return;
    }
    $found = [];
    foreach (rows('SELECT id, full_name, group_id FROM students ORDER BY full_name') as [$id, $full, $gid]) {
        if (mb_stripos((string)$full, $q) !== false) {
            $found[] = "• $full — " . groupLabel((int)$gid) . " (ID $id)";
        }
    }
    if (!$found) {
        answer($m, "😕 Никого не нашёл по запросу «{$q}».");
        return;
    }
    $more = count($found) > 30 ? "\n\nПоказаны первые 30 результатов, уточните запрос." : '';
    answer($m, '🔎 Найдено (' . count($found) . "):\n\n" . implode("\n", array_slice($found, 0, 30)) . $more);
}

function cmdFind(array $m, string $args): void
{
    $uid = (int)$m['from']['id'];
    if ($args === '') {
        State::$awaitingInput[$uid] = 'find';
        answer($m, "🔎 Напишите имя или фамилию студента для поиска.\n\nОтмена: /cancel");
        return;
    }
    runFindStudent($m, $args);
}

function removeKeyboard(): array
{
    $buttons = [];
    $list = rows('SELECT s.id, s.full_name, g.name FROM students s LEFT JOIN groups g ON g.id = s.group_id ORDER BY s.full_name LIMIT 90');
    foreach ($list as [$sid, $name, $gname]) {
        $label = ($name ?: '?') . ($gname ? " ($gname)" : '');
        $buttons[] = [btn('❌ ' . mb_substr($label, 0, 38), "rm:$sid")];
    }
    return kb($buttons);
}

function cmdRemove(array $m): void
{
    if (countStudents() === 0) {
        answer($m, '📭 Список студентов пуст.');
        return;
    }
    send($m['chat']['id'], '🗑 Нажмите на студента, чтобы удалить его из рассылки:', removeKeyboard());
}

function cbRemoveStudent(array $cb, array $p): void
{
    $sid = (int)($p[1] ?? 0);
    run('DELETE FROM students WHERE id = ?', [$sid]);
    if (countStudents() === 0) {
        edit($cb, '📭 Список студентов пуст.');
    } else {
        edit($cb, '🗑 Студент удалён. Кого ещё удалить?', removeKeyboard());
    }
    ack($cb);
}

function cmdExport(array $m): void
{
    $list = rows('SELECT full_name, group_id, id FROM students ORDER BY group_id, full_name');
    if (!$list) {
        answer($m, '📭 Студентов пока нет.');
        return;
    }
    $path = BASE_DIR . '/export_tmp.csv';
    $f = fopen($path, 'w');
    fwrite($f, "\xEF\xBB\xBF"); // UTF-8 BOM для корректного открытия в Excel
    fputcsv($f, ['Имя и фамилия', 'Группа', 'Telegram ID'], ';', '"', '');
    foreach ($list as [$full, $gid, $id]) {
        fputcsv($f, [$full, groupLabel((int)$gid), $id], ';', '"', '');
    }
    fclose($f);
    try {
        tg('sendDocument', [
            'chat_id'  => $m['chat']['id'],
            'document' => new CURLFile($path, 'text/csv', 'students_' . date('d-m-Y') . '.csv'),
            'caption'  => '📄 Список студентов: ' . count($list),
        ]);
    } finally {
        @unlink($path);
    }
}

// ---------- История, статистика и удаление рассылок ----------
function cmdHistory(array $m): void
{
    $list = rows('SELECT sent_at, preview, delivered, total, by_name, group_name, deleted FROM history ORDER BY id DESC LIMIT 5');
    if (!$list) {
        answer($m, '📭 Рассылок ещё не было.');
        return;
    }
    $lines = [];
    foreach ($list as $i => [$when, $preview, $delivered, $total, $by, $gname, $deleted]) {
        $line = ($i + 1) . ". 🕘 $when\n   💬 $preview\n   ✅ Доставлено: $delivered из $total";
        if ($gname) {
            $line .= "\n   🎯 Кому: $gname";
        }
        if ($by) {
            $line .= "\n   👨‍🏫 $by";
        }
        if ($deleted) {
            $line .= "\n   🗑 Удалена у студентов";
        }
        $lines[] = $line;
    }
    sendLong($m, "🕘 Последние рассылки:\n\n" . implode("\n\n", $lines));
}

function cmdStats(array $m): void
{
    $groups = (int)val('SELECT COUNT(*) FROM groups');
    $reqs = (int)val('SELECT COUNT(*) FROM requests');
    $sent = (int)val('SELECT COUNT(*) FROM history');
    $planned = (int)val('SELECT COUNT(*) FROM scheduled');
    $last = val('SELECT sent_at FROM history ORDER BY id DESC LIMIT 1');
    $teachers = count(teacherIds());

    answer(
        $m,
        "📊 Статистика бота\n\n"
        . '👥 Подписано студентов: ' . countStudents() . "\n"
        . "⏳ Заявок на проверке: $reqs\n"
        . "📚 Групп: $groups\n"
        . "👨‍🏫 Преподавателей: $teachers\n"
        . "📣 Отправлено рассылок: $sent\n"
        . "⏰ Запланировано: $planned\n"
        . '🕘 Последняя рассылка: ' . ($last ?? 'ещё не было')
    );
}

function cmdDelete(array $m): void
{
    $list = rows(
        'SELECT id, sent_at, preview FROM history WHERE ts > ? AND deleted = 0 ORDER BY id DESC LIMIT 5',
        [microtime(true) - 47 * 3600]
    );
    if (!$list) {
        answer($m, "📭 Нет рассылок, которые можно удалить.\nTelegram позволяет удалять сообщения только в течение 48 часов после отправки.");
        return;
    }
    $buttons = [];
    foreach ($list as [$hid, $when, $preview]) {
        $buttons[] = [btn('🗑 ' . mb_substr($when, 0, 16) . ' — ' . mb_substr($preview, 0, 22), "del:$hid")];
    }
    send(
        $m['chat']['id'],
        "🗑 Какую рассылку удалить у всех студентов?\n(доступны рассылки за последние 48 часов)",
        kb($buttons)
    );
}

function cbDeleteBroadcast(array $cb, array $p): void
{
    $hid = (int)($p[1] ?? 0);
    $byChat = [];
    foreach (rows('SELECT chat_id, message_id FROM sent WHERE history_id = ?', [$hid]) as [$chat, $mid]) {
        $byChat[(int)$chat][] = (int)$mid;
    }
    ack($cb);
    edit($cb, '⏳ Удаляю сообщения у студентов...');

    $ok = 0;
    foreach ($byChat as $chat => $mids) {
        try {
            foreach (array_chunk($mids, 100) as $chunk) {
                tg('deleteMessages', ['chat_id' => $chat, 'message_ids' => $chunk]);
            }
            $ok++;
        } catch (TgError $e) {
        }
        usleep(50000);
    }
    run('UPDATE history SET deleted = 1 WHERE id = ?', [$hid]);
    run('DELETE FROM sent WHERE history_id = ?', [$hid]);
    edit($cb, "🗑 Рассылка удалена у студентов: $ok из " . count($byChat));
}

// ---------- Вопросы студентов и ответы преподавателей ----------
function forwardStudentQuestion(array $m): void
{
    $uid = (int)$m['from']['id'];
    $r = row('SELECT s.full_name, g.name FROM students s LEFT JOIN groups g ON g.id = s.group_id WHERE s.id = ?', [$uid]);
    $who = $r ? "{$r[0]} ({$r[1]})" : fullName($m['from']);

    $delivered = 0;
    foreach (teacherIds() as $tid) {
        try {
            $head = send($tid, "💬 Вопрос от студента: $who\n↩️ Ответьте на это сообщение через Reply (Ответить), и ваш ответ уйдёт студенту.");
            $copy = tg('copyMessage', [
                'chat_id'      => $tid,
                'from_chat_id' => $m['chat']['id'],
                'message_id'   => $m['message_id'],
            ]);
            foreach ([$head['message_id'], $copy['message_id']] as $mid) {
                run('INSERT INTO questions (teacher_id, message_id, student_id) VALUES (?, ?, ?)', [$tid, (int)$mid, $uid]);
            }
            $delivered++;
        } catch (TgError $e) {
        }
    }
    answer(
        $m,
        $delivered > 0
            ? '📨 Ваш вопрос передан преподавателю. Ответ придёт сюда.'
            : '⚠️ Не удалось передать вопрос. Попробуйте позже.'
    );
}

function tryTeacherReply(array $m): bool
{
    if (!isset($m['reply_to_message'])) {
        return false;
    }
    $uid = (int)$m['from']['id'];
    $sid = val(
        'SELECT student_id FROM questions WHERE teacher_id = ? AND message_id = ?',
        [$uid, (int)$m['reply_to_message']['message_id']]
    );
    if ($sid === null) {
        return false;
    }
    try {
        send((int)$sid, '👨‍🏫 Ответ преподавателя:');
        tg('copyMessage', [
            'chat_id'      => (int)$sid,
            'from_chat_id' => $m['chat']['id'],
            'message_id'   => $m['message_id'],
        ]);
        answer($m, '✅ Ответ отправлен студенту.');
    } catch (TgError $e) {
        answer($m, '⚠️ Не удалось отправить ответ (возможно, студент заблокировал бота).');
    }
    return true;
}

// ---------- Отложенные рассылки (/scheduled) ----------
function parseWhen(string $text): ?DateTime
{
    $text = trim($text);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?\s+(\d{1,2}):(\d{2})$/', $text, $x)) {
        $d = (int)$x[1];
        $mo = (int)$x[2];
        $y = (isset($x[3]) && $x[3] !== '') ? (int)$x[3] : (int)date('Y');
        $h = (int)$x[4];
        $mi = (int)$x[5];
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59) {
            return null;
        }
        return (new DateTime())->setDate($y, $mo, $d)->setTime($h, $mi, 0);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $text, $x)) {
        $h = (int)$x[1];
        $mi = (int)$x[2];
        if ($h > 23 || $mi > 59) {
            return null;
        }
        $d = (new DateTime())->setTime($h, $mi, 0);
        if ($d <= new DateTime()) {
            $d->modify('+1 day');
        }
        return $d;
    }
    return null;
}

function cbSchedMenu(array $cb, array $p): void
{
    $key = $p[1] ?? '';
    $gid = (int)($p[2] ?? 0);
    if (!ownsKey($key, (int)$cb['from']['id'])) {
        ack($cb);
        return;
    }
    if (!isset(State::$pending[$key])) {
        edit($cb, EXPIRED);
        ack($cb);
        return;
    }
    edit($cb, '⏰ Когда отправить рассылку?', kb([
        [btn('🕐 Через 1 час', "at:$key:$gid:h1")],
        [btn('🌅 Завтра в 08:00', "at:$key:$gid:tm")],
        [btn('✍️ Своё время', "at:$key:$gid:cu")],
        [btn('❌ Отмена', "cancel:$key")],
    ]));
    ack($cb);
}

function createScheduled(int $uid, string $key, int $gid, DateTime $when): string
{
    $data = State::$pending[$key] ?? null;
    if ($data === null) {
        return EXPIRED;
    }
    unset(State::$pending[$key]);
    run(
        'INSERT INTO scheduled (send_at, from_id, msg_ids, preview, group_id, by_name) VALUES (?, ?, ?, ?, ?, ?)',
        [$when->format('Y-m-d H:i:s'), $uid, implode(',', $data['ids']), $data['preview'], $gid, $data['by']]
    );
    return '⏰ Рассылка запланирована на ' . $when->format('d.m.Y H:i') . ".\n"
        . '🎯 Кому: ' . groupLabel($gid) . "\n\n"
        . "Список отложенных: /scheduled\n"
        . '⚠️ Не удаляйте исходное сообщение в чате с ботом до момента отправки.';
}

function cbSchedPick(array $cb, array $p): void
{
    $uid = (int)$cb['from']['id'];
    $key = $p[1] ?? '';
    $gid = (int)($p[2] ?? 0);
    $opt = $p[3] ?? '';
    if (!ownsKey($key, $uid)) {
        ack($cb);
        return;
    }
    if (!isset(State::$pending[$key])) {
        edit($cb, EXPIRED);
        ack($cb);
        return;
    }
    if ($opt === 'cu') {
        State::$awaitingTime[$uid] = [$key, $gid];
        edit($cb, "✍️ Напишите дату и время отправки, например:\n06.11 08:30 или просто 08:30\n\nОтмена: /cancel");
        ack($cb);
        return;
    }
    $when = $opt === 'h1' ? new DateTime('+1 hour') : (new DateTime('tomorrow'))->setTime(8, 0, 0);
    edit($cb, createScheduled($uid, $key, $gid, $when));
    ack($cb);
}

function scheduledView(): array
{
    $list = rows('SELECT id, send_at, preview, by_name, group_id FROM scheduled ORDER BY send_at');
    $lines = [];
    $buttons = [];
    foreach ($list as $i => [$id, $sendAt, $preview, $by, $gid]) {
        $when = date('d.m H:i', strtotime($sendAt));
        $lines[] = ($i + 1) . ". ⏰ $when\n   💬 $preview\n   🎯 " . groupLabel((int)$gid) . "\n   👨‍🏫 $by";
        $buttons[] = [btn('❌ Отменить №' . ($i + 1) . " ($when)", "sc:$id")];
    }
    return [$list, implode("\n\n", $lines), kb($buttons)];
}

function cmdScheduled(array $m): void
{
    [$list, $text, $markup] = scheduledView();
    if (!$list) {
        answer($m, '📭 Запланированных рассылок нет.');
        return;
    }
    send($m['chat']['id'], "⏰ Запланированные рассылки:\n\n" . $text, $markup);
}

function cbSchedCancel(array $cb, array $p): void
{
    run('DELETE FROM scheduled WHERE id = ?', [(int)($p[1] ?? 0)]);
    [$list, $text, $markup] = scheduledView();
    if (!$list) {
        edit($cb, '📭 Запланированных рассылок больше нет.');
    } else {
        edit($cb, "❌ Рассылка отменена.\n\n⏰ Осталось:\n\n" . $text, $markup);
    }
    ack($cb);
}

function runDueScheduled(): void
{
    $due = rows(
        'SELECT id, send_at, from_id, msg_ids, preview, group_id, by_name FROM scheduled WHERE send_at <= ? ORDER BY send_at',
        [date('Y-m-d H:i:s')]
    );
    foreach ($due as [$id, $sendAt, $from, $msgIds, $preview, $gid, $by]) {
        run('DELETE FROM scheduled WHERE id = ?', [(int)$id]);
        $ids = array_map('intval', explode(',', (string)$msgIds));
        $res = broadcast((int)$from, $ids, (string)$preview, (int)$gid, (string)$by);
        $late = time() - strtotime((string)$sendAt) > 180;
        $text = '⏰ Запланированная рассылка отправлена' . ($late ? ' (с опозданием: бот был выключен)' : '')
            . "\n\n" . report($res);
        try {
            send((int)$from, $text);
        } catch (TgError $e) {
        }
    }
}

// ---------- Резервная копия базы (/backup) ----------
function sendBackup(int $chat, bool $silent = false): void
{
    $tmp = BASE_DIR . '/backup_tmp.db';
    if (is_file($tmp)) {
        @unlink($tmp);
    }
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
    } catch (Throwable $e) {
        copy(BASE_DIR . '/students.db', $tmp);
    }
    try {
        tg('sendDocument', [
            'chat_id'              => $chat,
            'document'             => new CURLFile($tmp, 'application/octet-stream', 'backup_' . date('d-m-Y_H-i') . '.db'),
            'caption'              => '💾 Копия базы данных (студенты, преподаватели, группы, история).',
            'disable_notification' => $silent,
        ], 120);
    } finally {
        @unlink($tmp);
    }
}

function cmdBackup(array $m): void
{
    sendBackup((int)$m['chat']['id']);
}

function maybeAutoBackup(): void
{
    if (ADMIN_ID === 0) {
        return;
    }
    $last = (float)(val('SELECT value FROM meta WHERE key = ?', ['last_backup']) ?? 0);
    if (microtime(true) - $last < 86400) {
        return;
    }
    run('DELETE FROM sent WHERE history_id IN (SELECT id FROM history WHERE ts < ?)', [microtime(true) - 3 * 86400]);
    try {
        sendBackup(ADMIN_ID, true);
        run('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', ['last_backup', (string)microtime(true)]);
    } catch (Throwable $e) {
    }
}

function onTick(): void
{
    static $last = 0;
    if (time() - $last < 20) {
        return;
    }
    $last = time();
    runDueScheduled();
    maybeAutoBackup();
}

// ---------- Рассылка сообщений студентам ----------
function confirmText(string $key, int $gid): string
{
    $n = count(State::$pending[$key]['ids']);
    $extra = $n > 1 ? " (сообщений: $n)" : '';
    return '📣 Отправить ' . countStudents($gid) . ' студентам (' . groupLabel($gid) . ')?' . $extra;
}

function confirmMarkup(string $key, int $gid): array
{
    return kb([
        [btn('✅ Отправить', "send:$key:$gid:n"), btn('📌 С закреплением', "send:$key:$gid:p")],
        [btn('🔕 Без звука', "send:$key:$gid:s"), btn('⏰ Отложить', "sch:$key:$gid")],
        [btn('❌ Отмена', "cancel:$key")],
    ]);
}

function askTarget(array $m, array $ids, string $preview): void
{
    if (countStudents() === 0) {
        answer($m, '📭 Некому отправлять: пока нет подписанных студентов.');
        return;
    }

    $from = (int)$m['from']['id'];
    $key = $from . '_' . $ids[0];
    State::$pending[$key] = [
        'ids'     => $ids,
        'preview' => $preview,
        'from_id' => $from,
        'by'      => fullName($m['from']),
    ];

    $groups = rows('SELECT id, name FROM groups ORDER BY id');
    if (count($groups) === 1) {
        $gid = (int)$groups[0][0];
        replyTo($m, confirmText($key, $gid), confirmMarkup($key, $gid));
        return;
    }

    $buttons = [];
    foreach ($groups as [$gid, $name]) {
        $buttons[] = [btn('📚 ' . mb_substr($name, 0, 30) . ' (' . countStudents((int)$gid) . ')', "to:$key:$gid")];
    }
    $buttons[] = [btn('👥 Все группы (' . countStudents() . ')', "to:$key:0")];
    $buttons[] = [btn('❌ Отмена', "cancel:$key")];
    replyTo($m, '🎯 Кому отправить?', kb($buttons));
}

function flushAlbums(): void
{
    foreach (State::$albums as $gid => $a) {
        if (microtime(true) - $a['last'] < 1.5) {
            continue;
        }
        unset(State::$albums[$gid]);
        sort($a['ids']);
        $preview = ($a['preview'] !== '' ? $a['preview'] : '🖼 Альбом') . ' (альбом: ' . count($a['ids']) . ')';
        try {
            askTarget($a['msg'], $a['ids'], $preview);
        } catch (Throwable $e) {
            out('ERROR in album: ' . $e->getMessage());
        }
    }
}

function deliver(int $sid, int $from, array $ids, bool $silent, bool $pin): array
{
    $res = tg('copyMessages', [
        'chat_id'              => $sid,
        'from_chat_id'         => $from,
        'message_ids'          => $ids,
        'disable_notification' => $silent,
    ]);
    if ($pin && $res) {
        try {
            tg('pinChatMessage', ['chat_id' => $sid, 'message_id' => $res[0]['message_id']]);
        } catch (TgError $e) {
        }
    }
    return $res;
}

function broadcast(int $from, array $ids, string $preview, int $gid, string $by, bool $silent = false, bool $pin = false): array
{
    $students = $gid > 0
        ? rows('SELECT id, full_name FROM students WHERE group_id = ?', [$gid])
        : rows('SELECT id, full_name FROM students');

    $ok = 0;
    $blocked = [];
    $failed = [];
    $sentRows = [];
    foreach ($students as [$sid, $name]) {
        $name = $name ?: '?';
        try {
            $res = deliver((int)$sid, $from, $ids, $silent, $pin);
            $ok++;
            foreach ($res as $r) {
                $sentRows[] = [(int)$sid, (int)$r['message_id']];
            }
        } catch (TgError $e) {
            if ($e->tgCode === 403) {
                run('DELETE FROM students WHERE id = ?', [(int)$sid]);
                $blocked[] = $name;
            } else {
                $failed[] = $name;
            }
        }
        usleep(50000);
    }

    run(
        'INSERT INTO history (sent_at, preview, delivered, total, by_name, group_name, ts, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
        [nowStr(), $preview, $ok, count($students), $by, groupLabel($gid), microtime(true)]
    );
    $hid = (int)db()->lastInsertId();
    db()->beginTransaction();
    foreach ($sentRows as [$c, $mid]) {
        run('INSERT INTO sent (history_id, chat_id, message_id) VALUES (?, ?, ?)', [$hid, $c, $mid]);
    }
    db()->commit();

    return ['ok' => $ok, 'total' => count($students), 'blocked' => $blocked, 'failed' => $failed];
}

function report(array $res, bool $pin = false, bool $silent = false): string
{
    $text = "✅ Готово! Доставлено: {$res['ok']} из {$res['total']}";
    if ($pin) {
        $text .= "\n📌 Сообщение закреплено";
    }
    if ($silent) {
        $text .= "\n🔕 Отправлено без звука";
    }
    if ($res['blocked']) {
        $text .= "\n🚫 Заблокировали бота (удалены из списка): " . namesLine($res['blocked']);
    }
    if ($res['failed']) {
        $text .= "\n⚠️ Не удалось отправить: " . namesLine($res['failed']);
    }
    return $text;
}

function cbSend(array $cb, string $key, int $gid, string $mode): void
{
    $data = State::$pending[$key] ?? null;
    if ($data === null) {
        edit($cb, EXPIRED);
        ack($cb);
        return;
    }
    unset(State::$pending[$key]);

    $silent = $mode === 's';
    $pin = $mode === 'p';
    edit($cb, '⏳ Отправляю рассылку...');
    ack($cb);
    $res = broadcast($data['from_id'], $data['ids'], $data['preview'], $gid, $data['by'], $silent, $pin);
    edit($cb, report($res, $pin, $silent));
}

// ---------- Обработка сообщений преподавателя ----------
function onTeacherMessage(array $m): void
{
    $uid = (int)$m['from']['id'];
    $text = trim($m['text'] ?? '');

    // 1) Преподаватель вводит дату/время для отложенной рассылки
    if (isset(State::$awaitingTime[$uid])) {
        if ($text === '') {
            answer($m, "✍️ Напишите дату и время текстом (например: 06.11 08:30 или 08:30).\nОтмена: /cancel");
            return;
        }
        [$key, $gid] = State::$awaitingTime[$uid];
        $when = parseWhen($text);
        if ($when === null) {
            answer($m, "⚠️ Не понял время. Пример: 06.11 08:30 или просто 08:30.\nОтмена: /cancel");
            return;
        }
        if ($when <= new DateTime()) {
            answer($m, "⏰ Это время уже прошло. Напишите будущее время.\nОтмена: /cancel");
            return;
        }
        unset(State::$awaitingTime[$uid]);
        answer($m, createScheduled($uid, $key, (int)$gid, $when));
        return;
    }

    // 2) Преподаватель вводит данные после нажатия /newgroup, /find или /addteacher
    if (isset(State::$awaitingInput[$uid])) {
        $mode = State::$awaitingInput[$uid];
        unset(State::$awaitingInput[$uid]);

        if ($mode === 'newgroup') {
            createGroup($m, $text);
            return;
        }
        if ($mode === 'find') {
            runFindStudent($m, $text);
            return;
        }
        if ($mode === 'addteacher') {
            if (isset($m['forward_from']['id'])) {
                $f = $m['forward_from'];
                addTeacherById($m, (int)$f['id'], fullName($f));
                return;
            }
            if (isset($m['forward_origin']['sender_user']['id'])) {
                $f = $m['forward_origin']['sender_user'];
                addTeacherById($m, (int)$f['id'], fullName($f));
                return;
            }
            if (preg_match('/^\d+$/', $text)) {
                addTeacherById($m, (int)$text);
                return;
            }
            answer($m, "⚠️ Не удалось определить ID (возможно, у пользователя скрыта пересылка сообщений).\nПопросите его написать боту /id и пришлите число командой:\n/addteacher 123456789");
            return;
        }
    }

    // 3) Ответ преподавателя на вопрос студента через Reply
    if (tryTeacherReply($m)) {
        return;
    }

    // 4) Обычное сообщение или альбом для рассылки студентам
    $gid = $m['media_group_id'] ?? null;
    if ($gid === null) {
        askTarget($m, [(int)$m['message_id']], describe($m));
        return;
    }

    if (!isset(State::$albums[$gid])) {
        State::$albums[$gid] = ['ids' => [], 'preview' => '', 'last' => 0.0, 'msg' => $m];
    }
    State::$albums[$gid]['ids'][] = (int)$m['message_id'];
    if (!empty($m['caption'])) {
        State::$albums[$gid]['preview'] = describe($m);
    }
    State::$albums[$gid]['last'] = microtime(true);
}

// ---------- Обработка сообщений студентов ----------
function onStudentMessage(array $m): void
{
    $uid = (int)$m['from']['id'];
    $text = trim($m['text'] ?? '');

    // 1) Студент вводит имя и фамилию после перехода по ссылке
    $gid = val('SELECT group_id FROM awaiting_name WHERE user_id = ?', [$uid]);
    if ($gid !== null) {
        if ($text === '' || $text[0] === '/') {
            answer($m, '✍️ Напишите ваши настоящие имя и фамилию обычным текстом.');
            return;
        }
        $full = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $text)), 0, 60);
        if (!validName($full)) {
            answer($m, "✍️ Напишите настоящие имя и фамилию (минимум два слова, например: Айбек Тураров).\nТолько буквы, без цифр и смайликов.");
            return;
        }

        $gid = (int)$gid;
        // Если уже был подтверждён ранее (например, переходит в другую группу)
        if (val('SELECT 1 FROM students WHERE id = ?', [$uid]) !== null) {
            run(
                'INSERT OR REPLACE INTO students (id, name, full_name, group_id) VALUES (?, ?, ?, ?)',
                [$uid, fullName($m['from']), $full, $gid]
            );
            run('DELETE FROM awaiting_name WHERE user_id = ?', [$uid]);
            answer($m, "✅ Готово, $full! Вы переведены в группу «" . groupLabel($gid) . "».\n\n/stop — отписаться");
            return;
        }

        $username = isset($m['from']['username']) ? '@' . $m['from']['username'] : '';
        run(
            'INSERT OR REPLACE INTO requests (student_id, name, full_name, group_id, username) VALUES (?, ?, ?, ?, ?)',
            [$uid, fullName($m['from']), $full, $gid, $username]
        );
        run('DELETE FROM awaiting_name WHERE user_id = ?', [$uid]);

        answer($m, "⏳ Заявка отправлена преподавателю. Как только он подтвердит её, вы начнёте получать рассылки.\n\nℹ️ Ваши имя и фамилия ($full) будут видны преподавателю.");

        $reqText = "👤 Новая заявка на подписку\n"
            . "Имя: $full\n"
            . '📚 Группа: ' . groupLabel($gid) . "\n"
            . 'Telegram: ' . fullName($m['from']) . ($username !== '' ? " ($username)" : '');
        $markup = kb([[btn('✅ Принять', "ra:$uid"), btn('❌ Отклонить', "rr:$uid")]]);
        foreach (teacherIds() as $tid) {
            try {
                send((int)$tid, $reqText, $markup);
            } catch (TgError $e) {
            }
        }
        return;
    }

    // 2) Заявка ещё на рассмотрении
    if (val('SELECT 1 FROM requests WHERE student_id = ?', [$uid]) !== null
        && val('SELECT 1 FROM students WHERE id = ?', [$uid]) === null) {
        answer($m, '⏳ Ваша заявка ещё ждёт подтверждения преподавателя.');
        return;
    }

    // 3) Неподписанный пользователь
    if (val('SELECT 1 FROM students WHERE id = ?', [$uid]) === null) {
        answer($m, '🔒 Чтобы пользоваться ботом, откройте ссылку-приглашение от вашего преподавателя.');
        return;
    }

    if ($text !== '' && $text[0] === '/') {
        answer($m, "ℹ️ Неизвестная команда.\nДоступные команды: /start, /stop, /id");
        return;
    }

    // 4) Вопрос от подписанного студента
    forwardStudentQuestion($m);
}

// ---------- Кнопки (Callback Query) ----------
function onCallback(array $cb): void
{
    $uid = (int)$cb['from']['id'];
    if (!isTeacher($uid) || !isset($cb['message'])) {
        ack($cb);
        return;
    }
    $p = explode(':', (string)($cb['data'] ?? ''));
    $act = $p[0];
    $key = $p[1] ?? '';

    // Кнопки только для главного преподавателя
    if ($act === 'tr') {
        if (isOwner($uid)) {
            cbRemoveTeacher($cb, $p);
        } else {
            ack($cb, 'Только главный преподаватель может управлять преподавателями.', true);
        }
        return;
    }

    // Кнопки для всех преподавателей
    switch ($act) {
        case 'gc':
            cbGroupCode($cb, $p);
            return;
        case 'gd':
            cbGroupDelete($cb, $p);
            return;
        case 'ra':
            cbAcceptRequest($cb, $p);
            return;
        case 'rr':
            cbRejectRequest($cb, $p);
            return;
        case 'rm':
            cbRemoveStudent($cb, $p);
            return;
        case 'del':
            cbDeleteBroadcast($cb, $p);
            return;
        case 'sch':
            cbSchedMenu($cb, $p);
            return;
        case 'at':
            cbSchedPick($cb, $p);
            return;
        case 'sc':
            cbSchedCancel($cb, $p);
            return;
    }

    // Кнопки подтверждения отправки конкретной рассылки
    if (in_array($act, ['cancel', 'to', 'send'], true) && !ownsKey($key, $uid)) {
        ack($cb);
        return;
    }

    switch ($act) {
        case 'cancel':
            unset(State::$pending[$key], State::$awaitingTime[$uid]);
            edit($cb, '❌ Отменено.');
            ack($cb);
            break;
        case 'to':
            if (!isset(State::$pending[$key])) {
                edit($cb, EXPIRED);
                ack($cb);
                break;
            }
            $gid = (int)($p[2] ?? 0);
            edit($cb, confirmText($key, $gid), confirmMarkup($key, $gid));
            ack($cb);
            break;
        case 'send':
            cbSend($cb, $key, (int)($p[2] ?? 0), $p[3] ?? 'n');
            break;
        default:
            ack($cb);
    }
}

// ---------- Входящие сообщения ----------
function onMessage(array $m): void
{
    if (($m['chat']['type'] ?? '') !== 'private' || !isset($m['from'])) {
        return;
    }
    $uid = (int)$m['from']['id'];
    $cmd = parseCommand(trim($m['text'] ?? ''));

    if ($cmd !== null) {
        [$name, $args] = $cmd;

        if ($name === 'cancel') {
            cmdCancel($m);
            return;
        }

        // Любая другая команда сбрасывает ожидание ввода
        unset(State::$awaitingTime[$uid], State::$awaitingInput[$uid]);

        switch ($name) {
            case 'id':
                answer($m, "🆔 Ваш Telegram ID: $uid");
                return;
            case 'start':
                cmdStart($m, $args);
                return;
            case 'stop':
                cmdStop($m);
                return;
        }

        if (isTeacher($uid)) {
            switch ($name) {
                case 'groups':
                    cmdGroups($m);
                    return;
                case 'newgroup':
                    cmdNewGroup($m, $args);
                    return;
                case 'requests':
                    cmdRequests($m);
                    return;
                case 'list':
                    cmdList($m);
                    return;
                case 'find':
                    cmdFind($m, $args);
                    return;
                case 'remove':
                    cmdRemove($m);
                    return;
                case 'export':
                    cmdExport($m);
                    return;
                case 'history':
                    cmdHistory($m);
                    return;
                case 'delete':
                    cmdDelete($m);
                    return;
                case 'scheduled':
                    cmdScheduled($m);
                    return;
                case 'stats':
                    cmdStats($m);
                    return;
                case 'teachers':
                    if (isOwner($uid)) {
                        cmdTeachers($m);
                    } else {
                        answer($m, '⛔ Управление преподавателями доступно только главному преподавателю.');
                    }
                    return;
                case 'addteacher':
                    if (isOwner($uid)) {
                        cmdAddTeacher($m, $args);
                    } else {
                        answer($m, '⛔ Добавлять преподавателей может только главный преподаватель.');
                    }
                    return;
                case 'backup':
                    if (isOwner($uid)) {
                        cmdBackup($m);
                    } else {
                        answer($m, '⛔ Копия базы данных доступна только главному преподавателю.');
                    }
                    return;
                default:
                    answer($m, 'ℹ️ Неизвестная команда. Нажмите /start для просмотра списка команд.');
                    return;
            }
        }

        onStudentMessage($m);
        return;
    }

    if (isTeacher($uid)) {
        onTeacherMessage($m);
        return;
    }
    onStudentMessage($m);
}

function handleUpdate(array $u): void
{
    if (isset($u['message'])) {
        onMessage($u['message']);
    } elseif (isset($u['callback_query'])) {
        onCallback($u['callback_query']);
    }
}

// ---------- Запуск бота ----------
try {
    tg('deleteWebhook');
    $me = tg('getMe');
} catch (TgError $e) {
    out('ERROR: cannot connect to Telegram: ' . $e->getMessage());
    exit(1);
}
define('BOT_USERNAME', (string)$me['username']);

if (ADMIN_ID === 0) {
    out('WARNING: ADMIN_ID is not set in .env. Send /id to the bot, put the number into .env and restart.');
}

try {
    setupMenusOnStartup();
} catch (Throwable $e) {
    out('ERROR in startup menu setup: ' . $e->getMessage());
}

out('Bot started: @' . BOT_USERNAME . ' (ADMIN_ID: ' . ADMIN_ID . '). Stop: Ctrl+C');

$offset = 0;
while (true) {
    $wait = State::$albums ? 1 : 10;
    try {
        $updates = tg('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $wait,
            'allowed_updates' => ['message', 'callback_query'],
        ], $wait + 15);
    } catch (TgError $e) {
        out('ERROR: ' . $e->getMessage());
        if ($e->tgCode === 401) {
            out('The token is wrong. Check .env');
            exit(1);
        }
        if ($e->tgCode === 409) {
            out('Another copy of the bot is running! Close other terminals.');
        }
        sleep(3);
        continue;
    }

    foreach ($updates as $u) {
        $offset = $u['update_id'] + 1;
        try {
            handleUpdate($u);
        } catch (Throwable $e) {
            out('ERROR in handler: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        }
    }

    flushAlbums();

    try {
        onTick();
    } catch (Throwable $e) {
        out('ERROR in tick: ' . $e->getMessage());
    }
}
