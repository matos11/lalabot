<?php
/**
 * LALA BINGO — Admin Console (roles & privileges + Firebase Deposits, Withdrawals & Admins)
 * Requires PHP 7.4+ with cURL. Single file, Firebase Realtime Database (REST) + Telegram Bot API.
 */
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
date_default_timezone_set('Africa/Addis_Ababa');

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '');
define('GAME_URL', getenv('GAME_URL') ?: 'https://lalabingobot.vercel.app/');
define('BASE_FIREBASE', rtrim(getenv('BASE_FIREBASE') ?: 'https://lalabingobot-default-rtdb.firebaseio.com', '/') . '/');
define('FIREBASE_AUTH', getenv('FIREBASE_AUTH') ?: '');
define('ROOT_USER', getenv('ADMIN_USER') ?: 'admin');
define('ROOT_PASS', getenv('ADMIN_PASS') ?: 'admin123');
define('DEFAULT_CREDS', !getenv('ADMIN_PASS'));

/* ───────────── Roles & privileges ───────────── */
function permList(): array {
    return [
        'users.view' => 'View players', 'users.balance' => 'Adjust balances & bonuses', 'users.ban' => 'Ban, VIP & notes',
        'users.message' => 'Message players', 'users.delete' => 'Delete players',
        'deposits.view' => 'View deposits', 'deposits.process' => 'Approve / reject deposits',
        'withdrawals.view' => 'View withdrawals', 'withdrawals.process' => 'Approve / reject withdrawals',
        'broadcast.send' => 'Send broadcasts', 'settings.manage' => 'Game & bot settings',
        'logs.view' => 'View audit log & ledger', 'export.data' => 'Export CSV', 'admins.manage' => 'Manage admins',
    ];
}
function roleList(): array {
    $all = array_keys(permList());
    return [
        'superadmin' => ['label' => 'Super admin', 'desc' => 'Everything, including admin accounts', 'perms' => $all],
        'manager'    => ['label' => 'Manager', 'desc' => 'Runs the game day to day', 'perms' => array_values(array_diff($all, ['admins.manage']))],
        'finance'    => ['label' => 'Finance', 'desc' => 'Deposits, withdrawals, exports', 'perms' => ['users.view', 'deposits.view', 'deposits.process', 'withdrawals.view', 'withdrawals.process', 'export.data', 'logs.view']],
        'support'    => ['label' => 'Support', 'desc' => 'Helps players, moderates', 'perms' => ['users.view', 'users.ban', 'users.message', 'deposits.view', 'withdrawals.view']],
        'viewer'     => ['label' => 'Viewer', 'desc' => 'Read-only', 'perms' => ['users.view', 'deposits.view', 'withdrawals.view']],
    ];
}
function effPerms(array $a): array {
    $roles = roleList();
    $role = $roles[$a['role'] ?? 'manager'] ?? $roles['viewer'];
    if (isset($a['perms']) && is_string($a['perms'])) {
        return array_values(array_intersect(array_filter(explode(',', $a['perms'])), array_keys(permList())));
    }
    return $role['perms'];
}
function can(string $p): bool { return in_array($p, $GLOBALS['ME']['perms'] ?? [], true); }

/* ───────────── Firebase + Telegram ───────────── */
function fb(string $m, string $path, $data = null, array $q = [], array $hdr = [], &$rh = null, &$code = null) {
    if (FIREBASE_AUTH) $q['auth'] = FIREBASE_AUTH;
    $rh = [];
    $ch = curl_init(BASE_FIREBASE . $path . '.json' . ($q ? '?' . http_build_query($q) : ''));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$rh) {
            $p = explode(':', $l, 2);
            if (count($p) === 2) $rh[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($l);
        },
    ]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r !== false && $r !== '') ? json_decode($r, true) : null;
}
function fbGet(string $p, array $q = []) { return fb('GET', $p, null, $q); }
function fbPut(string $p, $d) { return fb('PUT', $p, $d); }
function fbPatch(string $p, array $d) { return fb('PATCH', $p, $d); }
function fbDel(string $p) { return fb('DELETE', $p); }
function k($s): string { return rawurlencode((string)$s); }

/** Concurrency-safe balance change (ETag compare-and-set) */
function adjustBalance(string $uid, float $delta, &$after = null): bool {
    for ($i = 0; $i < 6; $i++) {
        $h = []; $c = 0;
        $cur = fb('GET', "users/" . k($uid) . "/balance", null, [], ['X-Firebase-ETag: true'], $h, $c);
        $new = round((float)$cur + $delta, 2);
        if ($new < 0) return false;
        $h2 = []; $c2 = 0;
        fb('PUT', "users/" . k($uid) . "/balance", $new, [], isset($h['etag']) ? ['if-match: ' . $h['etag']] : [], $h2, $c2);
        if ($c2 === 200) { $after = $new; return true; }
        usleep(120000);
    }
    return false;
}
/** Move a request to a new status only once. Returns the previous status, or false if already final. */
function casStatus(string $path, string $to, array $final) {
    for ($i = 0; $i < 5; $i++) {
        $h = []; $c = 0;
        $cur = fb('GET', "$path/status", null, [], ['X-Firebase-ETag: true'], $h, $c);
        $prev = is_string($cur) ? $cur : 'pending';
        if (in_array($prev, $final, true)) return false;
        $h2 = []; $c2 = 0;
        fb('PUT', "$path/status", $to, [], isset($h['etag']) ? ['if-match: ' . $h['etag']] : [], $h2, $c2);
        if ($c2 === 200) return $prev;
        usleep(100000);
    }
    return false;
}
function syncUserOne(string $uid): void {
    $u = fbGet("users/" . k($uid));
    if (is_array($u) && !empty($u['phone'])) fbPut('userone/' . preg_replace('/[.#$\[\]\/]/', '_', (string)$u['phone']), $u);
}
function tg(string $method, array $params): array {
    if (BOT_TOKEN === '') return ['ok' => false, 'description' => 'BOT_TOKEN is not set'];
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true) ?: ['ok' => false];
}
function sendOne(string $chat, string $text, array $kb, $img = null): bool {
    $p = ['chat_id' => $chat, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($kb)];
    if ($img) { $p['photo'] = $img; $p['caption'] = $text; $m = 'sendPhoto'; } else { $p['text'] = $text; $m = 'sendMessage'; }
    $r = tg($m, $p);
    if (($r['error_code'] ?? 0) == 429) { sleep(min(10, (int)($r['parameters']['retry_after'] ?? 2))); $r = tg($m, $p); }
    return !empty($r['ok']);
}
function settingsDefaults(): array {
    return ['game_enabled' => true, 'maintenance_msg' => 'The game is paused for maintenance. Please check back soon.', 'entry_fee' => 10, 'commission_pct' => 10,
        'min_deposit' => 10, 'min_withdraw' => 50, 'max_withdraw' => 5000, 'welcome_bonus' => 0, 'telebirr_name' => 'YISAK', 'telebirr_number' => '0979652325', 'cbe_account' => '', 'notify_users' => true];
}
function notifyUser(string $uid, string $text): void {
    global $SET;
    if (!empty($SET['notify_users']) && $uid !== '') tg('sendMessage', ['chat_id' => $uid, 'text' => $text, 'parse_mode' => 'HTML']);
}

/* ───────────── Small helpers ───────────── */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((float)$n, 2); }
function ts($r): int {
    foreach (['processed_at', 'approved_at', 'created_at', 'timestamp', 'time', 'date'] as $f) {
        if (isset($r[$f]) && is_numeric($r[$f])) { $v = (float)$r[$f]; return (int)($v > 1e12 ? $v / 1000 : $v); }
    }
    return 0;
}
function fdate(int $t): string { return $t ? date('d M Y, H:i', $t) : '—'; }
function uidOf($key, $u): string { return preg_replace('/\D/', '', (string)($u['telegram_id'] ?? $key)); }
function chip(string $s): string {
    $m = ['processed' => 'ok', 'approved' => 'ok', 'pending' => 'warn', 'rejected' => 'bad', 'banned' => 'bad', 'vip' => 'vip', 'active' => 'ok', 'disabled' => 'bad'];
    return '<span class="chip ' . ($m[$s] ?? '') . '">' . e($s) . '</span>';
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }
function go(string $tab = 'dashboard', array $q = []): void { header('Location: admin.php?' . http_build_query(['tab' => $tab] + $q)); exit; }
function back(): void { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
function need(string $p): void { if (!can($p)) { flash('Your role does not allow that action.', 'bad'); go('dashboard'); } }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">'; }
function audit(string $action, string $detail = ''): void {
    global $ME;
    fb('POST', 'admin_logs', ['by' => $ME['username'] ?? '?', 'role' => $ME['role'] ?? '', 'action' => $action, 'detail' => $detail, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'at' => time()]);
}
function ledger(string $uid, string $type, float $amount, float $after, string $note = ''): void {
    global $ME;
    fb('POST', 'transactions', ['telegram_id' => $uid, 'type' => $type, 'amount' => $amount, 'balance_after' => $after, 'note' => $note, 'by' => $ME['username'] ?? '', 'at' => time()]);
}
function segmentUsers(array $users, string $seg): array {
    $out = [];
    foreach ($users as $key => $u) {
        if (!is_array($u) || !empty($u['banned'])) continue;
        $bal = (float)($u['balance'] ?? 0);
        if ($seg === 'funded' && $bal <= 0) continue;
        if ($seg === 'empty' && $bal > 0) continue;
        if ($seg === 'vip' && empty($u['vip'])) continue;
        $id = uidOf($key, $u);
        if ($id !== '') $out[] = $id;
    }
    return $out;
}
function guardGrant(string $role, array $perms): void {
    global $ME;
    if ($ME['role'] !== 'superadmin' && ($role === 'superadmin' || in_array('admins.manage', $perms, true))) {
        flash('Only a Super admin can grant admin-management rights.', 'bad'); back();
    }
}
function csvOut(string $name, array $head, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Ymd-His') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head);
    foreach ($rows as $r) fputcsv($o, array_map(function ($v) { $v = (string)$v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }, $r));
    exit;
}

/* ───────────── Auth ───────────── */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$POST = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($POST && !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { flash('Session expired. Please try again.', 'bad'); back(); }

if (isset($_GET['logout']) && hash_equals($_SESSION['csrf'], (string)$_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

if ($POST && isset($_POST['login'])) {
    if (($_SESSION['lock'] ?? 0) > time()) { flash('Too many attempts. Wait a few minutes and try again.', 'bad'); back(); }
    $u = trim((string)($_POST['username'] ?? '')); $p = (string)($_POST['password'] ?? ''); $ok = null;
    if (hash_equals(ROOT_USER, $u) && hash_equals(ROOT_PASS, $p)) $ok = 'root';
    else {
        foreach (fbGet('admins') ?: [] as $id => $a) {
            if (!is_array($a) || ($a['username'] ?? '') !== $u || !($a['active'] ?? true)) continue;
            if (isset($a['pass_hash']) && password_verify($p, $a['pass_hash'])) $ok = $id;
            elseif (isset($a['password']) && hash_equals((string)$a['password'], $p)) { 
                $ok = $id;
                fbPatch("admins/" . k($id), ['pass_hash' => password_hash($p, PASSWORD_DEFAULT), 'password' => null, 'role' => $a['role'] ?? 'manager']);
            }
            if ($ok) break;
        }
    }
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['aid'] = $ok; $_SESSION['fails'] = 0;
        if ($ok !== 'root') fbPatch("admins/" . k($ok), ['last_login' => time()]);
        $ME = ['username' => $u, 'role' => $ok === 'root' ? 'superadmin' : 'x']; audit('login');
        go();
    }
    $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
    if ($_SESSION['fails'] >= 5) { $_SESSION['lock'] = time() + 300; $_SESSION['fails'] = 0; }
    usleep(800000); flash('Wrong username or password.', 'bad'); back();
}

$ME = null;
if (!empty($_SESSION['aid'])) {
    if ($_SESSION['aid'] === 'root') $ME = ['id' => 'root', 'username' => ROOT_USER, 'role' => 'superadmin', 'perms' => array_keys(permList()), 'limit' => 0];
    else {
        $a = fbGet('admins/' . k($_SESSION['aid']));
        if (is_array($a) && ($a['active'] ?? true)) {
            $a['role'] = $a['role'] ?? 'manager';
            $ME = ['id' => $_SESSION['aid'], 'username' => $a['username'] ?? '?', 'role' => $a['role'], 'perms' => effPerms($a), 'limit' => (float)($a['limit'] ?? 0)];
        } else { session_destroy(); header('Location: admin.php'); exit; }
    }
}

$SET = array_merge(settingsDefaults(), is_array($s = ($ME ? fbGet('settings') : null)) ? $s : []);
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ───────────── Exports ───────────── */
if ($ME && isset($_GET['export'])) {
    need('export.data');
    $t = $_GET['export']; audit('export', $t);
    if ($t === 'users') { need('users.view'); $rows = [];
        foreach (fbGet('users') ?: [] as $key => $u) if (is_array($u)) $rows[] = [uidOf($key, $u), $u['first_name'] ?? '', $u['username'] ?? '', $u['phone'] ?? '', $u['balance'] ?? 0, !empty($u['banned']) ? 'yes' : 'no', !empty($u['vip']) ? 'yes' : 'no'];
        csvOut('players', ['telegram_id', 'first_name', 'username', 'phone', 'balance', 'banned', 'vip'], $rows); }
    if ($t === 'deposits') { need('deposits.view'); $rows = [];
        foreach (fbGet('deposits') ?: [] as $id => $d) if (is_array($d)) $rows[] = [$id, $d['telegram_id'] ?? '', $d['claimed_by'] ?? '', $d['amount'] ?? 0, $d['status'] ?? 'pending', fdate(ts($d))];
        csvOut('deposits', ['tx_id', 'telegram_id', 'claimed_by', 'amount', 'status', 'time'], $rows); }
    if ($t === 'withdrawals') { need('withdrawals.view'); $rows = [];
        foreach (fbGet('withdrawals') ?: [] as $id => $w) if (is_array($w)) $rows[] = [$id, $w['telegram_id'] ?? '', $w['first_name'] ?? '', $w['phone'] ?? '', $w['method'] ?? '', $w['account_details'] ?? '', $w['amount'] ?? 0, $w['status'] ?? 'pending', fdate(ts($w))];
        csvOut('withdrawals', ['id', 'telegram_id', 'first_name', 'phone', 'method', 'account', 'amount', 'status', 'time'], $rows); }
    go();
}

/* ───────────── Actions ───────────── */
if ($ME && $POST) {
    $act = $_POST['action'] ?? '';
    $lim = $ME['limit'];
    switch ($act) {

    case 'adjust_balance':
        need('users.balance');
        $uid = preg_replace('/\D/', '', (string)($_POST['uid'] ?? '')); $mode = $_POST['mode'] ?? 'add';
        $amt = round((float)($_POST['amount'] ?? 0), 2); $why = trim((string)($_POST['reason'] ?? ''));
        $u = $uid !== '' ? fbGet('users/' . k($uid)) : null;
        if (!is_array($u)) { flash('Player not found.', 'bad'); back(); }
        $cur = (float)($u['balance'] ?? 0);
        if ($amt < 0 || ($amt == 0 && $mode !== 'set')) { flash('Enter an amount above 0.', 'bad'); back(); }
        $delta = $mode === 'sub' ? -$amt : ($mode === 'set' ? $amt - $cur : $amt);
        if ($delta == 0) { flash('Balance is already ' . money($cur) . ' ETB.', 'warn'); back(); }
        if ($lim > 0 && abs($delta) > $lim) { flash('Your limit is ' . money($lim) . ' ETB per action.', 'bad'); back(); }
        if (!adjustBalance($uid, $delta, $after)) { flash('Could not update — the player may have too little balance or is mid-game. Try again.', 'bad'); back(); }
        ledger($uid, $mode === 'bonus' ? 'bonus' : 'adjust', $delta, $after, $why);
        syncUserOne($uid);
        audit('balance.' . $mode, "player $uid: " . ($delta > 0 ? '+' : '') . money($delta) . " → " . money($after) . ($why ? " ($why)" : ''));
        if ($mode === 'bonus') notifyUser($uid, '🎁 You received a bonus of <b>' . money($amt) . ' ETB</b>' . ($why ? "\n" . e($why) : ''));
        flash('Balance updated: ' . money($after) . ' ETB.'); back();

    case 'toggle_ban':
        need('users.ban'); $uid = preg_replace('/\D/', '', (string)$_POST['uid']); $ban = ($_POST['value'] ?? '') === '1';
        fbPatch('users/' . k($uid), ['banned' => $ban, 'banned_by' => $ban ? $ME['username'] : null, 'banned_at' => $ban ? time() : null]);
        audit($ban ? 'player.ban' : 'player.unban', $uid); flash($ban ? 'Player banned.' : 'Player unbanned.'); back();

    case 'toggle_vip':
        need('users.ban'); $uid = preg_replace('/\D/', '', (string)$_POST['uid']); $v = ($_POST['value'] ?? '') === '1';
        fbPatch('users/' . k($uid), ['vip' => $v]); audit($v ? 'player.vip' : 'player.unvip', $uid); flash($v ? 'Marked as VIP.' : 'VIP removed.'); back();

    case 'save_note':
        need('users.ban'); $uid = preg_replace('/\D/', '', (string)$_POST['uid']);
        fbPatch('users/' . k($uid), ['note' => mb_substr(trim((string)$_POST['note']), 0, 500)]); audit('player.note', $uid); flash('Note saved.'); back();

    case 'message_user':
        need('users.message'); $uid = preg_replace('/\D/', '', (string)$_POST['uid']); $txt = trim((string)$_POST['text']);
        if ($txt === '') { flash('Write a message first.', 'bad'); back(); }
        $r = tg('sendMessage', ['chat_id' => $uid, 'text' => $txt, 'parse_mode' => 'HTML']);
        audit('player.message', $uid); flash(!empty($r['ok']) ? 'Message sent.' : 'Telegram refused: ' . ($r['description'] ?? 'unknown error'), !empty($r['ok']) ? 'ok' : 'bad'); back();

    case 'delete_user':
        need('users.delete'); $uid = preg_replace('/\D/', '', (string)$_POST['uid']);
        $u = fbGet('users/' . k($uid));
        if (is_array($u) && !empty($u['phone'])) fbDel('userone/' . preg_replace('/[.#$\[\]\/]/', '_', (string)$u['phone']));
        fbDel('users/' . k($uid)); audit('player.delete', $uid); flash('Player deleted.'); go('users');

    case 'process_deposit':
        need('deposits.process');
        $id = (string)($_POST['tx_id'] ?? ''); $to = ($_POST['status'] ?? '') === 'rejected' ? 'rejected' : 'processed';
        $d = fbGet('deposits/' . k($id)); if (!is_array($d)) { flash('Deposit not found.', 'bad'); back(); }
        $amt = (float)($d['amount'] ?? 0);
        if ($to === 'processed' && $lim > 0 && $amt > $lim) { flash('This deposit is above your limit of ' . money($lim) . ' ETB.', 'bad'); back(); }
        $prev = casStatus('deposits/' . k($id), $to, ['processed']);
        if ($prev === false) { flash('Already processed — nothing changed.', 'warn'); back(); }
        fbPatch('deposits/' . k($id), ['processed_by' => $ME['username'], 'processed_at' => time()]);
        $tid = preg_replace('/\D/', '', (string)($d['telegram_id'] ?? ''));
        if ($to === 'processed' && $tid !== '') {
            if (!adjustBalance($tid, $amt, $after)) { fbPut('deposits/' . k($id) . '/status', $prev); flash('Could not credit the player. Deposit left as ' . $prev . '.', 'bad'); back(); }
            ledger($tid, 'deposit', $amt, $after, "Deposit $id"); syncUserOne($tid);
            notifyUser($tid, '✅ Your deposit of <b>' . money($amt) . ' ETB</b> was approved. Balance: <b>' . money($after) . ' ETB</b>');
        } elseif ($to === 'rejected' && $tid !== '') notifyUser($tid, '❌ Your deposit <code>' . e($id) . '</code> could not be verified and was rejected.');
        audit('deposit.' . $to, "$id · " . money($amt) . ' ETB');
        flash($to === 'processed' ? ($tid === '' ? 'Approved, but the deposit has no player ID — balance NOT changed.' : 'Deposit approved and credited.') : 'Deposit rejected.', $to === 'processed' && $tid === '' ? 'warn' : 'ok'); back();

    case 'process_withdrawal':
        need('withdrawals.process');
        $id = (string)($_POST['wdr_id'] ?? ''); $to = ($_POST['status'] ?? '') === 'rejected' ? 'rejected' : 'approved'; $why = trim((string)($_POST['reason'] ?? ''));
        $w = fbGet('withdrawals/' . k($id)); if (!is_array($w)) { flash('Withdrawal not found.', 'bad'); back(); }
        $amt = (float)($w['amount'] ?? 0);
        if ($to === 'approved' && $lim > 0 && $amt > $lim) { flash('This withdrawal is above your limit of ' . money($lim) . ' ETB.', 'bad'); back(); }
        $prev = casStatus('withdrawals/' . k($id), $to, ['approved', 'rejected']);
        if ($prev === false) { flash('Already handled — nothing changed.', 'warn'); back(); }
        fbPatch('withdrawals/' . k($id), ['processed_by' => $ME['username'], 'processed_at' => time(), 'reason' => $why]);
        $tid = preg_replace('/\D/', '', (string)($w['telegram_id'] ?? ''));
        if ($to === 'rejected' && $tid !== '') {
            if (!adjustBalance($tid, $amt, $after)) { fbPut('withdrawals/' . k($id) . '/status', $prev); flash('Could not refund the player. Request left as ' . $prev . '.', 'bad'); back(); }
            ledger($tid, 'refund', $amt, $after, "Withdrawal $id rejected"); syncUserOne($tid);
            notifyUser($tid, '↩️ Your withdrawal of <b>' . money($amt) . ' ETB</b> was rejected and refunded.' . ($why ? "\n" . e($why) : ''));
        } elseif ($to === 'approved' && $tid !== '') notifyUser($tid, '💸 Your withdrawal of <b>' . money($amt) . ' ETB</b> has been sent.');
        audit('withdrawal.' . $to, "$id · " . money($amt) . ' ETB'); flash($to === 'approved' ? 'Withdrawal marked as sent.' : 'Withdrawal rejected and refunded.'); back();

    case 'send_broadcast':
        need('broadcast.send');
        $text = trim((string)($_POST['message'] ?? '')); $seg = $_POST['segment'] ?? 'all';
        if ($text === '') { flash('Write the message first.', 'bad'); back(); }
        $btnT = trim((string)($_POST['btn_text'] ?? '')) ?: '🌴 Play now'; $btnU = trim((string)($_POST['btn_url'] ?? '')) ?: GAME_URL;
        if (!preg_match('#^https://#', $btnU)) { flash('Button link must start with https://', 'bad'); back(); }
        $img = null;
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['image_file']['tmp_name'];
            if (getimagesize($tmp) === false || $_FILES['image_file']['size'] > 10 * 1048576) { flash('Poster must be an image under 10 MB.', 'bad'); back(); }
            if (mb_strlen($text) > 1000) { flash('With a poster, the text must be under 1000 characters.', 'bad'); back(); }
            $img = new CURLFile($tmp, mime_content_type($tmp), $_FILES['image_file']['name']);
        }
        $test = preg_replace('/\D/', '', (string)($_POST['test_id'] ?? ''));
        $targets = $test !== '' ? [$test] : segmentUsers(fbGet('users') ?: [], $seg);
        if (!$targets) { flash('No players match that audience.', 'warn'); back(); }
        @set_time_limit(0); ignore_user_abort(true);
        $kb = ['inline_keyboard' => [[['text' => $btnT, 'web_app' => ['url' => $btnU]]]]]; $okc = 0; $bad = 0;
        foreach ($targets as $chat) { sendOne($chat, $text, $kb, $img) ? $okc++ : $bad++; usleep(40000); }
        if ($test === '') fb('POST', 'broadcasts', ['by' => $ME['username'], 'at' => time(), 'segment' => $seg, 'text' => mb_substr($text, 0, 140), 'ok' => $okc, 'fail' => $bad]);
        audit($test !== '' ? 'broadcast.test' : 'broadcast.send', "$seg · ok $okc / fail $bad");
        flash("Broadcast finished — delivered $okc, failed $bad.", $bad && !$okc ? 'bad' : 'ok'); back();

    case 'toggle_game':
        need('settings.manage'); $on = ($_POST['value'] ?? '') === '1';
        fbPatch('settings', ['game_enabled' => $on]); audit('game.' . ($on ? 'resume' : 'pause')); flash($on ? 'Game is live.' : 'Game paused.'); back();

    case 'save_settings':
        need('settings.manage');
        $new = ['game_enabled' => isset($_POST['game_enabled']), 'notify_users' => isset($_POST['notify_users']), 'maintenance_msg' => trim((string)$_POST['maintenance_msg']),
            'telebirr_name' => trim((string)$_POST['telebirr_name']), 'telebirr_number' => trim((string)$_POST['telebirr_number']), 'cbe_account' => trim((string)$_POST['cbe_account'])];
        foreach (['entry_fee', 'commission_pct', 'min_deposit', 'min_withdraw', 'max_withdraw', 'welcome_bonus'] as $f) $new[$f] = max(0, (float)($_POST[$f] ?? 0));
        $new['commission_pct'] = min(100, $new['commission_pct']);
        fbPatch('settings', $new); audit('settings.save'); flash('Settings saved.'); back();

    case 'add_admin':
        need('admins.manage');
        $un = trim((string)$_POST['username']); $pw = (string)$_POST['password']; $role = $_POST['role'] ?? 'support';
        $perms = array_values(array_intersect((array)($_POST['perms'] ?? []), array_keys(permList()))); $limit = max(0, (float)($_POST['limit'] ?? 0));
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $un)) { flash('Username: 3–32 letters, numbers, . _ -', 'bad'); back(); }
        if (strlen($pw) < 8) { flash('Password needs at least 8 characters.', 'bad'); back(); }
        if (!isset(roleList()[$role])) { flash('Unknown role.', 'bad'); back(); }
        $taken = hash_equals(ROOT_USER, $un); foreach (fbGet('admins') ?: [] as $a) if (($a['username'] ?? '') === $un) $taken = true;
        if ($taken) { flash('That username is already used.', 'bad'); back(); }
        guardGrant($role, $perms);
        $rec = ['username' => $un, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'limit' => $limit, 'active' => true, 'created_at' => time(), 'created_by' => $ME['username']];
        $def = roleList()[$role]['perms']; $a1 = $perms; $a2 = $def; sort($a1); sort($a2);
        if ($a1 !== $a2) $rec['perms'] = implode(',', $perms);
        fbPut('admins/' . uniqid('adm_'), $rec); audit('admin.add', "$un ($role)"); flash("Admin $un created."); back();

    case 'update_admin':
        need('admins.manage'); $id = (string)$_POST['id']; $t = fbGet('admins/' . k($id));
        if (!is_array($t)) { flash('Admin not found.', 'bad'); back(); }
        if (($t['role'] ?? '') === 'superadmin' && $ME['role'] !== 'superadmin') { flash('Only a Super admin can edit a Super admin.', 'bad'); back(); }
        $role = $_POST['role'] ?? 'support'; if (!isset(roleList()[$role])) { flash('Unknown role.', 'bad'); back(); }
        $perms = array_values(array_intersect((array)($_POST['perms'] ?? []), array_keys(permList()))); guardGrant($role, $perms);
        $active = isset($_POST['active']); if ($id === $ME['id'] && !$active) { flash('You cannot disable your own account.', 'bad'); back(); }
        $def = roleList()[$role]['perms']; $a1 = $perms; $a2 = $def; sort($a1); sort($a2);
        $upd = ['role' => $role, 'limit' => max(0, (float)($_POST['limit'] ?? 0)), 'active' => $active, 'perms' => $a1 !== $a2 ? implode(',', $perms) : null];
        $np = (string)($_POST['new_password'] ?? '');
        if ($np !== '') { if (strlen($np) < 8) { flash('New password needs at least 8 characters.', 'bad'); back(); } $upd['pass_hash'] = password_hash($np, PASSWORD_DEFAULT); }
        fbPatch('admins/' . k($id), $upd); audit('admin.update', $t['username'] ?? $id); flash('Admin updated.'); back();

    case 'delete_admin':
        need('admins.manage'); $id = (string)$_POST['id']; $t = fbGet('admins/' . k($id));
        if ($id === $ME['id']) { flash('You cannot delete yourself.', 'bad'); back(); }
        if (is_array($t) && ($t['role'] ?? '') === 'superadmin' && $ME['role'] !== 'superadmin') { flash('Only a Super admin can delete a Super admin.', 'bad'); back(); }
        fbDel('admins/' . k($id)); audit('admin.delete', $t['username'] ?? $id); flash('Admin deleted.'); back();

    case 'change_password':
        if ($ME['id'] === 'root') { flash('The root account password is set with the ADMIN_PASS environment variable.', 'warn'); back(); }
        $a = fbGet('admins/' . k($ME['id'])); $np = (string)$_POST['new_password'];
        if (!is_array($a) || !password_verify((string)$_POST['current_password'], $a['pass_hash'] ?? '')) { flash('Current password is wrong.', 'bad'); back(); }
        if (strlen($np) < 8) { flash('New password needs at least 8 characters.', 'bad'); back(); }
        fbPatch('admins/' . k($ME['id']), ['pass_hash' => password_hash($np, PASSWORD_DEFAULT)]); audit('password.change'); flash('Password changed.'); back();
    }
}

/* ───────────── Page routing ───────────── */
$nav = [
    'dashboard'   => ['📊', 'Dashboard', 'ዳሽቦርድ', null],
    'users'       => ['👥', 'Players', 'ተጫዋቾች', 'users.view'],
    'deposits'    => ['📥', 'Deposits', 'ብር ማስገቢያ', 'deposits.view'],
    'withdrawals' => ['📤', 'Withdrawals', 'ብር ማውጫ', 'withdrawals.view'],
    'broadcast'   => ['📢', 'Broadcast', 'ብሮድካስት', 'broadcast.send'],
    'settings'    => ['🎛️', 'Game settings', 'ቅንብር', 'settings.manage'],
    'logs'        => ['🧾', 'Audit & ledger', 'ታሪክ', 'logs.view'],
    'admins'      => ['🛡️', 'Admins & roles', 'አስተዳዳሪዎች', 'admins.manage'],
];
$tab = $_GET['tab'] ?? 'dashboard';
$tabKey = $tab === 'user' ? 'users' : $tab;
if ($ME && $tab !== 'account' && (!isset($nav[$tabKey]) || ($nav[$tabKey][3] && !can($nav[$tabKey][3])))) $tab = $tabKey = 'dashboard';

/* ───────────── Data for the current page ───────────── */
$users = $deposits = $withdrawals = [];
if ($ME) {
    if (in_array($tab, ['dashboard', 'users', 'user', 'broadcast'])) $users = array_filter(fbGet('users') ?: [], 'is_array');
    if (in_array($tab, ['dashboard', 'deposits', 'user'])) $deposits = array_filter(fbGet('deposits') ?: [], 'is_array');
    if (in_array($tab, ['dashboard', 'withdrawals', 'user'])) $withdrawals = array_filter(fbGet('withdrawals') ?: [], 'is_array');
    $byTime = function ($a, $b) { return ts($b) <=> ts($a); };
    uasort($deposits, $byTime); uasort($withdrawals, $byTime);
}
$perPage = 25;
$roles = roleList(); $perms = permList();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>LALA BINGO · Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Ethiopic:wght@400;600&display=swap" rel="stylesheet">
<script>try{var t=localStorage.getItem('lb-theme');if(t)document.documentElement.dataset.theme=t;else if(matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.dataset.theme='dark'}catch(e){}</script>
<style>
:root{--bg:#f2f5f4;--surface:#fff;--ink:#13201f;--muted:#5d6f6b;--line:#e0e7e5;--side:#10282b;--side-ink:#b9d0cb;--accent:#e9a100;--accent-ink:#1b1400;--accent-soft:#fff3d1;--ok:#17915a;--warn:#c9780f;--bad:#d1423a;--vip:#7a4ee0;--r:14px}
[data-theme=dark]{--bg:#0b1415;--surface:#122022;--ink:#e6f0ee;--muted:#8da39f;--line:#213436;--side:#081a1c;--accent-soft:#33290b}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-padding-top:80px}
body{font:15px/1.5 'Plus Jakarta Sans','Noto Sans Ethiopic',system-ui,sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
a{color:inherit}
.sidebar{width:252px;background:var(--side);color:var(--side-ink);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;flex-shrink:0}
.brand{display:flex;align-items:center;gap:12px;padding:22px 20px;color:#fff;font-weight:800;font-size:17px;letter-spacing:.02em}
.ball{width:36px;height:36px;border-radius:50%;background:radial-gradient(circle at 32% 28%,#fff 0 14%,transparent 15%),var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:15px;box-shadow:inset -3px -4px 0 rgba(0,0,0,.14)}
.brand small{display:block;font-weight:500;font-size:11.5px;color:var(--side-ink);letter-spacing:0}
.menu{list-style:none;padding:8px 12px;flex:1;overflow-y:auto}
.menu a{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;text-decoration:none;color:var(--side-ink);font-weight:600;margin-bottom:2px}
.menu a span.t small{display:block;font-weight:400;font-size:11.5px;opacity:.7}
.menu a:hover{background:rgba(255,255,255,.07)}
.menu a.on{background:var(--accent);color:var(--accent-ink)}
.menu a.on small{opacity:.75}
.me{padding:14px 16px;border-top:1px solid rgba(255,255,255,.1);font-size:13px}
.me b{color:#fff;display:block}
.main{flex:1;min-width:0;padding:0 28px 48px}
.top{display:flex;align-items:center;gap:12px;padding:18px 0;position:sticky;top:0;background:var(--bg);z-index:20}
.top h1{font-size:22px;font-weight:800;flex:1}
.top h1 small{font-weight:500;font-size:14px;color:var(--muted);margin-left:8px}
.iconbtn{background:var(--surface);border:1px solid var(--line);color:var(--ink);border-radius:10px;padding:8px 12px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;text-decoration:none;width:auto;margin:0}
.burger{display:none}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:22px;margin-bottom:20px}
.card h3{font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px;justify-content:space-between}
.card h3 small{font-weight:500;color:var(--muted);font-size:13px}
.grid{display:grid;gap:16px;margin-bottom:20px}
.g5{grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}.g2{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:18px;display:flex;gap:14px;align-items:center;text-decoration:none}
.stat .ball{width:44px;height:44px;font-size:19px;flex-shrink:0}
.stat.alert{border-color:var(--accent);background:var(--accent-soft)}
.stat p{font-size:12.5px;color:var(--muted);font-weight:600}.stat strong{font-size:21px;font-weight:800;font-variant-numeric:tabular-nums;display:block;line-height:1.2}.stat em{font-style:normal;font-size:12px;color:var(--muted)}
.tbl{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;color:var(--muted);font-weight:600;font-size:12.5px;padding:10px 12px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:12px;border-bottom:1px solid var(--line);vertical-align:middle}
tr:last-child td{border-bottom:0}
td small,.muted{color:var(--muted)}
.num{font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}
.chip{display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:700;background:var(--line);color:var(--muted)}
.chip.ok{background:#17915a22;color:var(--ok)}.chip.warn{background:#e9a10026;color:var(--warn)}.chip.bad{background:#d1423a22;color:var(--bad)}.chip.vip{background:#7a4ee022;color:var(--vip)}
label{display:block;font-size:13px;font-weight:600;color:var(--muted);margin:12px 0 5px}
input,select,textarea{width:100%;padding:10px 12px;background:var(--bg);border:1px solid var(--line);border-radius:10px;color:var(--ink);font:inherit}
input[type=checkbox]{width:auto;accent-color:var(--accent)}
input:focus,select:focus,textarea:focus,button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
textarea{min-height:96px;resize:vertical}
button,.btn{background:var(--accent);color:var(--accent-ink);border:0;border-radius:10px;padding:10px 16px;font:inherit;font-weight:700;cursor:pointer;margin-top:14px;text-decoration:none;display:inline-block}
button:hover,.btn:hover{filter:brightness(1.07)}
button.sm{padding:5px 11px;font-size:12.5px;margin:0}
button.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}
button.ok{background:var(--ok);color:#fff}button.bad{background:var(--bad);color:#fff}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.row>*{margin-top:0}
.row form{display:inline}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}.toolbar input,.toolbar select{width:auto;min-width:180px}.toolbar .btn,.toolbar button{margin:0}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.tabs a{padding:6px 14px;border-radius:99px;border:1px solid var(--line);text-decoration:none;font-size:13px;font-weight:600;color:var(--muted)}.tabs a.on{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.toast{position:fixed;right:20px;top:20px;z-index:99;padding:13px 18px;border-radius:12px;background:var(--ink);color:var(--bg);font-weight:600;max-width:380px;box-shadow:0 10px 30px #0004;border-left:5px solid var(--ok)}
.toast.bad{border-color:var(--bad)}.toast.warn{border-color:var(--accent)}
.banner{background:var(--accent-soft);border:1px solid var(--accent);padding:12px 16px;border-radius:12px;margin-bottom:18px;font-size:13.5px}
.bars{display:flex;gap:14px;align-items:flex-end;height:170px;padding-top:8px}
.bars div.d{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end}
.bars .pair{display:flex;gap:4px;align-items:flex-end;flex:1;width:100%;justify-content:center}
.bars .pair i{width:34%;max-width:22px;border-radius:6px 6px 2px 2px;min-height:3px;display:block}
.bars small{font-size:11.5px;color:var(--muted)}
.i-in{background:var(--ok)}.i-out{background:var(--accent)}
.legend{display:flex;gap:16px;font-size:12.5px;color:var(--muted);margin-top:8px}.legend b{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px}
.permgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:4px 14px;margin-top:8px}.permgrid label{margin:0;font-weight:500;color:var(--ink);display:flex;gap:8px;align-items:center;font-size:13.5px}
details{border:1px solid var(--line);border-radius:12px;padding:10px 14px;margin-top:8px;background:var(--bg)}summary{cursor:pointer;font-weight:600}
.big{font-size:30px;font-weight:800;font-variant-numeric:tabular-nums}
.split{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.pager{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap}.pager a{padding:5px 11px;border:1px solid var(--line);border-radius:8px;text-decoration:none;font-size:13px}.pager a.on{background:var(--ink);color:var(--bg)}
.login{margin:auto;width:min(400px,92vw);background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:34px}
.login .ball{width:56px;height:56px;font-size:24px;margin:0 auto 14px}.login h1{text-align:center;font-size:20px}.login p{text-align:center;color:var(--muted);font-size:13px;margin-bottom:8px}
@media(max-width:860px){.sidebar{position:fixed;left:-270px;z-index:50;transition:left .2s}body.nav-open .sidebar{left:0}.burger{display:inline-block}.main{padding:0 14px 40px}.split{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<?php if ($flash): ?><div class="toast <?= e($flash[1]) ?>" id="toast" role="status"><?= e($flash[0]) ?></div><?php endif; ?>

<?php if (!$ME): ?>
<div class="login">
  <div class="ball">B</div>
  <h1>LALA BINGO admin</h1>
  <p>Sign in to manage players, payments and the game.</p>
  <form method="POST"><?= csrf() ?>
    <label for="u">Username</label><input id="u" name="username" required autofocus autocomplete="username">
    <label for="p">Password · የይለፍ ቃል</label><input id="p" type="password" name="password" required autocomplete="current-password">
    <button type="submit" name="login" value="1" style="width:100%">Sign in</button>
  </form>
</div>

<?php else: ?>
<aside class="sidebar">
  <div class="brand"><div class="ball">B</div><div>LALA BINGO<small>Admin console</small></div></div>
  <ul class="menu">
    <?php foreach ($nav as $key => $n): if ($n[3] && !can($n[3])) continue; ?>
      <li><a href="admin.php?tab=<?= $key ?>" class="<?= $tabKey === $key ? 'on' : '' ?>"><span><?= $n[0] ?></span><span class="t"><?= e($n[1]) ?><small><?= e($n[2]) ?></small></span></a></li>
    <?php endforeach; ?>
  </ul>
  <div class="me"><b><?= e($ME['username']) ?></b><?= e($roles[$ME['role']]['label'] ?? $ME['role']) ?><br>
    <a href="admin.php?tab=account">Account</a> · <a href="admin.php?logout=<?= $_SESSION['csrf'] ?>">Sign out</a></div>
</aside>

<div class="main">
  <div class="top">
    <button class="iconbtn burger" type="button" id="burger" aria-label="Menu">☰</button>
    <h1><?php if ($tab === 'account') echo 'My account'; else { echo e($nav[$tabKey][1]); echo '<small>' . e($nav[$tabKey][2]) . '</small>'; } ?></h1>
    <?php if (can('settings.manage')): ?>
      <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="toggle_game"><input type="hidden" name="value" value="<?= $SET['game_enabled'] ? '0' : '1' ?>">
        <button class="iconbtn" style="margin:0" data-confirm="<?= $SET['game_enabled'] ? 'Pause the game for all players?' : 'Resume the game?' ?>"><?= $SET['game_enabled'] ? '🟢 Game live · Pause' : '🔴 Game paused · Resume' ?></button></form>
    <?php else: ?><span class="chip <?= $SET['game_enabled'] ? 'ok' : 'bad' ?>"><?= $SET['game_enabled'] ? 'Game live' : 'Game paused' ?></span><?php endif; ?>
    <button class="iconbtn" type="button" id="theme" aria-label="Toggle theme">🌓</button>
  </div>

  <?php if (DEFAULT_CREDS && $ME['id'] === 'root'): ?>
    <div class="banner">⚠️ You are using the default <b>admin / admin123</b> login. Set the <code>ADMIN_USER</code> and <code>ADMIN_PASS</code> environment variables on your server, or create database-backed admins under <a href="admin.php?tab=admins">Admins &amp; roles</a>.</div>
  <?php endif; ?>
  <?php if (BOT_TOKEN === ''): ?><div class="banner">The <code>BOT_TOKEN</code> environment variable is not set — broadcasts and player notifications are disabled.</div><?php endif; ?>

<?php /* ═════════ DASHBOARD ═════════ */ if ($tab === 'dashboard'):
    $totBal = 0; $banned = 0; $vips = 0;
    foreach ($users as $u) { $totBal += (float)($u['balance'] ?? 0); $banned += !empty($u['banned']); $vips += !empty($u['vip']); }
    $pd = array_filter($deposits, function ($d) { return !in_array($d['status'] ?? 'pending', ['processed', 'rejected'], true); });
    $pw = array_filter($withdrawals, function ($w) { return ($w['status'] ?? 'pending') === 'pending'; });
    $pdSum = array_sum(array_map(function ($d) { return (float)($d['amount'] ?? 0); }, $pd));
    $pwSum = array_sum(array_map(function ($w) { return (float)($w['amount'] ?? 0); }, $pw));
    $days = []; for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = ['in' => 0, 'out' => 0];
    foreach ($deposits as $d) { if (($d['status'] ?? '') !== 'processed' || !($t = ts($d))) continue; $dk = date('Y-m-d', $t); if (isset($days[$dk])) $days[$dk]['in'] += (float)($d['amount'] ?? 0); }
    foreach ($withdrawals as $w) { if (($w['status'] ?? '') !== 'approved' || !($t = ts($w))) continue; $dk = date('Y-m-d', $t); if (isset($days[$dk])) $days[$dk]['out'] += (float)($w['amount'] ?? 0); }
    $today = $days[date('Y-m-d')]; $max = 1; foreach ($days as $d) $max = max($max, $d['in'], $d['out']);
    $top = $users; uasort($top, function ($a, $b) { return (float)($b['balance'] ?? 0) <=> (float)($a['balance'] ?? 0); }); $top = array_slice($top, 0, 5, true);
?>
  <div class="grid g5">
    <a class="stat" href="admin.php?tab=users"><div class="ball">B</div><div><p>Players</p><strong><?= count($users) ?></strong><em><?= $vips ?> VIP · <?= $banned ?> banned</em></div></a>
    <div class="stat"><div class="ball">I</div><div><p>Player balances</p><strong><?= money($totBal) ?></strong><em>ETB held in wallets</em></div></div>
    <a class="stat <?= $pd ? 'alert' : '' ?>" href="admin.php?tab=deposits&s=pending"><div class="ball">N</div><div><p>Deposits to review</p><strong><?= count($pd) ?></strong><em><?= money($pdSum) ?> ETB</em></div></a>
    <a class="stat <?= $pw ? 'alert' : '' ?>" href="admin.php?tab=withdrawals&s=pending"><div class="ball">G</div><div><p>Withdrawals to pay</p><strong><?= count($pw) ?></strong><em><?= money($pwSum) ?> ETB</em></div></a>
    <div class="stat"><div class="ball">O</div><div><p>Net today</p><strong><?= money($today['in'] - $today['out']) ?></strong><em><?= money($today['in']) ?> in · <?= money($today['out']) ?> out</em></div></div>
  </div>
  <div class="grid g2">
    <div class="card"><h3>Money flow, last 7 days <small>ETB</small></h3>
      <div class="bars"><?php foreach ($days as $dk => $v): ?><div class="d"><div class="pair"><i class="i-in" style="height:<?= round($v['in'] / $max * 100) ?>%" title="In <?= money($v['in']) ?>"></i><i class="i-out" style="height:<?= round($v['out'] / $max * 100) ?>%" title="Out <?= money($v['out']) ?>"></i></div><small><?= date('D', strtotime($dk)) ?></small></div><?php endforeach; ?></div>
      <div class="legend"><span><b class="i-in"></b>Deposits approved</span><span><b class="i-out"></b>Withdrawals sent</span></div>
    </div>
    <div class="card"><h3>Top balances</h3>
      <div class="tbl"><table><?php foreach ($top as $key => $u): ?><tr><td><a href="admin.php?tab=user&id=<?= e(uidOf($key, $u)) ?>"><b><?= e($u['first_name'] ?? 'Player') ?></b></a> <small>@<?= e($u['username'] ?? '—') ?></small></td><td class="num" style="text-align:right"><?= money($u['balance'] ?? 0) ?> ETB</td></tr><?php endforeach; ?>
      <?php if (!$top): ?><tr><td class="muted">No players yet.</td></tr><?php endif; ?></table></div>
    </div>
  </div>

<?php /* ═════════ PLAYERS LIST ═════════ */ elseif ($tab === 'users'):
    $q = trim((string)($_GET['q'] ?? '')); $f = $_GET['f'] ?? 'all'; $page = max(1, (int)($_GET['page'] ?? 1));
    $list = array_filter($users, function ($u) use ($q, $f) {
        if ($f === 'banned' && empty($u['banned'])) return false; if ($f === 'vip' && empty($u['vip'])) return false;
        if ($f === 'empty' && (float)($u['balance'] ?? 0) > 0) return false; if ($f === 'funded' && (float)($u['balance'] ?? 0) <= 0) return false;
        if ($q === '') return true;
        return stripos(implode(' ', [$u['first_name'] ?? '', $u['username'] ?? '', $u['phone'] ?? '', $u['telegram_id'] ?? '']), $q) !== false;
    });
    $total = count($list); $pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages);
    $slice = array_slice($list, ($page - 1) * $perPage, $perPage, true);
?>
  <div class="card">
    <form class="toolbar" method="GET"><input type="hidden" name="tab" value="users">
      <input name="q" value="<?= e($q) ?>" placeholder="Search name, @username, phone, ID">
      <select name="f"><?php foreach (['all' => 'All players', 'funded' => 'Has balance', 'empty' => 'Zero balance', 'vip' => 'VIP', 'banned' => 'Banned'] as $v => $l): ?><option value="<?= $v ?>" <?= $f === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <button class="btn" type="submit">Filter</button>
      <?php if (can('export.data')): ?><a class="btn iconbtn" href="admin.php?export=users" style="background:var(--surface);color:var(--ink)">⬇ Export CSV</a><?php endif; ?>
    </form>
    <div class="tbl"><table>
      <tr><th>Player</th><th>Phone / ID</th><th>Balance</th><th>Status</th><th></th></tr>
      <?php foreach ($slice as $key => $u): $id = uidOf($key, $u); ?>
        <tr><td><b><?= e($u['first_name'] ?? 'Player') ?></b><br><small>@<?= e($u['username'] ?? '—') ?></small></td>
          <td><code><?= e($u['phone'] ?? 'N/A') ?></code><br><small>ID <?= e($id) ?></small></td>
          <td class="num"><?= money($u['balance'] ?? 0) ?> ETB</td>
          <td><?= !empty($u['banned']) ? chip('banned') : chip('active') ?> <?= !empty($u['vip']) ? chip('vip') : '' ?></td>
          <td><a class="btn sm" style="margin:0" href="admin.php?tab=user&id=<?= e($id) ?>">Manage</a></td></tr>
      <?php endforeach; if (!$slice): ?><tr><td colspan="5" class="muted">No players match.</td></tr><?php endif; ?>
    </table></div>
    <div class="pager"><?php for ($p = 1; $p <= $pages; $p++): ?><a class="<?= $p === $page ? 'on' : '' ?>" href="admin.php?<?= e(http_build_query(['tab' => 'users', 'q' => $q, 'f' => $f, 'page' => $p])) ?>"><?= $p ?></a><?php endfor; ?><span class="muted" style="align-self:center;font-size:13px"><?= $total ?> players</span></div>
  </div>

<?php /* ═════════ PLAYER DETAIL ═════════ */ elseif ($tab === 'user'):
    $uid = preg_replace('/\D/', '', (string)($_GET['id'] ?? '')); $pu = null; $pkey = null;
    foreach ($users as $key => $u) if (uidOf($key, $u) === $uid) { $pu = $u; $pkey = $key; break; }
    if (!$pu): ?><div class="card">Player not found. <a href="admin.php?tab=users">Back to players</a></div>
<?php else:
    $myDep = array_filter($deposits, function ($d) use ($uid) { return preg_replace('/\D/', '', (string)($d['telegram_id'] ?? '')) === $uid; });
    $myWdr = array_filter($withdrawals, function ($w) use ($uid) { return preg_replace('/\D/', '', (string)($w['telegram_id'] ?? '')) === $uid; });
    $led = can('logs.view') ? array_reverse(array_filter(fbGet('transactions', ['orderBy' => '"$key"', 'limitToLast' => 500]) ?: [], function ($t) use ($uid) { return (string)($t['telegram_id'] ?? '') === $uid; }), true) : [];
?>
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div><div class="big"><?= money($pu['balance'] ?? 0) ?> <small style="font-size:15px" class="muted">ETB</small></div>
        <b><?= e($pu['first_name'] ?? 'Player') ?></b> <span class="muted">@<?= e($pu['username'] ?? '—') ?> · ID <?= e($uid) ?> · <?= e($pu['phone'] ?? 'no phone') ?></span></div>
      <div><?= !empty($pu['banned']) ? chip('banned') : chip('active') ?> <?= !empty($pu['vip']) ? chip('vip') : '' ?></div>
    </div>
  </div>
  <div class="grid g2">
    <?php if (can('users.balance')): ?>
    <div class="card"><h3>Adjust balance</h3>
      <form method="POST" data-confirm="Apply this balance change?"><?= csrf() ?><input type="hidden" name="action" value="adjust_balance"><input type="hidden" name="uid" value="<?= e($uid) ?>">
        <label>Action</label><select name="mode"><option value="add">Add money</option><option value="bonus">Give bonus (notifies player)</option><option value="sub">Remove money</option><option value="set">Set exact balance</option></select>
        <label>Amount (ETB)</label><input type="number" name="amount" step="0.01" min="0" required>
        <label>Reason (kept in the ledger)</label><input name="reason" maxlength="120" placeholder="e.g. Cash deposit at office">
        <?php if ($ME['limit'] > 0): ?><p class="muted" style="font-size:12.5px;margin-top:8px">Your limit: <?= money($ME['limit']) ?> ETB per action.</p><?php endif; ?>
        <button type="submit">Apply</button></form></div>
    <?php endif; ?>
    <div class="card"><h3>Status &amp; notes</h3>
      <?php if (can('users.ban')): ?>
      <div class="row">
        <form method="POST" data-confirm="<?= !empty($pu['banned']) ? 'Unban this player?' : 'Ban this player? They should be blocked from playing.' ?>"><?= csrf() ?><input type="hidden" name="action" value="toggle_ban"><input type="hidden" name="uid" value="<?= e($uid) ?>"><input type="hidden" name="value" value="<?= !empty($pu['banned']) ? '0' : '1' ?>"><button class="sm <?= empty($pu['banned']) ? 'bad' : 'ok' ?>"><?= !empty($pu['banned']) ? 'Unban player' : 'Ban player' ?></button></form>
        <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="toggle_vip"><input type="hidden" name="uid" value="<?= e($uid) ?>"><input type="hidden" name="value" value="<?= !empty($pu['vip']) ? '0' : '1' ?>"><button class="sm ghost"><?= !empty($pu['vip']) ? 'Remove VIP' : 'Mark as VIP' ?></button></form>
      </div>
      <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="save_note"><input type="hidden" name="uid" value="<?= e($uid) ?>">
        <label>Internal note (only admins see this)</label><textarea name="note" maxlength="500"><?= e($pu['note'] ?? '') ?></textarea><button class="sm" type="submit">Save note</button></form>
      <?php else: ?><p class="muted"><?= e($pu['note'] ?? 'No note.') ?></p><?php endif; ?>
    </div>
  </div>
  <?php if (can('users.message')): ?><div class="card"><h3>Send a Telegram message</h3>
    <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="message_user"><input type="hidden" name="uid" value="<?= e($uid) ?>"><textarea name="text" placeholder="HTML allowed: &lt;b&gt;bold&lt;/b&gt;" required></textarea><button type="submit">Send to player</button></form></div><?php endif; ?>
  <div class="grid g2">
    <div class="card"><h3>Deposits <small><?= count($myDep) ?></small></h3><div class="tbl"><table>
      <?php foreach (array_slice($myDep, 0, 10, true) as $id => $d): ?><tr><td><code><?= e($id) ?></code><br><small><?= fdate(ts($d)) ?></small></td><td class="num"><?= money($d['amount'] ?? 0) ?></td><td><?= chip($d['status'] ?? 'pending') ?></td></tr><?php endforeach; if (!$myDep): ?><tr><td class="muted">None</td></tr><?php endif; ?></table></div></div>
    <div class="card"><h3>Withdrawals <small><?= count($myWdr) ?></small></h3><div class="tbl"><table>
      <?php foreach (array_slice($myWdr, 0, 10, true) as $id => $w): ?><tr><td><code><?= e($id) ?></code><br><small><?= fdate(ts($w)) ?></small></td><td class="num"><?= money($w['amount'] ?? 0) ?></td><td><?= chip($w['status'] ?? 'pending') ?></td></tr><?php endforeach; if (!$myWdr): ?><tr><td class="muted">None</td></tr><?php endif; ?></table></div></div>
  </div>
  <?php if (can('logs.view')): ?><div class="card"><h3>Wallet ledger <small>latest <?= min(25, count($led)) ?></small></h3><div class="tbl"><table>
    <tr><th>When</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Note</th><th>By</th></tr>
    <?php foreach (array_slice($led, 0, 25, true) as $t): ?><tr><td><?= fdate((int)($t['at'] ?? 0)) ?></td><td><?= e($t['type'] ?? '') ?></td><td class="num" style="color:var(--<?= ($t['amount'] ?? 0) >= 0 ? 'ok' : 'bad' ?>)"><?= ($t['amount'] ?? 0) > 0 ? '+' : '' ?><?= money($t['amount'] ?? 0) ?></td><td class="num"><?= money($t['balance_after'] ?? 0) ?></td><td><?= e($t['note'] ?? '') ?></td><td><?= e($t['by'] ?? '') ?></td></tr><?php endforeach; if (!$led): ?><tr><td colspan="6" class="muted">No wallet changes made from this console yet.</td></tr><?php endif; ?></table></div></div><?php endif; ?>
  <?php if (can('users.delete')): ?><div class="card"><h3>Danger zone</h3><form method="POST" data-confirm="Delete this player permanently? This cannot be undone."><?= csrf() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="uid" value="<?= e($uid) ?>"><button class="bad sm" type="submit">Delete player</button></form></div><?php endif; ?>
<?php endif; ?>

<?php /* ═════════ DEPOSITS (Direct from Firebase DB) ═════════ */ elseif ($tab === 'deposits'):
    $s = $_GET['s'] ?? 'all';
    $list = array_filter($deposits, function ($d) use ($s) { $st = $d['status'] ?? 'pending'; return $s === 'all' || ($s === 'pending' ? !in_array($st, ['processed', 'rejected'], true) : $st === $s); });
?>
  <div class="card">
    <div class="tabs"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'processed' => 'Approved', 'rejected' => 'Rejected'] as $v => $l): ?><a class="<?= $s === $v ? 'on' : '' ?>" href="admin.php?tab=deposits&s=<?= $v ?>"><?= $l ?></a><?php endforeach; ?></div>
    <div class="toolbar"><input data-filter="#dt" placeholder="Search transaction or username"><?php if (can('export.data')): ?><a class="btn iconbtn" href="admin.php?export=deposits" style="background:var(--surface);color:var(--ink)">⬇ Export CSV</a><?php endif; ?></div>
    <div class="tbl"><table id="dt"><tr><th>Transaction</th><th>Player</th><th>Amount</th><th>Status</th><th></th></tr>
      <?php foreach ($list as $id => $d): $st = $d['status'] ?? 'pending'; ?>
        <tr><td><code><?= e($id) ?></code><br><small><?= fdate(ts($d)) ?></small></td>
          <td><?php if (!empty($d['telegram_id']) && can('users.view')): ?><a href="admin.php?tab=user&id=<?= e(preg_replace('/\D/', '', (string)$d['telegram_id'])) ?>">@<?= e($d['claimed_by'] ?? $d['telegram_id']) ?></a><?php else: ?>@<?= e($d['claimed_by'] ?? 'N/A') ?><?php endif; ?></td>
          <td class="num"><?= money($d['amount'] ?? 0) ?> ETB</td>
          <td><?= chip($st) ?><?php if (!empty($d['processed_by'])): ?><br><small>by <?= e($d['processed_by']) ?></small><?php endif; ?></td>
          <td><?php if (can('deposits.process') && $st !== 'processed'): ?><div class="row">
            <form method="POST" data-confirm="Approve and credit <?= money($d['amount'] ?? 0) ?> ETB?"><?= csrf() ?><input type="hidden" name="action" value="process_deposit"><input type="hidden" name="tx_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="processed"><button class="sm ok">Approve</button></form>
            <?php if ($st !== 'rejected'): ?><form method="POST" data-confirm="Reject this deposit?"><?= csrf() ?><input type="hidden" name="action" value="process_deposit"><input type="hidden" name="tx_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="rejected"><button class="sm bad">Reject</button></form><?php endif; ?></div><?php endif; ?></td></tr>
      <?php endforeach; if (!$list): ?><tr><td colspan="5" class="muted">Nothing here in Firebase database.</td></tr><?php endif; ?></table></div>
  </div>

<?php /* ═════════ WITHDRAWALS (Direct from Firebase DB) ═════════ */ elseif ($tab === 'withdrawals'):
    $s = $_GET['s'] ?? 'all';
    $list = array_filter($withdrawals, function ($w) use ($s) { return $s === 'all' || ($w['status'] ?? 'pending') === $s; });
?>
  <div class="card">
    <div class="tabs"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Sent', 'rejected' => 'Rejected'] as $v => $l): ?><a class="<?= $s === $v ? 'on' : '' ?>" href="admin.php?tab=withdrawals&s=<?= $v ?>"><?= $l ?></a><?php endforeach; ?></div>
    <div class="toolbar"><input data-filter="#wt" placeholder="Search name, phone, account"><?php if (can('export.data')): ?><a class="btn iconbtn" href="admin.php?export=withdrawals" style="background:var(--surface);color:var(--ink)">⬇ Export CSV</a><?php endif; ?></div>
    <div class="tbl"><table id="wt"><tr><th>Request</th><th>Player</th><th>Amount &amp; account</th><th>Status</th><th></th></tr>
      <?php foreach ($list as $id => $w): $st = $w['status'] ?? 'pending'; ?>
        <tr><td><code><?= e($id) ?></code><br><small><?= e($w['method'] ?? 'CBE') ?> · <?= fdate(ts($w)) ?></small></td>
          <td><?php if (!empty($w['telegram_id']) && can('users.view')): ?><a href="admin.php?tab=user&id=<?= e(preg_replace('/\D/', '', (string)$w['telegram_id'])) ?>"><b><?= e($w['first_name'] ?? 'Player') ?></b></a><?php else: ?><b><?= e($w['first_name'] ?? 'Player') ?></b><?php endif; ?><br><code><?= e($w['phone'] ?? '') ?></code></td>
          <td class="num"><?= money($w['amount'] ?? 0) ?> ETB<br><small style="font-weight:400"><?= e($w['account_details'] ?? '') ?></small></td>
          <td><?= chip($st) ?><?php if (!empty($w['processed_by'])): ?><br><small>by <?= e($w['processed_by']) ?></small><?php endif; ?><?php if (!empty($w['reason'])): ?><br><small><?= e($w['reason']) ?></small><?php endif; ?></td>
          <td><?php if (can('withdrawals.process') && $st === 'pending'): ?><div class="row">
            <form method="POST" data-confirm="Mark <?= money($w['amount'] ?? 0) ?> ETB as sent?"><?= csrf() ?><input type="hidden" name="action" value="process_withdrawal"><input type="hidden" name="wdr_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="approved"><button class="sm ok">Sent</button></form>
            <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="process_withdrawal"><input type="hidden" name="wdr_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="rejected"><input type="hidden" name="reason" value=""><button type="button" class="sm bad js-reject">Reject &amp; refund</button></form></div><?php endif; ?></td></tr>
      <?php endforeach; if (!$list): ?><tr><td colspan="5" class="muted">Nothing here in Firebase database.</td></tr><?php endif; ?></table></div>
  </div>

<?php /* ═════════ BROADCAST ═════════ */ elseif ($tab === 'broadcast'):
    $segs = ['all' => 'Everyone', 'funded' => 'Players with balance', 'empty' => 'Players with zero balance', 'vip' => 'VIP players'];
    $hist = array_reverse(fbGet('broadcasts', ['orderBy' => '"$key"', 'limitToLast' => 10]) ?: [], true);
?>
  <div class="grid g2">
    <div class="card"><h3>New broadcast</h3>
      <form method="POST" enctype="multipart/form-data" data-confirm="Send this broadcast now?"><?= csrf() ?><input type="hidden" name="action" value="send_broadcast">
        <label>Audience <span class="muted">(banned players are always skipped)</span></label>
        <select name="segment"><?php foreach ($segs as $v => $l): ?><option value="<?= $v ?>"><?= $l ?> — <?= count(segmentUsers($users, $v)) ?></option><?php endforeach; ?></select>
        <label>Poster image (optional)</label><input type="file" name="image_file" accept="image/*">
        <label>Message <span class="muted">(HTML: &lt;b&gt;, &lt;i&gt;, &lt;a&gt;)</span></label>
        <textarea name="message" required placeholder="💎 <b>Tonight's big jackpot!</b>"></textarea>
        <label>Button text</label><input name="btn_text" value="🌴 Play now">
        <label>Button link (https)</label><input name="btn_url" value="<?= e(GAME_URL) ?>">
        <label>Test first: send only to this Telegram ID (optional)</label><input name="test_id" inputmode="numeric" placeholder="your own Telegram ID">
        <button type="submit">🚀 Send broadcast</button></form></div>
    <div class="card"><h3>Recent broadcasts</h3><div class="tbl"><table>
      <?php foreach ($hist as $b): ?><tr><td><?= e($b['text'] ?? '') ?><br><small><?= fdate((int)($b['at'] ?? 0)) ?> · <?= e($b['by'] ?? '') ?> · <?= e($segs[$b['segment'] ?? 'all'] ?? '') ?></small></td><td class="num"><span style="color:var(--ok)"><?= (int)($b['ok'] ?? 0) ?></span> / <span style="color:var(--bad)"><?= (int)($b['fail'] ?? 0) ?></span></td></tr><?php endforeach; if (!$hist): ?><tr><td class="muted">No broadcasts yet.</td></tr><?php endif; ?></table></div>
      <p class="muted" style="font-size:12.5px;margin-top:10px">Delivered / failed. Large audiences can take a few minutes — keep this page open until it finishes.</p></div>
  </div>

<?php /* ═════════ SETTINGS ═════════ */ elseif ($tab === 'settings'): ?>
  <form method="POST" data-confirm="Save settings? Changes apply to players immediately."><?= csrf() ?><input type="hidden" name="action" value="save_settings">
  <div class="grid g2">
    <div class="card"><h3>Game rules</h3>
      <label class="row" style="color:var(--ink)"><input type="checkbox" name="game_enabled" <?= $SET['game_enabled'] ? 'checked' : '' ?>> Game is live</label>
      <label>Maintenance message (shown when paused)</label><textarea name="maintenance_msg"><?= e($SET['maintenance_msg']) ?></textarea>
      <div class="split"><div><label>Entry fee per card (ETB)</label><input type="number" step="0.01" name="entry_fee" value="<?= e($SET['entry_fee']) ?>"></div><div><label>House commission (%)</label><input type="number" step="0.1" max="100" name="commission_pct" value="<?= e($SET['commission_pct']) ?>"></div></div>
      <div class="split"><div><label>Welcome bonus (ETB)</label><input type="number" step="0.01" name="welcome_bonus" value="<?= e($SET['welcome_bonus']) ?>"></div><div><label>Min deposit (ETB)</label><input type="number" step="0.01" name="min_deposit" value="<?= e($SET['min_deposit']) ?>"></div></div>
      <div class="split"><div><label>Min withdrawal (ETB)</label><input type="number" step="0.01" name="min_withdraw" value="<?= e($SET['min_withdraw']) ?>"></div><div><label>Max withdrawal (ETB)</label><input type="number" step="0.01" name="max_withdraw" value="<?= e($SET['max_withdraw']) ?>"></div></div>
    </div>
    <div class="card"><h3>Payments &amp; bot</h3>
      <label>Telebirr account name</label><input name="telebirr_name" value="<?= e($SET['telebirr_name']) ?>">
      <label>Telebirr number</label><input name="telebirr_number" value="<?= e($SET['telebirr_number']) ?>">
      <label>CBE account</label><input name="cbe_account" value="<?= e($SET['cbe_account']) ?>">
      <label class="row" style="color:var(--ink)"><input type="checkbox" name="notify_users" <?= $SET['notify_users'] ? 'checked' : '' ?>> Notify players on Telegram when payments are approved or rejected</label>
      <label>Game web app link</label><input value="<?= e(GAME_URL) ?>" readonly>
      <p class="muted" style="font-size:12.5px;margin-top:10px">Saved under <code>settings/</code> in your database. The game and bot must read these values (and each player's <code>banned</code> flag) to enforce them.</p>
    </div>
  </div><button type="submit">Save settings</button></form>

<?php /* ═════════ LOGS ═════════ */ elseif ($tab === 'logs'):
    $v = $_GET['v'] ?? 'audit';
    $rows = array_reverse(fbGet($v === 'ledger' ? 'transactions' : 'admin_logs', ['orderBy' => '"$key"', 'limitToLast' => 300]) ?: [], true);
?>
  <div class="card"><div class="tabs"><a class="<?= $v === 'audit' ? 'on' : '' ?>" href="admin.php?tab=logs&v=audit">Admin actions</a><a class="<?= $v === 'ledger' ? 'on' : '' ?>" href="admin.php?tab=logs&v=ledger">Wallet ledger</a></div>
    <div class="toolbar"><input data-filter="#lg" placeholder="Search"></div>
    <div class="tbl"><table id="lg">
    <?php if ($v === 'ledger'): ?><tr><th>When</th><th>Player</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Note</th><th>By</th></tr>
      <?php foreach ($rows as $t): ?><tr><td><?= fdate((int)($t['at'] ?? 0)) ?></td><td><a href="admin.php?tab=user&id=<?= e($t['telegram_id'] ?? '') ?>"><?= e($t['telegram_id'] ?? '') ?></a></td><td><?= e($t['type'] ?? '') ?></td><td class="num"><?= ($t['amount'] ?? 0) > 0 ? '+' : '' ?><?= money($t['amount'] ?? 0) ?></td><td class="num"><?= money($t['balance_after'] ?? 0) ?></td><td><?= e($t['note'] ?? '') ?></td><td><?= e($t['by'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php else: ?><tr><th>When</th><th>Admin</th><th>Action</th><th>Detail</th><th>IP</th></tr>
      <?php foreach ($rows as $l): ?><tr><td><?= fdate((int)($l['at'] ?? 0)) ?></td><td><b><?= e($l['by'] ?? '') ?></b><br><small><?= e($l['role'] ?? '') ?></small></td><td><code><?= e($l['action'] ?? '') ?></code></td><td><?= e($l['detail'] ?? '') ?></td><td><small><?= e($l['ip'] ?? '') ?></small></td></tr><?php endforeach; ?>
    <?php endif; if (!$rows): ?><tr><td class="muted">Nothing recorded yet.</td></tr><?php endif; ?></table></div></div>

<?php /* ═════════ ADMINS (Saved to Firebase DB) ═════════ */ elseif ($tab === 'admins'):
    $admins = array_filter(fbGet('admins') ?: [], 'is_array');
    $permForm = function (array $have) use ($perms) { foreach ($perms as $pk => $pl) echo '<label><input type="checkbox" name="perms[]" value="' . e($pk) . '" ' . (in_array($pk, $have, true) ? 'checked' : '') . '> ' . e($pl) . '</label>'; };
    $roleOpts = function (string $sel) use ($roles) { foreach ($roles as $rk => $r) echo '<option value="' . e($rk) . '" ' . ($sel === $rk ? 'selected' : '') . '>' . e($r['label']) . ' — ' . e($r['desc']) . '</option>'; };
?>
  <div class="card"><h3>Team <small><?= count($admins) + 1 ?> accounts</small></h3>
    <p class="muted" style="margin-bottom:14px; font-size:13px;">New admin logins created here are saved securely inside your Firebase database under the <code>admins/</code> node.</p>
    <div class="tbl"><table><tr><th>Admin</th><th>Role</th><th>Limit / action</th><th>Last sign-in</th><th></th></tr>
      <tr><td><b><?= e(ROOT_USER) ?></b> <small>root</small></td><td>Super admin</td><td>No limit</td><td>—</td><td class="muted">Set by server environment</td></tr>
      <?php foreach ($admins as $id => $a): $r = $a['role'] ?? 'manager'; ?>
        <tr><td><b><?= e($a['username'] ?? '') ?></b><br><?= ($a['active'] ?? true) ? chip('active') : chip('disabled') ?></td><td><?= e($roles[$r]['label'] ?? $r) ?></td><td class="num"><?= !empty($a['limit']) ? money($a['limit']) . ' ETB' : 'No limit' ?></td><td><?= fdate((int)($a['last_login'] ?? 0)) ?></td>
          <td style="min-width:260px"><details><summary>Edit</summary>
            <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="update_admin"><input type="hidden" name="id" value="<?= e($id) ?>">
              <label>Role</label><select name="role" data-role-preset><?php $roleOpts($r); ?></select>
              <label>Privileges</label><div class="permgrid"><?php $permForm(effPerms($a)); ?></div>
              <label>Max amount per approval / adjustment (0 = no limit)</label><input type="number" name="limit" min="0" step="0.01" value="<?= e($a['limit'] ?? 0) ?>">
              <label>New password (leave empty to keep)</label><input type="password" name="new_password" autocomplete="new-password">
              <label class="row" style="color:var(--ink)"><input type="checkbox" name="active" <?= ($a['active'] ?? true) ? 'checked' : '' ?>> Account active</label>
              <button class="sm" type="submit">Save admin</button></form>
            <form method="POST" data-confirm="Delete this admin?" style="margin-top:8px"><?= csrf() ?><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="id" value="<?= e($id) ?>"><button class="sm bad" type="submit">Delete admin</button></form></details></td></tr>
      <?php endforeach; ?></table></div></div>

  <div class="card"><h3>Add admin (Saved to Firebase)</h3>
    <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="add_admin">
      <div class="split"><div><label>Username</label><input name="username" required pattern="[A-Za-z0-9_.\-]{3,32}"></div><div><label>Password (8+ characters)</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div></div>
      <div class="split"><div><label>Role</label><select name="role" data-role-preset><?php $roleOpts('support'); ?></select></div><div><label>Max amount per approval / adjustment (0 = no limit)</label><input type="number" name="limit" min="0" step="0.01" value="0"></div></div>
      <label>Privileges <span class="muted">— picking a role fills these in; tick or untick to customise</span></label><div class="permgrid"><?php $permForm($roles['support']['perms']); ?></div>
      <button type="submit">Save admin to Firebase</button></form></div>

  <div class="card"><h3>What each role can do</h3><div class="tbl"><table>
    <tr><th>Privilege</th><?php foreach ($roles as $r): ?><th><?= e($r['label']) ?></th><?php endforeach; ?></tr>
    <?php foreach ($perms as $pk => $pl): ?><tr><td><?= e($pl) ?></td><?php foreach ($roles as $r): ?><td><?= in_array($pk, $r['perms'], true) ? '✔' : '<span class="muted">–</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div></div>

<?php /* ═════════ ACCOUNT ═════════ */ elseif ($tab === 'account'): ?>
  <div class="grid g2">
    <div class="card"><h3>Signed in as <?= e($ME['username']) ?></h3><p><?= e($roles[$ME['role']]['label'] ?? $ME['role']) ?><?= $ME['limit'] > 0 ? ' · limit ' . money($ME['limit']) . ' ETB per action' : '' ?></p>
      <div class="permgrid" style="margin-top:12px"><?php foreach ($perms as $pk => $pl): ?><label><?= in_array($pk, $ME['perms'], true) ? '✔' : '<span class="muted">–</span>' ?> <?= e($pl) ?></label><?php endforeach; ?></div></div>
    <div class="card"><h3>Change password</h3><form method="POST"><?= csrf() ?><input type="hidden" name="action" value="change_password">
      <label>Current password</label><input type="password" name="current_password" required autocomplete="current-password">
      <label>New password (8+ characters)</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"><button type="submit">Update password</button></form></div>
  </div>
<?php endif; ?>
</div>

<script>
(function(){
  var ROLES = <?= json_encode(array_map(function ($r) { return $r['perms']; }, $roles)) ?>;
  document.getElementById('burger').onclick = function(){ document.body.classList.toggle('nav-open'); };
  document.getElementById('theme').onclick = function(){ var d = document.documentElement, n = d.dataset.theme === 'dark' ? 'light' : 'dark'; d.dataset.theme = n; try{localStorage.setItem('lb-theme', n)}catch(e){} };
  var t = document.getElementById('toast'); if (t) setTimeout(function(){ t.style.display = 'none'; }, 5500);
  document.addEventListener('submit', function(e){ var m = e.target.getAttribute('data-confirm'); if (m && !confirm(m)) e.preventDefault(); });
  document.addEventListener('click', function(e){
    var b = e.target.closest('.js-reject'); if (!b) return;
    var r = prompt('Reason for rejecting (the player will see this):', ''); if (r === null) return;
    var f = b.form; f.elements.reason.value = r; f.submit();
  });
  document.querySelectorAll('[data-filter]').forEach(function(i){ i.addEventListener('input', function(){
    var q = i.value.toLowerCase(); document.querySelectorAll(i.getAttribute('data-filter') + ' tr').forEach(function(tr, n){ if (n) tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none'; });
  }); });
  document.querySelectorAll('[data-role-preset]').forEach(function(s){ s.addEventListener('change', function(){
    var set = ROLES[s.value] || []; s.form.querySelectorAll('input[name="perms[]"]').forEach(function(c){ c.checked = set.indexOf(c.value) > -1; });
  }); });
})();
</script>
<?php endif; ?>
</body>
</html>
