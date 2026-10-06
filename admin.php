<?php
// admin.php - LALA BINGO Broadcast Admin Panel

// Configuration Constants
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8605292135:AAHDAoOxTRw-0xBLXJGY8rIaRtVBG3LnKxM');
define('GAME_URL', getenv('GAME_URL') ?: 'https://lalabingobot.vercel.app/');
define('BASE_FIREBASE', getenv('BASE_FIREBASE') ?: 'https://lalabingobot-default-rtdb.firebaseio.com/');

define('ADMIN_PASSWORD', 'admin123'); // Change this to your secure password!

$status_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['password'] ?? '') !== ADMIN_PASSWORD) {
        $status_message = "❌ የተሳሳተ የይለፍ ቃል! (Incorrect Password)";
    } else {
        $text = trim($_POST['message'] ?? '');
        $image_url = trim($_POST['image_url'] ?? '');
        $btn_text = trim($_POST['btn_text'] ?? '🌴 Play now');
        $btn_url = trim($_POST['btn_url'] ?? GAME_URL);

        if (empty($text)) {
            $status_message = "❌ እባክዎ የመልእክት ጽሁፍ ያስገቡ!";
        } else {
            $all_users = firebaseGet(BASE_FIREBASE . "users.json");
            $success_count = 0;
            $fail_count = 0;

            if ($all_users && is_array($all_users)) {
                $keyboard = [
                    "inline_keyboard" => [
                        [["text" => $btn_text, "web_app" => ["url" => $btn_url]]]
                    ]
                ];

                foreach ($all_users as $telegram_id => $userData) {
                    if (!isset($userData['telegram_id'])) continue;
                    $chat_id = $userData['telegram_id'];

                    if (!empty($image_url)) {
                        $payload = [
                            "chat_id" => $chat_id,
                            "photo" => $image_url,
                            "caption" => $text,
                            "parse_mode" => "HTML",
                            "reply_markup" => json_encode($keyboard)
                        ];
                        $res = curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto", $payload);
                    } else {
                        $payload = [
                            "chat_id" => $chat_id,
                            "text" => $text,
                            "parse_mode" => "HTML",
                            "reply_markup" => json_encode($keyboard)
                        ];
                        $res = curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage", $payload);
                    }

                    if ($res && isset($res['ok']) && $res['ok'] === true) {
                        $success_count++;
                    } else {
                        $fail_count++;
                    }
                    usleep(35000); // Prevent hitting Telegram rate limits (~30 msgs/sec)
                }
                $status_message = "✅ ብሮድካስት ተጠናቋል! የተሳካ: <b>{$success_count}</b>, ያልተሳካ: <b>{$fail_count}</b>";
            } else {
                $status_message = "❌ በዳታቤዝ ውስጥ ምንም ተጠቃሚ አልተገኘም::";
            }
        }
    }
}

// Helper Functions
function firebaseGet($url) {  
    $ch = curl_init($url);  
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  
    $res = curl_exec($ch);  
    curl_close($ch);  
    return $res ? json_decode($res, true) : null;  
}

function curlPost($url, $post) {  
    $ch = curl_init($url);  
    curl_setopt($ch, CURLOPT_POST, true);  
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);  
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  
    $r = curl_exec($ch);  
    curl_close($ch);  
    return json_decode($r, true);  
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <title>LALA BINGO - Admin Broadcast Panel</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; display: flex; justify-content: center; }
        .container { width: 100%; max-width: 600px; background: #1e293b; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        h2 { text-align: center; color: #38bdf8; margin-bottom: 20px; }
        label { display: block; margin-top: 15px; font-weight: bold; color: #cbd5e1; }
        input, textarea { width: 100%; padding: 12px; margin-top: 5px; background: #0f172a; border: 1px solid #334155; color: #fff; border-radius: 6px; box-sizing: border-box; }
        textarea { height: 120px; resize: vertical; }
        button { width: 100%; margin-top: 25px; background: #0284c7; color: white; border: none; padding: 14px; font-size: 16px; font-weight: bold; border-radius: 6px; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #0369a1; }
        .alert { padding: 15px; background: #334155; border-left: 4px solid #38bdf8; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>📢 LALA BINGO Admin Broadcast</h2>
        <?php if (!empty($status_message)): ?>
            <div class="alert"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <form method="POST">
            <label>የአስተዳዳሪ የይለፍ ቃል (Password):</label>
            <input type="password" name="password" required placeholder="admin123">

            <label>የምስል ሊንክ (Image URL - አማራጭ):</label>
            <input type="text" name="image_url" placeholder="https://example.com/banner.jpg">

            <label>የመልእክት ጽሁፍ (Message HTML supported):</label>
            <textarea name="message" required placeholder="💎 <b>የላቀ አሸናፊ ነፍ ዛሬ በ LALA BINGO!</b>&#10;&#10;እድልዎን አሁኑኑ ይሞክሩ..."></textarea>

            <label>የቁልፍ ጽሁፍ (Button Text):</label>
            <input type="text" name="btn_text" value="🌴 Play now 🕹️">

            <label>የቁልፍ ሊንክ (Button WebApp URL):</label>
            <input type="text" name="btn_url" value="<?php echo GAME_URL; ?>">

            <button type="submit">🚀 ብሮድካስት ላክ (Send Broadcast)</button>
        </form>
    </div>
</body>
</html>
