<?php
/**
 * LALA BINGO — Admin Console (roles & privileges)
 * Requires PHP 7.4+ with cURL. Single file, Firebase Realtime Database (REST) + Telegram Bot API.
 */
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
date_default_timezone_set('Africa/Addis_Ababa');

define('BOT_TOKEN', getenv('8605292135:AAEghPf8D6fmTNHIJsRFktyIWd52B0ekSPE') ?: '');
define('ADMIN_BOT_TOKEN', getenv('8764719227:AAFgOaMBxMPz8dFyTIfFolSvD3Ryca-abm0') ?: getenv('BOT_TOKEN') ?: '');
// Example default with 3 admin chat IDs separated by commas (change these to your actual IDs)
define('ADMIN_CHAT_ID', getenv('ADMIN_CHAT_ID') ?: '1858412022,1791621405,555444333');
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
        'deposits.view' => 'View deposits', 'deposits.process' => 'Link deposits to players',
        'transactions.view' => 'View incoming payments', 'transactions.import' => 'Extract & save payments',
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

/** Concurrency-safe balance change */
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
function tg(string $token, string $method, array $params): array {
    if ($token === '') return ['ok' => false, 'description' => 'Token not set'];
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true) ?: ['ok' => false];
}
function sendUserMsg(string $chat, string $text, array $kb = []): bool {
    if (BOT_TOKEN === '' || $chat === '') return false;
    $p = ['chat_id' => $chat, 'parse_mode' => 'HTML'];
    if ($kb) $p['reply_markup'] = json_encode($kb);
    $p['text'] = $text;
    $r = tg(BOT_TOKEN, 'sendMessage', $p);
    return !empty($r['ok']);
}
function getAdminChatIds(): array {
    $raw = ADMIN_CHAT_ID;
    if ($raw === '') return [];
    $ids = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    return array_unique(array_map('trim', $ids));
}
function notifyAdminBot(string $text): void {
    if (ADMIN_BOT_TOKEN === '') return;
    $chatIds = getAdminChatIds();
    if (empty($chatIds)) return;

    foreach ($chatIds as $chatId) {
        tg(ADMIN_BOT_TOKEN, 'sendMessage', [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML',
            'text' => $text
        ]);
    }
}
function settingsDefaults(): array {
    return ['game_enabled' => true, 'maintenance_msg' => 'The game is paused for maintenance. Please check back soon.', 'entry_fee' => 10, 'commission_pct' => 10,
        'min_deposit' => 10, 'min_withdraw' => 50, 'max_withdraw' => 5000, 'welcome_bonus' => 0, 'telebirr_name' => 'YISAK', 'telebirr_number' => '0979652325', 'cbe_account' => '', 'notify_users' => true];
}

/* ───────────── SMS & Transactions ───────────── */
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
              '/[?&]id=([A-Za-z0-9]{6,30})/i', '/\b(FT\d{5,}[A-Z0-9]*)\b/'] as $re) {
        if (preg_match($re, $t, $m) && ($id = normTxId($m[1])) !== '') { $out['tx_id'] = $id; break; }
    }
    if (preg_match('/\bfrom\s+(.+?)\s*(?:\(|\[|,|\bon\b|\bat\b|\bwith\b|\bto\b|\.\s|$)/i', $t, $m)) {
        $out['name'] = mb_substr(trim(preg_replace('/[\d\*]{6,}/', '', $m[1]), " \t-."), 0, 60);
    }
    return $out;
}
function txnFromRecord($rec): array {
    $text = is_string($rec) ? $rec : (is_array($rec) ? ($rec['text'] ?? implode(' ', array_filter($rec, 'is_string'))) : '');
    $p = parseTxn($text);
    if (is_array($rec)) {
        if (isset($rec['amount']) && is_numeric($rec['amount'])) $p['amount'] = (float)$rec['amount'];
        if (!empty($rec['tx_id'])) $p['tx_id'] = normTxId((string)$rec['tx_id']);
        if (!$p['ts']) $p['ts'] = ts($rec);
    }
    $p['raw'] = mb_substr($text, 0, 500);
    return $p;
}
function saveDeposit(array $p, string $source, string $by): string {
    if (($p['amount'] ?? 0) <= 0 || ($p['tx_id'] ?? '') === '') return 'invalid';
    $rec = ['amount' => round((float)$p['amount'], 2), 'sender_name' => mb_substr((string)($p['name'] ?? ''), 0, 60), 'tx_id' => $p['tx_id'], 'status' => 'pending',
        'created_at' => ($p['ts'] ?? 0) ?: time(), 'imported_at' => time(), 'source' => $source, 'imported_by' => $by];
    $h = []; $c = 0;
    fb('PUT', 'deposits/' . k($p['tx_id']), $rec, [], ['if-match: null_etag'], $h, $c);
    return $c === 200 ? 'saved' : ($c === 412 ? 'duplicate' : 'error');
}
function creditDeposit(string $id, string $by = 'auto'): string {
    $d = fbGet('deposits/' . k($id)); if (!is_array($d)) return 'missing';
    $tid = preg_replace('/\D/', '', (string)($d['telegram_id'] ?? '')); $amt = (float)($d['amount'] ?? 0);
    if ($tid === '' || $amt <= 0) return 'skip';
    $prev = casStatus('deposits/' . k($id), 'processed', ['processed', 'rejected']);
    if ($prev === false) return 'skip';
    if (!adjustBalance($tid, $amt, $after)) { fbPut('deposits/' . k($id) . '/status', $prev); return 'error'; }
    fbPatch('deposits/' . k($id), ['processed_by' => $by, 'processed_at' => time()]);
    ledger($tid, 'deposit', $amt, $after, "Deposit $id", $by); syncUserOne($tid);
    sendUserMsg($tid, '✅ Your deposit of <b>' . money($amt) . ' ETB</b> was received. Balance: <b>' . money($after) . ' ETB</b>');
    audit('deposit.auto', "$id · " . money($amt) . " ETB → player $tid");
    return 'credited';
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
function ledger(string $uid, string $type, float $amount, float $after, string $note = '', ?string $by = null): void {
    global $ME;
    fb('POST', 'wallet_ledger', ['telegram_id' => $uid, 'type' => $type, 'amount' => $amount, 'balance_after' => $after, 'note' => $note, 'by' => $by ?? ($ME['username'] ?? ''), 'at' => time()]);
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
    $u = trim((string)($_POST['username'] ?? '')); $p = (string)($_POST['password'] ?? ''); $ok = null;
    if (hash_equals(ROOT_USER, $u) && hash_equals(ROOT_PASS, $p)) $ok = 'root';
    else {
        foreach (fbGet('admins') ?: [] as $id => $a) {
            if (is_array($a) && ($a['username'] ?? '') === $u && ($a['active'] ?? true) && isset($a['pass_hash']) && password_verify($p, $a['pass_hash'])) { $ok = $id; break; }
        }
    }
    if ($ok) {
        session_regenerate_id(true); $_SESSION['aid'] = $ok;
        $ME = ['username' => $u, 'role' => $ok === 'root' ? 'superadmin' : 'x']; audit('login'); go();
    }
    flash('Wrong username or password.', 'bad'); back();
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

/* ───────────── Actions ───────────── */
if ($ME && $POST) {
    $act = $_POST['action'] ?? '';
    switch ($act) {
    case 'adjust_balance':
        need('users.balance');
        $uid = preg_replace('/\D/', '', (string)($_POST['uid'] ?? '')); $mode = $_POST['mode'] ?? 'add';
        $amt = round((float)($_POST['amount'] ?? 0), 2); $why = trim((string)($_POST['reason'] ?? ''));
        $u = $uid !== '' ? fbGet('users/' . k($uid)) : null;
        if (!is_array($u)) { flash('Player not found.', 'bad'); back(); }
        $cur = (float)($u['balance'] ?? 0);
        $delta = $mode === 'sub' ? -$amt : ($mode === 'set' ? $amt - $cur : $amt);
        if (!adjustBalance($uid, $delta, $after)) { flash('Could not update balance.', 'bad'); back(); }
        ledger($uid, $mode === 'bonus' ? 'bonus' : 'adjust', $delta, $after, $why);
        syncUserOne($uid);
        if ($mode === 'bonus') sendUserMsg($uid, '🎁 You received a bonus of <b>' . money($amt) . ' ETB</b>' . ($why ? "\n" . e($why) : ''));
        flash('Balance updated: ' . money($after) . ' ETB.'); back();

    case 'process_withdrawal':
        need('withdrawals.process');
        $id = (string)($_POST['wdr_id'] ?? ''); $to = ($_POST['status'] ?? '') === 'rejected' ? 'rejected' : 'approved'; $why = trim((string)($_POST['reason'] ?? ''));
        $w = fbGet('withdrawals/' . k($id)); if (!is_array($w)) { flash('Withdrawal not found.', 'bad'); back(); }
        $amt = (float)($w['amount'] ?? 0);
        $prev = casStatus('withdrawals/' . k($id), $to, ['approved', 'rejected']);
        if ($prev === false) { flash('Already handled.', 'warn'); back(); }
        fbPatch('withdrawals/' . k($id), ['processed_by' => $ME['username'], 'processed_at' => time(), 'reason' => $why]);
        $tid = preg_replace('/\D/', '', (string)($w['telegram_id'] ?? ''));
        
        if ($to === 'rejected' && $tid !== '') {
            if (!adjustBalance($tid, $amt, $after)) { fbPut('withdrawals/' . k($id) . '/status', $prev); flash('Could not refund player.', 'bad'); back(); }
            ledger($tid, 'refund', $amt, $after, "Withdrawal $id rejected"); syncUserOne($tid);
            sendUserMsg($tid, '❌ <b>Withdrawal Rejected & Refunded</b>\nYour withdrawal request of <b>' . money($amt) . ' ETB</b> was rejected and refunded.' . ($why ? "\nReason: " . e($why) : ''));
        } elseif ($to === 'approved' && $tid !== '') {
            sendUserMsg($tid, '🎉 <b>Withdrawal Approved!</b>\nYour payout request of <b>' . money($amt) . ' ETB</b> has been successfully processed and sent to your account.');
        }
        flash($to === 'approved' ? 'Withdrawal approved & player notified.' : 'Withdrawal rejected and refunded.'); back();

    case 'link_deposit':
        need('deposits.process'); $id = (string)($_POST['tx_id'] ?? ''); $tid = preg_replace('/\D/', '', (string)($_POST['tid'] ?? ''));
        $d = fbGet('deposits/' . k($id)); $u = $tid !== '' ? fbGet('users/' . k($tid)) : null;
        if (!is_array($d) || !is_array($u)) { flash('Deposit or player not found.', 'bad'); back(); }
        fbPatch('deposits/' . k($id), ['telegram_id' => $tid, 'claimed_by' => $u['username'] ?? ($u['first_name'] ?? $tid)]);
        creditDeposit($id, 'auto'); flash('Linked and credited successfully!'); back();

    case 'save_settings':
        need('settings.manage');
        $new = ['game_enabled' => isset($_POST['game_enabled']), 'notify_users' => isset($_POST['notify_users']), 'maintenance_msg' => trim((string)$_POST['maintenance_msg']),
            'telebirr_name' => trim((string)$_POST['telebirr_name']), 'telebirr_number' => trim((string)$_POST['telebirr_number']), 'cbe_account' => trim((string)$_POST['cbe_account'])];
        foreach (['entry_fee', 'commission_pct', 'min_deposit', 'min_withdraw', 'max_withdraw', 'welcome_bonus'] as $f) $new[$f] = max(0, (float)($_POST[$f] ?? 0));
        fbPatch('settings', $new); flash('Settings saved.'); back();
    }
}

/* ───────────── Navigation & Fetching ───────────── */
$nav = [
    'dashboard'    => ['📊', 'Dashboard', 'ዳሽቦርድ', null],
    'users'        => ['👥', 'Players', 'ተጫዋቾች', 'users.view'],
    'deposits'     => ['📥', 'Deposits', 'ብር ማስገቢያ', 'deposits.view'],
    'withdrawals'  => ['📤', 'Withdrawals', 'ብር ማውጫ', 'withdrawals.view'],
    'transactions' => ['💳', 'Incoming payments', 'የገቢ ክፍያዎች', 'transactions.view'],
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
    uasort($deposits, function ($a, $b) { return ts($b) <=> ts($a); });
    uasort($withdrawals, function ($a, $b) { return ts($b) <=> ts($a); });
}
$perPage = 25;
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
:root{--bg:#f8fafc;--surface:#ffffff;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--side:#0f172a;--side-ink:#94a3b8;--accent:#f59e0b;--accent-ink:#ffffff;--accent-soft:#fef3c7;--ok:#10b981;--warn:#f59e0b;--bad:#ef4444;--r:16px}
[data-theme=dark]{--bg:#090d16;--surface:#111c2e;--ink:#f1f5f9;--muted:#94a3b8;--line:#1e293b;--side:#070b14;--side-ink:#94a3b8;--accent-soft:#291e08}
*{box-sizing:border-box;margin:0;padding:0}
body{font:14.5px/1.5 'Plus Jakarta Sans','Noto Sans Ethiopic',sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
a{color:inherit}
.sidebar{width:260px;background:var(--side);color:var(--side-ink);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;flex-shrink:0}
.brand{display:flex;align-items:center;gap:12px;padding:24px 20px;color:#fff;font-weight:800;font-size:18px}
.ball{width:38px;height:38px;border-radius:50%;background:radial-gradient(circle at 30% 30%,#fff 0 15%,transparent 16%),var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:15px}
.brand small{display:block;font-weight:500;font-size:11px;color:var(--side-ink);opacity:0.8}
.menu{list-style:none;padding:8px 14px;flex:1;overflow-y:auto}
.menu a{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:12px;text-decoration:none;color:var(--side-ink);font-weight:600;margin-bottom:4px}
.menu a:hover{background:rgba(255,255,255,.06);color:#fff}
.menu a.on{background:var(--accent);color:var(--accent-ink)}
.badge-count{margin-left:auto;background:var(--bad);color:#fff;font-size:11px;font-weight:800;padding:2px 7px;border-radius:99px}
.me{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12.5px}
.me b{color:#fff;display:block}
.main{flex:1;min-width:0;padding:0 32px 48px}
.top{display:flex;align-items:center;gap:14px;padding:22px 0;position:sticky;top:0;background:var(--bg);z-index:20;border-bottom:1px solid var(--line);margin-bottom:24px}
.top h1{font-size:20px;font-weight:800;flex:1;display:flex;align-items:center;gap:10px}
.top h1 small{font-weight:500;font-size:13px;color:var(--muted)}
.iconbtn{background:var(--surface);border:1px solid var(--line);color:var(--ink);border-radius:12px;padding:9px 14px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.burger{display:none}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:24px;margin-bottom:20px}
.card h3{font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:10px;justify-content:space-between}
.grid{display:grid;gap:18px;margin-bottom:22px}
.g5{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}.g2{grid-template-columns:repeat(auto-fit,minmax(340px,1fr))}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:20px;display:flex;gap:14px;align-items:center;text-decoration:none}
.stat .ball{width:46px;height:46px;font-size:18px;flex-shrink:0}
.stat.alert{border-color:var(--bad);background:rgba(239,68,68,0.03)}
.stat p{font-size:12px;color:var(--muted);font-weight:600}.stat strong{font-size:20px;font-weight:800;display:block;margin-top:2px}
.tbl{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;color:var(--muted);font-weight:600;font-size:12px;padding:12px 14px;border-bottom:2px solid var(--line);white-space:nowrap}
td{padding:14px;border-bottom:1px solid var(--line);vertical-align:middle}
.num{font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}
.chip{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700;background:var(--line);color:var(--muted)}
.chip.ok{background:rgba(16,185,129,0.12);color:var(--ok)}.chip.warn{background:rgba(245,158,11,0.12);color:var(--warn)}.chip.bad{background:rgba(239,68,68,0.12);color:var(--bad)}
label{display:block;font-size:12.5px;font-weight:700;color:var(--muted);margin:14px 0 6px}
input,select,textarea{width:100%;padding:11px 14px;background:var(--bg);border:1px solid var(--line);border-radius:12px;color:var(--ink);font:inherit}
button,.btn{background:var(--accent);color:var(--accent-ink);border:0;border-radius:12px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer;margin-top:14px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px}
button.sm{padding:6px 12px;font-size:12px;margin:0;border-radius:8px}
button.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}
button.ok{background:var(--ok);color:#fff}button.bad{background:var(--bad);color:#fff}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.toolbar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}.tabs a{padding:7px 16px;border-radius:99px;border:1px solid var(--line);text-decoration:none;font-size:13px;font-weight:600;color:var(--muted)}.tabs a.on{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.toast{position:fixed;right:24px;top:24px;z-index:99;padding:14px 20px;border-radius:14px;background:var(--surface);color:var(--ink);font-weight:600;box-shadow:0 12px 32px rgba(0,0,0,0.12);border:1px solid var(--line);border-left:5px solid var(--ok)}
.toast.bad{border-left-color:var(--bad)}
.calendar-group{margin-bottom:20px}
.calendar-date-header{font-size:13px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px;padding-bottom:4px;border-bottom:1px dashed var(--line)}
.login{margin:auto;width:min(420px,92vw);background:var(--surface);border:1px solid var(--line);border-radius:24px;padding:38px}
@media(max-width:860px){.sidebar{position:fixed;left:-280px;z-index:50;transition:left .25s ease}.burger{display:inline-flex}.main{padding:0 16px 40px}}
</style>
</head>
<body>
<?php if ($flash): ?><div class="toast <?= e($flash[1]) ?>" id="toast"><?= e($flash[0]) ?></div><?php endif; ?>

<?php if (!$ME): ?>
<div class="login">
  <div class="ball">B</div>
  <h1 style="text-align:center">LALA BINGO Admin</h1>
  <p style="text-align:center;color:var(--muted);font-size:13px;margin-bottom:20px">Sign in to manage deposits &amp; payouts</p>
  <form method="POST">
    <label>Username</label><input name="username" required autocomplete="username">
    <label>Password</label><input type="password" name="password" required autocomplete="current-password">
    <button type="submit" name="login" value="1" style="width:100%;margin-top:20px">Sign In</button>
  </form>
</div>
<?php else: ?>
<aside class="sidebar">
  <div class="brand"><div class="ball">B</div><div>LALA BINGO<small>Admin Portal</small></div></div>
  <ul class="menu">
    <?php foreach ($nav as $key => $n): if ($n[3] && !can($n[3])) continue; 
        $badge = ($key === 'deposits' && $pendingDepositsCount > 0) ? "<span class=\"badge-count\">$pendingDepositsCount</span>" : (($key === 'withdrawals' && $pendingWithdrawalsCount > 0) ? "<span class=\"badge-count\">$pendingWithdrawalsCount</span>" : '');
    ?>
      <li><a href="admin.php?tab=<?= $key ?>" class="<?= $tabKey === $key ? 'on' : '' ?>"><span><?= $n[0] ?></span><span class="t"><?= e($n[1]) ?><small><?= e($n[2]) ?></small></span><?= $badge ?></a></li>
    <?php endforeach; ?>
  </ul>
  <div class="me"><b><?= e($ME['username']) ?></b><br><a href="admin.php?logout=<?= $_SESSION['csrf'] ?>">Sign out</a></div>
</aside>

<div class="main">
  <div class="top">
    <button class="iconbtn burger" type="button" id="burger">☰</button>
    <h1><?= e($nav[$tabKey][1]) ?> <small><?= e($nav[$tabKey][2]) ?></small></h1>
    <button class="iconbtn" type="button" id="theme">🌓</button>
  </div>

<?php /* ═════════ DASHBOARD ═════════ */ if ($tab === 'dashboard'):
    $totBal = 0; foreach ($users as $u) { $totBal += (float)($u['balance'] ?? 0); }
?>
  <div class="grid g5">
    <div class="stat"><div class="ball">👥</div><div><p>Players</p><strong><?= count($users) ?></strong></div></div>
    <div class="stat"><div class="ball">💰</div><div><p>Wallets Held</p><strong><?= money($totBal) ?> ETB</strong></div></div>
    <a class="stat <?= $pendingDepositsCount ? 'alert' : '' ?>" href="admin.php?tab=deposits&s=pending"><div class="ball">📥</div><div><p>Pending Deposits</p><strong><?= $pendingDepositsCount ?></strong></div></a>
    <a class="stat <?= $pendingWithdrawalsCount ? 'alert' : '' ?>" href="admin.php?tab=withdrawals&s=pending"><div class="ball">📤</div><div><p>Pending Payouts</p><strong><?= $pendingWithdrawalsCount ?></strong></div></a>
    <div class="stat"><div class="ball">🚀</div><div><p>Game Status</p><strong><?= $SET['game_enabled'] ? 'Active' : 'Paused' ?></strong></div></div>
  </div>

<?php /* ═════════ DEPOSITS (CALENDAR & RANGE FILTER) ═════════ */ elseif ($tab === 'deposits'):
    $s = $_GET['s'] ?? 'all';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $list = array_filter($deposits, function ($d) use ($s, $from, $to) {
        $st = $d['status'] ?? 'pending';
        if ($s !== 'all' && ($s === 'pending' ? in_array($st, ['processed', 'rejected'], true) : $st !== $s)) return false;
        
        $t = ts($d);
        if ($from && $t < strtotime($from)) return false;
        if ($to && $t > strtotime($to . ' 23:59:59')) return false;
        return true;
    });
    
    $calendarDeposits = [];
    foreach ($list as $id => $d) {
        $timestamp = ts($d);
        $dayKey = $timestamp ? date('Y-m-d', $timestamp) : 'Unscheduled';
        $calendarDeposits[$dayKey][$id] = $d;
    }
    krsort($calendarDeposits);
?>
  <div class="card">
    <div class="tabs"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'processed' => 'Approved', 'rejected' => 'Rejected'] as $v => $l): ?><a class="<?= $s === $v ? 'on' : '' ?>" href="admin.php?tab=deposits&s=<?= $v ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"><?= $l ?></a><?php endforeach; ?></div>
    
    <form class="toolbar" method="GET">
      <input type="hidden" name="tab" value="deposits"><input type="hidden" name="s" value="<?= e($s) ?>">
      <div><label>From Date</label><input type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To Date</label><input type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn" type="submit" style="margin-top:0">Filter Calendar</button>
      <a class="btn ghost" href="admin.php?tab=deposits" style="margin-top:0">Reset</a>
    </form>

    <?php if (!$calendarDeposits): ?><p class="muted">No deposits found for this date range.</p>
    <?php else: foreach ($calendarDeposits as $dateKey => $dayItems): ?>
        <div class="calendar-group">
            <div class="calendar-date-header">📅 <?= $dateKey === 'Unscheduled' ? $dateKey : date('l, F j, Y', strtotime($dateKey)) ?> (<?= count($dayItems) ?> deposits)</div>
            <div class="tbl"><table>
                <tr><th>Transaction ID</th><th>Player / Sender</th><th>Amount</th><th>Status</th><th>Action</th></tr>
                <?php foreach ($dayItems as $id => $d): $st = $d['status'] ?? 'pending'; ?>
                    <tr>
                        <td><code><?= e($id) ?></code><br><small><?= fdate(ts($d)) ?></small></td>
                        <td>
                            <?php if (!empty($d['telegram_id'])): ?><a href="admin.php?tab=user&id=<?= e(preg_replace('/\D/', '', (string)$d['telegram_id'])) ?>">@<?= e($d['claimed_by'] ?? $d['telegram_id']) ?></a><?php else: ?><small class="muted">Unlinked</small><?php endif; ?>
                            <?php if (empty($d['telegram_id']) && $st !== 'processed' && can('deposits.process')): ?>
                                <form method="POST" class="row" style="margin-top:6px"><?= csrf() ?><input type="hidden" name="action" value="link_deposit"><input type="hidden" name="tx_id" value="<?= e($id) ?>"><input name="tid" inputmode="numeric" placeholder="Telegram ID" required style="width:130px;padding:4px 6px"><button class="sm ghost">Link</button></form>
                            <?php endif; ?>
                        </td>
                        <td class="num" style="color:var(--ok)">+<?= money($d['amount'] ?? 0) ?> ETB</td>
                        <td><?= chip($st) ?></td>
                        <td><small class="muted"><?= $st === 'processed' ? 'Credited' : 'Pending' ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        </div>
    <?php endforeach; endif; ?>
  </div>

<?php /* ═════════ WITHDRAWALS (CALENDAR & RANGE FILTER) ═════════ */ elseif ($tab === 'withdrawals'):
    $s = $_GET['s'] ?? 'all';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $list = array_filter($withdrawals, function ($w) use ($s, $from, $to) {
        $st = $w['status'] ?? 'pending';
        if ($s !== 'all' && $st !== $s) return false;
        
        $t = ts($w);
        if ($from && $t < strtotime($from)) return false;
        if ($to && $t > strtotime($to . ' 23:59:59')) return false;
        return true;
    });

    $calendarWithdrawals = [];
    foreach ($list as $id => $w) {
        $timestamp = ts($w);
        $dayKey = $timestamp ? date('Y-m-d', $timestamp) : 'Unscheduled';
        $calendarWithdrawals[$dayKey][$id] = $w;
    }
    krsort($calendarWithdrawals);
?>
  <div class="card">
    <div class="tabs"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Sent', 'rejected' => 'Rejected'] as $v => $l): ?><a class="<?= $s === $v ? 'on' : '' ?>" href="admin.php?tab=withdrawals&s=<?= $v ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"><?= $l ?></a><?php endforeach; ?></div>
    
    <form class="toolbar" method="GET">
      <input type="hidden" name="tab" value="withdrawals"><input type="hidden" name="s" value="<?= e($s) ?>">
      <div><label>From Date</label><input type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To Date</label><input type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn" type="submit" style="margin-top:0">Filter Calendar</button>
      <a class="btn ghost" href="admin.php?tab=withdrawals" style="margin-top:0">Reset</a>
    </form>

    <?php if (!$calendarWithdrawals): ?><p class="muted">No withdrawal requests found for this date range.</p>
    <?php else: foreach ($calendarWithdrawals as $dateKey => $dayItems): ?>
        <div class="calendar-group">
            <div class="calendar-date-header">📅 <?= $dateKey === 'Unscheduled' ? $dateKey : date('l, F j, Y', strtotime($dateKey)) ?> (<?= count($dayItems) ?> requests)</div>
            <div class="tbl"><table>
                <tr><th>Request ID</th><th>Player Details</th><th>Amount &amp; Account</th><th>Status</th><th>Actions</th></tr>
                <?php foreach ($dayItems as $id => $w): $st = $w['status'] ?? 'pending'; ?>
                    <tr>
                        <td><code><?= e($id) ?></code><br><small><?= e($w['method'] ?? 'CBE') ?> · <?= fdate(ts($w)) ?></small></td>
                        <td><b><?= e($w['first_name'] ?? 'Player') ?></b><br><code><?= e($w['phone'] ?? '') ?></code></td>
                        <td class="num" style="color:var(--bad)">-<?= money($w['amount'] ?? 0) ?> ETB<br><small style="font-weight:400"><?= e($w['account_details'] ?? '') ?></small></td>
                        <td><?= chip($st) ?><?php if (!empty($w['reason'])): ?><br><small><?= e($w['reason']) ?></small><?php endif; ?></td>
                        <td>
                            <?php if (can('withdrawals.process') && $st === 'pending'): ?>
                                <div class="row">
                                    <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="process_withdrawal"><input type="hidden" name="wdr_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="approved"><button class="sm ok">Send &amp; Notify</button></form>
                                    <form method="POST"><?= csrf() ?><input type="hidden" name="action" value="process_withdrawal"><input type="hidden" name="wdr_id" value="<?= e($id) ?>"><input type="hidden" name="status" value="rejected"><input type="hidden" name="reason" value=""><button type="button" class="sm bad js-reject">Reject &amp; Refund</button></form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        </div>
    <?
