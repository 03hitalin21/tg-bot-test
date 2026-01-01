<?php declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tehran');

const ADMIN_ID = 7662218600;
const ADMIN_USERNAME = 'saeedsalehiz';
const REQUIRED_CHANNEL = '@HVPN_Ch';

$botToken = getenv('BOT_TOKEN') ?: '8353715306:AAE8txJqcGD8Lc7mRt___o7EDnfdKtdo77g';
if ($botToken === '') {
    http_response_code(500);
    echo 'BOT_TOKEN را در متغیر محیطی تنظیم کن.';
    exit;
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $needleLength = strlen($needle);
        return substr($haystack, -$needleLength) === $needle;
    }
}


$incoming = file_get_contents('php://input');
if (!$incoming) {
    exit;
}
$update = json_decode($incoming, true);
if (!$update) {
    exit;
}

$bot = new ShopBot($botToken);
$bot->handle($update);

class ShopBot
{
    private TelegramClient $telegram;
    private PDO $db;

    public function __construct(string $token)
    {
        $this->telegram = new TelegramClient($token);
        $this->db = new PDO('sqlite:' . __DIR__ . '/tbot.sqlite');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON;');
        $this->initializeSchema();
    }

    public function handle(array $update): void
    {
        try {
            if (isset($update['message'])) {
                $user = $this->ensureUser($update['message']['from']);
                $this->handleMessage($user, $update['message']);
            } elseif (isset($update['callback_query'])) {
                $user = $this->ensureUser($update['callback_query']['from']);
                $this->handleCallback($user, $update['callback_query']);
            }
        } catch (Throwable $e) {
            $text = "خطای غیرمنتظره رخ داد.\n" . $e->getMessage();
            $this->telegram->sendMessage(ADMIN_ID, $text);
        }
    }

    private function initializeSchema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS users(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id INTEGER UNIQUE,
            first_name TEXT,
            username TEXT,
            referral_code TEXT UNIQUE,
            referred_by TEXT,
            wallet_balance REAL DEFAULT 0,
            wallet_id TEXT UNIQUE,
            last_trial_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS settings(
            key TEXT PRIMARY KEY,
            value TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS sections(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE,
            label TEXT NOT NULL,
            type TEXT NOT NULL,
            parent_name TEXT,
            sort_order REAL DEFAULT 100,
            created_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS plan_options(
            id TEXT PRIMARY KEY,
            parent_name TEXT NOT NULL,
            label TEXT NOT NULL,
            description TEXT,
            price REAL DEFAULT 0,
            kind TEXT DEFAULT "paid",
            created_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS orders(
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            plan_id TEXT NOT NULL,
            plan_label TEXT,
            price REAL,
            final_price REAL,
            type TEXT,
            status TEXT,
            discount_code TEXT,
            meta TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS topups(
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            amount REAL,
            status TEXT,
            receipt_file_id TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS promo_codes(
            code TEXT PRIMARY KEY,
            kind TEXT,
            value REAL,
            max_uses INTEGER,
            max_per_user INTEGER DEFAULT 1,
            expires_at TEXT,
            total_used INTEGER DEFAULT 0,
            created_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS promo_usages(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            promo_code TEXT,
            user_id INTEGER,
            order_id TEXT,
            used_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS point_transactions(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            delta REAL NOT NULL,
            reason TEXT,
            meta TEXT,
            created_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS guide_images(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id TEXT,
            media_type TEXT DEFAULT "photo",
            caption TEXT,
            created_at TEXT
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS user_states(
            user_id INTEGER PRIMARY KEY,
            state TEXT,
            payload TEXT,
            updated_at TEXT
        )');

        try {
            $this->db->exec('ALTER TABLE guide_images ADD COLUMN media_type TEXT DEFAULT "photo"');
        } catch (Throwable $e) {
        }

        try {
            $this->db->exec('ALTER TABLE sections ADD COLUMN sort_order REAL DEFAULT 100');
        } catch (Throwable $e) {
        }

        try {
            $this->db->exec('ALTER TABLE users ADD COLUMN points_balance REAL DEFAULT 0');
        } catch (Throwable $e) {
        }

        try {
            $this->db->exec('ALTER TABLE plan_options ADD COLUMN points_reward REAL DEFAULT 0');
        } catch (Throwable $e) {
        }

        try {
            $this->db->exec('ALTER TABLE users ADD COLUMN last_trial_at TEXT');
        } catch (Throwable $e) {
        }

        $defaults = [
            'welcome_text' => "سلام! \nبه هه‌لکار / Helkar خوش اومدی 🌐🔥\nبا ما اینترنت پرسرعت و پایدار داشته باش.",
            'subsections_menu_text' => 'یکی از بخش‌های زیر را انتخاب کن:',
            'plan_options_text' => 'پلن مورد نظرت را انتخاب کن:',
            'payment_text' => 'مبلغ را کارت‌به‌کارت کن و رسید را برای ما بفرست. بعد از تایید، کیف پولت شارژ می‌شود.',
            'guide_text' => 'در این بخش آموزش‌های متنی/تصویری قرار می‌گیرد.',
            'support_text' => 'ارتباط با پشتیبانی: @saeedsalehiz',
            'increase_money_label' => 'افزایش موجودی',
            'increase_money_enabled' => '0',
            'referral_percent' => '10',
            'referral_section_label' => '🎁 کد دعوت',
            'myplans_section_label' => '📦 سرویس‌های من',
            'wallet_section_label' => '💳 کیف پول من',
            'guide_section_label' => '📘 راهنما',
            'support_section_label' => '☎️ پشتیبانی',
            'points_section_label' => '⭐ امتیازها',
            'points_guide_text' => 'در این بخش امتیازها و قوانین تبدیل آن‌ها نمایش داده می‌شود.',
            'points_conversion_enabled' => '0',
            'points_conversion_label' => '♻️ تبدیل امتیاز',
            'points_convert_points_unit' => '1',
            'points_convert_amount_unit' => '100',
            'topup_points_amount_unit' => '100',
            'topup_points_point_unit' => '1',
            'referral_inviter_points' => '0',
            'referral_new_user_points' => '0',
            'points_transfer_enabled' => '1',
            'trial_enabled' => '1',
            'trial_section_label' => '🎯 تست رایگان',
            'trial_info_text' => "برای آشنایی با سرویس می‌توانی یک بار تست رایگان دریافت کنی.\nبعد از ثبت درخواست، پشتیبانی کانفیگ اختصاصی را می‌فرستد.",
            'trial_cooldown_days' => '180',
            'trial_plan_label' => 'تست رایگان'
        ];

        foreach ($defaults as $key => $value) {
            $this->ensureSetting($key, $value);
        }

        $this->ensureDefaultSections();
    }

    private function ensureDefaultSections(): void
    {
        $map = [
            ['wallet', $this->getSetting('wallet_section_label', '💳 کیف پول من'), 'wallet', null, 10],
            ['myplans', $this->getSetting('myplans_section_label', '📦 سرویس‌های من'), 'myplans', null, 20],
            ['referral', $this->getSetting('referral_section_label', '🎁 کد دعوت'), 'referral', null, 30],
            ['support', $this->getSetting('support_section_label', '☎️ پشتیبانی'), 'support', null, 40],
            ['guide', $this->getSetting('guide_section_label', '📘 راهنما'), 'guide', null, 50],
            ['points', $this->getSetting('points_section_label', '⭐ امتیازها'), 'points', null, 60],
        ];

        if ($this->trialEnabled()) {
            $map[] = ['freetrial', $this->getSetting('trial_section_label', '🎯 تست رایگان'), 'trial_root', null, 70];
        } else {
            $this->db->prepare('DELETE FROM sections WHERE name="freetrial" OR parent_name="freetrial"')->execute();
        }

        foreach ($map as [$name, $label, $type, $parent, $order]) {
            if (!$this->getSection($name)) {
                $this->insertSection($name, $label, $type, $parent, $order);
            } else {
                $this->db->prepare('UPDATE sections SET sort_order=:ord WHERE name=:name')
                    ->execute(['ord' => $order, 'name' => $name]);
            }
        }
    }

    private function ensureSetting(string $key, string $value): void
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO settings(key,value) VALUES(:k,:v)');
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    private function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key=:k');
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->db->prepare('INSERT INTO settings(key,value) VALUES(:k,:v)
            ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    private function ensureUser(array $from): array
    {
        $chatId = (int)$from['id'];
        $stmt = $this->db->prepare('SELECT * FROM users WHERE chat_id=:chat');
        $stmt->execute(['chat' => $chatId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $now = date('c');

        if (!$user) {
            $wallet = $this->generateWalletId();
            $refCode = $this->generateReferralCode();
            $insert = $this->db->prepare('INSERT INTO users(chat_id,first_name,username,referral_code,wallet_id,last_trial_at,created_at,updated_at)
                VALUES(:chat,:first,:username,:ref,:wallet,NULL,:c,:u)');
            $insert->execute([
                'chat' => $chatId,
                'first' => $from['first_name'] ?? '',
                'username' => $from['username'] ?? '',
                'ref' => $refCode,
                'wallet' => $wallet,
                'c' => $now,
                'u' => $now,
            ]);
            $stmt->execute(['chat' => $chatId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $update = $this->db->prepare('UPDATE users SET first_name=:first, username=:username, updated_at=:u WHERE id=:id');
            $update->execute([
                'first' => $from['first_name'] ?? '',
                'username' => $from['username'] ?? '',
                'u' => $now,
                'id' => $user['id'],
            ]);
            $stmt->execute(['chat' => $chatId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $user ?: [];
    }

    private function generateWalletId(): string
    {
        do {
            $id = 'WLT' . strtoupper(bin2hex(random_bytes(4)));
            $exists = $this->db->query("SELECT 1 FROM users WHERE wallet_id='{$id}'")->fetch();
        } while ($exists);
        return $id;
    }

    private function generateReferralCode(): string
    {
        do {
            $code = 'REF' . strtoupper(bin2hex(random_bytes(3)));
            $exists = $this->db->query("SELECT 1 FROM users WHERE referral_code='{$code}'")->fetch();
        } while ($exists);
        return $code;
    }

    private function slug(string $value): string
    {
        $value = preg_replace('/[^a-z0-9_]+/i', '_', trim($value));
        return strtolower(trim($value, '_'));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function ensureChannelMembershipOrPrompt(int $chatId): bool
    {
        if ($this->hasJoinedRequiredChannel($chatId)) {
            return true;
        }
        $this->sendJoinChannelPrompt($chatId);
        return false;
    }

    private function hasJoinedRequiredChannel(int $chatId): bool
    {
        if (REQUIRED_CHANNEL === '') {
            return true;
        }
        $member = $this->telegram->getChatMember(REQUIRED_CHANNEL, $chatId);
        if (!$member) {
            return false;
        }
        $status = $member['status'] ?? '';
        if (in_array($status, ['creator', 'administrator', 'member'], true)) {
            return true;
        }
        if ($status === 'restricted' && !empty($member['is_member'])) {
            return true;
        }
        return false;
    }

    private function sendJoinChannelPrompt(int $chatId): void
    {
        if (REQUIRED_CHANNEL === '') {
            return;
        }
        $keyboard = [
            [
                [
                    'text' => 'عضویت در کانال',
                    'url' => 'https://t.me/' . ltrim(REQUIRED_CHANNEL, '@'),
                ],
            ],
            [
                [
                    'text' => 'بررسی عضویت ✅',
                    'callback_data' => 'check_join',
                ],
            ],
        ];
        $text = "برای استفاده از ربات ابتدا باید عضو کانال ما شوی:\n"
            . REQUIRED_CHANNEL . "\nبعد از عضویت روی دکمه بررسی عضویت ✅ بزن.";
        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function trialEnabled(): bool
    {
        return $this->getSetting('trial_enabled', '1') === '1';
    }

    private function getTrialCooldownDays(): int
    {
        $days = (int)$this->getSetting('trial_cooldown_days', '180');
        return $days >= 0 ? $days : 0;
    }

    private function sendTrialOffer(int $chatId, array $user): void
    {
        if (!$this->trialEnabled()) {
            $this->telegram->sendMessage($chatId, 'در حال حاضر تست رایگان فعال نیست.');
            return;
        }
        $info = $this->getSetting('trial_info_text', 'برای دریافت تست رایگان روی دکمه زیر بزن.');
        $cooldown = $this->getTrialCooldownDays();
        if ($cooldown > 0) {
            $info .= "\n\n(هر " . $cooldown . " روز یکبار امکان دریافت تست وجود دارد.)";
        }
        if (!empty($user['last_trial_at'])) {
            $last = strtotime($user['last_trial_at']);
            if ($last) {
                $info .= "\nآخرین دریافت تست: " . date('Y-m-d', $last);
            }
        }
        $keyboard = [
            [
                ['text' => 'درخواست تست رایگان ✅', 'callback_data' => 'trial_request'],
            ],
        ];
        $this->telegram->sendMessage($chatId, $info, [
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function handleTrialRequest(array $user, int $chatId): void
    {
        if (!$this->trialEnabled()) {
            $this->telegram->sendMessage($chatId, 'امکان ثبت تست رایگان غیرفعال است.');
            return;
        }
        if ($this->userHasPendingTrialOrder((int)$user['id'])) {
            $this->telegram->sendMessage($chatId, 'درخواست تست قبلی تو هنوز بررسی نشده است.');
            return;
        }
        $cooldownDays = $this->getTrialCooldownDays();
        if ($cooldownDays > 0 && !empty($user['last_trial_at'])) {
            $last = strtotime($user['last_trial_at']);
            if ($last) {
                $nextAllowed = $last + ($cooldownDays * 86400);
                if ($nextAllowed > time()) {
                    $this->telegram->sendMessage($chatId, 'درخواست تست جدید بعد از تاریخ ' . date('Y-m-d H:i', $nextAllowed) . ' امکان‌پذیر است.');
                    return;
                }
            }
        }
        $orderId = $this->createOrderId();
        $planLabel = $this->getSetting('trial_plan_label', 'تست رایگان');
        $now = date('c');
        $this->db->prepare('INSERT INTO orders(id,user_id,plan_id,plan_label,price,final_price,type,status,created_at,updated_at)
            VALUES(:id,:user,:plan,:label,0,0,"trial","pending_admin",:c,:c)')
            ->execute([
                'id' => $orderId,
                'user' => $user['id'],
                'plan' => 'trial_general',
                'label' => $planLabel,
                'c' => $now,
            ]);
        $this->telegram->sendMessage($chatId, "درخواست تست رایگان ثبت شد ✅\nشناسه سفارش: {$orderId}\nپس از بررسی پشتیبانی، کانفیگ اختصاصی برایت ارسال می‌شود.");
        $adminText = "درخواست تست رایگان\n"
            . "Order: {$orderId}\n"
            . "کاربر: {$user['first_name']} (@{$user['username']})\n"
            . "ChatID: {$user['chat_id']}\n"
            . "پلن: {$planLabel}\n"
            . "برای ارسال کانفیگ از /deliverconfig یا /deliverconfigfile استفاده کن.";
        $this->notifyAdmin($adminText);
    }

    private function userHasPendingTrialOrder(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM orders WHERE user_id=:u AND type="trial" AND status IN ("pending_admin","awaiting_config")');
        $stmt->execute(['u' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function handleMessage(array $user, array $message): void
    {
        $chatId = (int)$message['chat']['id'];
        $text = trim($message['text'] ?? '');

        if (!$this->ensureChannelMembershipOrPrompt($chatId)) {
            return;
        }

        if ($text !== '' && str_starts_with($text, '/')) {
            $this->handleCommand($user, $text, $message);
            return;
        }

        if ($this->isAdmin($user) && $this->messageContainsMedia($message)) {
            $fileId = $this->extractFileId($message);
            if ($fileId) {
                $this->telegram->sendMessage($chatId, "file_id:\n<code>{$fileId}</code>");
            } else {
                $this->telegram->sendMessage($chatId, 'فایل شناسایی نشد.');
            }
            return;
        }

        $state = $this->getUserState((int)$user['id']);
        if ($state) {
            $this->handleStatefulMessage($user, $message, $state);
            return;
        }

        $this->telegram->sendMessage($chatId, 'از منو استفاده کن یا /start بزن.');
    }

    private function isAdmin(array $user): bool
    {
        if ((int)$user['chat_id'] === ADMIN_ID) {
            return true;
        }
        $username = $user['username'] ?? '';
        return $username && strtolower($username) === strtolower(ADMIN_USERNAME);
    }

    private function handleCommand(array $user, string $text, array $message): void
    {
        $chatId = (int)$message['chat']['id'];
        $command = strtolower(strtok($text, ' '));
        $argsText = trim(substr($text, strlen($command)));

        if ($command === '/cancel') {
            $this->clearUserState((int)$user['id']);
            $this->telegram->sendMessage($chatId, 'فرآیند قبلی لغو شد.');
            return;
        }

        if ($command === '/start') {
            $payload = trim(substr($text, 6));
            if ($payload !== '') {
                $this->handleStartPayload($user, $payload);
            }
            $this->sendSectionsMenu($chatId, $user);
            return;
        }

        if ($command === '/status') {
            if (!$this->isAdmin($user)) {
                $this->telegram->sendMessage($chatId, 'این دستور فقط مخصوص ادمین است.');
                return;
            }
            $this->sendStatusReport();
            return;
        }

        if ($command === '/buy') {
            $this->sendPaidSectionsShortcut($chatId);
            return;
        }

        if ($command === '/mywallet') {
            $this->sendWalletOverview($user);
            return;
        }

        if ($command === '/charge') {
            $this->promptTopup($user);
            return;
        }

        if ($command === '/myservices') {
            $this->sendMyPlans($user);
            return;
        }

        if ($command === '/referral') {
            $this->promptReferralInput($user);
            return;
        }

        if ($command === '/support') {
            $this->sendSupportInfo($user);
            return;
        }

        if ($command === '/guide') {
            $this->sendGuideSection($user);
            return;
        }

        if ($command === '/points') {
            $this->sendPointsSection($user);
            return;
        }

        if ($command === '/convertpoints') {
            $this->promptPointsConversion($user);
            return;
        }

        if ($command === '/sendpoints') {
            $this->promptPointsTransfer($user);
            return;
        }

        if ($command === '/freetrial') {
            if ($this->trialEnabled()) {
                $this->sendTrialOffer($chatId, $user);
            } else {
                $this->telegram->sendMessage($chatId, 'تست رایگان فعلاً فعال نیست.');
            }
            return;
        }

        if ($command === '/sendmassage') {
            if (!$this->isAdmin($user)) {
                return;
            }
            $parts = $this->parseArgs($argsText);
            if (count($parts) < 2) {
                $this->telegram->sendMessage($chatId, 'فرمت: /sendmassage <chat_id> "پیام"');
                return;
            }
            $target = (int)$parts[0];
            $content = $parts[1];
            $this->telegram->sendMessage($target, $content);
            $this->telegram->sendMessage($chatId, 'پیام ارسال شد.');
            return;
        }

        if (!$this->isAdmin($user)) {
            $this->telegram->sendMessage($chatId, 'دستور ناشناخته. از منو استفاده کن.');
            return;
        }

        $this->handleAdminCommand($command, $argsText, $chatId);
    }

    private function handleStartPayload(array $user, string $payload): void
    {
        if (stripos($payload, 'ref') === 0) {
            $code = substr($payload, 3);
            if ($code !== '') {
                $this->handleReferralCodeSubmission($user, strtoupper($code));
            }
        }
    }

    private function parseArgs(string $text): array
    {
        if ($text === '') {
            return [];
        }
        preg_match_all('/"([^"]+)"|(\S+)/u', $text, $matches);
        $parts = [];
        foreach ($matches[0] as $idx => $part) {
            $parts[] = $matches[1][$idx] !== '' ? $matches[1][$idx] : $matches[2][$idx];
        }
        return $parts;
    }

    private function handleAdminCommand(string $command, string $argsText, int $chatId): void
    {
        $args = $this->parseArgs($argsText);
        switch ($command) {
            case '/setwelcomemessage':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن جدید را بعد از دستور بنویس.');
                    return;
                }
                $this->setSetting('welcome_text', $argsText);
                $this->telegram->sendMessage($chatId, 'پیام خوشامد ذخیره شد.');
                return;

            case '/addpaidplansection':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /addpaidplansection <name> "<label>"');
                    return;
                }
                $name = $this->slug($args[0]);
                $label = $args[1];
                $ok = $this->insertSection($name, $label, 'paid_root');
                $this->telegram->sendMessage($chatId, $ok ? 'بخش با موفقیت ایجاد شد.' : 'نام تکراری است.');
                return;

            case '/updatepaidplansectionlabel':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /updatepaidplansectionlabel <name> "<label>"');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE sections SET label=:l WHERE name=:n AND type="paid_root"');
                $stmt->execute(['l' => $args[1], 'n' => $args[0]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'برچسب بروزرسانی شد.' : 'بخشی با این نام نیست.');
                return;

            case '/updatepaidplansectionname':
            case '/updatesectionname':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: '.$command.' <old_name> <new_name>');
                    return;
                }
                $updated = $this->renameSection($args[0], $this->slug($args[1]));
                $this->telegram->sendMessage($chatId, $updated ? 'نام بروزرسانی شد.' : 'بخشی با این نام موجود نیست.');
                return;

            case '/updatesectionlabel':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /updatesectionlabel <name> "<label>"');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE sections SET label=:l WHERE name=:n');
                $stmt->execute(['l' => $args[1], 'n' => $args[0]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'برچسب تغییر کرد.' : 'بخشی با این نام نیست.');
                return;

            case '/addpaidplansubsection':
                if (count($args) < 3) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /addpaidplansubsection <name> <section_name> "<label>"');
                    return;
                }
                $parent = $this->getSection($args[1]);
                if (!$parent || $parent['type'] !== 'paid_root') {
                    $this->telegram->sendMessage($chatId, 'بخش اصلی پیدا نشد.');
                    return;
                }
                $ok = $this->insertSection($this->slug($args[0]), $args[2], 'paid_subsection', $parent['name']);
                $this->telegram->sendMessage($chatId, $ok ? 'زیر‌بخش اضافه شد.' : 'نام تکراری است.');
                return;

            case '/updatepaidplansubsectionlabel':
                if (count($args) < 3) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /updatepaidplansubsectionlabel <name> <section_name> "<label>"');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE sections SET label=:l WHERE name=:n AND parent_name=:p');
                $stmt->execute(['l' => $args[2], 'n' => $args[0], 'p' => $args[1]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'برچسب بروزرسانی شد.' : 'زیر‌بخش پیدا نشد.');
                return;

            case '/updatepaidplansubsectionname':
                if (count($args) < 3) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /updatepaidplansubsectionname <name> <section_name> <new_name>');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE sections SET name=:new WHERE name=:old AND parent_name=:parent');
                $stmt->execute(['new' => $this->slug($args[2]), 'old' => $args[0], 'parent' => $args[1]]);
                if ($stmt->rowCount()) {
                    $this->db->prepare('UPDATE plan_options SET parent_name=:new WHERE parent_name=:old')
                        ->execute(['new' => $this->slug($args[2]), 'old' => $args[0]]);
                }
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'نام تغییر کرد.' : 'پیدا نشد.');
                return;

            case '/add2subsection':
                if (count($args) < 4) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /add2subsection <sub_section> "<label>" "<description>" <price>');
                    return;
                }
                $planId = 'PP' . strtoupper(bin2hex(random_bytes(3)));
                $ok = $this->insertPlanOption($planId, $args[0], $args[1], $args[2], (float)$args[3], 'paid');
                $this->telegram->sendMessage($chatId, $ok ? "گزینه اضافه شد. ID: <code>{$planId}</code>" : 'زیر‌بخش پیدا نشد.');
                return;

            case '/update2subsectiondescription':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /update2subsectiondescription <id> "<description>"');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE plan_options SET description=:d WHERE id=:id');
                $stmt->execute(['d' => $args[1], 'id' => $args[0]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'توضیح بروزرسانی شد.' : 'پلن پیدا نشد.');
                return;

            case '/update2subsectionprice':
                if (count($args) < 2 || !is_numeric($args[1])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /update2subsectionprice <id> <price>');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE plan_options SET price=:p WHERE id=:id');
                $stmt->execute(['p' => (float)$args[1], 'id' => $args[0]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'قیمت تغییر کرد.' : 'پلن پیدا نشد.');
                return;

            case '/update2subsectionlabel':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /update2subsectionlabel <id> "<label>"');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE plan_options SET label=:l WHERE id=:id');
                $stmt->execute(['l' => $args[1], 'id' => $args[0]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'برچسب تغییر کرد.' : 'پلن یافت نشد.');
                return;

            case '/createmyplanssection':
                $label = $argsText !== '' ? $argsText : $this->getSetting('myplans_section_label', '📦 سرویس‌های من');
                $this->insertSection('myplans', $label, 'myplans');
                $this->telegram->sendMessage($chatId, 'بخش سرویس‌های من فعال شد.');
                return;

            case '/createreferralsection':
                $label = $argsText !== '' ? $argsText : $this->getSetting('referral_section_label', '🎁 کد دعوت');
                $this->insertSection('referral', $label, 'referral');
                $this->telegram->sendMessage($chatId, 'بخش ارجاع ایجاد شد.');
                return;

            case '/setreferralpercent':
                if ($argsText === '' || !is_numeric($args[0])) {
                    $this->telegram->sendMessage($chatId, 'درصد را به صورت عددی وارد کن.');
                    return;
                }
                $this->setSetting('referral_percent', (string)$args[0]);
                $this->telegram->sendMessage($chatId, 'درصد تخفیف ذخیره شد.');
                return;

            case '/createsupportsection':
                $label = $argsText !== '' ? $argsText : $this->getSetting('support_section_label', '☎️ پشتیبانی');
                $this->insertSection('support', $label, 'support');
                $this->telegram->sendMessage($chatId, 'بخش پشتیبانی ساخته شد.');
                return;

            case '/setsupporttext':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن جدید را وارد کن.');
                    return;
                }
                $this->setSetting('support_text', $argsText);
                $this->telegram->sendMessage($chatId, 'متن پشتیبانی ذخیره شد.');
                return;

            case '/createsectionwallet':
                $this->insertSection('wallet', 'MyWallet', 'wallet');
                $this->telegram->sendMessage($chatId, 'بخش کیف پول ساخته شد.');
                return;

            case '/createincreasemoney':
                $this->setSetting('increase_money_enabled', '1');
                $this->telegram->sendMessage($chatId, 'دکمه افزایش موجودی فعال شد.');
                return;

            case '/updateincreasemoneylabel':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن جدید را بنویس.');
                    return;
                }
                $this->setSetting('increase_money_label', $argsText);
                $this->telegram->sendMessage($chatId, 'برچسب دکمه تغییر کرد.');
                return;

            case '/setpaymenttext':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن راهنمای پرداخت را بنویس.');
                    return;
                }
                $this->setSetting('payment_text', $argsText);
                $this->telegram->sendMessage($chatId, 'راهنمای پرداخت ذخیره شد.');
                return;

            case '/setpointsguidetext':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن راهنمای امتیاز را وارد کن.');
                    return;
                }
                $this->setSetting('points_guide_text', $argsText);
                $this->telegram->sendMessage($chatId, 'راهنمای امتیاز بروزرسانی شد.');
                return;

            case '/createconvertpoints':
                $label = $argsText !== '' ? $argsText : $this->getSetting('points_conversion_label', '♻️ تبدیل امتیاز');
                $this->setSetting('points_conversion_label', $label);
                $this->setSetting('points_conversion_enabled', '1');
                $this->telegram->sendMessage($chatId, 'دکمه تبدیل امتیاز فعال شد.');
                return;

            case '/updateconvertlabel':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن جدید دکمه را وارد کن.');
                    return;
                }
                $this->setSetting('points_conversion_label', $argsText);
                $this->telegram->sendMessage($chatId, 'برچسب دکمه تبدیل امتیاز تغییر کرد.');
                return;

            case '/setconvertpointratio':
                if (count($args) < 2 || !is_numeric($args[0]) || !is_numeric($args[1])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /setconvertpointratio <points> <toman>');
                    return;
                }
                if ((float)$args[0] <= 0 || (float)$args[1] <= 0) {
                    $this->telegram->sendMessage($chatId, 'مقادیر باید بزرگ‌تر از صفر باشند.');
                    return;
                }
                $this->setSetting('points_convert_points_unit', (string)$args[0]);
                $this->setSetting('points_convert_amount_unit', (string)$args[1]);
                $this->telegram->sendMessage($chatId, 'نسبت تبدیل امتیاز ذخیره شد.');
                return;

            case '/settopuppointsratio':
                if (count($args) < 2 || !is_numeric($args[0]) || !is_numeric($args[1])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /settopuppointsratio <amount_toman> <points>');
                    return;
                }
                if ((float)$args[0] <= 0 || (float)$args[1] < 0) {
                    $this->telegram->sendMessage($chatId, 'مقادیر باید معتبر باشند.');
                    return;
                }
                $this->setSetting('topup_points_amount_unit', (string)$args[0]);
                $this->setSetting('topup_points_point_unit', (string)$args[1]);
                $this->telegram->sendMessage($chatId, 'نسبت امتیاز شارژ کیف پول ذخیره شد.');
                return;

            case '/settopupbuttons':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'مبالغ مورد نظر را با کاما یا فاصله وارد کن. مثل: /settopupbuttons 100000 250000 500000');
                    return;
                }
                $parts = preg_split('/[,\s]+/', $argsText);
                $amounts = [];
                foreach ($parts as $part) {
                    if ($part === '') {
                        continue;
                    }
                    if (!is_numeric($part) || (float)$part <= 0) {
                        $this->telegram->sendMessage($chatId, "مقدار نامعتبر: {$part}");
                        return;
                    }
                    $amounts[] = (string)(float)$part;
                }
                if (!$amounts) {
                    $this->telegram->sendMessage($chatId, 'حداقل یک مبلغ معتبر وارد کن.');
                    return;
                }
                $stored = implode(',', $amounts);
                $this->setSetting('topup_quick_amounts', $stored);
                $this->telegram->sendMessage($chatId, 'مبالغ ثابت شارژ ذخیره شد.');
                return;

            case '/settopupbonuspercent':
                if (count($args) < 1 || !is_numeric($args[0])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /settopupbonuspercent <percent>');
                    return;
                }
                $percent = (float)$args[0];
                if ($percent < 0) {
                    $this->telegram->sendMessage($chatId, 'درصد نمی‌تواند منفی باشد.');
                    return;
                }
                $this->setSetting('topup_bonus_percent', (string)$percent);
                $this->telegram->sendMessage($chatId, "درصد هدیه شارژ روی {$percent}% تنظیم شد.");
                return;

            case '/setreferralpoints':
                if (count($args) < 2 || !is_numeric($args[0]) || !is_numeric($args[1])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /setreferralpoints <inviter_points> <new_user_points>');
                    return;
                }
                $this->setSetting('referral_inviter_points', (string)$args[0]);
                $this->setSetting('referral_new_user_points', (string)$args[1]);
                $this->telegram->sendMessage($chatId, 'امتیازهای ارجاع تنظیم شد.');
                return;

            case '/setplanpoints':
                if (count($args) < 2 || !is_numeric($args[1])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /setplanpoints <plan_id> <points>');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE plan_options SET points_reward=:p WHERE id=:id');
                $stmt->execute(['p' => (float)$args[1], 'id' => $args[0]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'امتیاز پلن بروزرسانی شد.' : 'پلنی با این شناسه یافت نشد.');
                return;

            case '/enabletrial':
                $this->setSetting('trial_enabled', '1');
                $this->ensureDefaultSections();
                $this->telegram->sendMessage($chatId, 'تست رایگان فعال شد.');
                return;

            case '/disabletrial':
                $this->setSetting('trial_enabled', '0');
                $this->ensureDefaultSections();
                $this->telegram->sendMessage($chatId, 'تست رایگان غیرفعال شد.');
                return;

            case '/settrialinfo':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن توضیحات تست را وارد کن.');
                    return;
                }
                $this->setSetting('trial_info_text', $argsText);
                $this->telegram->sendMessage($chatId, 'توضیحات تست بروزرسانی شد.');
                return;

            case '/settrialcooldown':
                if (count($args) < 1 || !is_numeric($args[0])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /settrialcooldown <days>');
                    return;
                }
                $days = (int)$args[0];
                if ($days < 0) {
                    $this->telegram->sendMessage($chatId, 'عدد معتبر نیست.');
                    return;
                }
                $this->setSetting('trial_cooldown_days', (string)$days);
                $this->telegram->sendMessage($chatId, "فاصله بین تست‌ها روی {$days} روز تنظیم شد.");
                return;

            case '/settriallabel':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'برچسب بخش تست را وارد کن.');
                    return;
                }
                $this->setSetting('trial_section_label', $argsText);
                $this->db->prepare('UPDATE sections SET label=:lbl WHERE name="freetrial"')
                    ->execute(['lbl' => $argsText]);
                $this->ensureDefaultSections();
                $this->telegram->sendMessage($chatId, 'برچسب تست بروزرسانی شد.');
                return;

            case '/setsectionorder':
                if (count($args) < 2 || !is_numeric($args[1])) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /setsectionorder <name> <order_number>');
                    return;
                }
                $stmt = $this->db->prepare('UPDATE sections SET sort_order=:ord WHERE name=:name');
                $stmt->execute(['ord' => (float)$args[1], 'name' => $args[0]]);
                $this->telegram->sendMessage($chatId, $stmt->rowCount() ? 'ترتیب بخش بروزرسانی شد.' : 'بخشی با این نام یافت نشد.');
                return;

            case '/addguidesection':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن راهنما را وارد کن.');
                    return;
                }
                $this->setSetting('guide_text', $argsText);
                $this->insertSection('guide', $this->getSetting('guide_section_label', '📘 راهنما'), 'guide');
                $this->telegram->sendMessage($chatId, 'بخش راهنما فعال شد.');
                return;

            case '/setguidetext':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن جدید را وارد کن.');
                    return;
                }
                $this->setSetting('guide_text', $argsText);
                $this->telegram->sendMessage($chatId, 'راهنما بروزرسانی شد.');
                return;

            case '/addguideimages':
                $adminId = $this->getAdminUserId();
                if ($adminId) {
                    $this->setUserState($adminId, 'awaiting_guide_images');
                }
                $this->telegram->sendMessage($chatId, 'تصاویر را بفرست و در پایان /doneguideimages بزن.');
                return;

            case '/doneguideimages':
                $adminId = $this->getAdminUserId();
                if ($adminId) {
                    $this->clearUserState($adminId);
                }
                $this->telegram->sendMessage($chatId, 'ذخیره تصاویر راهنما پایان یافت.');
                return;

            case '/deleteguideimages':
                $this->db->exec('DELETE FROM guide_images');
                $this->telegram->sendMessage($chatId, 'تمام تصاویر راهنما حذف شد.');
                return;

            case '/deletesubsection':
                if (count($args) < 1) {
                    $this->telegram->sendMessage($chatId, 'نام زیر‌بخش را بنویس.');
                    return;
                }
                $this->deleteSubsection($args[0]);
                $this->telegram->sendMessage($chatId, 'زیر‌بخش حذف شد.');
                return;

            case '/deletesection':
                if (count($args) < 1) {
                    $this->telegram->sendMessage($chatId, 'نام بخش را وارد کن.');
                    return;
                }
                $this->deleteSectionTree($args[0]);
                $this->telegram->sendMessage($chatId, 'بخش و زیربخش‌هایش حذف شد.');
                return;

            case '/deleteall2subsections':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /deleteall2subsections <section_name> <sub_section_name>');
                    return;
                }
                $stmt = $this->db->prepare('DELETE FROM plan_options WHERE parent_name=:n');
                $stmt->execute(['n' => $args[1]]);
                $this->telegram->sendMessage($chatId, 'تمام گزینه‌های این زیر‌بخش حذف شدند.');
                return;

            case '/setsubsectionsmenutext':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن جدید را بنویس.');
                    return;
                }
                $this->setSetting('subsections_menu_text', $argsText);
                $this->telegram->sendMessage($chatId, 'متن ذخیره شد.');
                return;

            case '/set2subsectionsmenutext':
                if ($argsText === '') {
                    $this->telegram->sendMessage($chatId, 'متن جدید را بنویس.');
                    return;
                }
                $this->setSetting('plan_options_text', $argsText);
                $this->telegram->sendMessage($chatId, 'متن نمایش پلن‌ها بروزرسانی شد.');
                return;

            case '/createpromo':
                if (count($args) < 3) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /createpromo CODE percent 25 [max_uses] [max_per_user] [YYYY-MM-DD]');
                    return;
                }
                $this->createPromoCode($chatId, $args);
                return;

            case '/approvetopupid':
                if (count($args) < 1) {
                    $this->telegram->sendMessage($chatId, 'شناسه تاپ‌آپ را وارد کن.');
                    return;
                }
                $this->approveTopup($args[0], true);
                return;

            case '/notapprovetopupid':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /notapprovetopupid <topup_id> "<reason>"');
                    return;
                }
                $this->approveTopup($args[0], false, $args[1]);
                return;

            case '/deliverconfig':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /deliverconfig <order_id> "config_text"');
                    return;
                }
                $this->deliverConfig($args[0], $args[1], null);
                return;

            case '/deliverconfigfile':
                if (count($args) < 3) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /deliverconfigfile <order_id> <file_id> "description(optional)"');
                    return;
                }
                $this->deliverConfig($args[0], $args[2], $args[1]);
                return;

            case '/rejectorder':
                if (count($args) < 2) {
                    $this->telegram->sendMessage($chatId, 'فرمت: /rejectorder <order_id> "<reason>"');
                    return;
                }
                $this->rejectOrder($args[0], $args[1]);
                return;

            default:
                $this->telegram->sendMessage($chatId, 'دستور ناشناخته.');
        }
    }

    private function insertSection(string $name, string $label, string $type, ?string $parent = null, float $sortOrder = 100): bool
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO sections(name,label,type,parent_name,sort_order,created_at)
                VALUES(:n,:l,:t,:p,:s,:c)');
            $stmt->execute([
                'n' => $name,
                'l' => $label,
                't' => $type,
                'p' => $parent,
                's' => $sortOrder,
                'c' => date('c'),
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function renameSection(string $old, string $new): bool
    {
        $stmt = $this->db->prepare('UPDATE sections SET name=:new WHERE name=:old');
        $stmt->execute(['new' => $new, 'old' => $old]);
        if ($stmt->rowCount()) {
            $this->db->prepare('UPDATE sections SET parent_name=:new WHERE parent_name=:old')
                ->execute(['new' => $new, 'old' => $old]);
            $this->db->prepare('UPDATE plan_options SET parent_name=:new WHERE parent_name=:old')
                ->execute(['new' => $new, 'old' => $old]);
            return true;
        }
        return false;
    }

    private function getSection(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sections WHERE name=:n');
        $stmt->execute(['n' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function insertPlanOption(string $id, string $parent, string $label, string $description, float $price, string $kind): bool
    {
        $parentRow = $this->getSection($parent);
        if (!$parentRow) {
            return false;
        }
        try {
            $stmt = $this->db->prepare('INSERT INTO plan_options(id,parent_name,label,description,price,kind,created_at)
                VALUES(:id,:parent,:label,:description,:price,:kind,:c)');
            $stmt->execute([
                'id' => $id,
                'parent' => $parent,
                'label' => $label,
                'description' => $description,
                'price' => $price,
                'kind' => $kind,
                'c' => date('c'),
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function getPlanOption(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plan_options WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function deleteSectionTree(string $name): void
    {
        $this->db->prepare('DELETE FROM plan_options WHERE parent_name=:n')->execute(['n' => $name]);
        $children = $this->db->prepare('SELECT name FROM sections WHERE parent_name=:n');
        $children->execute(['n' => $name]);
        foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $child) {
            $this->deleteSectionTree($child);
        }
        $this->db->prepare('DELETE FROM sections WHERE name=:n')->execute(['n' => $name]);
    }

    private function deleteSubsection(string $name): void
    {
        $this->db->prepare('DELETE FROM plan_options WHERE parent_name=:n')->execute(['n' => $name]);
        $this->db->prepare('DELETE FROM sections WHERE name=:n')->execute(['n' => $name]);
    }

    private function createPromoCode(int $chatId, array $args): void
    {
        [$code, $type, $value] = [$args[0], strtolower($args[1]), (float)$args[2]];
        $maxUses = $args[3] ?? null;
        $maxPerUser = $args[4] ?? null;
        $expires = $args[5] ?? null;

        if (!in_array($type, ['percent', 'flat'], true)) {
            $this->telegram->sendMessage($chatId, 'نوع تخفیف فقط percent یا flat است.');
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO promo_codes(code,kind,value,max_uses,max_per_user,expires_at,total_used,created_at)
            VALUES(:code,:kind,:value,:max,:per,:exp,0,:c)
            ON CONFLICT(code) DO UPDATE SET kind=excluded.kind,value=excluded.value,max_uses=excluded.max_uses,max_per_user=excluded.max_per_user,expires_at=excluded.expires_at');
        $stmt->execute([
            'code' => strtoupper($code),
            'kind' => $type,
            'value' => $value,
            'max' => $maxUses,
            'per' => $maxPerUser ?? 1,
            'exp' => $expires,
            'c' => date('c'),
        ]);
        $this->telegram->sendMessage($chatId, "کد تخفیف {$code} ذخیره شد.");
    }

    private function approveTopup(string $topupId, bool $approve, string $reason = ''): void
    {
        $stmt = $this->db->prepare('SELECT * FROM topups WHERE id=:id');
        $stmt->execute(['id' => $topupId]);
        $topup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$topup) {
            $this->telegram->sendMessage(ADMIN_ID, 'تاپ‌آپ پیدا نشد.');
            return;
        }

        $userStmt = $this->db->prepare('SELECT * FROM users WHERE id=:id');
        $userStmt->execute(['id' => $topup['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return;
        }

        if ($approve) {
            $addedAmount = (float)$topup['amount'];
            $bonusPercent = (float)$this->getSetting('topup_bonus_percent', '10');
            $bonusAmount = $this->calculateTopupBonus($addedAmount, $bonusPercent);
            $totalCredit = $addedAmount + $bonusAmount;
            $currentBalance = (float)$user['wallet_balance'];
            $this->creditWallet((int)$user['id'], $totalCredit);
            $newBalance = $currentBalance + $totalCredit;
            $this->db->prepare('UPDATE topups SET status="approved", updated_at=:u WHERE id=:id')
                ->execute(['u' => date('c'), 'id' => $topupId]);
            $msg = "شارژ تایید شد ✅\nشناسه: {$topupId}\nمبلغ پرداختی: " . number_format($addedAmount) . " تومان\n";
            if ($bonusAmount > 0) {
                $msg .= "هدیه {$bonusPercent}%: " . number_format($bonusAmount) . " تومان\n";
            }
            $msg .= "مبلغ افزوده شده به کیف پول: " . number_format($totalCredit) . " تومان\n"
                . "موجودی جدید: " . number_format($newBalance);
            $points = $this->calculateTopupPoints($addedAmount);
            if ($points > 0) {
                $this->addPoints((int)$user['id'], $points, 'TOPUP', ['topup_id' => $topupId]);
                $msg .= "\nامتیاز اضافه شده: {$points}";
            }
            $this->telegram->sendMessage((int)$user['chat_id'], $msg);
            $this->telegram->sendMessage(ADMIN_ID, "TopUp {$topupId} تایید شد.");
        } else {
            $this->db->prepare('UPDATE topups SET status="rejected", updated_at=:u WHERE id=:id')
                ->execute(['u' => date('c'), 'id' => $topupId]);
            $msg = "شارژ {$topupId} رد شد ❌\nدلیل: {$reason}";
            $this->telegram->sendMessage((int)$user['chat_id'], $msg);
            $this->telegram->sendMessage(ADMIN_ID, "TopUp {$topupId} رد شد.");
        }
    }

    private function startTopupTicket(array $user, float $amount): void
    {
        $topupId = 'TP' . strtoupper(bin2hex(random_bytes(3)));
        $now = date('c');
        $this->db->prepare('INSERT INTO topups(id,user_id,amount,status,created_at,updated_at)
            VALUES(:id,:user,:amount,"awaiting_receipt",:c,:c)')
            ->execute([
                'id' => $topupId,
                'user' => $user['id'],
                'amount' => $amount,
                'c' => $now,
            ]);
        $this->setUserState((int)$user['id'], 'awaiting_topup_receipt', ['topup_id' => $topupId, 'amount' => $amount]);
        $text = "مبلغ <b>" . number_format($amount) . " تومان</b> ثبت شد.\n"
            . $this->getSetting('payment_text', 'مبلغ را واریز و رسید را ارسال کن.')
            . "\nلطفاً رسید را به صورت عکس یا فایل بفرست.";
        $this->telegram->sendMessage((int)$user['chat_id'], $text, ['parse_mode' => 'HTML']);
    }

    private function finalizeTopupReceipt(array $user, array $message, array $payload): void
    {
        $topupId = $payload['topup_id'] ?? '';
        if ($topupId === '') {
            $this->clearUserState((int)$user['id']);
            return;
        }
        $fileId = $this->extractFileId($message);
        if (!$fileId) {
            $this->telegram->sendMessage((int)$user['chat_id'], 'فایل معتبر دریافت نشد.');
            return;
        }
        $mediaType = isset($message['photo']) ? 'photo' : 'document';
        $this->db->prepare('UPDATE topups SET status="pending_admin", receipt_file_id=:f, updated_at=:u WHERE id=:id')
            ->execute([
                'f' => $fileId,
                'u' => date('c'),
                'id' => $topupId,
            ]);
        $this->clearUserState((int)$user['id']);
        $text = "رسید دریافت شد ✅\nشناسه پیگیری: {$topupId}\nنتیجه از طریق ربات اطلاع داده می‌شود.";
        $this->telegram->sendMessage((int)$user['chat_id'], $text);

        $adminText = "درخواست شارژ جدید\n"
            . "کاربر: {$user['first_name']} (@{$user['username']})\n"
            . "شناسه: {$user['chat_id']}\n"
            . "Wallet: {$user['wallet_id']}\n"
            . "TopUp ID: {$topupId}\n"
            . "Amount: " . number_format((float)$payload['amount']) . " تومان";
        $this->notifyAdmin($adminText);
        $caption = "رسید {$topupId}";
        if ($mediaType === 'photo') {
            $this->telegram->sendPhoto(ADMIN_ID, $fileId, ['caption' => $caption]);
        } else {
            $this->telegram->sendDocument(ADMIN_ID, $fileId, ['caption' => $caption]);
        }
    }

    private function handleStatefulMessage(array $user, array $message, array $state): void
    {
        $chatId = (int)$user['chat_id'];
        $payload = $state['payload'] ?? [];
        $text = trim($message['text'] ?? '');

        switch ($state['state']) {
            case 'awaiting_referral_code':
                if ($text === '') {
                    $this->telegram->sendMessage($chatId, 'کد دعوت را وارد کن یا /cancel بزن.');
                    return;
                }
                $this->handleReferralCodeSubmission($user, strtoupper($text));
                return;

            case 'awaiting_topup_amount':
                $clean = str_replace([',', ' '], '', $text);
                if ($clean === '' || !is_numeric($clean)) {
                    $this->telegram->sendMessage($chatId, 'مبلغ را به صورت عددی وارد کن.');
                    return;
                }
                $amount = (float)$clean;
                if ($amount <= 0) {
                    $this->telegram->sendMessage($chatId, 'مبلغ باید بزرگتر از صفر باشد.');
                    return;
                }
                $this->startTopupTicket($user, $amount);
                return;

            case 'awaiting_topup_receipt':
                if (!$this->messageContainsMedia($message)) {
                    $this->telegram->sendMessage($chatId, 'فقط تصویر یا فایل رسید بفرست.');
                    return;
                }
                $this->finalizeTopupReceipt($user, $message, $payload);
                return;

            case 'awaiting_discount':
                $orderId = $payload['order_id'] ?? '';
                if ($orderId === '') {
                    $this->clearUserState((int)$user['id']);
                    return;
                }
                $this->processDiscountResponse($user, $orderId, $text);
                return;

            case 'awaiting_guide_images':
                if (!$this->messageContainsMedia($message)) {
                    $this->telegram->sendMessage($chatId, 'تصویر یا فایل ارسال کن یا /doneguideimages بزن.');
                    return;
                }
                $this->storeGuideImage($message);
                $this->telegram->sendMessage($chatId, 'تصویر ذخیره شد.');
                return;

            case 'awaiting_points_convert':
                if ($text === '') {
                    $this->telegram->sendMessage($chatId, 'مقدار امتیاز را وارد کن یا /cancel بزن.');
                    return;
                }
                $this->handlePointsConversionInput($user, $text);
                return;

            case 'awaiting_points_transfer_amount':
                $this->handlePointsTransferAmount($user, $text);
                return;

            case 'awaiting_points_transfer_wallet':
                $this->handlePointsTransferWallet($user, $text, $payload);
                return;
        }
    }

    private function getUserState(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT state,payload FROM user_states WHERE user_id=:id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'state' => $row['state'],
            'payload' => $row['payload'] ? json_decode($row['payload'], true) : [],
        ];
    }

    private function setUserState(int $userId, string $state, array $payload = []): void
    {
        $stmt = $this->db->prepare('INSERT INTO user_states(user_id,state,payload,updated_at)
            VALUES(:id,:state,:payload,:u)
            ON CONFLICT(user_id) DO UPDATE SET state=excluded.state,payload=excluded.payload,updated_at=excluded.updated_at');
        $stmt->execute([
            'id' => $userId,
            'state' => $state,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'u' => date('c'),
        ]);
    }

    private function clearUserState(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM user_states WHERE user_id=:id');
        $stmt->execute(['id' => $userId]);
    }

    private function messageContainsMedia(array $message): bool
    {
        return isset($message['photo']) || isset($message['document']);
    }

    private function extractFileId(array $message): ?string
    {
        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            return $photo['file_id'] ?? null;
        }
        if (isset($message['document'])) {
            return $message['document']['file_id'] ?? null;
        }
        return null;
    }

    private function storeGuideImage(array $message): void
    {
        $fileId = $this->extractFileId($message);
        if (!$fileId) {
            return;
        }
        $type = isset($message['document']) ? 'document' : 'photo';
        $caption = $message['caption'] ?? '';
        $stmt = $this->db->prepare('INSERT INTO guide_images(file_id,media_type,caption,created_at)
            VALUES(:f,:t,:c,:d)');
        $stmt->execute([
            'f' => $fileId,
            't' => $type,
            'c' => $caption,
            'd' => date('c'),
        ]);
    }

    private function handleCallback(array $user, array $callback): void
    {
        $data = $callback['data'] ?? '';
        $chatId = (int)$callback['from']['id'];
        if ($data === 'check_join') {
            if ($this->hasJoinedRequiredChannel($chatId)) {
                $this->telegram->answerCallbackQuery($callback['id'], 'عضویت تایید شد ✅');
                $this->sendSectionsMenu($chatId, $user);
            } else {
                $this->telegram->answerCallbackQuery($callback['id'], 'هنوز عضو کانال نشده‌ای.');
                $this->sendJoinChannelPrompt($chatId);
            }
            return;
        }

        if (!$this->ensureChannelMembershipOrPrompt($chatId)) {
            $this->telegram->answerCallbackQuery($callback['id'], 'برای ادامه باید عضو کانال شوی.');
            return;
        }

        $this->telegram->answerCallbackQuery($callback['id']);

        if ($data === 'trial_request') {
            $this->handleTrialRequest($user, $chatId);
            return;
        }

        if ($data === 'topup_custom') {
            $this->setUserState((int)$user['id'], 'awaiting_topup_amount');
            $this->telegram->sendMessage($chatId, 'مبلغ دلخواه را به تومان وارد کن یا /cancel بزن.');
            return;
        }

        if (str_starts_with($data, 'topup_amount:')) {
            $amountValue = (float)substr($data, 13);
            if ($amountValue <= 0) {
                $this->telegram->sendMessage($chatId, 'مقدار شارژ معتبر نیست.');
                return;
            }
            $this->startTopupTicket($user, $amountValue);
            return;
        }

        if (str_starts_with($data, 'section:')) {
            $sectionName = substr($data, 8);
            $this->handleSectionClick($user, $sectionName, $chatId);
            return;
        }

        if (str_starts_with($data, 'sub:')) {
            $name = substr($data, 4);
            $this->sendPlanOptions($chatId, $name);
            return;
        }

        if (str_starts_with($data, 'plan:')) {
            $planId = substr($data, 5);
            $plan = $this->getPlanOption($planId);
            if (!$plan || ($plan['kind'] ?? 'paid') !== 'paid') {
                $this->telegram->sendMessage($chatId, 'این پلن موجود نیست.');
                return;
            }
            $this->sendPlanDetailMessage($chatId, $plan);
            return;
        }

        if (str_starts_with($data, 'confirm:')) {
            $planId = substr($data, 8);
            $plan = $this->getPlanOption($planId);
            if ($plan && $plan['kind'] === 'paid') {
                $this->beginPaidOrder($user, $plan);
            }
            return;
        }

        if ($data === 'cancel_plan') {
            $this->telegram->sendMessage($chatId, 'فرآیند خرید لغو شد.');
            return;
        }

        if ($data === 'wallet:add') {
            $this->promptTopup($user);
            return;
        }

        if ($data === 'points:convert') {
            $this->promptPointsConversion($user);
            return;
        }

        if ($data === 'points:transfer') {
            if ($this->getSetting('points_transfer_enabled', '1') !== '1') {
                $this->telegram->sendMessage($chatId, 'انتقال امتیاز فعال نیست.');
                return;
            }
            $this->promptPointsTransfer($user);
            return;
        }

        if ($data === 'show_sections') {
            $this->sendSectionsMenu($chatId, $user);
            return;
        }
    }

    private function handleSectionClick(array $user, string $name, int $chatId): void
    {
        $section = $this->getSection($name);
        if (!$section) {
            $this->telegram->sendMessage($chatId, 'این بخش موجود نیست.');
            return;
        }

        switch ($section['type']) {
            case 'wallet':
                $this->sendWalletOverview($user);
                break;
            case 'referral':
                $this->promptReferralInput($user);
                break;
            case 'myplans':
                $this->sendMyPlans($user);
                break;
            case 'support':
                $this->sendSupportInfo($user);
                break;
            case 'guide':
                $this->sendGuideSection($user);
                break;
            case 'points':
                $this->sendPointsSection($user);
                break;
            case 'trial_root':
                $this->sendTrialOffer($chatId, $user);
                break;
            case 'paid_root':
                $this->sendPaidSubsections($chatId, $section['name']);
                break;
            default:
                $this->telegram->sendMessage($chatId, 'این بخش هنوز پیکربندی نشده.');
        }
    }

    private function sendSectionsMenu(int $chatId, array $user): void
    {
        $this->ensureDefaultSections();
        $stmt = $this->db->query('SELECT name,label FROM sections WHERE parent_name IS NULL ORDER BY sort_order ASC, id ASC');
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $keyboard = [];
        $row = [];
        foreach ($sections as $item) {
            if ($item['name'] === 'freetrial' && !$this->trialEnabled()) {
                continue;
            }
            $row[] = ['text' => $item['label'], 'callback_data' => 'section:' . $item['name']];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $keyboard[] = $row;
        }
        $welcome = $this->getSetting('welcome_text', 'سلام!');
        $text = $welcome . "\n\nکد دعوت تو: <code>{$user['referral_code']}</code>\n"
            . "شناسه کیف پول: <code>{$user['wallet_id']}</code>";
        $opts = [
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
            'parse_mode' => 'HTML',
        ];
        $this->telegram->sendMessage($chatId, $text, $opts);
    }

    private function sendPaidSectionsShortcut(int $chatId): void
    {
        $this->ensureDefaultSections();
        $stmt = $this->db->query('SELECT name,label FROM sections WHERE type="paid_root" ORDER BY sort_order ASC, id ASC');
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$sections) {
            $this->telegram->sendMessage($chatId, 'هنوز بخش خرید پلن تعریف نشده.');
            return;
        }
        $keyboard = [];
        $row = [];
        foreach ($sections as $item) {
            $row[] = ['text' => $item['label'], 'callback_data' => 'section:' . $item['name']];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $keyboard[] = $row;
        }
        $this->telegram->sendMessage($chatId, 'یکی از بخش‌های خرید را انتخاب کن:', [
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function sendPaidSubsections(int $chatId, string $sectionName): void
    {
        $text = $this->getSetting('subsections_menu_text', 'یکی از گزینه‌ها را انتخاب کن.');
        $stmt = $this->db->prepare('SELECT name,label FROM sections WHERE parent_name=:p ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['p' => $sectionName]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            $keyboard = [
                [
                    ['text' => 'بازگشت به منو ⬅️', 'callback_data' => 'show_sections'],
                ],
            ];
            $this->telegram->sendMessage($chatId, 'برای این بخش هنوز زیر‌بخشی ثبت نشده.', [
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
            ]);
            return;
        }
        $keyboard = [];
        foreach ($items as $item) {
            $keyboard[] = [
                ['text' => $item['label'], 'callback_data' => 'sub:' . $item['name']],
            ];
        }
        $keyboard[] = [
            ['text' => 'بازگشت به منو ⬅️', 'callback_data' => 'show_sections'],
        ];
        $opts = ['reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)];
        $this->telegram->sendMessage($chatId, $text, $opts);
    }

    private function sendPlanOptions(int $chatId, string $subsectionName): void
    {
        $baseText = $this->getSetting('plan_options_text', 'پلن مورد نظرت را انتخاب کن.');
        $adminContact = $this->getSetting('support_text', 'ارتباط با پشتیبانی: @saeedsalehiz');
        $text = $baseText . "\n\nاگر هیچ‌کدام از این پلن‌ها مناسب تو نبود، برای پلن اختصاصی به ادمین پیام بده:\n" . $adminContact;
        $stmt = $this->db->prepare('SELECT * FROM plan_options WHERE parent_name=:p AND kind="paid" ORDER BY created_at ASC');
        $stmt->execute(['p' => $subsectionName]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            $keyboard = [
                [
                    ['text' => 'بازگشت به منو ⬅️', 'callback_data' => 'show_sections'],
                ],
            ];
            $this->telegram->sendMessage($chatId, 'هنوز گزینه‌ای تعریف نشده.', [
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
            ]);
            return;
        }
        $parentName = $this->db->prepare('SELECT parent_name FROM sections WHERE name=:n');
        $parentName->execute(['n' => $subsectionName]);
        $parentSection = $parentName->fetchColumn();
        $keyboard = [];
        foreach ($items as $item) {
            $label = $item['label'];
            $label .= ' - ' . number_format((float)$item['price']) . ' تومان';
            if (!empty($item['points_reward']) && (float)$item['points_reward'] > 0) {
                $label .= ' • +' . number_format((float)$item['points_reward']) . ' امتیاز';
            }
            $keyboard[] = [
                ['text' => $label, 'callback_data' => 'plan:' . $item['id']],
            ];
        }
        if ($parentSection) {
            $keyboard[] = [
                ['text' => 'بازگشت ⬅️', 'callback_data' => 'section:' . $parentSection],
            ];
        }
        $keyboard[] = [
            ['text' => 'بازگشت به منو ⬅️', 'callback_data' => 'show_sections'],
        ];
        $opts = ['reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)];
        $this->telegram->sendMessage($chatId, $text, $opts);
    }

    private function sendPlanDetailMessage(int $chatId, array $plan): void
    {
        $priceLine = number_format((float)$plan['price']) . ' تومان';
        $label = $this->esc($plan['label']);
        $description = $this->esc($plan['description'] ?? '');
        $text = "نام پلن: {$label}\n"
            . "قیمت: {$priceLine}\n"
            . "توضیحات:\n{$description}\n\n"
            . "برای تایید دکمه زیر را بزن.";
        if (!empty($plan['points_reward']) && (float)$plan['points_reward'] > 0) {
            $text .= "\nامتیاز دریافتی پس از خرید: +" . number_format((float)$plan['points_reward']);
        }
        $callback = 'confirm:' . $plan['id'];
        $keyboard = [
            [
                ['text' => 'تایید ✅', 'callback_data' => $callback],
                ['text' => 'لغو ❌', 'callback_data' => 'cancel_plan'],
            ],
        ];
        $opts = ['reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)];
        $this->telegram->sendMessage($chatId, $text, $opts);
    }

    private function beginPaidOrder(array $user, array $plan): void
    {
        $orderId = $this->createOrderId();
        $now = date('c');
        $stmt = $this->db->prepare('INSERT INTO orders(id,user_id,plan_id,plan_label,price,type,status,created_at,updated_at)
            VALUES(:id,:user,:plan,:label,:price,"paid","awaiting_discount",:c,:c)');
        $stmt->execute([
            'id' => $orderId,
            'user' => $user['id'],
            'plan' => $plan['id'],
            'label' => $plan['label'],
            'price' => $plan['price'],
            'c' => $now,
        ]);
        $this->setUserState((int)$user['id'], 'awaiting_discount', ['order_id' => $orderId]);
        $label = $this->esc($plan['label']);
        $text = "سفارش {$orderId}\n"
            . "پلن: {$label}\n"
            . "قیمت: " . number_format((float)$plan['price']) . " تومان\n"
            . "اگر کد تخفیف یا کد ارجاع داری، الان وارد کن.\n"
            . "در غیر اینصورت عبارت «خیر» را بفرست.";
        $this->telegram->sendMessage((int)$user['chat_id'], $text);
    }

    private function processDiscountResponse(array $user, string $orderId, string $codeInput): void
    {
        $order = $this->getOrder($orderId);
        if (!$order || $order['user_id'] !== $user['id']) {
            $this->clearUserState((int)$user['id']);
            $this->telegram->sendMessage((int)$user['chat_id'], 'سفارش معتبر نیست.');
            return;
        }
        if ($order['status'] !== 'awaiting_discount') {
            $this->clearUserState((int)$user['id']);
            $this->telegram->sendMessage((int)$user['chat_id'], 'این سفارش قبلاً بررسی شده.');
            return;
        }

        $result = $this->evaluateDiscountCode($user, $order, trim($codeInput));
        $meta = ['discount_note' => $result['note']];
        $this->db->prepare('UPDATE orders SET final_price=:f, discount_code=:d, meta=:m, status="awaiting_payment", updated_at=:u WHERE id=:id')
            ->execute([
                'f' => $result['final_price'],
                'd' => $result['code'],
                'm' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'u' => date('c'),
                'id' => $orderId,
            ]);

        $this->clearUserState((int)$user['id']);
        $this->finalizePaidOrder($user, $orderId, $result);
    }

    private function evaluateDiscountCode(array $user, array $order, string $code): array
    {
        $price = (float)$order['price'];
        $final = $price;
        $note = 'بدون تخفیف.';
        $storedCode = null;
        $type = null;
        $value = 0;

        if ($code === '' || in_array(mb_strtolower($code), ['خیر', 'skip', 'no'], true)) {
            $autocode = $user['referred_by'] ?? '';
            if ($autocode) {
                $autoResult = $this->applyReferralDiscount($price, $autocode, $user);
                if ($autoResult !== null) {
                    return $autoResult;
                }
            }
            return [
                'final_price' => $final,
                'note' => $note,
                'code' => $storedCode,
                'type' => $type,
                'value' => $value,
            ];
        }

        $codeUpper = strtoupper($code);
        $refResult = $this->applyReferralDiscount($price, $codeUpper, $user);
        if ($refResult !== null) {
            return $refResult;
        }

        $promoStmt = $this->db->prepare('SELECT * FROM promo_codes WHERE code=:code');
        $promoStmt->execute(['code' => $codeUpper]);
        $promo = $promoStmt->fetch(PDO::FETCH_ASSOC);
        if ($promo) {
            $valid = true;
            if ($promo['max_uses']) {
                $used = $this->db->prepare('SELECT COUNT(*) FROM promo_usages WHERE promo_code=:code');
                $used->execute(['code' => $codeUpper]);
                if ((int)$used->fetchColumn() >= (int)$promo['max_uses']) {
                    $valid = false;
                }
            }
            if ($valid && $promo['expires_at']) {
                if (strtotime($promo['expires_at']) < time()) {
                    $valid = false;
                }
            }
            if ($valid) {
                $userUsage = $this->db->prepare('SELECT COUNT(*) FROM promo_usages WHERE promo_code=:code AND user_id=:user');
                $userUsage->execute(['code' => $codeUpper, 'user' => $user['id']]);
                if ((int)$userUsage->fetchColumn() >= (int)$promo['max_per_user']) {
                    $valid = false;
                }
            }
            if ($valid) {
                $discount = $promo['kind'] === 'percent'
                    ? ($promo['value'] / 100) * $price
                    : (float)$promo['value'];
                $final = max(0, $price - $discount);
                return [
                    'final_price' => $final,
                    'note' => "کد {$codeUpper} اعمال شد.",
                    'code' => $codeUpper,
                    'type' => 'promo',
                    'value' => $promo['value'],
                ];
            }
        }

        $this->telegram->sendMessage((int)$user['chat_id'], 'کد معتبر نبود. بدون تخفیف ادامه می‌دهیم.');
        return [
            'final_price' => $final,
            'note' => 'کد نامعتبر بود.',
            'code' => null,
            'type' => null,
            'value' => 0,
        ];
    }

    private function applyReferralDiscount(float $price, string $codeUpper, array $user): ?array
    {
        $refStmt = $this->db->prepare('SELECT * FROM users WHERE referral_code=:code');
        $refStmt->execute(['code' => $codeUpper]);
        $refUser = $refStmt->fetch(PDO::FETCH_ASSOC);
        if (!$refUser || $refUser['id'] === $user['id']) {
            return null;
        }
        $percent = (float)$this->getSetting('referral_percent', '10');
        if ($percent <= 0) {
            return null;
        }
        $discount = ($percent / 100) * $price;
        $final = max(0, $price - $discount);
        return [
            'final_price' => $final,
            'note' => "تخفیف دعوت{$percent}% اعمال شد.",
            'code' => 'REF-' . $codeUpper,
            'type' => 'referral',
            'value' => $percent,
        ];
    }
    private function finalizePaidOrder(array $user, string $orderId, array $discountInfo): void
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return;
        }

        $userStmt = $this->db->prepare('SELECT * FROM users WHERE id=:id');
        $userStmt->execute(['id' => $order['user_id']]);
        $freshUser = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$freshUser) {
            return;
        }

        $finalPrice = (float)$discountInfo['final_price'];

        if ((float)$freshUser['wallet_balance'] >= $finalPrice) {
            $this->deductWallet((int)$freshUser['id'], $finalPrice);
            $this->db->prepare('UPDATE orders SET status="awaiting_config", updated_at=:u WHERE id=:id')
                ->execute(['u' => date('c'), 'id' => $orderId]);
            if ($discountInfo['type'] === 'promo' && $discountInfo['code']) {
                $this->recordPromoUsage($discountInfo['code'], (int)$freshUser['id'], $orderId);
            }
            $planRow = $this->getPlanOption($order['plan_id']);
            if ($planRow && (float)$planRow['points_reward'] > 0) {
                $this->addPoints(
                    (int)$freshUser['id'],
                    (float)$planRow['points_reward'],
                    'PURCHASE',
                    ['order_id' => $orderId, 'plan_id' => $order['plan_id']]
                );
            }
            $msg = "پرداخت موفق ✅\nسفارش {$orderId}\n"
                . "مبلغ کسر شده: " . number_format($finalPrice) . " تومان\n"
                . "در حال آماده‌سازی کانفیگ دستی توسط تیم هه‌لکار هستیم؛ لطفاً کمی صبر کن.";
            $this->telegram->sendMessage((int)$freshUser['chat_id'], $msg);

            $planLabelSafe = $this->esc($order['plan_label']);
            $adminText = "سفارش جدید {$orderId}\n"
                . "کاربر: {$freshUser['first_name']} (@{$freshUser['username']})\n"
                . "ChatID: {$freshUser['chat_id']}\n"
                . "پلن: {$planLabelSafe}\n"
                . "مبلغ نهایی: " . number_format($finalPrice) . " تومان\n"
                . "کد تخفیف: " . ($discountInfo['code'] ?? 'ندارد');
            $this->notifyAdmin($adminText);
            return;
        }

        $this->db->prepare('UPDATE orders SET status="awaiting_funds", updated_at=:u WHERE id=:id')
            ->execute(['u' => date('c'), 'id' => $orderId]);
        $text = "موجودی کافی نیست ❗️\n"
            . "مبلغ مورد نیاز: " . number_format($finalPrice) . " تومان\n"
            . "موجودی فعلی: " . number_format((float)$freshUser['wallet_balance']) . " تومان\n"
            . "لطفاً ابتدا موجودی را افزایش بده.";
        $keyboard = [];
        if ($this->getSetting('increase_money_enabled', '0') === '1') {
            $keyboard[] = [
                ['text' => $this->getSetting('increase_money_label', 'افزایش موجودی'), 'callback_data' => 'wallet:add'],
            ];
        }
        $opts = $keyboard
            ? ['reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)]
            : [];
        $this->telegram->sendMessage((int)$freshUser['chat_id'], $text, $opts);
    }

    private function deductWallet(int $userId, float $amount): void
    {
        $stmt = $this->db->prepare('UPDATE users SET wallet_balance=wallet_balance-:amount WHERE id=:id');
        $stmt->execute(['amount' => $amount, 'id' => $userId]);
    }

    private function recordPromoUsage(string $code, int $userId, string $orderId): void
    {
        $stmt = $this->db->prepare('INSERT INTO promo_usages(promo_code,user_id,order_id,used_at)
            VALUES(:code,:user,:order,:t)');
        $stmt->execute([
            'code' => $code,
            'user' => $userId,
            'order' => $orderId,
            't' => date('c'),
        ]);
    }

    private function creditWallet(int $userId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE users SET wallet_balance=wallet_balance+:amount WHERE id=:id');
        $stmt->execute(['amount' => $amount, 'id' => $userId]);
    }

    private function addPoints(int $userId, float $points, string $reason, array $meta = []): void
    {
        if ($points <= 0) {
            return;
        }
        $this->changePointsBalance($userId, $points, $reason, $meta);
    }

    private function deductPoints(int $userId, float $points, string $reason, array $meta = []): bool
    {
        if ($points <= 0) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT points_balance FROM users WHERE id=:id');
        $stmt->execute(['id' => $userId]);
        $current = (float)$stmt->fetchColumn();
        if ($current + 1e-6 < $points) {
            return false;
        }
        $this->changePointsBalance($userId, -$points, $reason, $meta);
        return true;
    }

    private function changePointsBalance(int $userId, float $delta, string $reason, array $meta = []): void
    {
        if ($delta === 0.0) {
            return;
        }
        $this->db->prepare('UPDATE users SET points_balance=points_balance+:delta WHERE id=:id')
            ->execute(['delta' => $delta, 'id' => $userId]);
        $this->db->prepare('INSERT INTO point_transactions(user_id,delta,reason,meta,created_at)
            VALUES(:user,:delta,:reason,:meta,:created)')
            ->execute([
                'user' => $userId,
                'delta' => $delta,
                'reason' => $reason,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created' => date('c'),
            ]);
    }

    private function calculateTopupPoints(float $amount): float
    {
        $amountUnit = (float)$this->getSetting('topup_points_amount_unit', '100000');
        $pointsUnit = (float)$this->getSetting('topup_points_point_unit', '10');
        if ($amountUnit <= 0 || $pointsUnit <= 0) {
            return 0;
        }
        $blocks = floor($amount / $amountUnit);
        return $blocks > 0 ? $blocks * $pointsUnit : 0;
    }

    private function calculateTopupBonus(float $amount, ?float $percent = null): float
    {
        if ($percent === null) {
            $percent = (float)$this->getSetting('topup_bonus_percent', '10');
        }
        if ($percent <= 0) {
            return 0;
        }
        return round($amount * ($percent / 100));
    }

    private function getTopupQuickAmounts(): array
    {
        $raw = trim((string)$this->getSetting('topup_quick_amounts', '100000,200000,500000,1000000'));
        if ($raw === '') {
            return [100000, 200000, 500000, 1000000];
        }
        $parts = preg_split('/[,\s]+/', $raw);
        $amounts = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $value = (float)$part;
            if ($value > 0) {
                $amounts[] = $value;
            }
        }
        return $amounts ?: [100000, 200000, 500000];
    }

    private function getUserById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getUserByWalletId(string $walletId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE wallet_id=:w');
        $stmt->execute(['w' => $walletId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function createOrderId(): string
    {
        do {
            $id = 'ORD' . strtoupper(bin2hex(random_bytes(4)));
            $exists = $this->db->query("SELECT 1 FROM orders WHERE id='{$id}'")->fetch();
        } while ($exists);
        return $id;
    }

    private function promptTopup(array $user): void
    {
        $this->clearUserState((int)$user['id']);
        $amounts = $this->getTopupQuickAmounts();
        $keyboard = [];
        foreach ($amounts as $amount) {
            $label = number_format($amount) . ' تومان';
            $points = $this->calculateTopupPoints($amount);
            if ($points > 0) {
                $label .= " | {$points} امتیاز";
            }
            $keyboard[] = [
                ['text' => $label, 'callback_data' => 'topup_amount:' . (int)round($amount)],
            ];
        }
        $keyboard[] = [
            ['text' => 'مبلغ دلخواه ✍️', 'callback_data' => 'topup_custom'],
        ];
        $amountUnit = (float)$this->getSetting('topup_points_amount_unit', '100000');
        $pointsUnit = (float)$this->getSetting('topup_points_point_unit', '10');
        $ratioLine = '';
        if ($amountUnit > 0 && $pointsUnit > 0) {
            $pointsText = fmod($pointsUnit, 1.0) === 0.0
                ? number_format((int)$pointsUnit)
                : rtrim(rtrim(number_format($pointsUnit, 2, '.', ''), '0'), '.');
            $ratioLine = "\nهر " . number_format($amountUnit) . " تومان = {$pointsText} امتیاز";
        }
        $text = "مبلغ شارژ را انتخاب کن:\n"
            . "با انتخاب هر گزینه، توضیحات پرداخت برایت ارسال می‌شود."
            . $ratioLine
            . "\nدر صورت نیاز می‌توانی مبلغ دلخواه را وارد کنی.";
        $bonusPercent = (float)$this->getSetting('topup_bonus_percent', '10');
        if ($bonusPercent > 0) {
            $text .= "\n🎁 با هر شارژ {$bonusPercent}% هدیه به موجودیت اضافه می‌شود.";
        }
        $this->telegram->sendMessage((int)$user['chat_id'], $text, [
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function notifyAdmin(string $text): void
    {
        $this->telegram->sendMessage(ADMIN_ID, $text);
    }

    private function getOrder(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function sendWalletOverview(array $user): void
    {
        $text = "کیف پول من\n"
            . "موجودی: " . number_format((float)$user['wallet_balance']) . " تومان\n"
            . "شناسه کیف پول: <code>{$user['wallet_id']}</code>\n"
            . "کد دعوت تو: <code>{$user['referral_code']}</code>";
        $keyboard = [];
        if ($this->getSetting('increase_money_enabled', '0') === '1') {
            $keyboard[] = [
                ['text' => $this->getSetting('increase_money_label', 'افزایش موجودی'), 'callback_data' => 'wallet:add'],
            ];
        }
        $opts = ['parse_mode' => 'HTML'];
        if ($keyboard) {
            $opts['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }
        $this->telegram->sendMessage((int)$user['chat_id'], $text, $opts);
    }

    private function sendPointsSection(array $user): void
    {
        $fresh = $this->getUserById((int)$user['id']) ?? $user;
        $points = (float)($fresh['points_balance'] ?? 0);
        $text = "امتیازهای من\n"
            . "امتیاز فعلی: " . number_format($points) . "\n";
        $guide = $this->getSetting('points_guide_text', 'اینجا راهنمای امتیاز قرار می‌گیرد.');
        if ($guide !== '') {
            $text .= "\n{$guide}";
        }
        $pointsUnit = (float)$this->getSetting('points_convert_points_unit', '100');
        $amountUnit = (float)$this->getSetting('points_convert_amount_unit', '10000');
        if ($pointsUnit > 0 && $amountUnit > 0) {
            $text .= "\nنسبت تبدیل: هر " . number_format($pointsUnit) . " امتیاز = "
                . number_format($amountUnit) . " تومان";
        }
        $keyboard = [];
        if ($this->getSetting('points_conversion_enabled', '0') === '1') {
            $keyboard[] = [
                ['text' => $this->getSetting('points_conversion_label', '♻️ تبدیل امتیاز'), 'callback_data' => 'points:convert'],
            ];
        }
        if ($this->getSetting('points_transfer_enabled', '1') === '1') {
            $keyboard[] = [
                ['text' => 'انتقال امتیاز ➡️', 'callback_data' => 'points:transfer'],
            ];
        }
        $opts = [];
        if ($keyboard) {
            $opts['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }
        $this->telegram->sendMessage((int)$user['chat_id'], $text, $opts);
    }

    private function promptPointsConversion(array $user): void
    {
        if ($this->getSetting('points_conversion_enabled', '0') !== '1') {
            $this->telegram->sendMessage((int)$user['chat_id'], 'تبدیل امتیاز فعلاً فعال نیست.');
            return;
        }
        $pointsUnit = (float)$this->getSetting('points_convert_points_unit', '100');
        $amountUnit = (float)$this->getSetting('points_convert_amount_unit', '10000');
        if ($pointsUnit <= 0 || $amountUnit <= 0) {
            $this->telegram->sendMessage((int)$user['chat_id'], 'نسبت تبدیل به‌درستی تنظیم نشده است.');
            return;
        }
        $this->setUserState((int)$user['id'], 'awaiting_points_convert');
        $text = "چند امتیاز می‌خواهی تبدیل کنی؟\n"
            . "هر " . number_format($pointsUnit) . " امتیاز = " . number_format($amountUnit) . " تومان\n"
            . "عدد مورد نظر را وارد کن (یا /cancel برای لغو).";
        $this->telegram->sendMessage((int)$user['chat_id'], $text);
    }

    private function handlePointsConversionInput(array $user, string $text): void
    {
        $chatId = (int)$user['chat_id'];
        $clean = preg_replace('/[^\d]/', '', $text);
        if ($clean === '') {
            $this->telegram->sendMessage($chatId, 'فقط عدد امتیاز را ارسال کن.');
            return;
        }
        $pointsRequested = (int)$clean;
        if ($pointsRequested <= 0) {
            $this->telegram->sendMessage($chatId, 'مقدار وارد شده معتبر نیست.');
            return;
        }
        $pointsUnit = (float)$this->getSetting('points_convert_points_unit', '100');
        $amountUnit = (float)$this->getSetting('points_convert_amount_unit', '10000');
        if ($pointsUnit <= 0 || $amountUnit <= 0) {
            $this->telegram->sendMessage($chatId, 'نسبت تبدیل به‌درستی تنظیم نشده است.');
            return;
        }
        if (fmod($pointsRequested, $pointsUnit) > 0.00001) {
            $this->telegram->sendMessage($chatId, "مقدار باید ضریبی از " . number_format($pointsUnit) . " امتیاز باشد.");
            return;
        }
        $fresh = $this->getUserById((int)$user['id']) ?? $user;
        $currentPoints = (float)($fresh['points_balance'] ?? 0);
        if ($currentPoints + 1e-6 < $pointsRequested) {
            $this->telegram->sendMessage($chatId, 'امتیاز کافی نداری.');
            return;
        }
        $amount = ($pointsRequested / $pointsUnit) * $amountUnit;
        if ($amount <= 0) {
            $this->telegram->sendMessage($chatId, 'مقدار تبدیل محاسبه نشد.');
            return;
        }
        if (!$this->deductPoints((int)$user['id'], $pointsRequested, 'POINTS_CONVERT', ['amount' => $amount])) {
            $this->telegram->sendMessage($chatId, 'امتیاز کافی نیست یا همزمان مصرف شده است.');
            return;
        }
        $this->creditWallet((int)$user['id'], $amount);
        $this->clearUserState((int)$user['id']);
        $updated = $this->getUserById((int)$user['id']);
        $walletBalance = $updated ? (float)$updated['wallet_balance'] : 0;
        $remainingPoints = $updated ? (float)$updated['points_balance'] : 0;
        $msg = "تبدیل انجام شد ✅\n"
            . number_format($pointsRequested) . " امتیاز = " . number_format($amount) . " تومان\n"
            . "موجودی کیف پول: " . number_format($walletBalance) . " تومان\n"
            . "امتیاز باقی‌مانده: " . number_format($remainingPoints);
        $this->telegram->sendMessage($chatId, $msg);
    }

    private function promptPointsTransfer(array $user): void
    {
        $this->setUserState((int)$user['id'], 'awaiting_points_transfer_amount');
        $this->telegram->sendMessage((int)$user['chat_id'], 'چه تعداد امتیاز می‌خواهی منتقل کنی؟ عدد را بفرست یا /cancel بزن.');
    }

    private function handlePointsTransferAmount(array $user, string $text): void
    {
        $chatId = (int)$user['chat_id'];
        $clean = preg_replace('/[^\d]/', '', $text);
        if ($clean === '' || !is_numeric($clean)) {
            $this->telegram->sendMessage($chatId, 'مقدار را فقط به صورت عدد بفرست.');
            return;
        }
        $amount = (float)$clean;
        if ($amount <= 0) {
            $this->telegram->sendMessage($chatId, 'مقدار باید بیشتر از صفر باشد.');
            return;
        }
        $fresh = $this->getUserById((int)$user['id']) ?? $user;
        $current = (float)($fresh['points_balance'] ?? 0);
        if ($current + 1e-6 < $amount) {
            $this->telegram->sendMessage($chatId, 'امتیاز کافی نداری.');
            return;
        }
        $this->setUserState((int)$user['id'], 'awaiting_points_transfer_wallet', ['amount' => $amount]);
        $this->telegram->sendMessage($chatId, "مبلغ {$amount} امتیاز ثبت شد.\nشناسه کیف پول مقصد (Wallet ID) را بفرست یا /cancel بزن.");
    }

    private function handlePointsTransferWallet(array $user, string $text, array $payload): void
    {
        $chatId = (int)$user['chat_id'];
        $amount = (float)($payload['amount'] ?? 0);
        if ($amount <= 0) {
            $this->clearUserState((int)$user['id']);
            $this->telegram->sendMessage($chatId, 'مقدار انتقال نامعتبر است. دوباره تلاش کن.');
            return;
        }
        $walletId = trim($text);
        if ($walletId === '') {
            $this->telegram->sendMessage($chatId, 'شناسه کیف پول مقصد را بفرست.');
            return;
        }
        $target = $this->getUserByWalletId($walletId);
        if (!$target) {
            $this->telegram->sendMessage($chatId, 'کیف پولی با این شناسه پیدا نشد.');
            return;
        }
        if ((int)$target['id'] === (int)$user['id']) {
            $this->telegram->sendMessage($chatId, 'امتیاز را نمی‌توانی به خودت منتقل کنی.');
            return;
        }
        if (!$this->deductPoints((int)$user['id'], $amount, 'POINTS_TRANSFER_SEND', ['to_user_id' => $target['id'], 'wallet_id' => $walletId])) {
            $this->telegram->sendMessage($chatId, 'امتیاز کافی نیست.');
            return;
        }
        $this->addPoints((int)$target['id'], $amount, 'POINTS_TRANSFER_RECEIVE', ['from_user_id' => $user['id']]);
        $this->clearUserState((int)$user['id']);

        $senderMsg = "انتقال انجام شد ✅\n"
            . "مبلغ: " . number_format($amount) . " امتیاز\n"
            . "گیرنده: {$target['first_name']} (@{$target['username']})";
        $this->telegram->sendMessage($chatId, $senderMsg);

        $receiverMsg = "یک انتقال امتیاز دریافت کردی ✅\n"
            . "مبلغ: " . number_format($amount) . " امتیاز\n"
            . "فرستنده: {$user['first_name']} (@{$user['username']})";
        $this->telegram->sendMessage((int)$target['chat_id'], $receiverMsg);
    }

    private function promptReferralInput(array $user): void
    {
        $this->setUserState((int)$user['id'], 'awaiting_referral_code');
        $text = "کد دوستت را وارد کن تا تخفیف {$this->getSetting('referral_percent', '10')}٪ بگیری.\n"
            . "اگر کدی نداری /cancel بزن.";
        $this->telegram->sendMessage((int)$user['chat_id'], $text);
    }

    private function handleReferralCodeSubmission(array $user, string $code): void
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE referral_code=:code');
        $stmt->execute(['code' => $code]);
        $refUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$refUser || $refUser['id'] === $user['id']) {
            $this->telegram->sendMessage((int)$user['chat_id'], 'کد معتبر نیست.');
            return;
        }
        $alreadyHadRef = !empty($user['referred_by']);
        $this->db->prepare('UPDATE users SET referred_by=:code WHERE id=:id')
            ->execute(['code' => $code, 'id' => $user['id']]);
        $this->clearUserState((int)$user['id']);

        $inviterPoints = (float)$this->getSetting('referral_inviter_points', '0');
        $newUserPoints = (float)$this->getSetting('referral_new_user_points', '0');
        $pointsAwarded = false;
        if (!$alreadyHadRef) {
            if ($inviterPoints > 0) {
                $this->addPoints((int)$refUser['id'], $inviterPoints, 'REFERRAL_INVITER', ['invited_user_id' => $user['id']]);
                $pointsAwarded = true;
            }
            if ($newUserPoints > 0) {
                $this->addPoints((int)$user['id'], $newUserPoints, 'REFERRAL_NEW_USER', ['inviter_id' => $refUser['id']]);
                $pointsAwarded = true;
            }
        }

        $message = 'کد ارجاع ذخیره شد.';
        if ($pointsAwarded) {
            $message .= ' امتیاز به حساب‌ها اضافه شد.';
        } else {
            $message .= ' برای استفاده از تخفیف، هنگام خرید کد را وارد کن.';
        }
        $this->telegram->sendMessage((int)$user['chat_id'], $message);
    }

    private function sendMyPlans(array $user): void
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE user_id=:user ORDER BY created_at DESC LIMIT 10');
        $stmt->execute(['user' => $user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $this->telegram->sendMessage((int)$user['chat_id'], 'هنوز سفارشی ثبت نکردی.');
            return;
        }
        $lines = [];
        foreach ($rows as $row) {
            switch ($row['status']) {
                case 'awaiting_config':
                    $status = 'در انتظار کانفیگ';
                    break;
                case 'awaiting_funds':
                    $status = 'منتظر شارژ';
                    break;
                case 'delivered':
                    $status = 'تحویل شده';
                    break;
                case 'pending_admin':
                    $status = 'در انتظار تایید';
                    break;
                default:
                    $status = $row['status'];
                    break;
            }

            $label = $this->esc($row['plan_label']);
            $lines[] = "🔹 {$label} ({$row['id']})\n"
                . "وضعیت: {$status}\n"
                . "مبلغ: " . number_format((float)$row['final_price'] ?: (float)$row['price']) . " تومان";
        }
        $this->telegram->sendMessage((int)$user['chat_id'], implode("\n\n", $lines));
    }

    private function sendSupportInfo(array $user): void
    {
        $text = $this->getSetting('support_text', 'برای پشتیبانی به @saeedsalehiz پیام بده.');
        $this->telegram->sendMessage((int)$user['chat_id'], $text);
    }

    private function sendGuideSection(array $user): void
    {
        $text = $this->getSetting('guide_text', 'در حال حاضر توضیحی ثبت نشده.');
        $this->telegram->sendMessage((int)$user['chat_id'], $text);
        $stmt = $this->db->query('SELECT * FROM guide_images ORDER BY id ASC');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['media_type'] === 'document') {
                $this->telegram->sendDocument((int)$user['chat_id'], $row['file_id'], ['caption' => $row['caption']]);
            } else {
                $this->telegram->sendPhoto((int)$user['chat_id'], $row['file_id'], ['caption' => $row['caption']]);
            }
        }
    }

    private function sendStatusReport(): void
    {
        $stmt = $this->db->query('SELECT p.id AS plan_id,p.label,p.description,p.price,
            COALESCE(SUM(CASE WHEN o.status="awaiting_config" THEN 1 ELSE 0 END),0) AS waiting,
            COALESCE(SUM(CASE WHEN o.status="delivered" THEN 1 ELSE 0 END),0) AS delivered,
            COALESCE(COUNT(o.id),0) AS total
            FROM plan_options p
            LEFT JOIN orders o ON o.plan_id=p.id AND o.type="paid"
            WHERE p.kind="paid"
            GROUP BY p.id,p.label,p.description,p.price
            ORDER BY total DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $lines = [];
        foreach ($rows as $row) {
            $label = $this->esc($row['label']);
            $desc = $this->esc($row['description'] ?? '');
            $lines[] = "{$label} ({$row['plan_id']})\n"
                . "قیمت: " . number_format((float)$row['price']) . " تومان\n"
                . "توضیح: {$desc}\n"
                . "کل سفارش‌ها: {$row['total']} | در انتظار کانفیگ: {$row['waiting']} | تحویل شده: {$row['delivered']}";
        }
        if (!$lines) {
            $lines[] = 'هنوز سفارشی ثبت نشده.';
        }
        $this->telegram->sendMessage(ADMIN_ID, implode("\n\n", $lines));
    }

    private function deliverConfig(string $orderId, string $text, ?string $fileId): void
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            $this->telegram->sendMessage(ADMIN_ID, 'سفارشی با این شناسه نیست.');
            return;
        }
        $userStmt = $this->db->prepare('SELECT chat_id FROM users WHERE id=:id');
        $userStmt->execute(['id' => $order['user_id']]);
        $chatId = (int)$userStmt->fetchColumn();
        if (!$chatId) {
            return;
        }
        if ($fileId) {
            $this->telegram->sendDocument($chatId, $fileId, ['caption' => $text]);
        } else {
            $this->telegram->sendMessage($chatId, $text);
        }
        $this->db->prepare('UPDATE orders SET status="delivered", updated_at=:u WHERE id=:id')
            ->execute(['u' => date('c'), 'id' => $orderId]);
        if (($order['type'] ?? '') === 'trial') {
            $this->db->prepare('UPDATE users SET last_trial_at=:t WHERE id=:id')
                ->execute(['t' => date('c'), 'id' => $order['user_id']]);
        }
        $this->telegram->sendMessage(ADMIN_ID, "سفارش {$orderId} تحویل کاربر شد.");
    }

    private function rejectOrder(string $orderId, string $reason): void
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            $this->telegram->sendMessage(ADMIN_ID, 'سفارشی با این شناسه نیست.');
            return;
        }
        $this->db->prepare('UPDATE orders SET status="rejected", updated_at=:u WHERE id=:id')
            ->execute(['u' => date('c'), 'id' => $orderId]);
        $chatId = (int)$this->db->query("SELECT chat_id FROM users WHERE id={$order['user_id']}")->fetchColumn();
        if ($chatId) {
            $this->telegram->sendMessage($chatId, "درخواست {$orderId} رد شد ❌\nدلیل: {$reason}");
        }
    }

    private function getAdminUserId(): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE chat_id=:chat');
        $stmt->execute(['chat' => ADMIN_ID]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
}

class TelegramClient
{
    private string $apiBase;

    public function __construct(string $token)
    {
        $this->apiBase = "https://api.telegram.org/bot{$token}/";
    }

    public function sendMessage(int $chatId, string $text, array $options = []): void
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options);
        if (!isset($payload['parse_mode'])) {
            $payload['parse_mode'] = 'HTML';
        }
        $this->request('sendMessage', $payload);
    }

    public function getChatMember(string $chatId, int $userId): ?array
    {
        $response = $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
        return $response['result'] ?? null;
    }

    public function sendPhoto(int $chatId, string $fileId, array $options = []): void
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'photo' => $fileId,
        ], $options);
        $this->request('sendPhoto', $payload);
    }

    public function sendDocument(int $chatId, string $fileId, array $options = []): void
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'document' => $fileId,
        ], $options);
        $this->request('sendDocument', $payload);
    }

    public function answerCallbackQuery(string $id, string $text = ''): void
    {
        $payload = ['callback_query_id' => $id];
        if ($text !== '') {
            $payload['text'] = $text;
        }
        $this->request('answerCallbackQuery', $payload);
    }

    private function request(string $method, array $params): ?array
    {
        $ch = curl_init($this->apiBase . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        if ($response === false) {
            error_log('Telegram API error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $decoded = json_decode($response, true);
        if (!$decoded || !($decoded['ok'] ?? false)) {
            error_log('Telegram API response: ' . $response);
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        return $decoded;
    }
}
