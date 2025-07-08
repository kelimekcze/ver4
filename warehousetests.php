<?php
// api/warehouses.php - DEBUG VERZE pro diagnostiku problémů
error_reporting(E_ALL);
ini_set('display_errors', 1);

// LOG všechno pro debugging
error_log("WAREHOUSE API: Request started - " . $_SERVER['REQUEST_METHOD']);
error_log("WAREHOUSE API: Session status: " . session_status());

// Start session pouze pokud není aktivní
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS a JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log session data
error_log("WAREHOUSE API: Session data: " . json_encode([
    'user_id' => $_SESSION['user_id'] ?? 'not_set',
    'user_type' => $_SESSION['user_type'] ?? 'not_set',
    'company_id' => $_SESSION['company_id'] ?? 'not_set'
]));

// Try to include files
try {
    $dbPath = __DIR__ . '/../config/database.php';
    $licensePath = __DIR__ . '/../middleware/license_check.php';
    
    if (!file_exists($dbPath)) {
        throw new Exception("Database config not found at: $dbPath");
    }
    
    if (!file_exists($licensePath)) {
        error_log("WAREHOUSE API: License middleware not found, skipping");
    } else {
        include_once $licensePath;
    }
    
    include_once $dbPath;
    
} catch (Exception $e) {
    error_log("WAREHOUSE API: Include error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server configuration error',
        'debug' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

function authenticate() {
    error_log("WAREHOUSE API: Checking authentication");
    
    if(!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        error_log("WAREHOUSE API: Authentication failed - no session data");
        http_response_code(401);
        echo json_encode([
            'error' => 'Neautorizovaný přístup',
            'code' => 'UNAUTHORIZED',
            'debug' => [
                'session_user_id' => isset($_SESSION['user_id']) ? 'set' : 'not_set',
                'session_user_type' => isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'not_set'
            ]
        ]);
        exit;
    }
    
    error_log("WAREHOUSE API: Authentication successful for user: " . $_SESSION['user_id']);
    return $_SESSION;
}

function checkCreateDeleteAccess($user) {
    error_log("WAREHOUSE API: Checking create/delete access for user type: " . $user['user_type']);
    
    if ($user['user_type'] !== 'super_admin') {
        error_log("WAREHOUSE API: Create/delete access denied");
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění k této akci',
            'message' => 'Pouze Super Admin může vytvářet a mazat sklady',
            'code' => 'INSUFFICIENT_PERMISSIONS',
            'debug' => [
                'user_type' => $user['user_type'],
                'required' => 'super_admin'
            ]
        ]);
        exit;
    }
    
    error_log("WAREHOUSE API: Create/delete access granted");
}

function checkEditAccess($user) {
    error_log("WAREHOUSE API: Checking edit access for user type: " . $user['user_type']);
    
    if (!in_array($user['user_type'], ['super_admin', 'admin', 'logistics'])) {
        error_log("WAREHOUSE API: Edit access denied");
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění k úpravě skladů',
            'message' => 'Pouze Admin a Logistika mohou upravovat sklady',
            'code' => 'INSUFFICIENT_PERMISSIONS',
            'debug' => [
                'user_type' => $user['user_type'],
                'required' => ['super_admin', 'admin', 'logistics']
            ]
        ]);
        exit;
    }
    
    error_log("WAREHOUSE API: Edit access granted");
}

function checkViewAccess($user) {
    error_log("WAREHOUSE API: Checking view access for user type: " . $user['user_type']);
    
    if (!in_array($user['user_type'], ['super_admin', 'admin', 'logistics', 'driver'])) {
        error_log("WAREHOUSE API: View access denied");
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění k zobrazení skladů',
            'code' => 'INSUFFICIENT_PERMISSIONS',
            'debug' => [
                'user_type' => $user['user_type'],
                'required' => ['super_admin', 'admin', 'logistics', 'driver']
            ]
        ]);
        exit;
    }
    
    error_log("WAREHOUSE API: View access granted");
}

function validateWarehouseData($data, $isEdit = false) {
    error_log("WAREHOUSE API: Validating data: " . json_encode($data));
    
    $errors = [];
    
    if (empty($data['name']) || trim($data['name']) === '') {
        $errors[] = 'Název skladu je povinný';
    }
    
    if (!empty($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Neplatný formát emailu';
    }
    
    if (!empty($data['working_hours_start']) && !empty($data['working_hours_end'])) {
        if ($data['working_hours_start'] >= $data['working_hours_end']) {
            $errors[] = 'Čas konce musí být později než čas začátku';
        }
    }
    
    if (isset($data['max_simultaneous_slots'])) {
        $maxSlots = intval($data['max_simultaneous_slots']);
        if ($maxSlots < 1 || $maxSlots > 100) {
            $errors[] = 'Maximální počet slotů musí být mezi 1 a 100';
        }
    }
    
    if (!empty($errors)) {
        error_log("WAREHOUSE API: Validation errors: " . json_encode($errors));
    }
    
    return $errors;
}

// Initialize database
try {
    error_log("WAREHOUSE API: Connecting to database");
    $database = new Database();
    $db = $database->connect();
    error_log("WAREHOUSE API: Database connected successfully");
} catch (Exception $e) {
    error_log("WAREHOUSE API: Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'debug' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Authenticate user
$user = authenticate();

// Check license for company users
if (isset($_SESSION['company_id'])) {
    error_log("WAREHOUSE API: Checking license for company: " . $_SESSION['company_id']);
    try {
        if (function_exists('checkLicenseMiddleware')) {
            checkLicenseMiddleware($_SESSION['company_id']);
        } else {
            error_log("WAREHOUSE API: License middleware function not available");
        }
    } catch(Exception $e) {
        error_log("WAREHOUSE API: License check failed: " . $e->getMessage());
        http_response_code(403);
        echo json_encode([
            'error' => 'Neplatná licence firmy',
            'code' => 'LICENSE_EXPIRED',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle request method
error_log("WAREHOUSE API: Processing " . $_SERVER['REQUEST_METHOD'] . " request");

switch($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        checkViewAccess($user);
        
        try {
            $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
            error_log("WAREHOUSE API: GET request for company_id: " . ($company_id ?? 'all'));
            
            if (isset($_GET['id'])) {
                $warehouse_id = intval($_GET['id']);
                error_log("WAREHOUSE API: GET specific warehouse: " . $warehouse_id);
                
                $query = "SELECT * FROM warehouses WHERE id = :warehouse_id AND is_active = 1";
                if ($company_id) {
                    $query .= " AND company_id = :company_id";
                }
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':warehouse_id', $warehouse_id);
                if ($company_id) {
                    $stmt->bindParam(':company_id', $company_id);
                }
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode([
                        'success' => true,
                        'warehouse' => $warehouse,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'error' => 'Sklad nenalezen',
                        'code' => 'WAREHOUSE_NOT_FOUND'
                    ]);
                }
                
            } else {
                error_log("WAREHOUSE API: GET all warehouses");
                
                $query = "SELECT w.*, COUNT(ts.id) as active_slots_count 
                         FROM warehouses w 
                         LEFT JOIN time_slots ts ON w.id = ts.warehouse_id AND ts.is_active = 1
                         WHERE w.is_active = 1";
                
                if ($company_id) {
                    $query .= " AND w.company_id = :company_id";
                }
                
                $query .= " GROUP BY w.id ORDER BY w.name";
                
                $stmt = $db->prepare($query);
                if ($company_id) {
                    $stmt->bindParam(':company_id', $company_id);
                }
                $stmt->execute();
                
                $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("WAREHOUSE API: Found " . count($warehouses) . " warehouses");
                
                echo json_encode([
                    'success' => true,
                    'warehouses' => $warehouses,
                    'count' => count($warehouses),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
            
        } catch(Exception $e) {
            error_log('WAREHOUSE API: GET error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při načítání skladů',
                'code' => 'DATABASE_ERROR',
                'debug' => $e->getMessage()
            ]);
        }
        break;
        
    case 'POST':
        checkCreateDeleteAccess($user);
        
        try {
            error_log("WAREHOUSE API: POST request started");
            
            $input = file_get_contents("php://input");
            error_log("WAREHOUSE API: Raw input: " . $input);
            
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("WAREHOUSE API: JSON decode error: " . json_last_error_msg());
                http_response_code(400);
                echo json_encode([
                    'error' => 'Neplatná JSON data',
                    'code' => 'INVALID_JSON',
                    'debug' => json_last_error_msg(),
                    'raw_input' => substr($input, 0, 200)
                ]);
                exit;
            }
            
            if (!$data) {
                error_log("WAREHOUSE API: No data received");
                http_response_code(400);
                echo json_encode([
                    'error' => 'Žádná data nebyla přijata',
                    'code' => 'NO_DATA',
                    'raw_input' => substr($input, 0, 200)
                ]);
                exit;
            }
            
            error_log("WAREHOUSE API: Decoded data: " . json_encode($data));
            
            $errors = validateWarehouseData($data);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chyby ve validaci',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $errors
                ]);
                exit;
            }
            
            $company_id = $user['user_type'] === 'super_admin' ? ($data['company_id'] ?? null) : $_SESSION['company_id'];
            error_log("WAREHOUSE API: Creating warehouse for company_id: " . ($company_id ?? 'null'));
            
            $query = "INSERT INTO warehouses (
                        company_id, name, address, contact_person, contact_phone, contact_email, 
                        working_hours_start, working_hours_end, max_simultaneous_slots, created_by
                      ) VALUES (
                        :company_id, :name, :address, :contact_person, :contact_phone, :contact_email, 
                        :working_hours_start, :working_hours_end, :max_simultaneous_slots, :created_by
                      )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->bindParam(':name', trim($data['name']));
            $stmt->bindParam(':address', $data['address'] ?? '');
            $stmt->bindParam(':contact_person', $data['contact_person'] ?? '');
            $stmt->bindParam(':contact_phone', $data['contact_phone'] ?? '');
            $stmt->bindParam(':contact_email', $data['contact_email'] ?? '');
            $stmt->bindParam(':working_hours_start', $data['working_hours_start'] ?? '08:00:00');
            $stmt->bindParam(':working_hours_end', $data['working_hours_end'] ?? '16:00:00');
            $stmt->bindParam(':max_simultaneous_slots', $data['max_simultaneous_slots'] ?? 5);
            $stmt->bindParam(':created_by', $user['user_id']);
            
            if ($stmt->execute()) {
                $warehouse_id = $db->lastInsertId();
                error_log("WAREHOUSE API: Warehouse created successfully with ID: " . $warehouse_id);
                
                echo json_encode([
                    'success' => true,
                    'warehouse_id' => $warehouse_id,
                    'message' => 'Sklad byl úspěšně vytvořen',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                error_log("WAREHOUSE API: Failed to execute INSERT query");
                $errorInfo = $stmt->errorInfo();
                error_log("WAREHOUSE API: PDO Error: " . json_encode($errorInfo));
                
                http_response_code(500);
                echo json_encode([
                    'error' => 'Chyba při vytváření skladu',
                    'code' => 'CREATION_FAILED',
                    'debug' => $errorInfo
                ]);
            }
            
        } catch(Exception $e) {
            error_log('WAREHOUSE API: POST error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při vytváření skladu',
                'code' => 'SERVER_ERROR',
                'debug' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        break;
        
    case 'PUT':
        checkEditAccess($user);
        
        try {
            $input = file_get_contents("php://input");
            error_log("WAREHOUSE API: PUT raw input: " . $input);
            
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['warehouse_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'ID skladu je povinné',
                    'code' => 'MISSING_WAREHOUSE_ID'
                ]);
                exit;
            }
            
            $warehouse_id = intval($data['warehouse_id']);
            
            $errors = validateWarehouseData($data, true);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chyby ve validaci',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $errors
                ]);
                exit;
            }
            
            $currentQuery = "SELECT * FROM warehouses WHERE id = :warehouse_id AND is_active = 1";
            if ($user['user_type'] !== 'super_admin') {
                $currentQuery .= " AND company_id = :company_id";
            }
            
            $currentStmt = $db->prepare($currentQuery);
            $currentStmt->bindParam(':warehouse_id', $warehouse_id);
            if ($user['user_type'] !== 'super_admin') {
                $currentStmt->bindParam(':company_id', $_SESSION['company_id']);
            }
            $currentStmt->execute();
            
            if ($currentStmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Sklad nenalezen nebo nemáte oprávnění',
                    'code' => 'WAREHOUSE_NOT_FOUND'
                ]);
                exit;
            }
            
            $query = "UPDATE warehouses SET 
                        name = :name, 
                        address = :address, 
                        contact_person = :contact_person, 
                        contact_phone = :contact_phone, 
                        contact_email = :contact_email, 
                        working_hours_start = :working_hours_start, 
                        working_hours_end = :working_hours_end, 
                        max_simultaneous_slots = :max_simultaneous_slots,
                        updated_at = NOW()
                      WHERE id = :warehouse_id";
            
            if ($user['user_type'] !== 'super_admin') {
                $query .= " AND company_id = :company_id";
            }
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouse_id);
            $stmt->bindParam(':name', trim($data['name']));
            $stmt->bindParam(':address', $data['address'] ?? '');
            $stmt->bindParam(':contact_person', $data['contact_person'] ?? '');
            $stmt->bindParam(':contact_phone', $data['contact_phone'] ?? '');
            $stmt->bindParam(':contact_email', $data['contact_email'] ?? '');
            $stmt->bindParam(':working_hours_start', $data['working_hours_start'] ?? '08:00:00');
            $stmt->bindParam(':working_hours_end', $data['working_hours_end'] ?? '16:00:00');
            $stmt->bindParam(':max_simultaneous_slots', $data['max_simultaneous_slots'] ?? 5);
            
            if ($user['user_type'] !== 'super_admin') {
                $stmt->bindParam(':company_id', $_SESSION['company_id']);
            }
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Sklad byl úspěšně aktualizován',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Chyba při aktualizaci skladu',
                    'code' => 'UPDATE_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            error_log('WAREHOUSE API: PUT error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při aktualizaci skladu',
                'code' => 'SERVER_ERROR',
                'debug' => $e->getMessage()
            ]);
        }
        break;
        
    case 'DELETE':
        checkCreateDeleteAccess($user);
        
        try {
            $input = file_get_contents("php://input");
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['warehouse_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'ID skladu je povinné',
                    'code' => 'MISSING_WAREHOUSE_ID'
                ]);
                exit;
            }
            
            $warehouse_id = intval($data['warehouse_id']);
            
            $warehouseQuery = "SELECT * FROM warehouses WHERE id = :warehouse_id AND is_active = 1";
            $warehouseStmt = $db->prepare($warehouseQuery);
            $warehouseStmt->bindParam(':warehouse_id', $warehouse_id);
            $warehouseStmt->execute();
            
            if ($warehouseStmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Sklad nenalezen',
                    'code' => 'WAREHOUSE_NOT_FOUND'
                ]);
                exit;
            }
            
            $checkQuery = "SELECT COUNT(*) as active_slots FROM time_slots WHERE warehouse_id = :warehouse_id AND is_active = 1";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':warehouse_id', $warehouse_id);
            $checkStmt->execute();
            
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($result['active_slots'] > 0) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Nelze smazat sklad s aktivními časovými sloty',
                    'code' => 'HAS_ACTIVE_SLOTS',
                    'active_slots' => $result['active_slots']
                ]);
                exit;
            }
            
            $query = "UPDATE warehouses SET is_active = 0, updated_at = NOW() WHERE id = :warehouse_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouse_id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Sklad byl úspěšně smazán',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Chyba při mazání skladu',
                    'code' => 'DELETE_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            error_log('WAREHOUSE API: DELETE error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při mazání skladu',
                'code' => 'SERVER_ERROR',
                'debug' => $e->getMessage()
            ]);
        }
        break;
        
    default:
        error_log("WAREHOUSE API: Method not allowed: " . $_SERVER['REQUEST_METHOD']);
        http_response_code(405);
        echo json_encode([
            'error' => 'Metoda není povolena',
            'code' => 'METHOD_NOT_ALLOWED',
            'method' => $_SERVER['REQUEST_METHOD'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ]);
        break;
}

error_log("WAREHOUSE API: Request completed successfully");
?>