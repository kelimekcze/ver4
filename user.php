<?php
// api/user.php - User management API endpoint
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../config/database.php';
include_once '../classes/User.php';
include_once '../middleware/license_check.php';

function authenticate() {
    if(!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Neautorizovaný přístup',
            'code' => 'UNAUTHORIZED'
        ]);
        exit;
    }
    return $_SESSION;
}

function checkAdminAccess($user) {
    if (!in_array($user['user_type'], ['super_admin', 'admin'])) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění ke správě uživatelů',
            'code' => 'INSUFFICIENT_PERMISSIONS'
        ]);
        exit;
    }
}

function validateUserData($data, $isUpdate = false) {
    $errors = [];
    
    if (!$isUpdate || isset($data['username'])) {
        if (empty($data['username'])) {
            $errors[] = 'Uživatelské jméno je povinné';
        }
    }
    
    if (!$isUpdate || isset($data['email'])) {
        if (empty($data['email'])) {
            $errors[] = 'Email je povinný';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Neplatný formát emailu';
        }
    }
    
    if (!$isUpdate || isset($data['password'])) {
        if (!$isUpdate && empty($data['password'])) {
            $errors[] = 'Heslo je povinné';
        } elseif (!empty($data['password']) && strlen($data['password']) < 6) {
            $errors[] = 'Heslo musí mít alespoň 6 znaků';
        }
    }
    
    if (!$isUpdate || isset($data['full_name'])) {
        if (empty($data['full_name'])) {
            $errors[] = 'Celé jméno je povinné';
        }
    }
    
    if (!$isUpdate || isset($data['user_type'])) {
        $validTypes = ['super_admin', 'admin', 'logistics', 'driver'];
        if (!in_array($data['user_type'], $validTypes)) {
            $errors[] = 'Neplatný typ uživatele';
        }
    }
    
    return $errors;
}

function logUserAction($action, $user_id, $admin_id, $data = null) {
    try {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'user_id' => $user_id,
            'admin_id' => $admin_id,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        error_log('USER_ACTION: ' . json_encode($logEntry));
    } catch (Exception $e) {
        error_log('User action log error: ' . $e->getMessage());
    }
}

$database = new Database();
$db = $database->connect();
$userManager = new User($db);

$user = authenticate();

// Check license for company users
if (isset($_SESSION['company_id'])) {
    try {
        checkLicenseMiddleware($_SESSION['company_id']);
    } catch(Exception $e) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Neplatná licence firmy',
            'code' => 'LICENSE_EXPIRED',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

switch($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        try {
            if (isset($_GET['id'])) {
                // Get specific user by ID
                $userId = intval($_GET['id']);
                
                // Check permissions
                if ($user['user_type'] !== 'super_admin' && $user['user_id'] != $userId) {
                    if ($user['user_type'] !== 'admin' || 
                        !isset($_SESSION['company_id']) || 
                        $user['company_id'] != $_SESSION['company_id']) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Nemáte oprávnění k tomuto uživateli']);
                        exit;
                    }
                }
                
                $userData = $userManager->getUserById($userId);
                
                if ($userData) {
                    echo json_encode([
                        'success' => true,
                        'user' => $userData
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Uživatel nenalezen']);
                }
                
            } else {
                // Get all users (admin only)
                checkAdminAccess($user);
                
                $companyId = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                $users = $userManager->getAllUsers($companyId);
                
                echo json_encode([
                    'success' => true,
                    'users' => $users,
                    'count' => count($users)
                ]);
            }
            
        } catch(Exception $e) {
            error_log('User GET error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při načítání uživatelů',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'POST':
        checkAdminAccess($user);
        
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Neplatná JSON data',
                    'code' => 'INVALID_JSON'
                ]);
                exit;
            }
            
            // Validation
            $errors = validateUserData($data);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chyby ve validaci',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $errors
                ]);
                exit;
            }
            
            // Check if email/username already exists
            if ($userManager->emailExists($data['email'])) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Email je již registrován',
                    'code' => 'EMAIL_EXISTS'
                ]);
                exit;
            }
            
            if ($userManager->usernameExists($data['username'])) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Uživatelské jméno je již použito',
                    'code' => 'USERNAME_EXISTS'
                ]);
                exit;
            }
            
            // Set company_id for non-super-admin users
            if ($user['user_type'] !== 'super_admin') {
                $data['company_id'] = $_SESSION['company_id'];
            }
            
            // Set default values
            $data['phone'] = $data['phone'] ?? null;
            $data['company_name'] = $data['company_name'] ?? null;
            $data['truck_license_plate'] = $data['truck_license_plate'] ?? null;
            $data['driver_license'] = $data['driver_license'] ?? null;
            
            $userId = $userManager->register($data);
            
            if ($userId) {
                logUserAction('created', $userId, $user['user_id'], [
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'user_type' => $data['user_type']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'user_id' => $userId,
                    'message' => 'Uživatel byl úspěšně vytvořen',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Chyba při vytváření uživatele',
                    'code' => 'CREATION_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            error_log('User POST error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při vytváření uživatele',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'PUT':
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data || !isset($data['user_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'ID uživatele je povinné',
                    'code' => 'MISSING_USER_ID'
                ]);
                exit;
            }
            
            $userId = intval($data['user_id']);
            
            // Check permissions
            if ($user['user_type'] !== 'super_admin') {
                if ($user['user_id'] != $userId) {
                    // Admin can edit users in their company
                    if ($user['user_type'] !== 'admin') {
                        http_response_code(403);
                        echo json_encode(['error' => 'Nemáte oprávnění upravovat jiné uživatele']);
                        exit;
                    }
                    
                    // Check if user belongs to same company
                    $targetUser = $userManager->getUserById($userId);
                    if (!$targetUser || $targetUser['company_id'] != $_SESSION['company_id']) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Nemáte oprávnění k tomuto uživateli']);
                        exit;
                    }
                }
            }
            
            // Validation for updates
            $errors = validateUserData($data, true);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chyby ve validaci',
                    'errors' => $errors
                ]);
                exit;
            }
            
            // Check email/username conflicts
            if (isset($data['email']) && $userManager->emailExists($data['email'], $userId)) {
                http_response_code(409);
                echo json_encode(['error' => 'Email je již použit jiným uživatelem']);
                exit;
            }
            
            if (isset($data['username']) && $userManager->usernameExists($data['username'], $userId)) {
                http_response_code(409);
                echo json_encode(['error' => 'Uživatelské jméno je již použito']);
                exit;
            }
            
            // Update user profile
            $result = $userManager->updateProfile($userId, $data);
            
            // Update password if provided
            if (!empty($data['password'])) {
                if (!empty($data['old_password'])) {
                    $result = $userManager->changePassword($userId, $data['old_password'], $data['password']);
                } else if ($user['user_type'] === 'super_admin' || $user['user_type'] === 'admin') {
                    // Admin can change password without old password
                    $newHash = password_hash($data['password'], PASSWORD_DEFAULT);
                    $query = "UPDATE users SET password_hash = :password_hash WHERE id = :user_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':password_hash', $newHash);
                    $stmt->bindParam(':user_id', $userId);
                    $result = $stmt->execute();
                }
            }
            
            if ($result) {
                logUserAction('updated', $userId, $user['user_id'], $data);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Uživatel byl úspěšně aktualizován',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Chyba při aktualizaci uživatele',
                    'code' => 'UPDATE_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            error_log('User PUT error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při aktualizaci uživatele',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'DELETE':
        checkAdminAccess($user);
        
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data || !isset($data['user_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'ID uživatele je povinné',
                    'code' => 'MISSING_USER_ID'
                ]);
                exit;
            }
            
            $userId = intval($data['user_id']);
            
            // Cannot delete yourself
            if ($userId == $user['user_id']) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Nemůžete smazat svůj vlastní účet',
                    'code' => 'CANNOT_DELETE_SELF'
                ]);
                exit;
            }
            
            // Check permissions
            if ($user['user_type'] !== 'super_admin') {
                $targetUser = $userManager->getUserById($userId);
                if (!$targetUser || $targetUser['company_id'] != $_SESSION['company_id']) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Nemáte oprávnění k tomuto uživateli']);
                    exit;
                }
            }
            
            // Soft delete - deactivate user
            $result = $userManager->setUserStatus($userId, 0);
            
            if ($result) {
                logUserAction('deleted', $userId, $user['user_id']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Uživatel byl deaktivován',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Chyba při mazání uživatele',
                    'code' => 'DELETE_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            error_log('User DELETE error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při mazání uživatele',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'error' => 'Metoda není povolena',
            'code' => 'METHOD_NOT_ALLOWED',
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ]);
        break;
}
?>