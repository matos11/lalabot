<?php
/**
 * LALA BINGO — Admin Console (roles & privileges)
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
        'deposits.view' => 'View deposits', 'deposits.process' => 'Link deposits to players (credit is automatic)',
        'transactions.view' => 'View incoming payments', 'transactions.import' => 'Extract & save payments to deposits',
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
        'finance'    => ['label' => 'Finance', 'desc' => 'Deposits, withdrawals, exports', 'perms' => ['users.view', 'deposits.view', 'deposits.process', 'transactions.view', 'transactions.import', 'withdrawals.view', 'withdrawals.process', 'export.data', 'logs.view']],
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
/** Move a request to a new status only once. Returns previous status or false. */
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

/* ───────────── Incoming payments: parse SMS / receipt text ───────────── */
function normTxId(string $s): string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
    return (strlen($s) >= 6 && strlen($s) <= 30) ? $s : '';
}
function parseTxn(string $text): array {
    $out = ['amount' => 0.0, 'name' => '', 'tx_id' => '', 'ts' => 0, 'phone' => ''];
    $t = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    if ($t === '') return $out;
    $num = '(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)';
    $cur = '(?:ETB|Birr|Br\.?)';
    foreach (["/(?:received|credited(?: with)?|deposited|sent you)\s*(?:an? amount of\s*)?$cur\s*$num/i", "/$cur\s*$num/i", "/$num\s*$cur/i"] as $re) {
        if (preg_match($re, $t, $m)) { $out['amount'] = (float)str_replace(',', '', $m[1]); break; }
    }
    foreach (['/transaction\s*(?:number|no\.?|id|ref(?:erence)?)\s*(?:is|:|-)?\s*([A-Za-z0-9]{6,30})/i',
              '/\bref(?:erence)?\b\s*(?:no\.?|number|id)?\s*[:\-]?\s*((?:FT)?[A-Za-z0-9]{8,30})/i',
              '/[?&]id=([A-Za-z0-9]{6,30})/i', '/\b(FT\d{5,}[A-Z0-9]*)\b/',
              '/\b(?:txn|trx|tid)\s*[:#]?\s*([A-Za-z0-9]{6,30})/i'] as $re) {
        if (preg_match($re, $t, $m) && ($id = normTxId($m[1])) !== '') { $out['tx_id'] = $id; break; }
    }
    if (preg_match('/\bfrom\s+(.+?)\s*(?:\(|\[|,|\bon\b|\bat\b|\bwith\b|\bto\b|\.\s|$)/i', $t, $m)
        || preg_match('/\b(?:sender|payer|name)\s*[:\-]\s*([^,;(]+)/i', $t, $m)) {
        $out['name'] = mb_substr(trim(preg_replace('/[\d\*]{6,}/', '', $m[1]), " \t-."), 0, 60);
    }
    if (preg_match('/\bfrom\b(.{0,70})/i', $t, $m2) && preg_match('/(?:\+?251|\b0)(9\d{8})\b/', $m2[1], $m3)) $out['phone'] = $m3[1];
    $tm = '(?:[ T,]+(?:at\s*)?(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?)?';
    $y = $mo = $d = 0; $hh = $mi = $ss = 0; $ap = '';
    if (preg_match('/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})' . $tm . '/i', $t, $m)) { [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]]; }
    elseif (preg_match('/(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})' . $tm . '/i', $t, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($mo > 12 && $d <= 12) [$d, $mo] = [$mo, $d];
    }
    if ($y) {
        $hh = (int)($m[4] ?? 0); $mi = (int)($m[5] ?? 0); $ss = (int)($m[6] ?? 0); $ap = strtoupper($m[7] ?? '');
        if ($ap === 'PM' && $hh < 12) $hh += 12; if ($ap === 'AM' && $hh === 12) $hh = 0;
        if (checkdate($mo, $d, $y)) $out['ts'] = (int)mktime($hh, $mi, $ss, $mo, $d, $y);
    }
    return $out;
}
function txnFromRecord($rec): array {
    $text = '';
    if (is_string($rec)) $text = $rec;
    elseif (is_array($rec)) {
        foreach (['text', 'message', 'sms', 'body', 'raw', 'content', 'msg'] as $f) if (!empty($rec[$f]) && is_string($rec[$f])) { $text = $rec[$f]; break; }
        if ($text === '') $text = implode(' ', array_filter($rec, 'is_string'));
    }
    $p = parseTxn($text);
    if (is_array($rec)) {
        if (isset($rec['amount']) && is_numeric($rec['amount']) && (float)$rec['amount'] > 0) $p['amount'] = (float)$rec['amount'];
        foreach (['tx_id', 'transaction_id', 'transaction_number', 'txn_id', 'ref', 'reference'] as $f)
            if (!empty($rec[$f]) && is_scalar($rec[$f]) && normTxId((string)$rec[$f]) !== '') { $p['tx_id'] = normTxId((string)$rec[$f]); break; }
        foreach (['sender_name', 'sender', 'name', 'from'] as $f) if (!empty($rec[$f]) && is_string($rec[$f])) { $p['name'] = mb_substr($rec[$f], 0, 60); break; }
        if (!$p['ts']) $p['ts'] = ts($rec);
    }
    $p['raw'] = mb_substr($text, 0, 500);
    return $p;
}
function saveDeposit(array $p, string $source, string $by): string {
    if (($p['amount'] ?? 0) <= 0 || ($p['tx_id'] ?? '') === '') return 'invalid';
    $rec = ['amount' => round((float)$p['amount'], 2), 'sender_name' => mb_substr((string)($p['name'] ?? ''), 0, 60), 'tx_id' => $p['tx_id'], 'status' => 'pending',
        'created_at' => ($p['ts'] ?? 0) ?: time(), 'imported_at' => time(), 'source' => $source, 'imported_by' => $by, 'raw' => mb_substr((string)($p['raw'] ?? ''), 0, 500)];
    if (!empty($p['phone'])) $rec['sender_phone'] = $p['phone'];
    if (!empty($p['uid'])) { $rec['telegram_id'] = $p['uid']; $rec['claimed_by'] = $p['uid_name'] ?? $p['uid']; }
    $h = []; $c = 0;
    fb('PUT', 'deposits/' . k($p['tx_id']), $rec, [], ['if-match: null_etag'], $h, $c);
    return $c === 200 ? 'saved' : ($c === 412 ? 'duplicate' : 'error');
}
function phoneIndex(): array {
    $idx = [];
    foreach (fbGet('users') ?: [] as $key => $u) {
        if (!is_array($u) || empty($u['phone'])) continue;
        $d = substr(preg_replace('/\D/', '', (string)$u['phone']), -9);
        if (strlen($d) < 9) continue;
        $idx[$d] = isset($idx[$d]) ? false : [uidOf($key, $u), $u['username'] ?? ($u['first_name'] ?? '')];
    }
    return $idx;
}
function creditDeposit(string $id, string $by = 'auto'): string {
    $d = fbGet('deposits/' . k($id)); if (!is_array($d)) return 'missing';
    $tid = preg_replace('/\D/', '', (string)($d['telegram_id'] ?? '')); $amt = (float)($d['amount'] ?? 0);
    if ($tid === '' || $amt <= 0) return 'skip';
    if (!is_array(fbGet('users/' . k($tid)))) return 'nouser';
    $prev = casStatus('deposits/' . k($id), 'processed', ['processed', 'rejected']);
    if ($prev === false) return 'skip';
    if (!adjustBalance($tid, $amt, $after)) { fbPut('deposits/' . k($id) . '/status', $prev); return 'error'; }
    fbPatch('deposits/' . k($id), ['processed_by' => $by, 'processed_at' => time()]);
    ledger($tid, 'deposit', $amt, $after, "Deposit $id", $by); syncUserOne($tid);
    notifyUser($tid, '✅ Your deposit of <b>' . money($amt) . ' ETB</b> was received. Balance: <b>' . money($after) . ' ETB</b>');
    audit('deposit.auto', "$id · " . money($amt) . " ETB → player $tid");
    return 'credited';
}
function autoCreditDeposits(array $deposits, string $by = 'auto'): int {
    $n = 0;
    foreach ($deposits as $id => $d) {
        if (!is_array($d) || ($d['status'] ?? 'pending') !== 'pending' || empty($d['telegram_id'])) continue;
        if (creditDeposit((string)$id, $by) === 'credited') $n++;
    }
    return $n;
}
function importFrom($raw, string $source, string $by, array $existing): array {
    $r = ['saved' => 0, 'duplicate' => 0, 'invalid' => 0, 'error' => 0, 'credited' => 0]; $idx = null;
    foreach ((array)$raw as $rec) {
        if (is_array($rec) && isset($rec['balance_after'])) continue;
        $p = txnFromRecord($rec);
        if ($p['amount'] <= 0 || $p['tx_id'] === '') { $r['invalid']++; continue; }
        if (isset($existing[$p['tx_id']])) { $r['duplicate']++; continue; }
        if ($p['phone'] !== '') {
            $idx = $idx ?? phoneIndex();
            if (!empty($idx[$p['phone']])) { $p['uid'] = $idx[$p['phone']][0]; $p['uid_name'] = $idx[$p['phone']][1]; }
        }
        $res = saveDeposit($p, $source, $by); $r[$res]++;
        if ($res === 'saved' || $res === 'duplicate') $existing[$p['tx_id']] = true;
        if ($res === 'saved' && !empty($p['uid']) && creditDeposit($p['tx_id'], 'auto') === 'credited') $r['credited']++;
    }
    return $r;
}
function runAutoImport($raw, string $source, string $by, array $deposits): array {
    $r = importFrom($raw, $source, $by, $deposits);
    $r['credited'] += autoCreditDeposits(array_filter(fbGet('deposits') ?: [], 'is_array'));
    return $r;
}
function saveMsg(string $res, string $id): array {
    $m = ['saved' => ["Deposit $id saved as pending.", 'ok'], 'duplicate' => ["Transaction number $id already exists — not saved again.", 'warn'],
        'invalid' => ['Could not find both an amount and a transaction number.', 'bad'], 'error' => ['Database write failed. Try again.', 'bad']];
    return $m[$res];
}

/* ───────────── Helpers ───────────── */
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
    $m = ['processed' => 'ok', 'approved' => 'ok', 'pending' => 'warn', 'rejected' => 'bad', 'banned' => 'bad', 'vip' => 'vip', 'active' => 'ok', 'disabled' => 'bad', 'new' => 'warn', 'saved' => 'ok', 'unreadable' => 'bad'];
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
function ledger(string $uid, string $type, float $amount, float $after, string $note = '', ?string $by = null): void {
    global $ME;
    fb('POST', 'wallet_ledger', ['telegram_id' => $uid, 'type' => $type, 'amount' => $amount, 'balance_after' => $after, 'note' => $note, 'by' => $by ?? ($ME['username'] ?? ''), 'at' => time()]);
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

if (isset($_GET['cron']) && getenv('CRON_KEY') && hash_equals((string)getenv('CRON_KEY'), (string)($_GET['key'] ?? ''))) {
    $ME = ['username' => 'cron', 'role' => 'system'];
    $SET = array_merge(settingsDefaults(), fbGet('settings') ?: []);
    $res = runAutoImport(fbGet('transactions', ['orderBy' => '"$key"', 'limitToLast' => 300]) ?: [], 'sms-auto', 'cron', fbGet('deposits') ?: []);
    if ($res['saved'] || $res['credited']) audit('import.cron', json_encode($res));
    header('Content-Type: application/json'); echo json_encode($res); exit;
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
        if (!adjustBalance($uid, $delta, $after)) { flash('Could not update balance. Try again.', 'bad'); back(); }
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
            if (!adjustBalance($tid, $amt, $after)) { fbPut('withdrawals/' . k($id) . '/status', $prev); flash('Could not refund the player.', 'bad'); back(); }
            ledger($tid, 'refund', $amt, $after, "Withdrawal $id rejected"); syncUserOne($tid);
            notifyUser($tid, '❌ <b>Withdrawal Rejected & Refunded</b>\nYour withdrawal request of <b>' . money($amt) . ' ETB</b> was rejected and refunded to your wallet.' . ($why ? "\nReason: " . e($why) : ''));
        } elseif ($to === 'approved' && $tid !== '') {
            notifyUser($tid, '🎉 <b>Withdrawal Approved!</b>\nYour payout request of <b>' . money($amt) . ' ETB</b> has been successfully processed and sent to your account.');
        }
        audit('withdrawal.' . $to, "$id · " . money($amt) . ' ETB'); flash($to === 'approved' ? 'Withdrawal approved & notification sent.' : 'Withdrawal rejected and refunded.'); back();

    case 'send_broadcast':
        need('broadcast.send');
        $text = trim((string)($_POST['message'] ?? '')); $seg = $_POST['segment'] ?? 'all';
        if ($text === '') { flash('Write the message first.', 'bad'); back(); }
        $btnT = trim((string)($_POST['btn_text'] ?? '')) ?: '🌴 Play now'; $btnU = trim((string)($_POST['btn_url'] ?? '')) ?: GAME_URL;
        $img = null;
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['image_file']['tmp_name'];
            if (getimagesize($tmp) !== false && $_FILES['image_file']['size'] <= 10 * 1048576) {
                $img = new CURLFile($tmp, mime_content_type($tmp), $_FILES['image_file']['name']);
            }
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

    case 'import_all':
        need('transactions.import');
        $r = runAutoImport(fbGet('transactions', ['orderBy' => '"$key"', 'limitToLast' => 300]) ?: [], 'sms-auto', $ME['username'], fbGet('deposits') ?: []);
        audit('import.all', json_encode($r));
        flash("Saved {$r['saved']} new · credited {$r['credited']} automatically.", $r['saved'] || $r['credited'] ? 'ok' : 'warn'); back();

    case 'import_one':
        need('transactions.import');
        $key = (string)($_POST['key'] ?? ''); $rec = fbGet('transactions/' . k($key));
        $r = runAutoImport($rec === null ? [] : [$key => $rec], 'sms-auto', $ME['username'], fbGet('deposits') ?: []);
        flash($r['saved'] ? 'Saved to deposits' . ($r['credited'] ? ' and credited to the player.' : '') : 'Could not import.', $r['saved'] ? 'ok' : 'warn'); back();

    case 'extract_manual':
        need('transactions.import'); $txt = trim((string)($_POST['text'] ?? ''));
        if ($txt === '') { unset($_SESSION['txn_preview']); back(); }
        $p = parseTxn($txt); $p['raw'] = mb_substr($txt, 0, 500); $_SESSION['txn_preview'] = $p;
        flash($p['amount'] > 0 && $p['tx_id'] !== '' ? 'Extracted successfully!' : 'Could not extract fully — fill in manually.', 'ok'); back();

    case 'clear_preview':
        unset($_SESSION['txn_preview']); back();

    case 'save_manual':
        need('transactions.import');
        $tid = preg_replace('/\D/', '', (string)($_POST['tid'] ?? '')); $pl = $tid !== '' ? fbGet('users/' . k($tid)) : null;
        $p = ['amount' => round((float)($_POST['amount'] ?? 0), 2), 'name' => trim((string)($_POST['name'] ?? '')), 'tx_id' => normTxId((string)($_POST['tx_id'] ?? '')),
            'ts' => (int)strtotime((string)($_POST['when'] ?? '')), 'raw' => (string)($_POST['raw'] ?? ''), 'uid' => $tid, 'uid_name' => is_array($pl) ? ($pl['username'] ?? ($pl['first_name'] ?? $tid)) : ''];
        $res = saveDeposit($p, 'manual', $ME['username']); $cr = '';
        if ($res === 'saved') {
            unset($_SESSION['txn_preview']); audit('deposit.manual', $p['tx_id'] . ' · ' . money($p['amount']) . ' ETB');
            if ($tid !== '') $cr = creditDeposit($p['tx_id'], 'auto');
        }
        [$m, $t] = saveMsg($res, $p['tx_id']);
        flash($cr === 'credited' ? "Deposit {$p['tx_id']} saved and credited!" : $m, $t); back();

    case 'link_deposit':
        need('deposits.process'); $id = (string)($_POST['tx_id'] ?? ''); $tid = preg_replace('/\D/', '', (string)($_POST['tid'] ?? ''));
        $d = fbGet('deposits/' . k($id)); $u = $tid !== '' ? fbGet('users/' . k($tid)) : null;
        if (!is_array($d) || !is_array($u)) { flash('Deposit or player not found.', 'bad'); back(); }
        fbPatch('deposits/' . k($id), ['telegram_id' => $tid, 'claimed_by' => $u['username'] ?? ($u['first_name'] ?? $tid)]);
        $cr = creditDeposit($id, 'auto'); audit('deposit.link', "$id → $tid");
        flash($cr === 'credited' ? 'Linked and credited successfully!' : 'Linked successfully.', 'ok'); back();

    case 'toggle_game':
        need('settings.manage'); $on = ($_POST['value'] ?? '') === '1';
        fbPatch('settings', ['game_enabled' => $on]); audit('game.' . ($on ? 'resume' : 'pause')); flash($on ? 'Game is live.' : 'Game paused.'); back();

    case 'save_settings':
        need('settings.manage');
        $new = ['game_enabled' => isset($_POST['game_enabled']), 'notify_users' => isset($_POST['notify_users']), 'maintenance_msg' => trim((string)$_POST['maintenance_msg']),
            'telebirr_name' => trim((string)$_POST['telebirr_name']), 'telebirr_number' => trim((string)$_POST['telebirr_number']), 'cbe_account' => trim((string)$_POST['cbe_account'])];
        foreach (['entry_fee', 'commission_pct', 'min_deposit', 'min_withdraw', 'max_withdraw', 'welcome_bonus'] as $f) $new[$f] = max(0, (float)($_POST[$f] ?? 0));
        fbPatch('settings', $new); audit('settings.save'); flash('Settings saved.'); back();

    case 'add_admin':
        need('admins.manage');
        $un = trim((string)$_POST['username']); $pw = (string)$_POST['password']; $role = $_POST['role'] ?? 'support';
        $perms = array_values(array_intersect((array)($_POST['perms'] ?? []), array_keys(permList()))); $limit = max(0, (float)($_POST['limit'] ?? 0));
        $rec = ['username' => $un, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'limit' => $limit, 'active' => true, 'created_at' => time()];
        fbPut('admins/' . uniqid('adm_'), $rec); audit('admin.add', "$un ($role)"); flash("Admin $un created."); back();

    case 'update_admin':
        need('admins.manage'); $id = (string)$_POST['id'];
        $role = $_POST['role'] ?? 'support'; $perms = array_values(array_intersect((array)($_POST['perms'] ?? []), array_keys(permList())));
        $upd = ['role' => $role, 'limit' => max(0, (float)($_POST['limit'] ?? 0)), 'active' => isset($_POST['active'])];
        if (!empty($_POST['new_password'])) $upd['pass_hash'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        fbPatch('admins/' . k($id), $upd); audit('admin.update', $id); flash('Admin updated.'); back();

    case 'delete_admin':
        need('admins.manage'); $id = (string)$_POST['id'];
        fbDel('admins/' . k($id)); audit('admin.delete', $id); flash('Admin deleted.'); back();

    case 'change_password':
        $a = fbGet('admins/' . k($ME['id'])); $np = (string)$_POST['new_password'];
        fbPatch('admins/' . k($ME['id']), ['pass_hash' => password_hash($np, PASSWORD_DEFAULT)]); audit('password.change'); flash('Password changed.'); back();
    }
}

/* ───────────── Routing & Fetching ───────────── */
$nav = [
    'dashboard'    => ['📊', 'Dashboard', 'ዳሽቦርድ', null],
    'users'        => ['👥', 'Players', 'ተጫዋቾች', 'users.view'],
    'deposits'     => ['📥', 'Deposits', 'ብር ማስገቢያ', 'deposits.view'],
    'transactions' => ['💳', 'Incoming payments', 'የገቢ ክፍያዎች', 'transactions.view'],
    'withdrawals'  => ['📤', 'Withdrawals', 'ብር ማውጫ', 'withdrawals.view'],
    'broadcast'    => ['📢', 'Broadcast', 'ብሮድካስት', 'broadcast.send'],
    'settings'     => ['🎛️', 'Game settings', 'ቅንብር', 'settings.manage'],
    'logs'         => ['🧾', 'Audit & ledger', 'ታሪክ', 'logs.view'],
    'admins'       => ['🛡️', 'Add admin & roles', 'አድሚን ጨምር', 'admins.manage'],
];
$tab = $_GET['tab'] ?? 'dashboard';
$tabKey = $tab === 'user' ? 'users' : $tab;
if ($ME && $tab !== 'account' && (!isset($nav[$tabKey]) || ($nav[$tabKey][3] && !can($nav[$tabKey][3])))) $tab = $tabKey = 'dashboard';

$users = $deposits = $withdrawals = [];
if ($ME) {
    if (in_array($tab, ['dashboard', 'users', 'user', 'broadcast'])) $users = array_filter(fbGet('users') ?: [], 'is_array');
    if (in_array($tab, ['dashboard', 'deposits', 'user', 'transactions'])) $deposits = array_filter(fbGet('deposits') ?: [], 'is_array');
    if (in_array($tab, ['dashboard', 'withdrawals', 'user'])) $withdrawals = array_filter(fbGet('withdrawals') ?: [], 'is_array');
    $byTime = function ($a, $b) { return ts($b) <=> ts($a); };
    uasort($deposits, $byTime); uasort($withdrawals, $byTime);
    $txRaw = []; $autoRes = null;
    if ($tab === 'transactions') {
        $txRaw = fbGet('transactions', ['orderBy' => '"$key"', 'limitToLast' => 300]) ?: [];
        if (can('transactions.import')) {
            $autoRes = runAutoImport($txRaw, 'sms-auto', $ME['username'], $deposits);
            if ($autoRes['saved'] || $autoRes['credited']) { audit('import.auto', json_encode($autoRes)); $deposits = array_filter(fbGet('deposits') ?: [], 'is_array'); uasort($deposits, $byTime); }
        }
    }
    if ($tab === 'deposits' && autoCreditDeposits($deposits)) { $deposits = array_filter(fbGet('deposits') ?: [], 'is_array'); uasort($deposits, $byTime); }
}
$perPage = 25;
$roles = roleList(); $perms = permList();

// Pending counts for Header Notification Badges
$pendingDepositsCount = count(array_filter($deposits, function($d) { return ($d['status'] ?? 'pending') === 'pending'; }));
$pendingWithdrawalsCount = count(array_filter($withdrawals, function($w) { return ($w['status'] ?? 'pending') === 'pending'; }));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LALA BINGO · Admin Console</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Ethiopic:wght@400;600&display=swap" rel="stylesheet">
<script>try{var t=localStorage.getItem('lb-theme');if(t)document.documentElement.dataset.theme=t;else if(matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.dataset.theme='dark'}catch(e){}</script>
<style>
:root{--bg:#f8fafc;--surface:#ffffff;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--side:#0f172a;--side-ink:#94a3b8;--accent:#f59e0b;--accent-ink:#ffffff;--accent-soft:#fef3c7;--ok:#10b981;--warn:#f59e0b;--bad:#ef4444;--vip:#8b5cf6;--r:16px}
[data-theme=dark]{--bg:#090d16;--surface:#111c2e;--ink:#f1f5f9;--muted:#94a3b8;--line:#1e293b;--side:#070b14;--side-ink:#94a3b8;--accent-soft:#291e08}
*{box-sizing:border-box;margin:0;padding:0}
body{font:14.5v/1.5 'Plus Jakarta Sans','Noto Sans Ethiopic',sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
a{color:inherit}
.sidebar{width:260px;background:var(--side);color:var(--side-ink);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;flex-shrink:0;box-shadow:4px 0 24px rgba(0,0,0,0.05)}
.brand{display:flex;align-items:center;gap:12px;padding:24px 20px;color:#fff;font-weight:800;font-size:18px}
.ball{width:38px;height:38px;border-radius:50%;background:radial-gradient(circle at 30% 30%,#fff 0 15%,transparent 16%),var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:15px;box-shadow:inset -3px -3px 0 rgba(0,0,0,0.2)}
.brand small{display:block;font-weight:500;font-size:11px;color:var(--side-ink);opacity:0.8}
.menu{list-style:none;padding:8px 14px;flex:1;overflow-y:auto}
.menu a{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:12px;text-decoration:none;color:var(--side-ink);font-weight:600;margin-bottom:4px;transition:all 0.15s ease}
.menu a span.t small{display:block;font-weight:400;font-size:11px;opacity:.7}
.menu a:hover{background:rgba(255,255,255,.06);color:#fff}
.menu a.on{background:var(--accent);color:var(--accent-ink);box-shadow:0 4px 12px rgba(245,158,11,0.25)}
.menu a.on small{opacity:.85}
.badge-count{margin-left:auto;background:var(--bad);color:#fff;font-size:11px;font-weight:800;padding:2px 7px;border-radius:99px}
.me{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12.5px}
.me b{color:#fff;display:block}
.main{flex:1;min-width:0;padding:0 32px 48px}
.top{display:flex;align-items:center;gap:14px;padding:22px 0;position:sticky;top:0;background:var(--bg);z-index:20;border-bottom:1px solid var(--line);margin-bottom:24px}
.top h1{font-size:20px;font-weight:800;flex:1;display:flex;align-items:center;gap:10px}
.top h1 small{font-weight:500;font-size:13px;color:var(--muted)}
.iconbtn{background:var(--surface);border:1px solid var(--line);color:var(--ink);border-radius:12px;padding:9px 14px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s}
.iconbtn:hover{border-color:var(--accent);background:var(--accent-soft)}
.burger{display:none}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.02)}
.card h3{font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:10px;justify-content:space-between}
.card h3 small{font-weight:500;color:var(--muted);font-size:12.5px}
.grid{display:grid;gap:18px;margin-bottom:22px}
.g5{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}.g2{grid-template-columns:repeat(auto-fit,minmax(340px,1fr))}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:20px;display:flex;gap:14px;align-items:center;text-decoration:none;transition:transform 0.15s, border-color 0.15s}
.stat:hover{transform:translateY(-2px);border-color:var(--accent)}
.stat .ball{width:46px;height:46px;font-size:18px;flex-shrink:0}
.stat.alert{border-color:var(--bad);background:rgba(239,68,68,0.03)}
.stat p{font-size:12px;color:var(--muted);font-weight:600}.stat strong{font-size:20px;font-weight:800;display:block;margin-top:2px}
.tbl{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;color:var(--muted);font-weight:600;font-size:12px;padding:12px 14px;border-bottom:2px solid var(--line);white-space:nowrap;background:rgba(0,0,0,0.01)}
td{padding:14px;border-bottom:1px solid var(--line);vertical-align:middle}
tr:hover td{background:rgba(0,0,0,0.015)}
td small,.muted{color:var(--muted)}
.num{font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}
.chip{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700;background:var(--line);color:var(--muted)}
.chip.ok{background:rgba(16,185,129,0.12);color:var(--ok)}.chip.warn{background:rgba(245,158,11,0.12);color:var(--warn)}.chip.bad{background:rgba(239,68,68,0.12);color:var(--bad)}.chip.vip{background:rgba(139,92,246,0.12);color:var(--vip)}
label{display:block;font-size:12.5px;font-weight:700;color:var(--muted);margin:14px 0 6px}
input,select,textarea{width:100%;padding:11px 14px;background:var(--bg);border:1px solid var(--line);border-radius:12px;color:var(--ink);font:inherit;transition:border-color 0.15s}
input[type=checkbox]{width:auto;accent-color:var(--accent)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(245,158,11,0.15)}
textarea{min-height:96px;resize:vertical}
button,.btn{background:var(--accent);color:var(--accent-ink);border:0;border-radius:12px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer;margin-top:14px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:filter 0.15s, transform 0.1s}
button:hover,.btn:hover{filter:brightness(1.05)}
button:active,.btn:active{transform:scale(0.98)}
button.sm{padding:6px 12px;font-size:12px;margin:0;border-radius:8px}
button.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}
button.ok{background:var(--ok);color:#fff}button.bad{background:var(--bad);color:#fff}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.toolbar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}.toolbar input,.toolbar select{width:auto;min-width:200px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}.tabs a{padding:7px 16px;border-radius:99px;border:1px solid var(--line);text-decoration:none;font-size:13px;font-weight:600;color:var(--muted);transition:all 0.15s}.tabs a.on{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.toast{position:fixed;right:24px;top:24px;z-index:99;padding:14px 20px;border-radius:14px;background:var(--surface);color:var(--ink);font-weight:600;max-width:400px;box-shadow:0 12px 32px rgba(0,0,0,0.12);border:1px solid var(--line);border-left:5px solid var(--ok);animation:slideIn 0.25s ease}
.toast.bad{border-left-color:var(--bad)}.toast.warn{border-left-color:var(--warn)}
@keyframes slideIn { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.banner{background:var(--accent-soft);border:1px solid var(--accent);padding:14px 18px;border-radius:var(--r);margin-bottom:20px;font-size:13.5px}
.calendar-group{margin-bottom:20px}
.calendar-date-header{font-size:13px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px;padding-bottom:4px;border-bottom:1px dashed var(--line)}
.login{margin:auto;width:min(420px,92vw);background:var(--surface);border:1px solid var(--line);border-radius:24px;padding:38px;box-shadow:0 20px 40px rgba(0,0,0,0.06)}
.login .ball{width:56px;height:56px;font-size:24px;margin:0 auto 16px}.login h1{text-align:center;font-size:22px;margin-bottom:6px}.login p{text-align:center;color:var(--muted);font-size:13px;margin-bottom:20px}
@media(max-width:860px){.sidebar{position:fixed;left:-280px;z-index:50;transition:left .25s ease}.burger{display:inline-flex}.main{padding:0 16px 40px}}
</style>
</head>
<body>
<?php if ($flash): ?><div class="toast <?= e($flash[1]) ?>" id="toast" role="status"><?= e($flash[0]) ?></div><?php endif; ?>

<?php if (!$ME): ?>
<div class="login">
  <div class="ball">B</div>
  <h1>LALA BINGO Admin</h1>
  <p>Sign in to manage players, deposits &amp; payouts</p>
  <form method="POST"><?= csrf() ?>
    <label for="u">Username</label><input id="u" name="username" required autofocus autocomplete="username">
    <label for="p">Password</label><input id="p" type="password" name="password" required autocomplete="current-password">
    <button type="submit" name="login" value="1" style="width:100%;margin-top:20px">Sign In</button>
  </form>
</div>

<?php else: ?>
<aside class="sidebar">
  <div class="brand"><div class="ball">B</div><div>LALA BINGO<small>Admin Portal</small></div></div>
  <ul class="menu">
    <?php foreach ($nav as $key => $n): if ($n[3] && !can($n[3])) continue; 
        $badge = '';
        if ($key === 'deposits' && $pendingDepositsCount > 0) $badge = "<span class=\"badge-count\">$pendingDepositsCount</span>";
        if ($key === 'withdrawals' && $pendingWithdrawalsCount > 0) $badge = "<span class=\"badge-count\">$pendingWithdrawalsCount</span>";
    ?>
      <li><a href="admin.php?tab=<?= $key ?>" class="<?= $tabKey === $key ? 'on' : '' ?>"><span><?= $n[0] ?></span><span class="t"><?= e($n[1]) ?><small><?= e($n[2]) ?></small></span><?= $badge ?></a></li>
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
      <form method="POST" style="margin:0"><?= csrf() ?><input type="hidden" name="action" value="toggle_game"><input type="hidden" name="value" value="<?= $SET['game_enabled'] ? '0' : '1' ?>">
        <button class="iconbtn" data-confirm="<?= $SET['game_enabled'] ? 'Pause the game for all players?' : 'Resume the game?' ?>"><?= $SET['game_enabled'] ? '🟢 Live · Pause' : '🔴 Paused · Resume' ?></button></form>
    <?php endif; ?>
    <button class="iconbtn" type="button" id="theme" aria-label="Toggle theme">🌓</button>
  </div>

  <?php if (DEFAULT_CREDS && $ME['id'] === 'root'): ?>
    <div class="banner">⚠️ Using default root credentials. Please configure secure environment variables on your server.</div>
  <?php endif; ?>

<?php /* ═════════ DASHBOARD ═════════ */ if ($tab === 'dashboard'):
    $totBal = 0; $banned = 0; $vips = 0;
    foreach ($users as $u) { $totBal += (float)($u['balance'] ?? 0); $banned += !empty($u['banned']); $vips += !empty($u['vip']); }
    $pd = array_filter($deposits, function ($d) { return !in_array($d['status'] ?? 'pending', ['processed', 'rejected'], true) && empty($d['telegram_id']); });
    $pw = array_filter($withdrawals, function ($w) { return ($w['status'] ?? 'pending') === 'pending'; });
    $pdSum = array_sum(array_map(function ($d) { return (float)($d['amount'] ?? 0); }, $pd));
    $pwSum = array_sum(array_map(function ($w) { return (float)($w['amount'] ?? 0); }, $pw));
?>
  <div class="grid g5">
    <a class="stat" href="admin.php?tab=users"><div class="ball">👥</div><div><p>Players</p><strong><?= count($users) ?></strong><em><?= $vips ?> VIP</em></div></a>
    <div class="stat"><div class="ball">💰</div><div><p>Wallets Held</p><strong><?= money($totBal) ?></strong><em>ETB in balance</em></div></div>
    <a class="stat <?= $pendingDepositsCount ? 'alert' : '' ?>" href="admin.php?tab=deposits&s=pending"><div class="ball">📥</div><div><p>Pending Deposits</p><strong><?= $pendingDepositsCount ?></strong><em><?= money($pdSum) ?> ETB</em></div></a>
    <a class="stat <?= $pendingWithdrawalsCount ? 'alert' : '' ?>" href="admin.php?tab=withdrawals&s=pending"><div class="ball">📤</div><div><p>Pending Payouts</p><strong><?= $pendingWithdrawalsCount ?></strong><em><?= money($pwSum) ?> ETB</em></div></a>
    <div class="stat"><div class="ball">🚀</div><div><p>Game Status</p><strong><?= $SET['game_enabled'] ? 'Active' : 'Paused' ?></strong><em>System online</em></div></div>
  </div>

  <div class="grid g2">
    <div class="card">
      <h3>Recent Deposits Timeline</h3>
      <div class="tbl"><table>
        <tr><th>Amount</th><th>Player / Ref</th><th>Time</th></tr>
        <?php 
        $recentDep = array_slice($deposits, 0, 5, true);
        foreach ($recentDep as $id => $d): ?>
          <tr><td class="num text-ok">+<?= money($d['amount'] ?? 0) ?> ETB</td><td><?= e($d['claimed_by'] ?? $id) ?></td><td><small><?= fdate(ts($d)) ?></small></td></tr>
        <?php endforeach; if (!$recentDep): ?><tr><td colspan="3" class="muted">No deposits recorded yet.</td></tr><?php endif; ?>
      </table></div>
    </div>
    <div class="card">
      <h3>Recent Payout Requests</h3>
      <div class="tbl"><table>
        <tr><th>Amount</th><th>Method</th><th>Status</th></tr>
        <?php 
        $recentWdr = array_slice($withdrawals, 0, 5, true);
        foreach ($recentWdr as $id => $w): ?>
          <tr><td class="num">-<?= money($w['amount'] ?? 0) ?> ETB</td><td><?= e($w['method'] ?? 'CBE') ?></td><td><?= chip($w['status'] ?? 'pending') ?></td></tr>
        <?php endforeach; if (!$recentWdr): ?><tr><td colspan="3" class="muted">No withdrawal requests yet.</td></tr><?php endif; ?>
      </table></div>
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
      <input name="q" value="<?= e($q) ?>" placeholder="Search name, username, phone, ID">
      <select name="f"><?php foreach (['all' => 'All players', 'funded' => 'Has balance', 'empty' => 'Zero balance', 'vip' => 'VIP', 'banned' => 'Banned'] as $v => $l): ?><option value="<?= $v ?>" <?= $f === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <button class="btn" type="submit">Filter</button>
      <?php if (can('export.data')): ?><a class="btn iconbtn" href="admin.php?export=users" style="background:var(--surface);color:var(--ink)">⬇ Export CSV</a><?php endif; ?>
    </form>
    <div class="tbl"><table>
      <tr><th>Player</th><th>Phone / ID</th><th>Balance</th><th>Status</th><th>Actions</th></tr>
      <?php foreach ($slice as $key => $u): $id = uidOf($key, $u); ?>
        <tr><td><b><?= e($u['first_name'] ?? 'Player') ?></b><br><small>@<?= e($u['username'] ?? '—') ?></small></td>
          <td><code><?= e($u['phone'] ?? 'N/A') ?></code><br><small>ID <?= e($id) ?></small></td>
          <td class="num"><?= money($u['balance'] ?? 0) ?> ETB</td>
          <td><?= !empty($u['banned']) ? chip('banned') : chip('active') ?> <?= !empty($u['vip']) ? chip('vip') : '' ?></td>
          <td><a class="btn sm" href="admin.php?tab=user&id=<?= e($id) ?>">Manage</a></td></tr>
      <?php endforeach; if (!$slice): ?><tr><td colspan="5" class="muted">No players match your search.</td></tr><?php endif; ?>
    </table></div>
    <div class="pager" style="margin-top:16px"><?php for ($p = 1; $p <= $pages; $p++): ?><a class="<?= $p === $page ? 'on' : '' ?>" href="admin.php?<?= e(http_build_query(['tab' => 'users', 'q' => $q, 'f' => $f, 'page' => $p])) ?>"><?= $p ?></a><?php endfor; ?></div>
  </div>

<?php /* ═════════ PLAYER DETAIL ═════════ */ elseif ($tab === 'user'):
    $uid = preg_replace('/\D/', '', (string)($_GET['id'] ?? '')); $pu = null;
    foreach ($users as $key => $u) if (uidOf($key, $u) === $uid) { $pu = $u; break; }
    if (!$pu): ?><div class="card">Player not found. <a href="admin.php?tab=users">Back to players</a></div>
<?php else:
    $myDep = array_filter($deposits, function ($d) use ($uid) { return preg_replace('/\D/', '', (string)($d['telegram_id'] ?? '')) === $uid; });
    $myWdr = array_filter($withdrawals, function ($w) use ($uid) { return preg_replace('/\D/', '', (string)($w['telegram_id'] ?? '')) === $uid; });
?>
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div><div class="big"><?= money($pu['balance'] ?? 0) ?> <small class="muted">ETB</small></div>
        <b><?= e($pu['first_name'] ?? 'Player') ?></b> <span class="muted">@<?= e($pu['username'] ?? '—') ?> · ID <?= e($uid) ?> · <?= e($pu['phone'] ?? 'no phone') ?></span></div>
      <div><?= !empty($pu['banned']) ? chip('banned') : chip('active') ?> <?= !empty($pu['vip']) ? chip('vip') : '' ?></div>
    </div>
  </div>
  <div class="grid g2">
    <?php if (can('users.balance')): ?>
    <div class="card"><h3>Adjust Balance</h3>
      <form method="POST" data-confirm="Apply this balance change?"><?= csrf() ?><input type="hidden" name="action" value="adjust_balance"><input type="hidden" name="uid" value="<?= e($uid) ?>">
        <label>Action</label><select name="mode"><option value="add">Add money</option><option value="bonus">Give bonus</option><option value="sub">Deduct money</option><option value="set">Set exact balance</option></select>
        <label>Amount (ETB)</label><input type="number" name="amount" step="0.01" min="0" required>
        <label>Reason</label><input name="reason" maxlength="120" placeholder="e.g. Promo reward">
        <button type="submit">Apply Change</button></form></div>
    <?php endif; ?>
    <div class="card"><h3>Status &amp; Controls</h3>
      <?php if (can('users.ban')): ?>
      <div class="row" style="margin-bottom:14px">
        <form method="POST" data-confirm="Toggle player ban status?"><?= csrf() ?><input type="hidden" name="action" value="toggle_ban"><input type="hidden" name="uid" value="<?= e($uid) ?>"><input type="hidden" name="value" value="<?= !empty($pu['banned']) ? '0' : '1' ?>"><button class="sm <?= empty($pu['banned']) ? 'bad' : 'ok' ?>"><?= !empty($pu['banned']) ? 'Unban Player' : 'Ban Player' ?></button></form>
        <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="toggle_vip"><input type="hidden" name="uid" value="<?= e($uid) ?>"><input type="hidden" name="value" value="<?= !empty($pu['vip']) ? '0' : '1' ?>"><button class="sm ghost"><?= !empty($pu['vip']) ? 'Remove VIP' : 'Mark as VIP' ?></button></form>
      </div>
      <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="save_note"><input type="hidden" name="uid" value="<?= e($uid) ?>">
        <label>Admin Note</label><textarea name="note" maxlength="500"><?= e($pu['note'] ?? '') ?></textarea><button class="sm" type="submit">Save Note</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php /* ═════════ DEPOSITS (CALENDAR VIEW) ═════════ */ elseif ($tab === 'deposits'):
    $s = $_GET['s'] ?? 'all';
    $list = array_filter($deposits, function ($d) use ($s) { $st = $d['status'] ?? 'pending'; return $s === 'all' || ($s === 'pending' ? !in_array($st, ['processed', 'rejected'], true) : $st === $s); });
    
    // Group deposits by calendar day (Y-m-d)
    $calendarDeposits = [];
    foreach ($list as $id => $d) {
        $timestamp = ts($d);
        $dayKey = $timestamp ? date('Y-m-d', $timestamp) : 'Unscheduled / Unknown Date';
        $calendarDeposits[$dayKey][$id] = $d;
    }
    krsort($calendarDeposits);
?>
  <div class="card">
    <div class="tabs"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'processed' => 'Approved', 'rejected' => 'Rejected'] as $v => $l): ?><a class="<?= $s === $v ? 'on' : '' ?>" href="admin.php?tab=deposits&s=<?= $v ?>"><?= $l ?></a><?php endforeach; ?></div>
    
    <?php if (can('export.data')): ?>
    <div class="toolbar"><a class="btn iconbtn" href="admin.php?export=deposits" style="background:var(--surface);color:var(--ink)">⬇ Export CSV</a></div>
    <?php endif; ?>

    <?php if (!$calendarDeposits): ?>
        <p class="muted">No deposits recorded in this view.</p>
    <?php else: ?>
        <?php foreach ($calendarDeposits as $dateKey => $dayItems): ?>
            <div class="calendar-group">
                <div class="calendar-date-header">📅 <?= $dateKey === 'Unscheduled / Unknown Date' ? $dateKey : date('l, F j, Y', strtotime($dateKey)) ?> (<?= count($dayItems) ?> deposits)</div>
                <div class="tbl"><table>
                    <tr><th>Transaction ID</th><th>Player / Sender</th><th>Amount</th><th>Status</th><th>Action</th></tr>
                    <?php foreach ($dayItems as $id => $d): $st = $d['status'] ?? 'pending'; ?>
                        <tr>
                            <td><code><?= e($id) ?></code><br><small><?= fdate(ts($d)) ?></small></td>
                            <td>
                                <?php if (!empty($d['telegram_id']) && can('users.view')): ?>
                                    <a href="admin.php?tab=user&id=<?= e(preg_replace('/\D/', '', (string)$d['telegram_id'])) ?>">@<?= e($d['claimed_by'] ?? $d['telegram_id']) ?></a>
                                <?php else: ?>
                                    <?= !empty($d['claimed_by']) ? '@' . e($d['claimed_by']) : '<small class="muted">Unlinked</small>' ?>
                                <?php endif; ?>
                                <?php if (!empty($d['sender_name'])): ?><br><small>Sender: <?= e($d['sender_name']) ?></small><?php endif; ?>
                                
                                <?php if (empty($d['telegram_id']) && $st !== 'processed' && can('deposits.process')): ?>
                                    <form method="POST" class="row" style="margin-top:6px"><?= csrf() ?><input type="hidden" name="action" value="link_deposit"><input type="hidden" name="tx_id" value="<?= e($id) ?>"><input name="tid" inputmode="numeric" placeholder="Player Telegram ID" required style="width:150px;padding:5px 8px"><button class="sm ghost">Link</button></form>
                                <?php endif; ?>
                            </td>
                            <td class="num text-ok">+<?= money($d['amount'] ?? 0) ?> ETB</td>
                            <td><?= chip($st) ?></td>
                            <td><small class="muted"><?= $st === 'processed' ? 'Credited' : 'Pending' ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </table></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php /* ═════════ WITHDRAWALS (CALENDAR VIEW) ═════════ */ elseif ($tab === 'withdrawals'):
    $s = $_GET['s'] ?? 'all';
    $list = array_filter($withdrawals, function ($w) use ($s) { return $s === 'all' || ($w['status'] ?? 'pending') === $s; });

    // Group withdrawals by calendar day (Y-m-d)
    $calendarWithdrawals = [];
    foreach ($list as $id => $w) {
        $timestamp = ts($w);
        $dayKey = $timestamp ? date('Y-m-d', $timestamp) : 'Unscheduled / Unknown Date';
        $calendarWithdrawals[$dayKey][$id] = $w;
    }
    krsort($calendarWithdrawals);
?>
  <div class="card">
    <div class="tabs"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Sent', 'rejected' => 'Rejected'] as $v => $l): ?><a class="<?= $s === $v ? 'on' : '' ?>" href="admin.php?tab=withdrawals&s=<?= $v ?>"><?= $l ?></a><?php endforeach; ?></div>
    
    <?php if (can('export.data')): ?>
    <div class="toolbar"><a class="btn iconbtn" href="admin.php?export=withdrawals" style="background:var(--surface);color:var(--ink)">⬇ Export CSV</a></div>
    <?php endif; ?>

    <?php if (!$calendarWithdrawals): ?>
        <p class="muted">No withdrawal requests found.</p>
    <?php else: ?>
        <?php foreach ($calendarWithdrawals as $dateKey => $dayItems): ?>
            <div class="calendar-group">
                <div class="calendar-date-header">📅 <?= $dateKey === 'Unscheduled / Unknown Date' ? $dateKey : date('l, F j, Y', strtotime($dateKey)) ?> (<?= count($dayItems) ?> requests)</div>
                <div class="tbl"><table>
                    <tr><th>Request ID</th><th>Player Details</th><th>Amount &amp; Account</th><th>Status</th><th>Actions</th></tr>
                    <?php foreach ($dayItems as $id => $w): $st = $w['status'] ?? 'pending'; ?>
                        <tr>
                            <td><code><?= e($id) ?></code><br><small><?= e($w['method'] ?? 'CBE') ?> · <?= fdate(ts($w)) ?></small></td>
                            <td><b><?= e($w['first_name'] ?? 'Player') ?></b><br><code><?= e($w['phone'] ?? '') ?></code></td>
                            <td class="num">-<?= money($w['amount'] ?? 0) ?> ETB<br><small style="font-weight:400"><?= e($w['account_details'] ?? '') ?></small></td>
                            <td><?= chip($st) ?><?php if (!empty($w['reason'])): ?><br><small><?= e($w['reason']) ?></small><?php endif; ?></td>
                            <td>
                                <?php if (can('withdrawals.process') && $st === 'pending'): ?>
                                    <div class="row">
                                        <form method="POST" data-confirm="Approve and mark payout as sent?"><?= csrf() ?><input type="hidden" name="action" value="process_withdrawal"><input type="hidden" name="wdr_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="approved"><button class="sm ok">Send &amp; Notify</button></form>
                                        <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="process_withdrawal"><input type="hidden" name="wdr_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="rejected"><input type="hidden" name="reason" value=""><button type="button" class="sm bad js-reject">Reject &amp; Refund</button></form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php /* ═════════ TRANSACTIONS ═════════ */ elseif ($tab === 'transactions'):
    $view = []; $seen = []; $cnt = ['new' => 0, 'saved' => 0, 'duplicate' => 0, 'unreadable' => 0];
    foreach (array_reverse($txRaw, true) as $key => $rec) {
        if (is_array($rec) && isset($rec['balance_after'])) continue;
        $p = txnFromRecord($rec);
        if ($p['amount'] <= 0 || $p['tx_id'] === '') $st = 'unreadable';
        elseif (isset($seen[$p['tx_id']])) $st = 'duplicate';
        elseif (isset($deposits[$p['tx_id']])) $st = 'saved';
        else $st = 'new';
        if ($p['tx_id'] !== '') $seen[$p['tx_id']] = true;
        $cnt[$st]++; $view[] = [$key, $p, $st];
    }
    $pv = $_SESSION['txn_preview'] ?? null;
?>
  <div class="grid g2">
    <div class="card"><h3>Manual Payment Entry</h3>
      <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="extract_manual">
        <label>Paste SMS text</label>
        <textarea name="text" placeholder="Paste Telebirr or bank SMS..." required><?= $pv ? e($pv['raw']) : '' ?></textarea>
        <button type="submit">Extract Data</button></form>
      <?php if ($pv): ?>
      <form method="POST" style="margin-top:14px;border-top:1px solid var(--line);padding-top:10px"><?= csrf() ?><input type="hidden" name="action" value="save_manual"><input type="hidden" name="raw" value="<?= e($pv['raw']) ?>">
        <div class="split"><div><label>Amount (ETB)</label><input type="number" step="0.01" name="amount" value="<?= e($pv['amount']) ?>" required></div>
          <div><label>Transaction ID</label><input name="tx_id" value="<?= e($pv['tx_id']) ?>" required></div></div>
        <button type="submit" class="ok" style="margin-top:12px">Save Deposit</button></form>
      <?php endif; ?>
    </div>
    <div class="card"><h3>Incoming SMS Sync</h3>
      <p class="muted" style="font-size:13px">Incoming records are automatically pulled and matched against user database accounts.</p>
      <div class="row" style="margin-top:14px"><?= chip('new') ?> <b><?= $cnt['new'] ?></b> <?= chip('saved') ?> <b><?= $cnt['saved'] ?></b></div>
      <?php if (can('transactions.import')): ?><form method="POST"><?= csrf() ?><input type="hidden" name="action" value="import_all"><button class="sm" type="submit" style="margin-top:14px">Sync All Now</button></form><?php endif; ?>
    </div>
  </div>

<?php /* ═════════ BROADCAST ═════════ */ elseif ($tab === 'broadcast'):
    $segs = ['all' => 'Everyone', 'funded' => 'Players with balance', 'empty' => 'Zero balance', 'vip' => 'VIP players'];
?>
  <div class="card" style="max-width:700px">
    <h3>Send Broadcast Notification</h3>
    <form method="POST" enctype="multipart/form-data" data-confirm="Send broadcast to targeted players?"><?= csrf() ?><input type="hidden" name="action" value="send_broadcast">
      <label>Target Audience</label>
      <select name="segment"><?php foreach ($segs as $v => $l): ?><option value="<?= $v ?>"><?= $l ?> (<?= count(segmentUsers($users, $v)) ?>)</option><?php endforeach; ?></select>
      <label>Poster Image (Optional)</label><input type="file" name="image_file" accept="image/*">
      <label>Message Content (HTML Supported)</label>
      <textarea name="message" required placeholder="🎉 Big jackpot starting soon..."></textarea>
      <label>Button Text</label><input name="btn_text" value="🌴 Play Now">
      <label>Button URL</label><input name="btn_url" value="<?= e(GAME_URL) ?>">
      <button type="submit" style="margin-top:18px">🚀 Send Broadcast Now</button></form>
  </div>

<?php /* ═════════ SETTINGS ═════════ */ elseif ($tab === 'settings'): ?>
  <form method="POST" data-confirm="Save game settings?"><?= csrf() ?><input type="hidden" name="action" value="save_settings">
  <div class="grid g2">
    <div class="card"><h3>Game Rules &amp; Limits</h3>
      <label class="row" style="color:var(--ink)"><input type="checkbox" name="game_enabled" <?= $SET['game_enabled'] ? 'checked' : '' ?>> Game Active</label>
      <label>Maintenance Message</label><textarea name="maintenance_msg"><?= e($SET['maintenance_msg']) ?></textarea>
      <div class="split"><div><label>Entry Fee (ETB)</label><input type="number" step="0.01" name="entry_fee" value="<?= e($SET['entry_fee']) ?>"></div><div><label>Commission (%)</label><input type="number" step="0.1" name="commission_pct" value="<?= e($SET['commission_pct']) ?>"></div></div>
      <div class="split"><div><label>Min Deposit</label><input type="number" step="0.01" name="min_deposit" value="<?= e($SET['min_deposit']) ?>"></div><div><label>Min Withdrawal</label><input type="number" step="0.01" name="min_withdraw" value="<?= e($SET['min_withdraw']) ?>"></div></div>
    </div>
    <div class="card"><h3>Payment Details</h3>
      <label>Telebirr Name</label><input name="telebirr_name" value="<?= e($SET['telebirr_name']) ?>">
      <label>Telebirr Number</label><input name="telebirr_number" value="<?= e($SET['telebirr_number']) ?>">
      <label>CBE Account</label><input name="cbe_account" value="<?= e($SET['cbe_account']) ?>">
      <label class="row" style="color:var(--ink);margin-top:14px"><input type="checkbox" name="notify_users" <?= $SET['notify_users'] ? 'checked' : '' ?>> Send Telegram Notifications for Payouts</label>
    </div>
  </div><button type="submit">Save Changes</button></form>

<?php /* ═════════ LOGS ═════════ */ elseif ($tab === 'logs'):
    $v = $_GET['v'] ?? 'audit';
    $rows = array_reverse(fbGet($v === 'ledger' ? 'wallet_ledger' : 'admin_logs', ['orderBy' => '"$key"', 'limitToLast' => 300]) ?: [], true);
?>
  <div class="card"><div class="tabs"><a class="<?= $v === 'audit' ? 'on' : '' ?>" href="admin.php?tab=logs&v=audit">Admin Actions</a><a class="<?= $v === 'ledger' ? 'on' : '' ?>" href="admin.php?tab=logs&v=ledger">Wallet Ledger</a></div>
    <div class="tbl"><table id="lg">
    <?php if ($v === 'ledger'): ?><tr><th>When</th><th>Player</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Note</th></tr>
      <?php foreach ($rows as $t): ?><tr><td><?= fdate((int)($t['at'] ?? 0)) ?></td><td><?= e($t['telegram_id'] ?? '') ?></td><td><?= e($t['type'] ?? '') ?></td><td class="num"><?= money($t['amount'] ?? 0) ?></td><td class="num"><?= money($t['balance_after'] ?? 0) ?></td><td><?= e($t['note'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php else: ?><tr><th>When</th><th>Admin</th><th>Action</th><th>Detail</th></tr>
      <?php foreach ($rows as $l): ?><tr><td><?= fdate((int)($l['at'] ?? 0)) ?></td><td><b><?= e($l['by'] ?? '') ?></b></td><td><code><?= e($l['action'] ?? '') ?></code></td><td><?= e($l['detail'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php endif; ?></table></div></div>

<?php /* ═════════ ADMINS ═════════ */ elseif ($tab === 'admins'):
    $admins = array_filter(fbGet('admins') ?: [], 'is_array');
?>
  <div class="card" style="max-width:700px"><h3>Create Admin Account</h3>
    <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="add_admin">
      <label>Username</label><input name="username" required>
      <label>Password</label><input type="password" name="password" required minlength="8">
      <label>Role</label><select name="role"><option value="manager">Manager</option><option value="finance">Finance</option><option value="support">Support</option></select>
      <button type="submit" style="margin-top:16px">Create Admin</button></form></div>
<?php elseif ($tab === 'account'): ?>
  <div class="card" style="max-width:500px"><h3>Change Password</h3>
    <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="change_password">
      <label>New Password</label><input type="password" name="new_password" required minlength="8">
      <button type="submit" style="margin-top:16px">Update Password</button></form></div>
<?php endif; ?>
</div>

<script>
(function(){
  document.getElementById('burger').onclick = function(){ document.body.classList.toggle('nav-open'); };
  document.getElementById('theme').onclick = function(){ var d = document.documentElement, n = d.dataset.theme === 'dark' ? 'light' : 'dark'; d.dataset.theme = n; try{localStorage.setItem('lb-theme', n)}catch(e){} };
  var t = document.getElementById('toast'); if (t) setTimeout(function(){ t.style.display = 'none'; }, 5500);
  document.addEventListener('submit', function(e){ var m = e.target.getAttribute('data-confirm'); if (m && !confirm(m)) e.preventDefault(); });
  document.addEventListener('click', function(e){
    var b = e.target.closest('.js-reject'); if (!b) return;
    var r = prompt('Reason for rejection:', ''); if (r === null) return;
    var f = b.form; f.elements.reason.value = r; f.submit();
  });
})();
</script>
<?php endif; ?>
</body>
</html>
