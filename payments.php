<?php
/**
 * payments.php - shared payment logic for LALA BINGO (used by bot.php).
 *
 *  - ingestTransactions(): reads the `transactions` node, extracts amount / sender name /
 *    transaction number / date and saves each payment ONCE into `deposits/<transaction number>`.
 *  - verifyDeposit(): a player pastes an SMS or a transaction id; we look the transaction up in
 *    the database and, if it is there and unused, credit the player with the AMOUNT FROM THE DATABASE
 *    (never the amount typed by the player).
 *
 * Needs BASE_FIREBASE (and optionally FIREBASE_AUTH / BOT_TOKEN) - bot.php defines them first.
 */
if (!defined('BASE_FIREBASE')) define('BASE_FIREBASE', rtrim(getenv('BASE_FIREBASE') ?: 'https://lalabingobot-default-rtdb.firebaseio.com', '/') . '/');
if (!defined('FIREBASE_AUTH')) define('FIREBASE_AUTH', getenv('FIREBASE_AUTH') ?: '');

/* ───────────── Firebase REST ───────────── */
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

/** Concurrency-safe balance change (ETag compare-and-set): the game server writes the same balance. */
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

/* ───────────── Small helpers ───────────── */
function money($n): string { return number_format((float)$n, 2); }
function ts($r): int {
    foreach (['processed_at', 'approved_at', 'created_at', 'timestamp', 'time', 'date'] as $f) {
        if (isset($r[$f]) && is_numeric($r[$f])) { $v = (float)$r[$f]; return (int)($v > 1e12 ? $v / 1000 : $v); }
    }
    return 0;
}
function uidOf($key, $u): string { return preg_replace('/\D/', '', (string)($u['telegram_id'] ?? $key)); }
function auditLog(string $action, string $detail = ''): void {
    fb('POST', 'admin_logs', ['by' => 'bot', 'role' => 'system', 'action' => $action, 'detail' => $detail, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'at' => time()]);
}
function ledgerEntry(string $uid, string $type, float $amount, float $after, string $note = ''): void {
    fb('POST', 'wallet_ledger', ['telegram_id' => $uid, 'type' => $type, 'amount' => $amount, 'balance_after' => $after, 'note' => $note, 'by' => 'bot', 'at' => time()]);
}
function tgNotify(string $chat, string $text): void {
    if (!defined('BOT_TOKEN') || BOT_TOKEN === '' || $chat === '') return;
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch); curl_close($ch);
}

/* ───────────── Extract amount / sender / transaction number / date from SMS text ───────────── */
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
    if (preg_match('/\bfrom\b(.{0,70})/i', $t, $m2) && preg_match('/(?:\+?251|\b0)(9\d{8})\b/', $m2[1], $m3)) $out['phone'] = $m3[1]; // only an unmasked sender number
    $tm = '(?:[ T,]+(?:at\s*)?(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?)?';
    $y = $mo = $d = 0; $hh = $mi = $ss = 0; $ap = '';
    if (preg_match('/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})' . $tm . '/i', $t, $m)) { [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]]; }
    elseif (preg_match('/(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})' . $tm . '/i', $t, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($mo > 12 && $d <= 12) [$d, $mo] = [$mo, $d];
    }
    if ($y) {
        $hh = (int)($m[4] ?? 0); $mi = (int)($m[5] ?? 0); $ss = (int)($m[6] ?? 0); $ap = strtoupper($m[7] ?? '');
        if ($ap === 'PM' && $hh < 12) $hh += 12;
        if ($ap === 'AM' && $hh === 12) $hh = 0;
        if (checkdate($mo, $d, $y)) $out['ts'] = (int)mktime($hh, $mi, $ss, $mo, $d, $y);
    }
    return $out;
}
/** A row of the `transactions` node can be raw SMS text or a record with text/amount/transaction fields. */
function txnFromRecord($rec): array {
    $text = '';
    if (is_string($rec)) $text = $rec;
    elseif (is_array($rec)) {
        foreach (['text', 'message', 'sms', 'body', 'raw', 'raw_sms', 'content', 'msg'] as $f) if (!empty($rec[$f]) && is_string($rec[$f])) { $text = $rec[$f]; break; }
        if ($text === '') $text = implode(' ', array_filter($rec, 'is_string'));
    }
    $p = parseTxn($text);
    if (is_array($rec)) {
        if (isset($rec['amount']) && is_numeric($rec['amount']) && (float)$rec['amount'] > 0) $p['amount'] = (float)$rec['amount'];
        foreach (['tx_id', 'transaction_id', 'transaction_number', 'txn_id', 'ref', 'reference', 'id'] as $f)
            if (!empty($rec[$f]) && is_scalar($rec[$f]) && normTxId((string)$rec[$f]) !== '') { $p['tx_id'] = normTxId((string)$rec[$f]); break; }
        foreach (['sender_name', 'sender', 'name', 'from'] as $f) if (!empty($rec[$f]) && is_string($rec[$f])) { $p['name'] = mb_substr($rec[$f], 0, 60); break; }
        if (!$p['ts']) $p['ts'] = ts($rec);
    }
    $p['raw'] = mb_substr($text, 0, 500);
    return $p;
}

/* ───────────── Save to deposits ONCE (the transaction number is the key) ───────────── */
function saveDeposit(array $p, string $source, string $by): string {
    if (($p['amount'] ?? 0) <= 0 || ($p['tx_id'] ?? '') === '') return 'invalid';
    $rec = ['amount' => round((float)$p['amount'], 2), 'sender_name' => mb_substr((string)($p['name'] ?? ''), 0, 60), 'tx_id' => $p['tx_id'], 'status' => 'pending',
        'created_at' => ($p['ts'] ?? 0) ?: time(), 'imported_at' => time(), 'source' => $source, 'imported_by' => $by, 'raw' => mb_substr((string)($p['raw'] ?? ''), 0, 500)];
    if (!empty($p['phone'])) $rec['sender_phone'] = $p['phone'];
    if (!empty($p['uid'])) { $rec['telegram_id'] = $p['uid']; $rec['claimed_by'] = $p['uid_name'] ?? $p['uid']; }
    $h = []; $c = 0;
    fb('PUT', 'deposits/' . k($p['tx_id']), $rec, [], ['if-match: null_etag'], $h, $c); // 412 = this transaction number already exists
    return $c === 200 ? 'saved' : ($c === 412 ? 'duplicate' : 'error');
}
/** Players indexed by the last 9 digits of their phone; a number shared by two players maps to false. */
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
/** Credit a deposit that already belongs to a player (phone matched). Runs once per deposit. */
function creditDeposit(string $id, string $by = 'auto'): string {
    $d = fbGet('deposits/' . k($id)); if (!is_array($d)) return 'missing';
    $tid = preg_replace('/\D/', '', (string)($d['telegram_id'] ?? '')); $amt = (float)($d['amount'] ?? 0);
    if ($tid === '' || $amt <= 0) return 'skip';
    if (!is_array(fbGet('users/' . k($tid)))) return 'nouser';
    $prev = casStatus('deposits/' . k($id), 'processed', ['processed', 'rejected']);
    if ($prev === false) return 'skip';
    if (!adjustBalance($tid, $amt, $after)) { fbPut('deposits/' . k($id) . '/status', $prev); return 'error'; }
    fbPatch('deposits/' . k($id), ['processed_by' => $by, 'processed_at' => time()]);
    ledgerEntry($tid, 'deposit', $amt, $after, "Deposit $id"); syncUserOne($tid);
    tgNotify($tid, "✅ ክፍያዎ ተቀብለናል: <b>" . money($amt) . " ETB</b>\n💰 ቀሪ ሂሳብ: <b>" . money($after) . " ETB</b>");
    auditLog('deposit.auto', "$id · " . money($amt) . " ETB → player $tid");
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
        if (is_array($rec) && isset($rec['balance_after'])) continue; // wallet history, not a payment
        $p = txnFromRecord($rec);
        if ($p['amount'] <= 0 || $p['tx_id'] === '') { $r['invalid']++; continue; }
        if (isset($existing[$p['tx_id']])) { $r['duplicate']++; continue; }
        if ($p['phone'] !== '') { // sender's full phone matches exactly one player -> link automatically
            $idx = $idx ?? phoneIndex();
            if (!empty($idx[$p['phone']])) { $p['uid'] = $idx[$p['phone']][0]; $p['uid_name'] = $idx[$p['phone']][1]; }
        }
        $res = saveDeposit($p, $source, $by); $r[$res]++;
        if ($res === 'saved' || $res === 'duplicate') $existing[$p['tx_id']] = true;
        if ($res === 'saved' && !empty($p['uid']) && creditDeposit($p['tx_id'], 'auto') === 'credited') $r['credited']++;
    }
    return $r;
}
/** Read the latest incoming payments, save the new ones to `deposits`, credit the ones that already belong to a player. */
function ingestTransactions(): array {
    $raw = fbGet('transactions', ['orderBy' => '"$key"', 'limitToLast' => 300]) ?: [];
    $deposits = fbGet('deposits') ?: [];
    $r = importFrom($raw, 'sms-auto', 'auto', is_array($deposits) ? $deposits : []);
    $r['credited'] += autoCreditDeposits(array_filter(fbGet('deposits') ?: [], 'is_array'));
    return $r;
}
/** Run ingestTransactions() at most once every $seconds (called on every bot update, so new payments are picked up fast). */
function maybeIngest(int $seconds = 20): void {
    $last = fbGet('meta/last_ingest');
    if (is_numeric($last) && time() - (int)$last < $seconds) return;
    fbPut('meta/last_ingest', time());
    ingestTransactions();
}

/* ───────────── Player pastes an SMS or a transaction id ───────────── */
/** Best guess of the transaction id inside whatever the player sent. */
function extractTxId(string $text): string {
    $p = parseTxn($text);
    if ($p['tx_id'] !== '') return $p['tx_id'];
    $t = trim($text);
    if (preg_match('/^[A-Za-z0-9]{6,30}$/', $t)) return normTxId($t);          // just the id
    if (preg_match('/\b(?=[A-Z0-9]*\d)(?=[A-Z0-9]*[A-Z])[A-Z0-9]{10}\b/', strtoupper($t), $m)) return $m[0]; // a 10-character code inside the text
    return '';
}
/**
 * Look the transaction up in the database. If it is there and unused, credit the player with the amount
 * stored in the database. Returns ['status' => credited|notfound|used|yours|other|invalid|error, ...]
 */
function verifyDeposit(string $txId, string $uid, array $user): array {
    $txId = normTxId($txId);
    if ($txId === '') return ['status' => 'invalid'];

    $d = fbGet('deposits/' . k($txId));
    if (!is_array($d)) {            // not extracted yet: pull in the newest incoming payments, then look again
        ingestTransactions();
        $d = fbGet('deposits/' . k($txId));
    }
    if (!is_array($d)) return ['status' => 'notfound', 'tx' => $txId];

    $st = $d['status'] ?? 'pending';
    $owner = preg_replace('/\D/', '', (string)($d['telegram_id'] ?? ''));
    $amt = round((float)($d['amount'] ?? 0), 2);

    if ($st === 'processed') return ['status' => $owner === $uid ? 'yours' : 'used', 'tx' => $txId, 'amount' => $amt];
    if ($st === 'rejected') return ['status' => 'used', 'tx' => $txId];
    if ($owner !== '' && $owner !== $uid) return ['status' => 'other', 'tx' => $txId];

    // If the payment shows the sender's full phone number it must be this player's phone.
    $sp = substr(preg_replace('/\D/', '', (string)($d['sender_phone'] ?? '')), -9);
    $up = substr(preg_replace('/\D/', '', (string)($user['phone'] ?? '')), -9);
    if ($sp !== '' && $up !== '' && $sp !== $up) return ['status' => 'other', 'tx' => $txId];
    if ($amt <= 0) return ['status' => 'invalid', 'tx' => $txId];

    $prev = casStatus('deposits/' . k($txId), 'processed', ['processed', 'rejected']); // only one claim can win
    if ($prev === false) return ['status' => 'used', 'tx' => $txId];
    if (!adjustBalance($uid, $amt, $after)) { fbPut('deposits/' . k($txId) . '/status', $prev); return ['status' => 'error', 'tx' => $txId]; }

    fbPatch('deposits/' . k($txId), ['telegram_id' => $uid, 'claimed_by' => ($user['username'] ?? '') ?: ($user['first_name'] ?? $uid), 'processed_by' => 'bot', 'processed_at' => time()]);
    ledgerEntry($uid, 'deposit', $amt, $after, "Deposit $txId (verified by player)");
    syncUserOne($uid);
    auditLog('deposit.verified', "$txId · " . money($amt) . " ETB → player $uid");
    return ['status' => 'credited', 'tx' => $txId, 'amount' => $amt, 'balance' => $after];
}
