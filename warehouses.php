<?php
// api/warehouses.php - Kompletní s oprávněními a GET endpoint pro jednotlivý sklad
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../config/database.php';
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

function checkCreateDeleteAccess($user) {
    // Pouze super_admin může vytvářet a mazat sklady
    if ($user['user_type'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění k této akci',
            'message' => 'Pouze Super Admin může vytvářet a mazat sklady',
            'code' => 'INSUFFICIENT_PERMISSIONS'
        ]);
        exit;
    }
}

function checkEditAccess($user) {
    // Super admin, admin a logistics mohou editovat
    if (!in_array($user['user_type'], ['super_admin', 'admin', 'logistics'])) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění k úpravě skladů',
            'message' => 'Pouze Admin a Logistika mohou upravovat sklady',
            'code' => 'INSUFFICIENT_PERMISSIONS'
        ]);
        exit;
    }
}

function checkViewAccess($user) {
    // Všichni ověření uživatelé mohou zobrazit sklady
    if (!in_array($user['user_type'], ['super_admin', 'admin', 'logistics', 'driver'])) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění k zobrazení skladů',
            'code' => 'INSUFFICIENT_PERMISSIONS'
        ]);
        exit;
    }
}

function validateWarehouseData($data, $isEdit = false) {
    $errors = [];
    
    // Název je povinný
    if (empty($data['name']) || trim($data['name']) === '') {
        $errors[] = 'Název skladu je povinný';
    }
    
    // Validace emailu (pokud je zadán)
    if (!empty($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Neplatný formát emailu';
    }
    
    // Validace pracovní doby
    if (!empty($data['working_hours_start']) && !empty($data['working_hours_end'])) {
        if ($data['working_hours_start'] >= $data['working_hours_end']) {
            $errors[] = 'Čas konce musí být později než čas začátku';
        }
    }
    
    // Validace maximálního počtu slotů
    if (isset($data['max_simultaneous_slots'])) {
        $maxSlots = intval($data['max_simultaneous_slots']);
        if ($maxSlots < 1 || $maxSlots > 100) {
            $errors[] = 'Maximální počet slotů musí být mezi 1 a 100';
        }
    }
    
    return $errors;
}

function logWarehouseAction($action, $warehouse_id, $user_id, $data = null) {
    try {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'warehouse_id' => $warehouse_id,
            'user_id' => $user_id,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        error_log('WAREHOUSE_ACTION: ' . json_encode($logEntry));
    } catch (Exception $e) {
        error_log('Warehouse action log error: ' . $e->getMessage());
    }
}

$database = new Database();
$db = $database->connect();
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
        checkViewAccess($user);
        
        try {
            $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
            
            // Pokud je požadován konkrétní sklad
            if (isset($_GET['id'])) {
                $warehouse_id = intval($_GET['id']);
                
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
                // Všechny sklady
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
                
                echo json_encode([
                    'success' => true,
                    'warehouses' => $warehouses,
                    'count' => count($warehouses),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
            
        } catch(Exception $e) {
            error_log('Warehouse GET error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při načítání skladů',
                'code' => 'DATABASE_ERROR',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'POST':
        // Pouze super_admin může vytvářet sklady
        checkCreateDeleteAccess($user);
        
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
            
            // Validace
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
            
            // Set company_id for non-super-admin users
            $company_id = $user['user_type'] === 'super_admin' ? ($data['company_id'] ?? null) : $_SESSION['company_id'];
            
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
                
                logWarehouseAction('created', $warehouse_id, $user['user_id'], $data);
                
                echo json_encode([
                    'success' => true,
                    'warehouse_id' => $warehouse_id,
                    'message' => 'Sklad byl úspěšně vytvořen',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Chyba při vytváření skladu',
                    'code' => 'CREATION_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            error_log('Warehouse POST error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při vytváření skladu',
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'PUT':
        // Admin, logistics a super_admin mohou editovat
        checkEditAccess($user);
        
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data || !isset($data['warehouse_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'ID skladu je povinné',
                    'code' => 'MISSING_WAREHOUSE_ID'
                ]);
                exit;
            }
            
            $warehouse_id = intval($data['warehouse_id']);
            
            // Validace
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
            
            // Get current warehouse for logging
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
            
            $currentWarehouse = $currentStmt->fetch(PDO::FETCH_ASSOC);
            
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
            
            // Add company restriction for non-super-admin
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
                logWarehouseAction('updated', $warehouse_id, $user['user_id'], [
                    'old' => $currentWarehouse,
                    'new' => $data
                ]);
                
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
            error_log('Warehouse PUT error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při aktualizaci skladu',
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'DELETE':
        // Pouze super_admin může mazat sklady
        checkCreateDeleteAccess($user);
        
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data || !isset($data['warehouse_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'ID skladu je povinné',
                    'code' => 'MISSING_WAREHOUSE_ID'
                ]);
                exit;
            }
            
            $warehouse_id = intval($data['warehouse_id']);
            
            // Get warehouse for logging
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
            
            $warehouse = $warehouseStmt->fetch(PDO::FETCH_ASSOC);
            
            // Zkontrolujme, zda sklad nemá aktivní sloty
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
            
            // Zkontrolujme, zda sklad nemá aktivní rezervace
            $bookingsQuery = "SELECT COUNT(*) as active_bookings 
                             FROM bookings b 
                             JOIN time_slots ts ON b.time_slot_id = ts.id 
                             WHERE ts.warehouse_id = :warehouse_id 
                             AND b.booking_status IN ('pending', 'confirmed', 'in_progress')";
            $bookingsStmt = $db->prepare($bookingsQuery);
            $bookingsStmt->bindParam(':warehouse_id', $warehouse_id);
            $bookingsStmt->execute();
            
            $bookingsResult = $bookingsStmt->fetch(PDO::FETCH_ASSOC);
            if ($bookingsResult['active_bookings'] > 0) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Nelze smazat sklad s aktivními rezervacemi',
                    'code' => 'HAS_ACTIVE_BOOKINGS',
                    'active_bookings' => $bookingsResult['active_bookings']
                ]);
                exit;
            }
            
            // Soft delete
            $query = "UPDATE warehouses SET is_active = 0, updated_at = NOW() WHERE id = :warehouse_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouse_id);
            
            if ($stmt->execute()) {
                logWarehouseAction('deleted', $warehouse_id, $user['user_id'], $warehouse);
                
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
            error_log('Warehouse DELETE error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při mazání skladu',
                'code' => 'SERVER_ERROR',
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