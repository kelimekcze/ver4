<?php
// api/register.php (OPRAVENO)
// Kontrola session - spustit pouze pokud není aktivní
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    include_once '../config/database.php';
    include_once '../classes/User.php';
    
    $database = new Database();
    $db = $database->connect();
    $user = new User($db);
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    // Validace povinných polí
    $required_fields = ['username', 'email', 'password', 'full_name', 'user_type'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Pole {$field} je povinné"]);
            exit;
        }
    }
    
    // Validace emailu
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatný formát emailu']);
        exit;
    }
    
    // Validace délky hesla
    if (strlen($data['password']) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Heslo musí mít alespoň 6 znaků']);
        exit;
    }
    
    // Nastavení výchozích hodnot
    $data['phone'] = $data['phone'] ?? null;
    $data['company_name'] = $data['company_name'] ?? null;
    $data['truck_license_plate'] = $data['truck_license_plate'] ?? null;
    $data['driver_license'] = $data['driver_license'] ?? null;
    $data['company_id'] = null; // Bude nastaveno až při přiřazení k firmě
    
    try {
        // Kontrola duplicitního emailu
        if ($user->emailExists($data['email'])) {
            http_response_code(409);
            echo json_encode(['error' => 'Email je již registrován']);
            exit;
        }
        
        // Kontrola duplicitního uživatelského jména
        if ($user->usernameExists($data['username'])) {
            http_response_code(409);
            echo json_encode(['error' => 'Uživatelské jméno je již použito']);
            exit;
        }
        
        $user_id = $user->register($data);
        
        if ($user_id) {
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'user_id' => $user_id,
                'message' => 'Registrace úspěšná. Můžete se přihlásit.'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Chyba při registraci uživatele']);
        }
        
    } catch(Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Chyba serveru při registraci']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda není povolena']);
}
?>