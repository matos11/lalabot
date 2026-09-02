<?php
// bot.php - LALA BINGO Webhook Engine

// Environment Configuration
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

    $user = findExistingAccount($telegram_id, $username);
    if (!$user || empty($user['phone'])) {
        sendMessage($chat_id, "⚠️ <b>መጀመሪያ ስልክ ቁጥርዎን በማጋራት መመዝገብ አለብዎት!</b>", [
            "keyboard" => [[["text" => "📱 ስልክ ቁጥርዎን ያጋሩ (Share Contact)", "request_contact" => true]]],
            "resize_keyboard" => true,
            "one_time_keyboard" => true
        ]);
        exit;
    }
    
    if ($callback_data === "menu_dashboard") {
        clearState($telegram_id);
        editMessageText($chat_id, $message_id, getDashboardText($telegram_id), getDashboardKeyboard($telegram_id));
    } elseif ($callback_data === "menu_play") {
        showPlayMenu($chat_id, $message_id, $user);
    } elseif ($callback_data === "menu_deposit") {
        showDepositMenu($chat_id, $telegram_id, $message_id);
    } elseif ($callback_data === "menu_withdraw") {
        showWithdrawPrompt($chat_id, $telegram_id, $message_id);
    } elseif (str_starts_with($callback_data, "wdr_method_")) {
        $method = str_replace("wdr_method_", "", $callback_data);
        
        $currentState = firebaseGet(URL_STATES . $telegram_id . ".json");
        if (is_array($currentState)) {
            $currentState['method'] = $method;
            $currentState['stage'] = "waiting_wdr_details";
            firebasePut(URL_STATES . $telegram_id . ".json", $currentState);
        }

        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ሰርዝ", "callback_data" => "menu_dashboard"]]]];
        
        if ($method === "telebirr") {
            $prompt = "📲 <b>የቴሌብር አካውንት መረጃ</b>\n\nእባክዎ ተቀባይ <b>ስም እና የስልክ ቁጥር</b> በዚህ መልክ በአንድ ላይ አስገብተው ይላኩ:\n\nምሳሌ: <code>ዮሐንስ አበበ - 0912345678</code>";
        } else {
            $prompt = "🏦 <b>የኢትዮጵያ ንግድ ባንክ (CBE) መረጃ</b>\n\nእባክዎ የባንክ <b>አካውንት ቁጥር እና ሙሉ ስም</b> በዚህ መልክ በአንድ ላይ አስገብተው ይላኩ:\n\nምሳሌ: <code>1000123456789 - አስቴር ከበደ</code>";
        }
        
        editMessageText($chat_id, $message_id, $prompt, $keyboard);
    } elseif ($callback_data === "menu_balance") {
        showBalanceMenu($chat_id, $telegram_id, $message_id, $user, $username);
    } elseif ($callback_data === "menu_instructions") {
        showInstructionsMenu($chat_id, $message_id);
    } elseif ($callback_data === "menu_referral") {
        showReferralMenu($chat_id, $telegram_id, $message_id);
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
    $username = $message["from"]["username"] ?? "";
    $first_name = $message["from"]["first_name"] ?? "User";
    $last_name = $message["from"]["last_name"] ?? "";
    $text = trim($message["text"] ?? "");

    // ----------------------------------------------------
    // CASE 1: Contact Sharing -> Finalize Registration
    // ----------------------------------------------------
    if (isset($message["contact"])) {
        $phone = (string)$message["contact"]["phone_number"];
        if (!str_starts_with($phone, "+") && !str_starts_with($phone, "0")) {
            $phone = "+" . $phone;
        }
        $clean_phone = preg_replace('/[.#$[\]\/]/', '_', $phone);
        $photo_url = "https://t.me/i/userpic/320/" . (!empty($username) ? $username : $telegram_id) . ".svg";
        
        $existing = findExistingAccount($telegram_id, $username, $phone);
        $balance = $existing ? floatval($existing["balance"] ?? 10.0) : 10.0;
        $created_at = $existing["created_at"] ?? time();

        $user_payload = [
            "balance" => $balance,
            "created_at" => (int)$created_at,
            "first_name" => $first_name,
            "lastSeen" => intval(microtime(true) * 1000),
            "last_name" => $last_name,
            "phone" => $phone,
            "photo_url" => $photo_url,
            "telegram_id" => (int)$telegram_id,
            "username" => ($username === "NoUsername" ? "" : $username)
        ];
        
        firebasePut(URL_USERS . $telegram_id . ".json", $user_payload);
        firebasePut(URL_USERONE . $clean_phone . ".json", $user_payload);

        $state_data = firebaseGet(URL_STATES . $telegram_id . ".json");
        if (is_array($state_data) && !empty($state_data['referrer_id'])) {
            $ref_id = $state_data['referrer_id'];
            if (strval($ref_id) !== strval($telegram_id)) {
                $referrer = firebaseGet(URL_USERS . $ref_id . ".json");
                if ($referrer) {
                    $referrer["balance"] = floatval($referrer["balance"] ?? 0) + 5.00;
                    firebasePut(URL_USERS . $ref_id . ".json", $referrer);
                    sendMessage($ref_id, "🎁 <b>+5.00 ETB ቦነስ በ LALA BINGO ገብቶልዎታል! (ጓደኛዎ ተመዝግቧል)</b>");
                }
            }
        }
        clearState($telegram_id);
        setBotCommands(); // Register slash menu commands

        $welcome_success = "✅ <b>ምዝገባዎ በተሳካ ሁኔታ ተጠናቋል!</b>\n━━━━━━━━━━━━━━━━━━━━\n"
                         . "👤 ስም: <b>" . htmlspecialchars($first_name . ($last_name ? " " . $last_name : "")) . "</b>\n"
                         . "📱 ስልክ: <code>" . $phone . "</code>\n"
                         . "🎁 የተበረከተ ቦነስ: <b>10.00 ETB</b>\n"
                         . "💰 ጠቅላላ ቀሪ ሂሳብ: <b>" . number_format($balance, 2) . " ETB</b>\n"
                         . "━━━━━━━━━━━━━━━━━━━━";

        sendMessage($chat_id, $welcome_success, getReplyKeyboard());
        sendMessage($chat_id, getDashboardText($telegram_id), getDashboardKeyboard($telegram_id));
        exit;
    }

    // ----------------------------------------------------
    // CASE 2: /start Command Processing
    // ----------------------------------------------------
    if (str_starts_with($text, "/start")) {
        $referrer_id = null; 
        $parts = explode(" ", $text);
        if (count($parts) > 1 && is_numeric($parts[1])) { 
            $referrer_id = trim($parts[1]); 
        }

        $existingUser = findExistingAccount($telegram_id, $username);

        if ($existingUser && !empty($existingUser['phone'])) {
            clearState($telegram_id);
            setBotCommands();
            
            $existingUser["lastSeen"] = intval(microtime(true) * 1000);
            $existingUser["telegram_id"] = (int)$telegram_id;
            firebasePut(URL_USERS . $telegram_id . ".json", $existingUser);
            
            $status_notify = "✅ <b>አካውንትዎ በዳታቤዝ ውስጥ ተገኝቷል!</b>\n━━━━━━━━━━━━━━━━━━━━\n"
                           . "👤 ስም: <b>" . htmlspecialchars($existingUser['first_name'] ?? $first_name) . "</b>\n"
                           . "📱 ስልክ: <code>" . $existingUser['phone'] . "</code>\n"
                           . "💰 ቀሪ ሂሳብ: <b>" . number_format(floatval($existingUser['balance'] ?? 0), 2) . " ETB</b>\n"
                           . "━━━━━━━━━━━━━━━━━━━━";

            sendMessage($chat_id, $status_notify, getReplyKeyboard());
            sendMessage($chat_id, getDashboardText($telegram_id), getDashboardKeyboard($telegram_id));
        } else {
            $state_payload = [
                "stage" => "waiting_contact",
                "referrer_id" => $referrer_id
            ];
            firebasePut(URL_STATES . $telegram_id . ".json", $state_payload);

            $welcome_msg = "👋 <b>እንኳን ወደ LALA BINGO በደህና መጡ! 🇯🇲🎲</b>\n\n"
                         . "⚠️ <i>አካውንትዎ በዳታቤዝ ውስጥ አልተገኘም ወይም አልተመዘገበም::</i>\n\n"
                         . "🎁 አሁኑኑ ሲመዘገቡ የ <b>10 ETB</b> ነፃ የመጫወቻ ቦነስ ያገኛሉ!\n\n"
                         . "ለመመዝገብ ከታች ያለውን <b>'📱 ስልክ ቁጥርዎን ያጋሩ'</b> የሚለውን ቁልፍ ይጫኑ:";

            sendMessage($chat_id, $welcome_msg, [
                "keyboard" => [[["text" => "📱 ስልክ ቁጥርዎን ያጋሩ (Share Contact)", "request_contact" => true]]],
                "resize_keyboard" => true,
                "one_time_keyboard" => true
            ]);
        }
        exit;
    }

    // Require registered phone before parsing commands
    $existingUser = findExistingAccount($telegram_id, $username);
    if (!$existingUser || empty($existingUser['phone'])) {
        sendMessage($chat_id, "⚠️ <b>ጨዋታውን ለመጠቀም መጀመሪያ ስልክ ቁጥርዎን ማጋራት አለብዎት::</b>", [
            "keyboard" => [[["text" => "📱 ስልክ ቁጥርዎን ያጋሩ (Share Contact)", "request_contact" => true]]],
            "resize_keyboard" => true,
            "one_time_keyboard" => true
        ]);
        exit;
    }

    // ----------------------------------------------------
    // CASE 3: Slash Commands & Bottom Keyboard Handling
    // ----------------------------------------------------
    if ($text === "🌴 ተጫወት (Play)" || $text === "/play") {
        clearState($telegram_id);
        showPlayMenu($chat_id, null, $existingUser);
        exit;
    }

    if ($text === "📥 ብር አስገባ (Deposit)" || $text === "/deposit") {
        showDepositMenu($chat_id, $telegram_id, null);
        exit;
    }

    if ($text === "📤 ብር አውጣ (Withdraw)" || $text === "/withdraw") {
        showWithdrawPrompt($chat_id, $telegram_id, null);
        exit;
    }

    if ($text === "💰 ቀሪ ሂሳብ (Balance)" || $text === "/balance") {
        clearState($telegram_id);
        showBalanceMenu($chat_id, $telegram_id, null, $existingUser, $username);
        exit;
    }

    if ($text === "🔗 ጓደኛ ጋብዝ (Invite)" || $text === "/invite") {
        clearState($telegram_id);
        showReferralMenu($chat_id, $telegram_id, null);
        exit;
    }

    if ($text === "ℹ️ መመሪያ (Help)" || $text === "/help") {
        clearState($telegram_id);
        showInstructionsMenu($chat_id, null);
        exit;
    }

    if ($text === "🏠 ዋና ማውጫ (Menu)" || $text === "/menu") {
        clearState($telegram_id);
        sendMessage($chat_id, getDashboardText($telegram_id), getDashboardKeyboard($telegram_id));
        exit;
    }

    $state_data = firebaseGet(URL_STATES . $telegram_id . ".json");

    // ----------------------------------------------------
    // Deposit SMS Verification
    // ----------------------------------------------------
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

        $user = findExistingAccount($telegram_id, $username) ?? [];
        $user["balance"] = floatval($user["balance"] ?? 0) + $system_amount;
        $user["lastSeen"] = intval(microtime(true) * 1000);
        
        firebasePut(URL_USERS . $telegram_id . ".json", $user);

        if (!empty($user['phone'])) {
            $clean_phone = preg_replace('/[.#$[\]\/]/', '_', (string)$user['phone']);
            firebasePut(URL_USERONE . $clean_phone . ".json", $user);
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

    // ----------------------------------------------------
    // Withdrawal Amount Processing
    // ----------------------------------------------------
    if ($state_data === "waiting_wdr_amount" && !empty($text)) {
        $withdraw_amount = floatval($text);
        $user = findExistingAccount($telegram_id, $username);
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

    // ----------------------------------------------------
    // Withdrawal Details Confirmation
    // ----------------------------------------------------
    if (is_array($state_data) && ($state_data['stage'] ?? '') === 'waiting_wdr_details' && !empty($text)) {
        $withdraw_amount = floatval($state_data['amount']);
        $method = $state_data['method'];

        $user = findExistingAccount($telegram_id, $username);
        $current_balance = floatval($user["balance"] ?? 0);
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];

        if ($withdraw_amount > $current_balance) {
            sendMessage($chat_id, "❌ ስህተት ተከስቷል:: ቀሪ ሂሳብዎ በቂ አይደለም::", $keyboard);
            clearState($telegram_id);
            return;
        }

        $user["balance"] = $current_balance - $withdraw_amount;
        $user["lastSeen"] = intval(microtime(true) * 1000);
        
        firebasePut(URL_USERS . $telegram_id . ".json", $user);

        if (!empty($user['phone'])) {
            $clean_phone = preg_replace('/[.#$[\]\/]/', '_', (string)$user['phone']);
            firebasePut(URL_USERONE . $clean_phone . ".json", $user);
        }

        clearState($telegram_id);

        $withdrawal_id = "WDR" . time() . rand(10, 99);
        $withdrawal_payload = [
            "id" => $withdrawal_id,
            "telegram_id" => (int)$telegram_id,
            "username" => ($username === "NoUsername" ? "" : $username),
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
// VIEW & DATABASE HELPERS
// ======================================
function getReplyKeyboard() {
    return [
        "keyboard" => [
            [
                ["text" => "🌴 ተጫወት (Play)", "web_app" => ["url" => GAME_URL]],
                ["text" => "💰 ቀሪ ሂሳብ (Balance)"]
            ],
            [
                ["text" => "📥 ብር አስገባ (Deposit)"],
                ["text" => "📤 ብር አውጣ (Withdraw)"]
            ],
            [
                ["text" => "🔗 ጓደኛ ጋብዝ (Invite)"],
                ["text" => "ℹ️ መመሪያ (Help)"]
            ],
            [
                ["text" => "🏠 ዋና ማውጫ (Menu)"]
            ]
        ],
        "resize_keyboard" => true,
        "is_persistent" => true
    ];
}

function showPlayMenu($chat_id, $message_id, $user) {
    $balance = floatval($user["balance"] ?? 0);
    if ($balance <= 0) {
        $keyboard = ["inline_keyboard" => [[["text" => "💳 ብር አስገባ (Deposit)", "callback_data" => "menu_deposit"]], [["text" => "🔙 ወደ ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
        $text = "⚠️ <b>የሂሳብዎ መጠን ለጨዋታ በቂ አይደለም!</b>\n\nየአሁኑ ቀሪ ሂሳብዎ: <code>ETB " . number_format($balance, 2) . "</code>\nእባክዎ መጀመሪያ ሂሳብዎን ይሙሉ::";
    } else {
        $keyboard = ["inline_keyboard" => [[["text" => "🌴 ጀምር (LAUNCH LALA BINGO)", "web_app" => ["url" => GAME_URL]]], [["text" => "🔙 ወደ ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
        $text = "🇯🇲 <b>ወደ LALA ቢንጎ የመጫወቻ ሜዳ እንኳን በደህና መጡ!</b>\n\nእድልዎን ይፈትሹና ትልቅ ያሸንፉ! ጨዋታውን ለመጀመር 'LAUNCH' የሚለውን ቁልፍ ይጫኑ::";
    }

    if ($message_id) {
        editMessageText($chat_id, $message_id, $text, $keyboard);
    } else {
        sendMessage($chat_id, $text, $keyboard);
    }
}

function showDepositMenu($chat_id, $telegram_id, $message_id = null) {
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

    if ($message_id) {
        editMessageText($chat_id, $message_id, $deposit_text, $keyboard);
    } else {
        sendMessage($chat_id, $deposit_text, $keyboard);
    }
}

function showWithdrawPrompt($chat_id, $telegram_id, $message_id = null) {
    firebasePut(URL_STATES . $telegram_id . ".json", "waiting_wdr_amount");
    $keyboard = ["inline_keyboard" => [[["text" => "🔙 ሰርዝ", "callback_data" => "menu_dashboard"]]]];
    $text = "💰 <b>ብር ማውጫ ገጽ (Withdraw)</b>\n\nማውጣት የሚፈልጉትን የገንዘብ መጠን በቁጥር ብቻ ያስገቡ (ምሳሌ: <code>150</code>):";

    if ($message_id) {
        editMessageText($chat_id, $message_id, $text, $keyboard);
    } else {
        sendMessage($chat_id, $text, $keyboard);
    }
}

function showBalanceMenu($chat_id, $telegram_id, $message_id, $user, $username) {
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
    
    if ($message_id) {
        editMessageText($chat_id, $message_id, $history_text, $keyboard);
    } else {
        sendMessage($chat_id, $history_text, $keyboard);
    }
}

function showInstructionsMenu($chat_id, $message_id = null) {
    $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
    $info = "ℹ️ <b>የአጠቃቀም መመሪያ እና ደንቦች (LALA BINGO)</b>\n\n1. <b>ገንዘብ ለማስገባት:</b> ቴሌብር ላይ በመክፈል የትራንዛክሽን ኮዱን መላክ::\n2. <b>ገንዘብ ለማውጣት:</b> ማውጫ በመንካት የባንክ ወይም ቴሌብር መረጃ ማቅረብ::\n3. <b>ደንብ:</b> ትክክለኛ መረጃ በማስገባት ፈጣን ክፍያ ያግኙ::";

    if ($message_id) {
        editMessageText($chat_id, $message_id, $info, $keyboard);
    } else {
        sendMessage($chat_id, $info, $keyboard);
    }
}

function showReferralMenu($chat_id, $telegram_id, $message_id = null) {
    $bot_info = getBotUsername();
    $ref_link = "https://t.me/" . $bot_info . "?start=" . $telegram_id;
    $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
    $ref_text = "🔗 <b>የጓደኛ መጋበዣ ሊንክ (LALA BINGO)</b>\n\nጓደኛዎ ሲመዘገብ የ <b>5.00 ETB</b> ቦነስ ያገኛሉ!\n\n<code>" . $ref_link . "</code>";

    if ($message_id) {
        editMessageText($chat_id, $message_id, $ref_text, $keyboard);
    } else {
        sendMessage($chat_id, $ref_text, $keyboard);
    }
}

function setBotCommands() {
    $commands = [
        ["command" => "menu", "description" => "ዋና ማውጫ (Main Dashboard)"],
        ["command" => "play", "description" => "LALA BINGO ጀምር (Play Game)"],
        ["command" => "deposit", "description" => "ብር አስገባ (Deposit Money)"],
        ["command" => "withdraw", "description" => "ብር አውጣ (Withdraw Money)"],
        ["command" => "balance", "description" => "ቀሪ ሂሳብ እና ታሪክ (Account Balance)"],
        ["command" => "invite", "description" => "ጓደኛ ጋብዝ (Invite Friends)"],
        ["command" => "help", "description" => "የአጠቃቀም መመሪያ (Rules & Guide)"]
    ];
    curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/setMyCommands", ["commands" => json_encode($commands)]);
}

function findExistingAccount($telegram_id, $username = "", $phone = "") {
    $user = firebaseGet(URL_USERS . $telegram_id . ".json");
    if ($user && is_array($user)) return $user;

    if (!empty($phone)) {
        $clean_phone = preg_replace('/[.#$[\]\/]/', '_', (string)$phone);
        $userone = firebaseGet(URL_USERONE . $clean_phone . ".json");
        if ($userone && is_array($userone)) return $userone;
    }

    $allUserone = firebaseGet(URL_USERONE . ".json");
    if (is_array($allUserone)) {
        foreach ($allUserone as $record) {
            if (isset($record['telegram_id']) && intval($record['telegram_id']) === intval($telegram_id)) {
                return $record;
            }
            if (!empty($username) && $username !== "NoUsername" && !empty($record['username']) && strtolower($record['username']) === strtolower($username)) {
                return $record;
            }
        }
    }
    return null;
}

function getDashboardText($telegram_id) {
    $user = findExistingAccount($telegram_id);
    $balance = floatval($user["balance"] ?? 0.0);
    return "🇯🇲 <b>LALA BINGO ዋና ማውጫ</b>\n\n"
         . "👤 ተጫዋች: <b>" . htmlspecialchars($user['first_name'] ?? 'User') . "</b>\n"
         . "📱 ስልክ: <code>" . (!empty($user['phone']) ? $user['phone'] : 'ያልተመዘገበ ⚠️') . "</code>\n"
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    $r = curl_exec($ch); 
    curl_close($ch); 
    return json_decode($r, true); 
}
?>
