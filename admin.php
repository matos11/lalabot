<?php
// admin.php - LALA BINGO Ultimate Admin Panel
session_start();

// Configuration Constants
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8605292135:AAHDAoOxTRw-0xBLXJGY8rIaRtVBG3LnKxM');
define('GAME_URL', getenv('GAME_URL') ?: 'https://lalabingobot.vercel.app/');
define('BASE_FIREBASE', getenv('BASE_FIREBASE') ?: 'https://lalabingobot-default-rtdb.firebaseio.com/');
define('ADMIN_PASSWORD', 'admin123'); // Secure your password here

$status_message = "";
$debug_errors = [];

// Handle Login / Logout Actions
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: admin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (($_POST['password'] ?? '') === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        $status_message = "❌ የተሳሳተ የይለፍ ቃል!";
    }
}

// Ensure Admin is Logged In
$is_authenticated = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Handle Admin Actions (Update Balance / Delete User / Broadcast)
if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Update User Balance
    if (isset($_POST['action']) && $_POST['action'] === 'update_balance') {
        $target_id = $_POST['telegram_id'];
        $new_balance = floatval($_POST['new_balance']);
        
        $user_data = firebaseGet(BASE_FIREBASE . "users/{$target_id}.json");
        if ($user_data) {
            $user_data['balance'] = $new_balance;
            firebasePut(BASE_FIREBASE . "users/{$target_id}.json", $user_data);
            
            // Sync with userone if phone exists
            if (!empty($user_data['phone'])) {
                $clean_phone = preg_replace('/[.#$[\]\/]/', '_', (string)$user_data['phone']);
                firebasePut(BASE_FIREBASE . "userone/{$clean_phone}.json", $user_data);
            }
            $status_message = "✅ የተጫዋች (ID: {$target_id}) ቀሪ ሂሳብ ወደ ETB {$new_balance} ተስተካክሏል!";
        }
    }

    // 2. Delete User
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $target_id = $_POST['telegram_id'];
        firebaseDelete(BASE_FIREBASE . "users/{$target_id}.json");
        $status_message = "🗑️ ተጫዋች (ID: {$target_id}) ከዳታቤዝ ተሰርዟል!";
    }

    // 3. Broadcast Message
    if (isset($_POST['action']) && $_POST['action'] === 'send_broadcast') {
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
                        $debug_errors[] = "ID: {$chat_id} -> " . ($res['description'] ?? 'Unknown error');
                    }
                    usleep(35000);
                }
                $status_message = "✅ ብሮድካስት ተጠናቋል! የተሳካ: <b>{$success_count}</b>, ያልተሳካ: <b>{$fail_count}</b>";
            }
        }
    }
}

// Fetch System Analytics Data
$users = $is_authenticated ? (firebaseGet(BASE_FIREBASE . "users.json") ?: []) : [];
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
function curlPost($url, $post) {  
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  
    $r = curl_exec($ch); curl_close($ch); return json_decode($r, true);  
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
    <title>LALA BINGO - Control & Admin Dashboard</title>
    <style>
        :root { --bg: #0b0f19; --card-bg: #111827; --border: #1f2937; --text: #f3f4f6; --text-muted: #9ca3af; --primary: #3b82f6; --primary-hover: #2563eb; --danger: #ef4444; --success: #10b981; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: var(--bg); color: var(--text); padding: 20px; }
        .wrapper { max-width: 1200px; margin: 0 auto; }
        
        /* Login Box */
        .login-card { max-width: 400px; margin: 100px auto; background: var(--card-bg); border: 1px solid var(--border); padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); text-align: center; }
        .login-card h2 { color: var(--primary); margin-bottom: 20px; }
        
        /* Dashboard Layout */
        .header { display: flex; justify-content: space-between; align-items: center; background: var(--card-bg); border: 1px solid var(--border); padding: 20px 30px; border-radius: 12px; margin-bottom: 25px; }
        .logout-btn { background: var(--danger); color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--border); padding: 20px; border-radius: 12px; }
        .stat-card h4 { color: var(--text-muted); font-size: 14px; margin-bottom: 8px; }
        .stat-card .value { font-size: 24px; font-weight: bold; color: var(--primary); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

        .card { background: var(--card-bg); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-bottom: 25px; }
        .card h3 { margin-bottom: 15px; color: var(--primary); border-bottom: 1px solid var(--border); padding-bottom: 10px; }

        label { display: block; margin-top: 12px; font-weight: 600; color: var(--text-muted); font-size: 14px; }
        input, textarea { width: 100%; padding: 12px; margin-top: 5px; background: #030712; border: 1px solid var(--border); color: #fff; border-radius: 8px; }
        textarea { height: 100px; resize: vertical; }
        
        button { background: var(--primary); color: white; border: none; padding: 12px 20px; font-weight: bold; border-radius: 8px; cursor: pointer; margin-top: 15px; width: 100%; transition: background 0.2s; }
        button:hover { background: var(--primary-hover); }

        /* Tables */
        .table-responsive { overflow-x: auto; max-height: 400px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); }
        th { background: #1f2937; color: var(--text); position: sticky; top: 0; }
        .inline-form { display: flex; gap: 5px; }
        .inline-form input { width: 90px; padding: 6px; }
        .btn-sm { padding: 6px 10px; font-size: 12px; width: auto; margin: 0; }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #dc2626; }

        .alert { padding: 15px; background: #1e3a8a; border-left: 4px solid var(--primary); margin-bottom: 20px; border-radius: 6px; font-size: 14px; }
        .error-box { background: #450a0a; color: #fca5a5; padding: 10px; border-radius: 6px; margin-top: 15px; font-size: 12px; max-height: 120px; overflow-y: auto; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php if (!$is_authenticated): ?>
        <!-- LOGIN SCREEN -->
        <div class="login-card">
            <h2>🔐 LALA BINGO Admin</h2>
            <?php if (!empty($status_message)): ?><div style="color:var(--danger); margin-bottom:15px;"><?php echo $status_message; ?></div><?php endif; ?>
            <form method="POST">
                <input type="password" name="password" required placeholder="የአስተዳዳሪ የይለፍ ቃል (Password)" autofocus>
                <button type="name" name="login" value="1">ግባ (Login)</button>
            </form>
        </div>
    <?php else: ?>
        <!-- DASHBOARD HEADER -->
        <div class="header">
            <div>
                <h2>🇯🇲 LALA BINGO Control Center</h2>
                <p style="color:var(--text-muted); font-size: 13px;">ዕለታዊ የተጫዋቾች አስተዳደር እና የብሮድካስት ማዕከል</p>
            </div>
            <a href="admin.php?action=logout" class="logout-btn">🚪 ውጣ (Logout)</a>
        </div>

        <?php if (!empty($status_message)): ?>
            <div class="alert"><?php echo $status_message; ?></div>
        <?php endif; ?>

        <!-- ANALYTICS CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <h4>👥 ጠቅላላ ተመዝጋቢዎች (Total Users)</h4>
                <div class="value"><?php echo $total_users; ?></div>
            </div>
            <div class="stat-card">
                <h4>💰 ጠቅላላ የተጠቃሚ ቀሪ ሂሳብ (Total Balance)</h4>
                <div class="value">ETB <?php echo number_format($total_balance, 2); ?></div>
            </div>
        </div>

        <div class="grid-2">
            <!-- USER MANAGEMENT PANEL -->
            <div class="card">
                <h3>👥 ተጫዋቾች መቆጣጠሪያ (User Control)</h3>
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
                                        <td><b><?php echo number_format(floatval($u['balance'] ?? 0), 2); ?></b></td>
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

            <!-- BROADCAST PANEL -->
            <div class="card">
                <h3>📢 ፖስተር እና መልእክት ብሮድካስት (Broadcast Center)</h3>
                <?php if (!empty($debug_errors)): ?>
                    <div class="error-box">
                        <strong>የስህተት ዝርዝር (Errors):</strong><br>
                        <?php foreach($debug_errors as $err) { echo htmlspecialchars($err) . "<br>"; } ?>
                    </div>
                <?php endif; ?>
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

                    <button type="submit">🚀 ብሮድካስት ለሁሉም ተጫዋቾች ላክ</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
