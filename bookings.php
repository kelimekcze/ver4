<?php
// api/bookings.php - Vylepšená verze
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../config/database.php';
include_once '../classes/Booking.php';
include_once '../middleware/license_check.php';

function authenticate() {
    if(!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Neautorizovaný přístup',
            'code' => 'UNAUTHORIZED',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    return $_SESSION;
}

function logError($message, $context = []) {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
        'user_id' => $_SESSION['user_id'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    error_log('BOOKING_API: ' . json_encode($logEntry));
}

function validateBookingData($data) {
    $errors = [];
    
    if (empty($data['time_slot_id'])) {
        $errors[] = 'Časový slot je povinný';
    }
    
    if (empty($data['truck_license_plate'])) {
        $errors[] = 'SPZ vozidla je povinná';
    } elseif (!preg_match('/^[A-Z0-9]{2,3}\s?[0-9]{4}$/', $data['truck_license_plate'])) {
        $errors[] = 'Neplatný formát SPZ (např. 1A2 3456)';
    }
    
    if (isset($data['cargo_weight']) && $data['cargo_weight'] > 0) {
        if ($data['cargo_weight'] > 50000) {
            $errors[] = 'Maximální hmotnost nákladu je 50 tun';
        }
    }
    
    if (isset($data['estimated_duration']) && $data['estimated_duration'] > 0) {
        if ($data['estimated_duration'] > 480) {
            $errors[] = 'Maximální délka rezervace je 8 hodin';
        }
    }
    
    return $errors;
}

$database = new Database();
$db = $database->connect();
$booking = new Booking($db);

$user = authenticate();

// Check license for company users
if (isset($_SESSION['company_id'])) {
    try {
        checkLicenseMiddleware($_SESSION['company_id']);
    } catch(Exception $e) {
        logError('License check failed', ['company_id' => $_SESSION['company_id'], 'error' => $e->getMessage()]);
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
            if (isset($_GET['dashboard_stats'])) {
                // Dashboard statistiky
                $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                
                $stats = [
                    'pending' => 0,
                    'confirmed' => 0,
                    'in_progress' => 0,
                    'completed_today' => 0,
                    'total_bookings' => 0
                ];
                
                // Počítání podle statusů
                $statusQuery = "SELECT booking_status, COUNT(*) as count 
                               FROM bookings b 
                               JOIN time_slots ts ON b.time_slot_id = ts.id 
                               JOIN warehouses w ON ts.warehouse_id = w.id";
                
                if ($company_id) {
                    $statusQuery .= " WHERE w.company_id = :company_id";
                }
                
                $statusQuery .= " AND b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY booking_status";
                
                $stmt = $db->prepare($statusQuery);
                if ($company_id) {
                    $stmt->bindParam(':company_id', $company_id);
                }
                $stmt->execute();
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $stats[$row['booking_status']] = $row['count'];
                    $stats['total_bookings'] += $row['count'];
                }
                
                // Dnešní dokončené
                $todayQuery = "SELECT COUNT(*) as count FROM bookings b 
                              JOIN time_slots ts ON b.time_slot_id = ts.id 
                              JOIN warehouses w ON ts.warehouse_id = w.id
                              WHERE b.booking_status = 'completed' 
                              AND DATE(b.check_out_time) = CURDATE()";
                
                if ($company_id) {
                    $todayQuery .= " AND w.company_id = :company_id";
                }
                
                $todayStmt = $db->prepare($todayQuery);
                if ($company_id) {
                    $todayStmt->bindParam(':company_id', $company_id);
                }
                $todayStmt->execute();
                $stats['completed_today'] = $todayStmt->fetch(PDO::FETCH_COLUMN);
                
                echo json_encode([
                    'success' => true,
                    'stats' => $stats,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } elseif (isset($_GET['upcoming'])) {
                // Nadcházející rezervace
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
                $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                
                $query = "SELECT b.*, ts.slot_date, ts.slot_time, ts.duration_minutes,
                                 w.name as warehouse_name, w.address as warehouse_address,
                                 u.full_name as driver_name, u.phone as driver_phone
                          FROM bookings b
                          JOIN time_slots ts ON b.time_slot_id = ts.id
                          JOIN warehouses w ON ts.warehouse_id = w.id
                          JOIN users u ON b.driver_id = u.id
                          WHERE b.booking_status IN ('pending', 'confirmed', 'in_progress')
                          AND CONCAT(ts.slot_date, ' ', ts.slot_time) >= NOW()";
                
                if ($company_id) {
                    $query .= " AND w.company_id = :company_id";
                }
                
                $query .= " ORDER BY ts.slot_date ASC, ts.slot_time ASC LIMIT :limit";
                
                $stmt = $db->prepare($query);
                if ($company_id) {
                    $stmt->bindParam(':company_id', $company_id);
                }
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                
                $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'bookings' => $bookings,
                    'count' => count($bookings)
                ]);
                
            } elseif (isset($_GET['driver_id'])) {
                $driver_id = intval($_GET['driver_id']);
                
                // Check access rights
                if ($user['user_type'] === 'driver' && $user['user_id'] != $driver_id) {
                    http_response_code(403);
                    echo json_encode([
                        'error' => 'Nemáte oprávnění k těmto rezervacím',
                        'code' => 'ACCESS_DENIED'
                    ]);
                    exit;
                }
                
                $bookings = $booking->getDriverBookings($driver_id);
                
                echo json_encode([
                    'success' => true,
                    'bookings' => $bookings
                ]);
                
            } else {
                // Všechny rezervace
                if ($user['user_type'] === 'driver') {
                    $bookings = $booking->getDriverBookings($user['user_id']);
                } else {
                    $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                    $bookings = $booking->getAllBookings($company_id);
                }
                
                echo json_encode([
                    'success' => true,
                    'bookings' => $bookings
                ]);
            }
            
        } catch(Exception $e) {
            logError('GET request failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při načítání dat',
                'code' => 'FETCH_ERROR',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'POST':
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
            $errors = validateBookingData($data);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chyby ve validaci',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $errors
                ]);
                exit;
            }
            
            // Set driver ID
            if ($user['user_type'] === 'driver') {
                $data['driver_id'] = $user['user_id'];
            } elseif (!isset($data['driver_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'ID řidiče je povinné',
                    'code' => 'MISSING_DRIVER_ID'
                ]);
                exit;
            }
            
            // Set default values
            $data['cargo_type'] = $data['cargo_type'] ?? '';
            $data['cargo_weight'] = $data['cargo_weight'] ?? null;
            $data['estimated_duration'] = $data['estimated_duration'] ?? 60;
            $data['special_requirements'] = $data['special_requirements'] ?? '';
            $data['booking_notes'] = $data['booking_notes'] ?? '';
            
            $booking_id = $booking->create($data);
            
            if ($booking_id) {
                logError('Booking created successfully', [
                    'booking_id' => $booking_id,
                    'slot_id' => $data['time_slot_id'],
                    'driver_id' => $data['driver_id']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'booking_id' => $booking_id,
                    'message' => 'Rezervace byla úspěšně vytvořena',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chyba při vytváření rezervace',
                    'code' => 'CREATION_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            logError('POST request failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při vytváření rezervace',
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'PUT':
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data || !isset($data['booking_id']) || !isset($data['action'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chybí povinná data (booking_id, action)',
                    'code' => 'MISSING_REQUIRED_DATA'
                ]);
                exit;
            }
            
            $booking_id = intval($data['booking_id']);
            $action = $data['action'];
            
            switch($action) {
                case 'update_status':
                    if ($user['user_type'] === 'driver') {
                        http_response_code(403);
                        echo json_encode([
                            'error' => 'Nemáte oprávnění měnit stav rezervace',
                            'code' => 'INSUFFICIENT_PERMISSIONS'
                        ]);
                        exit;
                    }
                    
                    $new_status = $data['status'];
                    $valid_statuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
                    
                    if (!in_array($new_status, $valid_statuses)) {
                        http_response_code(400);
                        echo json_encode([
                            'error' => 'Neplatný stav rezervace',
                            'code' => 'INVALID_STATUS',
                            'valid_statuses' => $valid_statuses
                        ]);
                        exit;
                    }
                    
                    $result = $booking->updateStatus($booking_id, $new_status, $user['user_id']);
                    $message = 'Stav rezervace byl aktualizován';
                    break;
                    
                case 'cancel':
                    $result = $booking->cancelBooking($booking_id, $user['user_id']);
                    $message = 'Rezervace byla zrušena';
                    break;
                    
                case 'checkin':
                    if ($user['user_type'] === 'driver') {
                        http_response_code(403);
                        echo json_encode([
                            'error' => 'Nemáte oprávnění k check-in',
                            'code' => 'INSUFFICIENT_PERMISSIONS'
                        ]);
                        exit;
                    }
                    
                    $result = $booking->checkIn($booking_id);
                    $message = 'Check-in proveden';
                    break;
                    
                case 'checkout':
                    if ($user['user_type'] === 'driver') {
                        http_response_code(403);
                        echo json_encode([
                            'error' => 'Nemáte oprávnění k check-out',
                            'code' => 'INSUFFICIENT_PERMISSIONS'
                        ]);
                        exit;
                    }
                    
                    $result = $booking->checkOut($booking_id);
                    $message = 'Check-out proveden';
                    break;
                    
                default:
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Neplatná akce',
                        'code' => 'INVALID_ACTION',
                        'valid_actions' => ['update_status', 'cancel', 'checkin', 'checkout']
                    ]);
                    exit;
            }
            
            if ($result) {
                logError("Booking action completed", [
                    'booking_id' => $booking_id,
                    'action' => $action,
                    'user_id' => $user['user_id']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Chyba při provádění akce',
                    'code' => 'ACTION_FAILED'
                ]);
            }
            
        } catch(Exception $e) {
            logError('PUT request failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba serveru při aktualizaci rezervace',
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
            'allowed_methods' => ['GET', 'POST', 'PUT']
        ]);
        break;
}
?>