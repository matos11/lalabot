<?php
// bot.php - LALA BINGO Webhook Engine

// Environment Configuration (Render Environment Variables with hardcoded fallbacks)
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8605292135:AAHDAoOxTRw-0xBLXJGY8rIaRtVBG3LnKxM');
define('GAME_URL', getenv('GAME_URL') ?: 'https://lalabingobot.vercel.app/');
define('BASE_FIREBASE', getenv('BASE_FIREBASE') ?: 'https://lalabingobot-default-rtdb.firebaseio.com/');

define('URL_USERS', BASE_FIREBASE . 'users/');
define('URL_USERONE', BASE_FIREBASE . 'userone/');
define('URL_STATES', BASE_FIREBASE . 'states/');
define('URL_DEPOSITS', BASE_FIREBASE . 'deposits/');
define('URL_WITHDRAWALS', BASE_FIREBASE . 'withdrawals/');

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    http_response_code(200);
    echo json_encode(['status' => 'active', 'app' => 'LALA BINGO']);
    exit;
}

// ======================================
// CALLBACK QUERIES (Inline Buttons)
// ======================================
if (isset($update["callback_query"])) {
    $callback = $update["callback_query"];
    $chat_id = $callback["message"]["chat"]["id"];
    $telegram_id = $callback["from"]["id"];
    $callback_data = $callback["data"];
    $message_id = $callback["message"]["message_id"];
    $username = $callback["from"]["username"] ?? "NoUsername";

    answerCallbackQuery($callback["id"]);
    
    if ($callback_data === "menu_dashboard") {
        clearState($telegram_id);
        editMessageText($chat_id, $message_id, getDashboardText($telegram_id), getDashboardKeyboard($telegram_id));
    } elseif ($callback_data === "menu_play") {
        $user = firebaseGet(URL_USERS . $telegram_id . ".json");
        $balance = floatval($user["balance"] ?? 0);
        if ($balance <= 0) {
            $keyboard = ["inline_keyboard" => [[["text" => "💳 ብር አስገባ (Deposit)", "callback_data" => "menu_deposit"]], [["text" => "🔙 ወደ ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
            editMessageText($chat_id, $message_id, "⚠️ <b>የሂሳብዎ መጠን ለጨዋታ በቂ አይደለም!</b>\n\nየአሁኑ ቀሪ ሂሳብዎ: <code>ETB " . number_format($balance, 2) . "</code>\nእባክዎ መጀመሪያ ሂሳብዎን ይሙሉ::", $keyboard);
        } else {
            $keyboard = ["inline_keyboard" => [[["text" => "🌴 ጀምር (LAUNCH LALA BINGO)", "web_app" => ["url" => GAME_URL]]], [["text" => "🔙 ወደ ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
            editMessageText($chat_id, $message_id, "🇯🇲 <b>ወደ LALA ቢንጎ የመጫወቻ ሜዳ እንኳን በደህና መጡ!</b>\n\nእድልዎን ይፈትሹና ትልቅ ያሸንፉ! ጨዋታውን ለመጀመር 'LAUNCH' የሚለውን ቁልፍ ይጫኑ::", $keyboard);
        }
    } elseif ($callback_data === "menu_deposit") {
        firebasePut(URL_STATES . $telegram_id . ".json", "waiting_deposit");
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ወደ ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
        
        $deposit_text = "━━━━━━━━━━ Telebirr ━━━━━━━━━\n\n"
                      . "Pay by ቴሌብር 📲 Tele Birr: <b>0979652325</b>\n"
                      . "Name 👤   <b>YISAK</b>\n\n"
                      . "Click to Copy 👉:   <code>0979652325</code>\n\n"
                      . "• ከከፈሉ በኋላ ከቴሌ ብር / 127 የተላከልዎትን የማረጋገጫ ሜሴጅ ላይ የሚገኘውን 10 ዲጂት ትራንዛክሽን ID/ቁጥር ወይንም ሊንክ ለቦቱ ይላኩ፡፡\n\n"
                      . "ለምሳሌ፡ <code>DLP01WUDO1</code> ወይንም ሊንኩን https://transactioninfo.ethiotelecom.et/receipt/DLP01WUDO1\n\n"
                      . "አጠቃላይ SMS ማስተላለፍም ይችላሉ::\n\n"
                      . "-------------------------------------\n"
                      . "After payment, send either:\n"
                      . "• Copy paste The SMS text from 127\n"
                      . "• Telebirr Transaction ID or receipt link";
                      
        editMessageText($chat_id, $message_id, $deposit_text, $keyboard);
    } 
    
    // --- STEP 1: INITIALIZE WITHDRAWAL REQUEST ---
    elseif ($callback_data === "menu_withdraw") {
        firebasePut(URL_STATES . $telegram_id . ".json", "waiting_wdr_amount");
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ሰርዝ", "callback_data" => "menu_dashboard"]]]];
        editMessageText($chat_id, $message_id, "💰 <b>ብር ማውጫ ገጽ (Withdraw)</b>\n\nማውጣት የሚፈልጉትን የገንዘብ መጠን በቁጥር ብቻ ያስገቡ (ምሳሌ: <code>150</code>):", $keyboard);
    } 
    
    // --- STEP 3: HANDLE GATEWAY PREFERENCE SELECTION ---
    elseif (str_starts_with($callback_data, "wdr_method_")) {
        $method = str_replace("wdr_method_", "", $callback_data);
        
        $currentState = firebaseGet(URL_STATES . $telegram_id . ".json");
        if (is_array($currentState)) {
            $currentState['method'] = $method;
            $currentState['stage'] = "waiting_wdr_details";
            firebasePut(URL_STATES . $telegram_id . ".json", $currentState);
        }

        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ሰርዝ", "callback_data" => "menu_dashboard"]]]];
        
        if ($method === "telebirr") {
            $prompt = "📲 <b>የቴሌብር አካውንት መረጃ</b>\n\nእባክዎ ተቀባይ <b>ስም እና የስልክ ቁጥር</b> በዚህ መልክ በ አንድ ላይ አስገብተው ይላኩ:\n\nምሳሌ: <code>ዮሐንስ አበበ - 0912345678</code>";
        } else {
            $prompt = "🏦 <b>የኢትዮጵያ ንግድ ባንክ (CBE) መረጃ</b>\n\nእባክዎ የባንክ <b>አካውንት ቁጥር እና ሙሉ ስም</b> በዚህ መልክ በ አንድ ላይ አስገብተው ይላኩ:\n\nምሳሌ: <code>1000123456789 - አስቴር ከበደ</code>";
        }
        
        editMessageText($chat_id, $message_id, $prompt, $keyboard);
    }
    
    // --- CONTINUOUS DASHBOARD MENUS ---
    elseif ($callback_data === "menu_balance") {
        $user = firebaseGet(URL_USERS . $telegram_id . ".json");
        $balance = floatval($user["balance"] ?? 0.0);
        
        $all_deposits = firebaseGet(URL_DEPOSITS . '.json') ?? [];
        $all_withdrawals = firebaseGet(URL_WITHDRAWALS . '.json') ?? [];

        $dep_log = ""; $dep_count = 0;
        foreach ($all_deposits as $tx => $meta) {
            if (($meta['claimed_by'] ?? '') === $username && ($meta['status'] ?? '') === 'processed') {
                $dep_count++;
                $tx_time = isset($meta['claimed_at']) ? date("d-m-Y H:i", $meta['claimed_at']) : date("d-m-Y H:i");
                $dep_log .= "▫️ <code>" . $tx_time . "</code> | <b>+" . number_format($meta['amount'] ?? 0, 2) . " ETB</b>\n";
                if ($dep_count >= 5) break; 
            }
        }
        if (empty($dep_log)) $dep_log = "<i>የተመዘገበ የገንዘብ ማስገቢያ ታሪክ የለም::</i>\n";

        $wdr_log = ""; $wdr_count = 0;
        foreach ($all_withdrawals as $wdr_id => $meta) {
            if (strval($meta['telegram_id'] ?? '') === strval($telegram_id)) {
                $wdr_count++;
                $status_raw = $meta['status'] ?? 'pending';
                $status_text = ($status_raw === 'approved') ? "✅ ተጠናቋል" : (($status_raw === 'rejected') ? "❌ ውድቅ የተደረገ" : "⏳ በሂደት ላይ");
                $wdr_time = isset($meta['timestamp']) ? date("d-m-Y H:i", intval($meta['timestamp'] / 1000)) : date("d-m-Y H:i");
                $wdr_log .= "▫️ <code>" . $wdr_time . "</code> | <b>" . number_format($meta['amount'] ?? 0, 2) . " ETB</b> (" . $status_text . ")\n";
                if ($wdr_count >= 5) break; 
            }
        }
        if (empty($wdr_log)) $wdr_log = "<i>የተመዘገበ የገንዘብ ማውጫ ታሪክ የለም::</i>\n";

        $history_text = "💳 <b>የሂሳብ መግለጫ እና የክፍያ ታሪክ</b>\n\n"
                      . "💰 ጠቅላላ ቀሪ ሂሳብ: <b>ETB " . number_format($balance, 2) . "</b>\n\n"
                      . "📥 <b>የገንዘብ ማስገቢያ ታሪክ</b>\n" . $dep_log . "\n"
                      . "📤 <b>የገንዘብ ማውጫ ታሪክ</b>\n" . $wdr_log;

        $keyboard = ["inline_keyboard" => [[["text" => "📥 ብር አስገባ", "callback_data" => "menu_deposit"], ["text" => "📤 ብር አውጣ", "callback_data" => "menu_withdraw"]], [["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
        editMessageText($chat_id, $message_id, $history_text, $keyboard);
    } elseif ($callback_data === "menu_instructions") {
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
        $info = "ℹ️ <b>የአጠቃቀም መመሪያ እና ደንቦች (LALA BINGO)</b>\n\n1. <b>ገንዘብ ለማስገባት:</b> ቴሌብር ላይ በመክፈል የትራንዛክሽን ኮዱን መላክ::\n2. <b>ገንዘብ ለማውጣት:</b> ማውጫ በመንካት የባንክ ወይም ቴሌብር መረጃ ማቅረብ::\n3. <b>ደንብ:</b> ትክክለኛ መረጃ በማስገባት ፈጣን ክፍያ ያግኙ::";
        editMessageText($chat_id, $message_id, $info, $keyboard);
    } elseif ($callback_data === "menu_referral") {
        $bot_info = getBotUsername();
        $ref_link = "https://t.me/" . $bot_info . "?start=" . $telegram_id;
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
        $ref_text = "🔗 <b>የጓደኛ መጋበዣ ሊንክ (LALA BINGO)</b>\n\nጓደኛዎ ሲመዘገብ የ <b>5.00 ETB</b> ቦነስ ያገኛሉ!\n\n<code>" . $ref_link . "</code>";
        editMessageText($chat_id, $message_id, $ref_text, $keyboard);
    }
    exit;
}

// ======================================
// TEXT MESSAGES & INPUT PROCESSING
// ======================================
if (isset($update["message"])) {
    $message = $update["message"];
    $chat_id = $message["chat"]["id"];
    $telegram_id = $message["from"]["id"];
    $username = $message["from"]["username"] ?? "NoUsername";
    $first_name = $message["from"]["first_name"] ?? "User";
    $text = trim($message["text"] ?? "");

    // Handle Contact Sharing -> Write to both users and userone node
    if (isset($message["contact"])) {
        $phone = $message["contact"]["phone_number"];
        
        // 1. Update/create user in users node
        $user = firebaseGet(URL_USERS . $telegram_id . ".json") ?? [];
        $user["telegram_id"] = $telegram_id;
        $user["first_name"] = $first_name;
        $user["username"] = $username;
        $user["phone"] = $phone;
        if (!isset($user["balance"])) $user["balance"] = 0.0;
        if (!isset($user["created_at"])) $user["created_at"] = time();
        $user["updated_at"] = time();
        
        firebasePut(URL_USERS . $telegram_id . ".json", $user);

        // 2. Create/update in userone node
        $clean_phone = preg_replace('/[.#$[\]\/]/', '_', $phone);
        $userone_key = !empty($clean_phone) ? $clean_phone : strval($telegram_id);
        
        $userone_payload = [
            "telegram_id" => $telegram_id,
            "phone" => $phone,
            "name" => $first_name,
            "first_name" => $first_name,
            "username" => $username,
            "balance" => floatval($user["balance"] ?? 0.0),
            "created_at" => time()
        ];
        
        firebasePut(URL_USERONE . $userone_key . ".json", $userone_payload);

        sendMessage($chat_id, "✅ <b>ስልክ ቁጥርዎ በተሳካ ሁኔታ ተመዝግቧል!</b>", ["remove_keyboard" => true]);
        sendMessage($chat_id, getDashboardText($telegram_id), getDashboardKeyboard($telegram_id));
        exit;
    }

    if (str_starts_with($text, "/start")) {
        clearState($telegram_id);
        $referrer_id = null; 
        $parts = explode(" ", $text);
        if (count($parts) > 1 && is_numeric($parts[1])) { 
            $referrer_id = trim($parts[1]); 
        }

        $user = firebaseGet(URL_USERS . $telegram_id . ".json");
        if (!$user) {
            $user_payload = [
                "telegram_id" => $telegram_id, 
                "first_name" => $first_name, 
                "username" => $username, 
                "balance" => 0.0, 
                "created_at" => time()
            ];
            firebasePut(URL_USERS . $telegram_id . ".json", $user_payload);
            
            if ($referrer_id && strval($referrer_id) !== strval($telegram_id)) {
                $referrer = firebaseGet(URL_USERS . $referrer_id . ".json");
                if ($referrer) {
                    $referrer["balance"] = floatval($referrer["balance"] ?? 0) + 5.00;
                    firebasePut(URL_USERS . $referrer_id . ".json", $referrer);
                    sendMessage($referrer_id, "🎁 <b>+5.00 ETB ቦነስ በ LALA BINGO ገብቶልዎታል!</b>");
                }
            }
            sendMessage($chat_id, "👋 <b>እንኳን ወደ LALA BINGO በደህና መጡ! 🇯🇲🎲</b>", ["keyboard" => [[["text" => "📱 ስልክ ቁጥርዎን ያጋሩ", "request_contact" => true]]], "resize_keyboard" => true, "one_time_keyboard" => true]);
        } else {
            sendMessage($chat_id, getDashboardText($telegram_id), getDashboardKeyboard($telegram_id));
        }
        exit;
    }

    $state_data = firebaseGet(URL_STATES . $telegram_id . ".json");

    // Deposit SMS Verification
    if ($state_data === "waiting_deposit" && !empty($text)) {
        if (preg_match('/\b([A-Z0-9]{10})\b/', strtoupper($text), $matches)) { 
            $tx_id = $matches[1]; 
        } else { 
            $tx_id = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text)); 
        }

        if (strlen($tx_id) !== 10) {
            sendMessage($chat_id, "❌ <b>የተሳሳተ የትራንዛክሽን ቁጥር!</b>\n\nእባክዎ ባለ 10 ዲጂት የቴሌብር ማረጋገጫ ቁጥር ወይም ሙሉውን SMS በትክክል ያስገቡ::", ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]]);
            return;
        }

        $parsed_amount = 0.0;
        if (preg_match('/(?:ETB|ብር)\s*([0-9,]+(?:\.\d{1,2})?)/ui', $text, $amt_matches) || 
            preg_match('/([0-9,]+(?:\.\d{1,2})?)\s*(?:ETB|ብር)/ui', $text, $amt_matches)) {
            $parsed_amount = floatval(str_replace(',', '', $amt_matches[1]));
        }

        $master_tx_url = BASE_FIREBASE . "transactions/" . $tx_id . ".json";
        $tx_record = firebaseGet($master_tx_url);
        
        if (!$tx_record) {
            $tx_record = firebaseGet(URL_DEPOSITS . $tx_id . ".json");
        }

        if (!$tx_record) {
            $error_msg = "❌ <b>የትራንዛክሽን ቁጥሩ በስርዓታችን ውስጥ አልተገኘም!</b>\n\n"
                       . "🆔 ቁጥር: <code>" . $tx_id . "</code>\n"
                       . ($parsed_amount > 0 ? "💵 የተገኘው መጠን: <code>" . number_format($parsed_amount, 2) . " ETB</code>\n\n" : "\n")
                       . "⚠️ እባክዎ በትክክል መላክዎን ያረጋግጡ ወይም ሲስተሙ እስኪያነበው ጥቂት ሰከንዶች ቆይተው እንደገና ይሞክሩ::";
            
            sendMessage($chat_id, $error_msg, ["inline_keyboard" => [[["text" => "🔄 እንደገና ሞክር", "callback_data" => "menu_deposit"]], [["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]]);
            return;
        }

        if (($tx_record["status"] ?? "") === "processed") {
            sendMessage($chat_id, "❌ ይህ የትራንዛክሽን ቁጥር (<code>" . $tx_id . "</code>) ቀድሞውኑ ጥቅም ላይ ውሏል!", ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]]);
            clearState($telegram_id);
            return;
        }

        $system_amount = floatval($tx_record["amount"] ?? 0);
        if ($system_amount <= 0 && $parsed_amount > 0) {
            $system_amount = $parsed_amount;
        }

        $user = firebaseGet(URL_USERS . $telegram_id . ".json");
        $user["balance"] = floatval($user["balance"] ?? 0) + $system_amount;
        firebasePut(URL_USERS . $telegram_id . ".json", $user);

        // Keep userone node in sync if phone exists
        if (!empty($user['phone'])) {
            $clean_phone = preg_replace('/[.#$[\]\/]/', '_', $user['phone']);
            $userone = firebaseGet(URL_USERONE . $clean_phone . ".json");
            if ($userone) {
                $userone["balance"] = $user["balance"];
                firebasePut(URL_USERONE . $clean_phone . ".json", $userone);
            }
        }

        $tx_record["status"] = "processed";
        $tx_record["amount"] = $system_amount;
        $tx_record["claimed_by"] = $username;
        $tx_record["claimed_at"] = time();
        $tx_record["raw_sms"] = $text; 
        
        firebasePut(URL_DEPOSITS . $tx_id . ".json", $tx_record);
        clearState($telegram_id);
        
        $success_msg = "✅ <b>ክፍያዎ በተሳካ ሁኔታ ተረጋግጧል!</b>\n━━━━━━━━━━━━━━━━━━━━\n"
                     . "🆔 የትራንዛክሽን ቁጥር: <code>" . $tx_id . "</code>\n"
                     . "💵 መጠን: <b>" . number_format($system_amount, 2) . " ETB</b>\n"
                     . "👤 ወደ LALA BINGO ሂሳብዎ ተጨምሯል!\n━━━━━━━━━━━━━━━━━━━━";
                     
        sendMessage($chat_id, $success_msg, ["inline_keyboard" => [[["text" => "🌴 አሁኑኑ ተጫወት", "callback_data" => "menu_play"]], [["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]]);
        exit;
    }

    // Withdrawal Amount
    if ($state_data === "waiting_wdr_amount" && !empty($text)) {
        $withdraw_amount = floatval($text);
        $user = firebaseGet(URL_USERS . $telegram_id . ".json");
        $current_balance = floatval($user["balance"] ?? 0);
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];

        if ($withdraw_amount <= 0 || $withdraw_amount > $current_balance) {
            sendMessage($chat_id, "❌ <b>የተሳሳተ የገንዘብ መጠን!</b> በቂ ቀሪ ሂሳብ የለዎትም::", $keyboard);
            clearState($telegram_id);
            return;
        }

        $wdr_meta = [
            "stage" => "waiting_wdr_method",
            "amount" => $withdraw_amount
        ];
        firebasePut(URL_STATES . $telegram_id . ".json", $wdr_meta);

        $method_keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => "📲 Telebirr", "callback_data" => "wdr_method_telebirr"],
                    ["text" => "🏦 CBE (ንግድ ባንክ)", "callback_data" => "wdr_method_cbe"]
                ],
                [["text" => "🔙 ሰርዝ", "callback_data" => "menu_dashboard"]]
            ]
        ];

        sendMessage($chat_id, "💳 <b>የመቀበያ ዘዴ ይምረጡ (Select Payment Method)</b>\n\nሊወጣ የታሰበ መጠን: <code>" . number_format($withdraw_amount, 2) . " ETB</code>\n\nገንዘቡ እንዲላክሎት የሚፈልጉበትን የክፍያ አማራጭ ይምረጡ:", $method_keyboard);
        exit;
    }

    // Withdrawal Confirmation
    if (is_array($state_data) && ($state_data['stage'] ?? '') === 'waiting_wdr_details' && !empty($text)) {
        $withdraw_amount = floatval($state_data['amount']);
        $method = $state_data['method'];

        $user = firebaseGet(URL_USERS . $telegram_id . ".json");
        $current_balance = floatval($user["balance"] ?? 0);
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];

        if ($withdraw_amount > $current_balance) {
            sendMessage($chat_id, "❌ ስህተት ተከስቷል:: ቀሪ ሂሳብዎ በቂ አይደለም::", $keyboard);
            clearState($telegram_id);
            return;
        }

        $user["balance"] = $current_balance - $withdraw_amount;
        firebasePut(URL_USERS . $telegram_id . ".json", $user);

        // Keep userone node in sync if phone exists
        if (!empty($user['phone'])) {
            $clean_phone = preg_replace('/[.#$[\]\/]/', '_', $user['phone']);
            $userone = firebaseGet(URL_USERONE . $clean_phone . ".json");
            if ($userone) {
                $userone["balance"] = $user["balance"];
                firebasePut(URL_USERONE . $clean_phone . ".json", $userone);
            }
        }

        clearState($telegram_id);

        $withdrawal_id = "WDR" . time() . rand(10, 99);
        $withdrawal_payload = [
            "id" => $withdrawal_id,
            "telegram_id" => $telegram_id,
            "username" => $username,
            "first_name" => $user["first_name"] ?? "User",
            "phone" => $user["phone"] ?? "Not Provided",
            "amount" => $withdraw_amount,
            "method" => strtoupper($method),
            "account_details" => $text, 
            "status" => "pending",
            "timestamp" => time() * 1000
        ];
        
        firebasePut(BASE_FIREBASE . "withdrawals/" . $withdrawal_id . ".json", $withdrawal_payload);

        $success_msg = "✅ <b>የማውጫ ጥያቄዎ በተሳካ ሁኔታ ቀርቧል!</b>\n\n"
                     . "💵 መጠን: <code>ETB " . number_format($withdraw_amount, 2) . "</code>\n"
                     . "💳 ዘዴ: <b>" . strtoupper($method) . "</b>\n"
                     . "📝 ዝርዝር: <code>" . htmlspecialchars($text) . "</code>\n"
                     . "⏳ ሁኔታ: <b>በማረጋገጥ ላይ (አስተዳዳሪዎች በቅርቡ ክፍያ ይልኩልዎታል)</b>";

        sendMessage($chat_id, $success_msg, $keyboard);
        exit;
    }
}

// ======================================
// VIEW & API HELPERS
// ======================================
function getDashboardText($telegram_id) {
    $user = firebaseGet(URL_USERS . $telegram_id . ".json");
    $balance = floatval($user["balance"] ?? 0.0);
    return "🇯🇲 <b>LALA BINGO ዋና ማውጫ</b>\n\n"
         . "👤 ተጫዋች: <b>" . htmlspecialchars($user['first_name'] ?? 'User') . "</b>\n"
         . "📱 ስልክ: <code>" . ($user['phone'] ?? 'ያልተመዘገበ ⚠️') . "</code>\n"
         . "💰 ቀሪ ሂሳብ: <b>ETB " . number_format($balance, 2) . "</b>\n\n"
         . "ከታች ያሉትን አማራጮች በመጠቀም አካውንትዎን ያስተዳድሩ ወይም ወደ ጨዋታው ይግቡ! ✨";
}

function getDashboardKeyboard($telegram_id) {
    return [
        "inline_keyboard" => [
            [["text" => "🌴 አሁኑኑ ተጫወት (PLAY NOW)", "web_app" => ["url" => GAME_URL]]],
            [["text" => "📥 ብር አስገባ", "callback_data" => "menu_deposit"], ["text" => "📤 ብር አውጣ", "callback_data" => "menu_withdraw"]],
            [["text" => "💳 ቀሪ ሂሳብ ታሪክ", "callback_data" => "menu_balance"], ["text" => "🔗 ጓደኛ ጋብዝ", "callback_data" => "menu_referral"]],
            [["text" => "ℹ️ የአጠቃቀም መመሪያ", "callback_data" => "menu_instructions"]]
        ]
    ];
}

function getBotUsername() {
    $res = curlPost("https://api.telegram.org/bot".BOT_TOKEN."/getMe", []);
    return $res['result']['username'] ?? 'lalabingobot';
}

function firebasePut($url, $data) { 
    $ch = curl_init($url); 
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch); 
    curl_close($ch); 
}

function firebaseGet($url) { 
    $ch = curl_init($url); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch); 
    curl_close($ch); 
    return $res ? json_decode($res, true) : null; 
}

function clearState($telegram_id) { 
    $ch = curl_init(URL_STATES . $telegram_id . ".json"); 
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE"); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch); 
    curl_close($ch); 
}

function sendMessage($chat_id, $text, $kbd = null) { 
    $p = ["chat_id" => $chat_id, "text" => $text, "parse_mode" => "HTML"]; 
    if ($kbd) $p["reply_markup"] = json_encode($kbd); 
    return curlPost("https://api.telegram.org/bot".BOT_TOKEN."/sendMessage", $p); 
}

function editMessageText($chat_id, $mid, $text, $kbd = null) { 
    $p = ["chat_id" => $chat_id, "message_id" => $mid, "text" => $text, "parse_mode" => "HTML"]; 
    if ($kbd) $p["reply_markup"] = json_encode($kbd); 
    return curlPost("https://api.telegram.org/bot".BOT_TOKEN."/editMessageText", $p); 
}

function answerCallbackQuery($id) { 
    curlPost("https://api.telegram.org/bot".BOT_TOKEN."/answerCallbackQuery", ["callback_query_id" => $id]); 
}

function curlPost($url, $post) { 
    $ch = curl_init($url); 
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $r = curl_exec($ch); 
    curl_close($ch); 
    return json_decode($r, true); 
}
?>
