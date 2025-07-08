<?php
// api/login.php - OPRAVENÁ VERZE
// Nastavení error reportingu
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
session_start();

// CORS hlavičky - musí být první!
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log incoming request for debugging
error_log("LOGIN REQUEST: " . $_SERVER['REQUEST_METHOD'] . " from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda není povolena', 'method' => $_SERVER['REQUEST_METHOD']]);
    exit;
}

try {
    // Check if files exist
    $databasePath = __DIR__ . '/../config/database.php';
    $userPath = __DIR__ . '/../classes/User.php';
    
    if (!file_exists($databasePath)) {
        throw new Exception("Database config file not found at: $databasePath");
    }
    
    if (!file_exists($userPath)) {
        throw new Exception("User class file not found at: $userPath");
    }
    
    // Include required files
    require_once $databasePath;
    require_once $userPath;
    
    // Get input data
    $input = file_get_contents("php://input");
    error_log("LOGIN INPUT: " . $input);
    
    if (empty($input)) {
        throw new Exception('No input data received');
    }
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }
    
    if (empty($data['email']) || empty($data['password'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Email a heslo jsou povinné',
            'received_data' => array_keys($data)
        ]);
        exit;
    }
    
    // Database connection
    $database = new Database();
    $db = $database->connect();
    $user = new User($db);
    
    error_log("ATTEMPTING LOGIN for: " . $data['email']);
    
    // Attempt login
    $user_data = $user->login($data['email'], $data['password']);
    
    if ($user_data) {
        error_log("LOGIN SUCCESS for user ID: " . $user_data['id']);
        
        // Check license validity for company users
        if ($user_data['company_id']) {
            $licensePath = __DIR__ . '/../classes/LicenseManager.php';
            if (file_exists($licensePath)) {
                require_once $licensePath;
                $licenseManager = new LicenseManager($db);
                $licenseCheck = $licenseManager->checkLicenseValidity($user_data['company_id']);
                
                if (!$licenseCheck['is_valid']) {
                    error_log("LICENSE INVALID for company: " . $user_data['company_id']);
                    http_response_code(403);
                    echo json_encode([
                        'error' => 'Licence firmy není platná',
                        'message' => $licenseCheck['message'],
                        'license_expired' => true
                    ]);
                    exit;
                }
            }
        }
        
        // Set session variables
        $_SESSION['user_id'] = $user_data['id'];
        $_SESSION['user_type'] = $user_data['user_type'];
        $_SESSION['full_name'] = $user_data['full_name'];
        $_SESSION['company_id'] = $user_data['company_id'];
        
        error_log("SESSION SET for user: " . $user_data['id'] . ", session_id: " . session_id());
        
        // Return success response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'user' => $user_data,
            'message' => 'Přihlášení úspěšné',
            'session_id' => session_id()
        ]);
        
    } else {
        error_log("LOGIN FAILED for: " . $data['email']);
        http_response_code(401);
        echo json_encode([
            'error' => 'Nesprávné přihlašovací údaje',
            'email_attempted' => $data['email']
        ]);
    }
    
} catch(PDOException $e) {
    error_log("DATABASE ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Chyba databáze',
        'message' => 'Nelze se připojit k databázi'
    ]);
    
} catch(Exception $e) {
    error_log("LOGIN ERROR: " . $e->getMessage());
    error_log("STACK TRACE: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Chyba serveru při přihlašování',
        'debug_message' => $e->getMessage()
    ]);
}
?>