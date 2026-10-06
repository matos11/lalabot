<?php
// admin.php - LALA BINGO Multi-Tab Admin Panel with Sidebar
session_start();

// Configuration Constants
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8605292135:AAHDAoOxTRw-0xBLXJGY8rIaRtVBG3LnKxM');
define('GAME_URL', getenv('GAME_URL') ?: 'https://lalabingobot.vercel.app/');
define('BASE_FIREBASE', getenv('BASE_FIREBASE') ?: 'https://lalabingobot-default-rtdb.firebaseio.com/');

// Default Admin Credentials (Change as needed)
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');

$status_message = "";
$debug_errors = [];

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Check against hardcoded default admin or stored admins in Firebase
    $stored_admins = firebaseGet(BASE_FIREBASE . "admins.json") ?: [];
    $is_valid = ($username === ADMIN_USER && $password === ADMIN_PASS);

    if (!$is_valid && !empty($stored_admins)) {
        foreach ($stored_admins as $adm) {
            if (($adm['username'] ?? '') === $username && ($adm['password'] ?? '') === $password) {
                $is_valid = true;
                break;
            }
        }
    }

    if ($is_valid) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        header("Location: admin.php");
        exit;
    } else {
        $status_message = "❌ የተሳሳተ መግቢያ ስም ወይም የይለፍ ቃል!";
    }
}

$is_authenticated = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$active_tab = $_GET['tab'] ?? 'dashboard';

// Handle Actions
if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update User Balance
    if ($action === 'update_balance') {
        $target_id = $_POST['telegram_id'];
        $new_balance = floatval($_POST['new_balance']);
        $user_data = firebaseGet(BASE_FIREBASE . "users/{$target_id}.json");
        if ($user_data) {
            $user_data['balance'] = $new_balance;
            firebasePut(BASE_FIREBASE . "users/{$target_id}.json", $user_data);
            if (!empty($user_data['phone'])) {
                $clean_phone = preg_replace('/[.#$[\]\/]/', '_', (string)$user_data['phone']);
                firebasePut(BASE_FIREBASE . "userone/{$clean_phone}.json", $user_data);
            }
            $status_message = "✅ የተጫዋች (ID: {$target_id}) ቀሪ ሂሳብ ወደ ETB {$new_balance} ተስተካክሏል!";
        }
    }

    // 2. Delete User
    if ($action === 'delete_user') {
        $target_id = $_POST['telegram_id'];
        firebaseDelete(BASE_FIREBASE . "users/{$target_id}.json");
        $status_message = "🗑️ ተጫዋች (ID: {$target_id}) ከዳታቤዝ ተሰርዟል!";
    }

    // 3. Process Deposit (Approve / Reject)
    if ($action === 'process_deposit') {
        $tx_id = $_POST['tx_id'];
        $status = $_POST['status']; // processed or rejected
        $tx_data = firebaseGet(BASE_FIREBASE . "deposits/{$tx_id}.json");
        if ($tx_data) {
            $tx_data['status'] = $status;
            firebasePut(BASE_FIREBASE . "deposits/{$tx_id}.json", $tx_data);
            
            // If approved, add balance to user
            if ($status === 'processed') {
                $telegram_id = $tx_data['telegram_id'] ?? null;
                $amount = floatval($tx_data['amount'] ?? 0);
                if ($telegram_id) {
                    $user_data = firebaseGet(BASE_FIREBASE . "users/{$telegram_id}.json");
                    if ($user_data) {
                        $user_data['balance'] = floatval($user_data['balance'] ?? 0) + $amount;
                        firebasePut(BASE_FIREBASE . "users/{$telegram_id}.json", $user_data);
                    }
                }
            }
            $status_message = "✅ የገንዘብ ማስገቢያ (Tx: {$tx_id}) ሁኔታ ወደ {$status} ተቀይሯል!";
        }
    }

    // 4. Process Withdrawal
    if ($action === 'process_withdrawal') {
        $wdr_id = $_POST['wdr_id'];
        $status = $_POST['status']; // approved or rejected
        $wdr_data = firebaseGet(BASE_FIREBASE . "withdrawals/{$wdr_id}.json");
        if ($wdr_data) {
            $wdr_data['status'] = $status;
            firebasePut(BASE_FIREBASE . "withdrawals/{$wdr_id}.json", $wdr_data);
            
            // If rejected, refund balance to user
            if ($status === 'rejected') {
                $telegram_id = $wdr_data['telegram_id'] ?? null;
                $amount = floatval($wdr_data['amount'] ?? 0);
                if ($telegram_id) {
                    $user_data = firebaseGet(BASE_FIREBASE . "users/{$telegram_id}.json");
                    if ($user_data) {
                        $user_data['balance'] = floatval($user_data['balance'] ?? 0) + $amount;
                        firebasePut(BASE_FIREBASE . "users/{$telegram_id}.json", $user_data);
                    }
                }
            }
            $status_message = "✅ የገንዘብ ማውጫ (ID: {$wdr_id}) ሁኔታ ወደ {$status} ተቀይሯል!";
        }
    }

    // 5. Add New Admin
    if ($action === 'add_admin') {
        $new_user = trim($_POST['new_username']);
        $new_pass = trim($_POST['new_password']);
        if (!empty($new_user) && !empty($new_pass)) {
            $admin_id = uniqid("adm_");
            firebasePut(BASE_FIREBASE . "admins/{$admin_id}.json", [
                'username' => $new_user,
                'password' => $new_pass,
                'created_at' => time()
            ]);
            $status_message = "✅ አዲስ አስተዳዳሪ ({$new_user}) በተሳካ ሁኔታ ተፈጥሯል!";
        }
    }

    // 6. Broadcast Message
    if ($action === 'send_broadcast') {
        $text = trim($_POST['message'] ?? '');
        $btn_text = trim($_POST['btn_text'] ?? '🌴 Play now');
        $btn_url = trim($_POST['btn_url'] ?? GAME_URL);

        if (empty($text)) {
            $status_message = "❌ እባክዎ የመልእክት ጽሁፍ ያስገቡ!";
        } else {
            $all_users = firebaseGet(BASE_FIREBASE . "users.json");
            $success_count = 0; $fail_count = 0;

            if ($all_users && is_array($all_users)) {
                $keyboard = [
                    "inline_keyboard" => [
                        [["text" => $btn_text, "web_app" => ["url" => $btn_url]]]
                    ]
                ];

                $local_image_path = null;
                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['image_file']['tmp_name'];
                    if (getimagesize($file_tmp) !== false) {
                        $local_image_path = new CURLFile($file_tmp, mime_content_type($file_tmp), $_FILES['image_file']['name']);
                    }
                }

                foreach ($all_users as $key => $userData) {
                    $chat_id = $userData['telegram_id'] ?? (is_numeric($key) ? $key : null);
                    if (!$chat_id) { $fail_count++; continue; }

                    if ($local_image_path) {
                        $payload = [
                            "chat_id" => $chat_id, "photo" => $local_image_path,
                            "caption" => $text, "parse_mode" => "HTML",
                            "reply_markup" => json_encode($keyboard)
                        ];
                        $res = curlPostMultipart("https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto", $payload);
                    } else {
                        $payload = [
                            "chat_id" => $chat_id, "text" => $text,
                            "parse_mode" => "HTML", "reply_markup" => json_encode($keyboard)
                        ];
                        $res = curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage", $payload);
                    }

                    if ($res && isset($res['ok']) && $res['ok'] === true) {
                        $success_count++;
                    } else {
                        $fail_count++;
                    }
                    usleep(35000);
                }
                $status_message = "✅ ብሮድካስት ተጠናቋል! የተሳካ: <b>{$success_count}</b>, ያልተሳካ: <b>{$fail_count}</b>";
            }
        }
    }
}

// Fetch Data for Dashboard
$users = $is_authenticated ? (firebaseGet(BASE_FIREBASE . "users.json") ?: []) : [];
$deposits = $is_authenticated ? (firebaseGet(BASE_FIREBASE . "deposits.json") ?: []) : [];
$withdrawals = $is_authenticated ? (firebaseGet(BASE_FIREBASE . "withdrawals.json") ?: []) : [];
$admins = $is_authenticated ? (firebaseGet(BASE_FIREBASE . "admins.json") ?: []) : [];

$total_users = count($users);
$total_balance = 0;
foreach ($users as $u) { $total_balance += floatval($u['balance'] ?? 0); }

// Helper Functions
function firebaseGet($url) {  
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  
    $res = curl_exec($ch); curl_close($ch); return $res ? json_decode($res, true) : null;  
}
function firebasePut($url, $data) {  
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  
    curl_exec($ch); curl_close($ch);  
}
function firebaseDelete($url) {  
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE"); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  
    curl_exec($ch); curl_close($ch);  
}
function curlPostMultipart($url, $post) {
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $r = curl_exec($ch); curl_close($ch); return json_decode($r, true);
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LALA BINGO - Ultimate Admin Dashboard</title>
    <style>
        :root { --bg: #090d16; --sidebar-bg: #111827; --card-bg: #1f2937; --border: #374151; --text: #f3f4f6; --text-muted: #9ca3af; --primary: #3b82f6; --primary-hover: #2563eb; --danger: #ef4444; --success: #10b981; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: var(--bg); color: var(--text); display: flex; height: 100vh; overflow: hidden; }

        /* Login Layout */
        .login-wrapper { width: 100vw; height: 100vh; display: flex; justify-content: center; align-items: center; background: var(--bg); }
        .login-card { width: 100%; max-width: 400px; background: var(--sidebar-bg); border: 1px solid var(--border); padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); text-align: center; }
        .login-card h2 { color: var(--primary); margin-bottom: 20px; }

        /* Sidebar Layout */
        .sidebar { width: 260px; background: var(--sidebar-bg); border-right: 1px solid var(--border); display: flex; flex-direction: column; height: 100vh; }
        .sidebar-brand { padding: 25px 20px; font-size: 18px; font-weight: bold; color: var(--primary); border-bottom: 1px solid var(--border); text-align: center; }
        .sidebar-menu { list-style: none; padding: 20px 10px; flex-grow: 1; overflow-y: auto; }
        .sidebar-menu li { margin-bottom: 8px; }
        .sidebar-menu a { display: block; padding: 12px 15px; color: var(--text-muted); text-decoration: none; border-radius: 8px; font-weight: 500; transition: 0.2s; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background: var(--primary); color: white; }
        .sidebar-footer { padding: 15px 20px; border-top: 1px solid var(--border); }
        .logout-btn { display: block; text-align: center; background: var(--danger); color: white; padding: 10px; border-radius: 8px; text-decoration: none; font-weight: bold; }

        /* Main Content */
        .main-content { flex-grow: 1; height: 100vh; overflow-y: auto; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; background: var(--sidebar-bg); padding: 15px 25px; border-radius: 12px; border: 1px solid var(--border); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--border); padding: 20px; border-radius: 12px; }
        .stat-card h4 { color: var(--text-muted); font-size: 13px; margin-bottom: 8px; }
        .stat-card .value { font-size: 22px; font-weight: bold; color: var(--primary); }

        .card { background: var(--card-bg); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-bottom: 25px; }
        .card h3 { margin-bottom: 15px; color: var(--primary); border-bottom: 1px solid var(--border); padding-bottom: 10px; }

        label { display: block; margin-top: 12px; font-weight: 600; color: var(--text-muted); font-size: 13px; }
        input, textarea, select { width: 100%; padding: 11px; margin-top: 5px; background: #0b0f16; border: 1px solid var(--border); color: #fff; border-radius: 8px; }
        textarea { height: 100px; resize: vertical; }
        button { background: var(--primary); color: white; border: none; padding: 11px 20px; font-weight: bold; border-radius: 8px; cursor: pointer; margin-top: 15px; width: 100%; transition: 0.2s; }
        button:hover { background: var(--primary-hover); }

        /* Tables */
        .table-responsive { overflow-x: auto; max-height: 450px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); }
        th { background: #111827; color: var(--text); position: sticky; top: 0; z-index: 10; }
        .inline-form { display: flex; gap: 5px; }
        .inline-form input { width: 80px; padding: 5px; }
        .btn-sm { padding: 5px 10px; font-size: 11px; width: auto; margin: 0; }
        .btn-success { background: var(--success); }
        .btn-danger { background: var(--danger); }

        .alert { padding: 15px; background: #1e3a8a; border-left: 4px solid var(--primary); margin-bottom: 20px; border-radius: 6px; font-size: 14px; }
        
        @media (max-width: 768px) {
            body { flex-direction: column; height: auto; overflow: auto; }
            .sidebar { width: 100%; height: auto; }
            .main-content { height: auto; overflow: visible; }
        }
    </style>
</head>
<body>

<?php if (!$is_authenticated): ?>
    <!-- LOGIN SCREEN -->
    <div class="login-wrapper">
        <div class="login-card">
            <h2>🔐 LALA BINGO Admin</h2>
            <?php if (!empty($status_message)): ?><div style="color:var(--danger); margin-bottom:15px; font-size:13px;"><?php echo $status_message; ?></div><?php endif; ?>
            <form method="POST">
                <input type="text" name="username" required placeholder="የአስተዳዳሪ ስም (Username)" autofocus>
                <input type="password" name="password" required placeholder="የይለፍ ቃል (Password)" style="margin-top:10px;">
                <button type="submit" name="login_submit" value="1">ግባ (Login)</button>
            </form>
            <p style="color:var(--text-muted); font-size:12px; margin-top:15px;">Default: admin / admin123</p>
        </div>
    </div>
<?php else: ?>
    <!-- SIDEBAR NAVIGATION -->
    <div class="sidebar">
        <div class="sidebar-brand">🇯🇲 LALA BINGO</div>
        <ul class="sidebar-menu">
            <li><a href="admin.php?tab=dashboard" class="<?php echo $active_tab==='dashboard'?'active':''; ?>">📊 ዳሽቦርድ (Dashboard)</a></li>
            <li><a href="admin.php?tab=users" class="<?php echo $active_tab==='users'?'active':''; ?>">👥 ተጫዋቾች (Users Control)</a></li>
            <li><a href="admin.php?tab=customer" class="<?php echo $active_tab==='customer'?'active':''; ?>">💬 የደንበኛ ሳይድ (Customer Side)</a></li>
            <li><a href="admin.php?tab=deposits" class="<?php echo $active_tab==='deposits'?'active':''; ?>">📥 ብር ማስገቢያ (Deposits)</a></li>
            <li><a href="admin.php?tab=withdrawals" class="<?php echo $active_tab==='withdrawals'?'active':''; ?>">📤 ብር ማውጫ (Withdrawals)</a></li>
            <li><a href="admin.php?tab=broadcast" class="<?php echo $active_tab==='broadcast'?'active':''; ?>">📢 ብሮድካስት (Broadcast)</a></li>
            <li><a href="admin.php?tab=admins" class="<?php echo $active_tab==='admins'?'active':''; ?>">🛡️ አድሚን ጨምር (Add Admin)</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="admin.php?action=logout" class="logout-btn">🚪 ውጣ (Logout)</a>
        </div>
    </div>

    <!-- MAIN CONTENT AREA -->
    <div class="main-content">
        <div class="header">
            <h3>🎛️ ቁጥጥር ማዕከል (Control Center)</h3>
            <span style="color:var(--text-muted); font-size:14px;">እንኳን ደህና መጡ, <b><?php echo htmlspecialchars($_SESSION['admin_username']); ?></b></span>
        </div>

        <?php if (!empty($status_message)): ?>
            <div class="alert"><?php echo $status_message; ?></div>
        <?php endif; ?>

        <!-- TAB 1: DASHBOARD -->
        <?php if ($active_tab === 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <h4>👥 ጠቅላላ ተመዝጋቢዎች</h4>
                    <div class="value"><?php echo $total_users; ?></div>
                </div>
                <div class="stat-card">
                    <h4>💰 ጠቅላላ የተጠቃሚ ሂሳብ</h4>
                    <div class="value">ETB <?php echo number_format($total_balance, 2); ?></div>
                </div>
                <div class="stat-card">
                    <h4>📥 ጠቅላላ የገንዘብ ማስገቢያ ጥያቄዎች</h4>
                    <div class="value"><?php echo count($deposits); ?></div>
                </div>
                <div class="stat-card">
                    <h4>📤 ጠቅላላ የገንዘብ ማውጫ ጥያቄዎች</h4>
                    <div class="value"><?php echo count($withdrawals); ?></div>
                </div>
            </div>

            <div class="card">
                <h3>📌 አጠቃላይ መግለጫ</h3>
                <p style="color:var(--text-muted); line-height: 1.6;">
                    ይህ የ <b>LALA BINGO</b> ቦት አስተዳደር መቆጣጠሪያ ማዕከል ነው። ከጎን ካለው ስላይድ ባር (Sidebar) በመጠቀም የተጫዋቾችን ሂሳብ ማስተካከል፣ የገንዘብ ማስገቢያ እና ማውጫ ጥያቄዎችን ማፅደቅ፣ እንዲሁም ለተጠቃሚዎች ፖስተር እና መልዕክቶችን ብሮድካስት ማድረግ ይችላሉ።
                </p>
            </div>

        <!-- TAB 2: USERS CONTROL -->
        <?php elseif ($active_tab === 'users'): ?>
            <div class="card">
                <h3>👥 ተጫዋቾች መቆጣጠሪያ (User Management)</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ተጫዋች (Name)</th>
                                <th>ስልክ / ID</th>
                                <th>ቀሪ ሂሳብ (Balance)</th>
                                <th>እርምጃ (Action)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                               <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">ምንም ተጠቃሚ አልተገኘም</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $key => $u): ?>
                                    <tr>
                                        <td>
                                            <b><?php echo htmlspecialchars($u['first_name'] ?? 'User'); ?></b><br>
                                            <small style="color:var(--text-muted);">@<?php echo htmlspecialchars($u['username'] ?? 'NoUsername'); ?></small>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($u['phone'] ?? 'N/A'); ?></code><br>
                                            <small style="color:var(--text-muted);">ID: <?php echo $u['telegram_id'] ?? $key; ?></small>
                                        </td>
                                        <td><b><?php echo number_format(floatval($u['balance'] ?? 0), 2); ?> ETB</b></td>
                                        <td>
                                            <form method="POST" class="inline-form" onsubmit="return confirm('ሂሳቡን ማስተካከል ይፈልጋሉ?');">
                                                <input type="hidden" name="action" value="update_balance">
                                                <input type="hidden" name="telegram_id" value="<?php echo $u['telegram_id'] ?? $key; ?>">
                                                <input type="number" step="0.01" name="new_balance" value="<?php echo floatval($u['balance'] ?? 0); ?>" required>
                                                <button type="submit" class="btn-sm">💾</button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('እርግጠኛ ኖት ይህንን ተጫዋች መሰረዝ ይፈልጋሉ?');" style="margin-top:4px;">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="telegram_id" value="<?php echo $u['telegram_id'] ?? $key; ?>">
                                                <button type="submit" class="btn-sm btn-danger" style="width:100%;">🗑️ ሰርዝ</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- TAB 3: CUSTOMER SIDE -->
        <?php elseif ($active_tab === 'customer'): ?>
            <div class="card">
                <h3>💬 የደንበኛ ሳይድ ሁኔታ (Customer Bot Interaction View)</h3>
                <p style="color:var(--text-muted); margin-bottom:15px; font-size:14px;">
                    ተጠቃሚዎች በቴሌግራም ቦቱ ውስጥ የሚመለከቷቸው ዋና ዋና አገናኞች እና ዌብ አፕ (Web App) ሁኔታዎች:
                </p>
                <label>የአሁኑ የጨዋታ ዌብ አፕ ሊንክ (Game Web App URL):</label>
                <input type="text" value="<?php echo GAME_URL; ?>" readonly style="background:#111827; color:#38bdf8;">
                
                <label style="margin-top:20px;">የቴሌብር ሂሳብ ቁጥር (Active Deposit Telebirr Account):</label>
                <input type="text" value="0979652325 (YISAK)" readonly style="background:#111827; color:#38bdf8;">
            </div>

        <!-- TAB 4: DEPOSITS -->
        <?php elseif ($active_tab === 'deposits'): ?>
            <div class="card">
                <h3>📥 የገንዘብ ማስገቢያ ጥያቄዎች (Deposit Approvals)</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ትራንዛክሽን ID</th>
                                <th>ተጠቃሚ (Username)</th>
                                <th>መጠን (Amount)</th>
                                <th>ሁኔታ (Status)</th>
                                <th>እርምጃ (Action)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($deposits)): ?>
                                <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">ምንም የገንዘብ ማስገቢያ ታሪክ የለም</td></tr>
                            <?php else: ?>
                                <?php foreach ($deposits as $tx_id => $d): ?>
                                    <tr>
                                        <td><code><?php echo $tx_id; ?></code></td>
                                        <td>@<?php echo htmlspecialchars($d['claimed_by'] ?? 'N/A'); ?></td>
                                        <td><b><?php echo number_format(floatval($d['amount'] ?? 0), 2); ?> ETB</b></td>
                                        <td><b><?php echo $d['status'] ?? 'pending'; ?></b></td>
                                        <td>
                                            <?php if (($d['status'] ?? '') !== 'processed'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="process_deposit">
                                                    <input type="hidden" name="tx_id" value="<?php echo $tx_id; ?>">
                                                    <input type="hidden" name="status" value="processed">
                                                    <button type="submit" class="btn-sm btn-success">✅ አጽድቅ</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- TAB 5: WITHDRAWALS -->
        <?php elseif ($active_tab === 'withdrawals'): ?>
            <div class="card">
                <h3>📤 የገንዘብ ማውጫ ጥያቄዎች (Withdrawal Requests)</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ማውጫ ID / ዘዴ</th>
                                <th>ተጠቃሚ / ስልክ</th>
                                <th>መጠን & አካውንት</th>
                                <th>ሁኔታ</th>
                                <th>እርምጃ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($withdrawals)): ?>
                                <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">ምንም የገንዘብ ማውጫ ጥያቄ የለም</td></tr>
                            <?php else: ?>
                                <?php foreach ($withdrawals as $wdr_id => $w): ?>
                                    <tr>
                                        <td><code><?php echo $wdr_id; ?></code><br><small><?php echo $w['method'] ?? 'CBE'; ?></small></td>
                                        <td><b><?php echo htmlspecialchars($w['first_name'] ?? 'User'); ?></b><br><code><?php echo $w['phone'] ?? ''; ?></code></td>
                                        <td><b><?php echo number_format(floatval($w['amount'] ?? 0), 2); ?> ETB</b><br><small><?php echo htmlspecialchars($w['account_details'] ?? ''); ?></small></td>
                                        <td><b><?php echo $w['status'] ?? 'pending'; ?></b></td>
                                        <td>
                                            <?php if (($w['status'] ?? '') === 'pending'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="process_withdrawal">
                                                    <input type="hidden" name="wdr_id" value="<?php echo $wdr_id; ?>">
                                                    <input type="hidden" name="status" value="approved">
                                                    <button type="submit" class="btn-sm btn-success">✅ ላክ</button>
                                                </form>
                                                <form method="POST" style="display:inline; margin-left:4px;">
                                                    <input type="hidden" name="action" value="process_withdrawal">
                                                    <input type="hidden" name="wdr_id" value="<?php echo $wdr_id; ?>">
                                                    <input type="hidden" name="status" value="rejected">
                                                    <button type="submit" class="btn-sm btn-danger">❌ ውድቅ</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- TAB 6: BROADCAST -->
        <?php elseif ($active_tab === 'broadcast'): ?>
            <div class="card">
                <h3>📢 ፖስተር እና መልእክት ብሮድካስት (Broadcast Center)</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="send_broadcast">
                    
                    <label>ከኮምፒዩተርዎ ፖስተር/ምስል ይምረጡ (Local Image):</label>
                    <input type="file" name="image_file" accept="image/*">

                    <label>የመልእክት ጽሁፍ (HTML Supported):</label>
                    <textarea name="message" required placeholder="💎 <b>የሳምንቱ ልዩ ዕድል በ LALA BINGO!</b>&#10;&#10;እድልዎን አሁኑኑ ይሞክሩ..."></textarea>

                    <label>የቁልፍ ጽሁፍ (Button Text):</label>
                    <input type="text" name="btn_text" value="🌴 Play now 🕹️">

                    <label>የቁልፍ ሊንክ (Button WebApp URL):</label>
                    <input type="text" name="btn_url" value="<?php echo GAME_URL; ?>">

                    <button type="submit">🚀 ብሮድካስት ለሁሉም ተጠቃሚዎች ላክ</button>
                </form>
            </div>

        <!-- TAB 7: ADD ADMIN -->
        <?php elseif ($active_tab === 'admins'): ?>
            <div class="card">
                <h3>🛡️ አዲስ አስተዳዳሪ ጨምር (Add New Admin)</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_admin">
                    
                    <label>አዲስ የአስተዳዳሪ ስም (Username):</label>
                    <input type="text" name="new_username" required placeholder="yisak_admin">

                    <label>የይለፍ ቃል (Password):</label>
                    <input type="password" name="new_password" required placeholder="secure_pass123">

                    <button type="submit">➕ አስተዳዳሪ መዝግብ</button>
                </form>

                <h3 style="margin-top:30px;">📋 अवailable Admins</h3>
                <ul>
                    <li><b><?php echo ADMIN_USER; ?></b> (Default Master Admin)</li>
                    <?php if (!empty($admins) && is_array($admins)): ?>
                        <?php foreach($admins as $adm): ?>
                            <li><b><?php echo htmlspecialchars($adm['username']); ?></b></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>
