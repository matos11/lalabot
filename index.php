<?php
// bot.php - LALA BINGO Webhook Engine

// Environment Configuration
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8605292135:AAEghPf8D6fmTNHIJsRFktyIWd52B0ekSPE');
define('GAME_URL', getenv('GAME_URL') ?: 'https://lalabingobot.vercel.app/');
define('BASE_FIREBASE', rtrim(getenv('BASE_FIREBASE') ?: 'https://lalabingobot-default-rtdb.firebaseio.com/', '/') . '/');

define('URL_USERS', BASE_FIREBASE . 'users/');
define('URL_USERONE', BASE_FIREBASE . 'userone/');
define('URL_STATES', BASE_FIREBASE . 'states/');
define('URL_DEPOSITS', BASE_FIREBASE . 'deposits/');
define('URL_TRANSACTIONS', BASE_FIREBASE . 'transactions/');
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
        setBotCommands();

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
    // CASE 3: Slash Commands & Keyboard Handling
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
    // Deposit SMS / Manual Text Parser & Automatic Top-Up
    // ----------------------------------------------------
    if ($state_data === "waiting_deposit" && !empty($text)) {
        $tx_id = '';
        $parsed_amount = 0.0;
        $sender_name = '';

        // Extract 10-digit Transaction ID
        if (preg_match('/\b([A-Z0-9]{10})\b/', strtoupper($text), $matches)) { 
            $tx_id = $matches[1]; 
        } else { 
            $tx_id = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($text, 0, 10))); 
        }

        if (strlen($tx_id) !== 10) {
            sendMessage($chat_id, "❌ <b>የተሳሳተ የትራንዛክሽን ቁጥር!</b>\n\nእባክዎ ባለ 10 ዲጂት የቴሌብር ማረጋገጫ ቁጥር ወይም ሙሉውን SMS በትክክል ያስገቡ::", ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]]);
            exit;
        }

        // Extract Amount from SMS text
        if (preg_match('/(?:ETB|ብር)\s*([0-9,]+(?:\.\d{1,2})?)/ui', $text, $amt_matches) || 
            preg_match('/([0-9,]+(?:\.\d{1,2})?)\s*(?:ETB|ብር)/ui', $text, $amt_matches)) {
            $parsed_amount = floatval(str_replace(',', '', $amt_matches[1]));
        }

        // Extract Sender Name
        if (preg_match('/(?:from|ከ|የተላከው ከ|Lekebal)\s*[:\-]?\s*([A-Za-z\s]{3,30})/ui', $text, $name_matches)) {
            $sender_name = trim($name_matches[1]);
        } else {
            $sender_name = $first_name . ($last_name ? " " . $last_name : "");
        }

        // 1. Check if transaction already used in deposits/transactions table
        $existing_tx = firebaseGet(URL_TRANSACTIONS . $tx_id . ".json") ?: firebaseGet(URL_DEPOSITS . $tx_id . ".json");
        if ($existing_tx && in_array($existing_tx["status"] ?? "", ["processed", "approved"], true)) {
            sendMessage($chat_id, "❌ ይህ የትራንዛክሽን ቁጥር (<code>" . $tx_id . "</code>) ቀድሞውኑ ጥቅም ላይ ውሏል! አንድ ኮድ ለአንድ ጊዜ ብቻ ነው የሚያገለግለው::", ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]]);
            clearState($telegram_id);
            exit;
        }

        $system_amount = $parsed_amount;
        if ($system_amount <= 0 && $existing_tx && isset($existing_tx['amount'])) {
            $system_amount = floatval($existing_tx['amount']);
        }

        if ($system_amount <= 0) {
            sendMessage($chat_id, "❌ <b>የገንዘብ መጠን ማግኘት አልተቻለም!</b>\n\nእባክዎ ትክክለኛውን የቴሌብር SMS ሙሉውን ኮፒ አድርገው ይላኩ።", ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]]);
            exit;
        }

        // 2. Automatically Credit User Balance
        $user = findExistingAccount($telegram_id, $username);
        $current_balance = floatval($user["balance"] ?? 0);
        $new_balance = $current_balance + $system_amount;

        $user["balance"] = $new_balance;
        $user["lastSeen"] = intval(microtime(true) * 1000);
        
        firebasePut(URL_USERS . $telegram_id . ".json", $user);
        if (!empty($user['phone'])) {
            firebasePut(URL_USERONE . preg_replace('/[.#$[\]\/]/', '_', (string)$user['phone']) . ".json", $user);
        }

        // 3. Mark transaction as approved
        $tx_payload = [
            "id" => $tx_id,
            "telegram_id" => (int)$telegram_id,
            "username" => ($username === "NoUsername" ? "" : $username),
            "claimed_by" => ($username === "NoUsername" ? $first_name : $username),
            "sender_name" => $sender_name,
            "amount" => $system_amount,
            "raw_sms" => $text,
            "status" => "approved",
            "timestamp" => time() * 1000
        ];
        
        firebasePut(URL_TRANSACTIONS . $tx_id . ".json", $tx_payload);
        firebasePut(URL_DEPOSITS . $tx_id . ".json", $tx_payload);
        clearState($telegram_id);
        
        $success_msg = "✅ <b>ክፍያዎ በትክክል ተረጋግጦ ቀሪ ሂሳብዎ ገብቷል!</b>\n━━━━━━━━━━━━━━━━━━━━\n"
                     . "🆔 የትራንዛክሽን ID: <code>" . $tx_id . "</code>\n"
                     . "💵 የገባው መጠን: <b>" . number_format($system_amount, 2) . " ETB</b>\n"
                     . "💰 አዲስ ቀሪ ሂሳብ: <b>" . number_format($new_balance, 2) . " ETB</b>\n"
                     . "━━━━━━━━━━━━━━━━━━━━";
                     
        sendMessage($chat_id, $success_msg, [
            "inline_keyboard" => [
                [["text" => "🌴 አሁኑኑ ተጫወት", "callback_data" => "menu_play"]], 
                [["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]
            ]
        ]);
        exit;
    }

    // ----------------------------------------------------
    // Withdrawal Processing
    // ----------------------------------------------------
    if ($state_data === "waiting_wdr_amount" && !empty($text)) {
        $withdraw_amount = floatval($text);
        $user = findExistingAccount($telegram_id, $username);
        $current_balance = floatval($user["balance"] ?? 0);
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];

        if ($withdraw_amount <= 0 || $withdraw_amount > $current_balance) {
            sendMessage($chat_id, "❌ <b>የተሳሳተ የገንዘብ መጠን!</b> በቂ ቀሪ ሂሳብ የለዎትም::", $keyboard);
            clearState($telegram_id);
            exit;
        }

        firebasePut(URL_STATES . $telegram_id . ".json", ["stage" => "waiting_wdr_method", "amount" => $withdraw_amount]);

        $method_keyboard = [
            "inline_keyboard" => [
                [["text" => "📲 Telebirr", "callback_data" => "wdr_method_telebirr"], ["text" => "🏦 CBE", "callback_data" => "wdr_method_cbe"]],
                [["text" => "🔙 ሰርዝ", "callback_data" => "menu_dashboard"]]
            ]
        ];

        sendMessage($chat_id, "💳 <b>የመቀበያ ዘዴ ይምረጡ</b>\n\nመጠን: <code>" . number_format($withdraw_amount, 2) . " ETB</code>", $method_keyboard);
        exit;
    }

    if (is_array($state_data) && ($state_data['stage'] ?? '') === 'waiting_wdr_details' && !empty($text)) {
        $withdraw_amount = floatval($state_data['amount']);
        $method = $state_data['method'];
        $user = findExistingAccount($telegram_id, $username);
        $current_balance = floatval($user["balance"] ?? 0);
        $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];

        if ($withdraw_amount > $current_balance) {
            sendMessage($chat_id, "❌ ስህተት ተከስቷል:: ቀሪ ሂሳብዎ በቂ አይደለም::", $keyboard);
            clearState($telegram_id);
            exit;
        }

        $user["balance"] = $current_balance - $withdraw_amount;
        $user["lastSeen"] = intval(microtime(true) * 1000);
        firebasePut(URL_USERS . $telegram_id . ".json", $user);
        if (!empty($user['phone'])) {
            firebasePut(URL_USERONE . preg_replace('/[.#$[\]\/]/', '_', (string)$user['phone']) . ".json", $user);
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

        sendMessage($chat_id, "✅ <b>የማውጫ ጥያቄዎ በተሳካ ሁኔታ ቀርቧል!</b>\n\n💵 መጠን: <code>ETB " . number_format($withdraw_amount, 2) . "</code>", $keyboard);
        exit;
    }
}

// Helpers
function getReplyKeyboard() {
    return [
        "keyboard" => [
            [["text" => "🌴 ተጫወት (Play)", "web_app" => ["url" => GAME_URL]], ["text" => "💰 ቀሪ ሂሳብ (Balance)"]],
            [["text" => "📥 ብር አስገባ (Deposit)"], ["text" => "📤 ብር አውጣ (Withdraw)"]],
            [["text" => "🔗 ጓደኛ ጋብዝ (Invite)"], ["text" => "ℹ️ መመሪያ (Help)"]],
            [["text" => "🏠 ዋና ማውጫ (Menu)"]]
        ],
        "resize_keyboard" => true,
        "is_persistent" => true
    ];
}
function showPlayMenu($chat_id, $message_id, $user) {
    $balance = floatval($user["balance"] ?? 0);
    $text = $balance <= 0 ? "⚠️ የሂሳብዎ መጠን ለጨዋታ በቂ አይደለም!" : "🇯🇲 ወደ LALA ቢንጎ የመጫወቻ ሜዳ እንኳን በደህና መጡ!";
    $keyboard = ["inline_keyboard" => $balance <= 0 ? [[["text" => "💳 ብር አስገባ", "callback_data" => "menu_deposit"]]] : [[["text" => "🌴 LAUNCH", "web_app" => ["url" => GAME_URL]]]]];
    $message_id ? editMessageText($chat_id, $message_id, $text, $keyboard) : sendMessage($chat_id, $text, $keyboard);
}
function showDepositMenu($chat_id, $telegram_id, $message_id = null) {
    firebasePut(URL_STATES . $telegram_id . ".json", "waiting_deposit");
    $text = "━━━━━━━━━━ Telebirr ━━━━━━━━━\n\nPay by ቴሌብር 📲: <b>0979652325</b>\nName 👤 <b>YISAK</b>\n\n• ከከፈሉ በኋላ የቴሌብር SMS ጽሁፍ ወይም 10 ዲጂት የትራንዛክሽን ID ለቦቱ ይላኩ፡፡";
    $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
    $message_id ? editMessageText($chat_id, $message_id, $text, $keyboard) : sendMessage($chat_id, $text, $keyboard);
}
function showWithdrawPrompt($chat_id, $telegram_id, $message_id = null) {
    firebasePut(URL_STATES . $telegram_id . ".json", "waiting_wdr_amount");
    $text = "💰 <b>ብር ማውጫ ገጽ (Withdraw)</b>\n\nማውጣት የሚፈልጉትን መጠን በቁጥር ብቻ ያስገቡ:";
    $keyboard = ["inline_keyboard" => [[["text" => "🔙 ሰርዝ", "callback_data" => "menu_dashboard"]]]];
    $message_id ? editMessageText($chat_id, $message_id, $text, $keyboard) : sendMessage($chat_id, $text, $keyboard);
}
function showBalanceMenu($chat_id, $telegram_id, $message_id, $user, $username) {
    $text = "💳 <b>ቀሪ ሂሳብ</b>\n\n💰 <b>" . number_format(floatval($user["balance"] ?? 0), 2) . " ETB</b>";
    $keyboard = ["inline_keyboard" => [[["text" => "📥 ብር አስገባ", "callback_data" => "menu_deposit"], ["text" => "📤 ብር አውጣ", "callback_data" => "menu_withdraw"]], [["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
    $message_id ? editMessageText($chat_id, $message_id, $text, $keyboard) : sendMessage($chat_id, $text, $keyboard);
}
function showInstructionsMenu($chat_id, $message_id = null) {
    $text = "ℹ️ <b>መመሪያ</b>\n\nቴሌብር በመክፈል ኮዱን በመላክ መሙላት ይችላሉ::";
    $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
    $message_id ? editMessageText($chat_id, $message_id, $text, $keyboard) : sendMessage($chat_id, $text, $keyboard);
}
function showReferralMenu($chat_id, $telegram_id, $message_id = null) {
    $ref_link = "https://t.me/" . getBotUsername() . "?start=" . $telegram_id;
    $text = "🔗 <b>የመጋበዣ ሊንክ</b>\n\n<code>" . $ref_link . "</code>";
    $keyboard = ["inline_keyboard" => [[["text" => "🔙 ዋና ማውጫ", "callback_data" => "menu_dashboard"]]]];
    $message_id ? editMessageText($chat_id, $message_id, $text, $keyboard) : sendMessage($chat_id, $text, $keyboard);
}
function setBotCommands() {
    $commands = [["command" => "menu", "description" => "ዋና ማውጫ"], ["command" => "play", "description" => "ተጫወት"], ["command" => "deposit", "description" => "ብር አስገባ"], ["command" => "withdraw", "description" => "ብር አውጣ"]];
    curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/setMyCommands", ["commands" => json_encode($commands)]);
}
function findExistingAccount($telegram_id, $username = "", $phone = "") {
    $user = firebaseGet(URL_USERS . $telegram_id . ".json");
    if ($user && is_array($user)) return $user;
    if (!empty($phone)) {
        $userone = firebaseGet(URL_USERONE . preg_replace('/[.#$[\]\/]/', '_', (string)$phone) . ".json");
        if ($userone && is_array($userone)) return $userone;
    }
    return null;
}
function getDashboardText($telegram_id) {
    $user = findExistingAccount($telegram_id);
    return "🇯🇲 <b>LALA BINGO ዋና ማውጫ</b>\n\n👤 <b>" . htmlspecialchars($user['first_name'] ?? 'User') . "</b>\n💰 ቀሪ ሂሳብ: <b>ETB " . number_format(floatval($user["balance"] ?? 0), 2) . "</b>";
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
    $res = curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/getMe", []);
    return $res['result']['username'] ?? 'lalabingobot';
}
function firebasePut($url, $data) {
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch); curl_close($ch);
}
function firebaseGet($url) {
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch); curl_close($ch); return $res ? json_decode($res, true) : null;
}
function clearState($telegram_id) {
    $ch = curl_init(URL_STATES . $telegram_id . ".json"); curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE"); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch); curl_close($ch);
}
function sendMessage($chat_id, $text, $kbd = null) {
    $p = ["chat_id" => $chat_id, "text" => $text, "parse_mode" => "HTML"]; if ($kbd) $p["reply_markup"] = json_encode($kbd);
    return curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage", $p);
}
function editMessageText($chat_id, $mid, $text, $kbd = null) {
    $p = ["chat_id" => $chat_id, "message_id" => $mid, "text" => $text, "parse_mode" => "HTML"]; if ($kbd) $p["reply_markup"] = json_encode($kbd);
    return curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText", $p);
}
function answerCallbackQuery($id) {
    curlPost("https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery", ["callback_query_id" => $id]);
}
function curlPost($url, $post) {
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $r = curl_exec($ch); curl_close($ch); return json_decode($r, true);
}
?>
