<?php
// api/export.php - Data export API endpoint
session_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
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

function checkExportAccess($user) {
    if (!in_array($user['user_type'], ['super_admin', 'admin', 'logistics'])) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Nemáte oprávnění k exportu dat',
            'code' => 'INSUFFICIENT_PERMISSIONS'
        ]);
        exit;
    }
}

function exportToCSV($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    if (!empty($data)) {
        // Write header
        fputcsv($output, array_keys($data[0]), ';');
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row, ';');
        }
    }
    
    fclose($output);
    exit;
}

function exportToJSON($data, $filename) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    
    echo json_encode([
        'export_date' => date('Y-m-d H:i:s'),
        'data_count' => count($data),
        'data' => $data
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function getDashboardData($db, $companyId = null) {
    try {
        // Get booking statistics
        $statsQuery = "
            SELECT 
                DATE(b.created_at) as date,
                COUNT(*) as total_bookings,
                SUM(CASE WHEN b.booking_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN b.booking_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN b.booking_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN b.booking_status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN b.booking_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM bookings b
            JOIN time_slots ts ON b.time_slot_id = ts.id
            JOIN warehouses w ON ts.warehouse_id = w.id
            WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        
        if ($companyId) {
            $statsQuery .= " AND w.company_id = :company_id";
        }
        
        $statsQuery .= " GROUP BY DATE(b.created_at) ORDER BY date DESC";
        
        $stmt = $db->prepare($statsQuery);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(Exception $e) {
        error_log("Dashboard export error: " . $e->getMessage());
        return [];
    }
}

function getBookingsData($db, $companyId = null, $dateFrom = null, $dateTo = null) {
    try {
        $query = "
            SELECT 
                b.id,
                b.created_at,
                ts.slot_date,
                ts.slot_time,
                ts.duration_minutes,
                w.name as warehouse_name,
                w.address as warehouse_address,
                b.truck_license_plate,
                b.cargo_type,
                b.cargo_weight,
                b.estimated_duration,
                b.booking_status,
                b.check_in_time,
                b.check_out_time,
                b.actual_duration,
                b.rating,
                b.feedback,
                u.full_name as driver_name,
                u.phone as driver_phone,
                u.email as driver_email
            FROM bookings b
            JOIN time_slots ts ON b.time_slot_id = ts.id
            JOIN warehouses w ON ts.warehouse_id = w.id
            JOIN users u ON b.driver_id = u.id
            WHERE 1=1
        ";
        
        if ($companyId) {
            $query .= " AND w.company_id = :company_id";
        }
        
        if ($dateFrom) {
            $query .= " AND ts.slot_date >= :date_from";
        }
        
        if ($dateTo) {
            $query .= " AND ts.slot_date <= :date_to";
        }
        
        $query .= " ORDER BY ts.slot_date DESC, ts.slot_time DESC";
        
        $stmt = $db->prepare($query);
        
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        if ($dateFrom) {
            $stmt->bindParam(':date_from', $dateFrom);
        }
        if ($dateTo) {
            $stmt->bindParam(':date_to', $dateTo);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(Exception $e) {
        error_log("Bookings export error: " . $e->getMessage());
        return [];
    }
}

function getSlotsData($db, $companyId = null, $dateFrom = null, $dateTo = null) {
    try {
        $query = "
            SELECT 
                ts.id,
                ts.slot_date,
                ts.slot_time,
                ts.duration_minutes,
                ts.max_capacity,
                ts.slot_type,
                ts.notes,
                ts.created_at,
                w.name as warehouse_name,
                w.address as warehouse_address,
                COUNT(b.id) as total_bookings,
                SUM(CASE WHEN b.booking_status IN ('pending', 'confirmed', 'in_progress') THEN 1 ELSE 0 END) as active_bookings,
                u.full_name as created_by_name
            FROM time_slots ts
            JOIN warehouses w ON ts.warehouse_id = w.id
            LEFT JOIN bookings b ON ts.id = b.time_slot_id
            LEFT JOIN users u ON ts.created_by = u.id
            WHERE ts.is_active = 1
        ";
        
        if ($companyId) {
            $query .= " AND w.company_id = :company_id";
        }
        
        if ($dateFrom) {
            $query .= " AND ts.slot_date >= :date_from";
        }
        
        if ($dateTo) {
            $query .= " AND ts.slot_date <= :date_to";
        }
        
        $query .= " GROUP BY ts.id ORDER BY ts.slot_date DESC, ts.slot_time DESC";
        
        $stmt = $db->prepare($query);
        
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        if ($dateFrom) {
            $stmt->bindParam(':date_from', $dateFrom);
        }
        if ($dateTo) {
            $stmt->bindParam(':date_to', $dateTo);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(Exception $e) {
        error_log("Slots export error: " . $e->getMessage());
        return [];
    }
}

function getWarehousesData($db, $companyId = null) {
    try {
        $query = "
            SELECT 
                w.*,
                COUNT(ts.id) as total_slots,
                COUNT(CASE WHEN ts.slot_date >= CURDATE() THEN ts.id END) as future_slots
            FROM warehouses w
            LEFT JOIN time_slots ts ON w.id = ts.warehouse_id AND ts.is_active = 1
            WHERE w.is_active = 1
        ";
        
        if ($companyId) {
            $query .= " AND w.company_id = :company_id";
        }
        
        $query .= " GROUP BY w.id ORDER BY w.name";
        
        $stmt = $db->prepare($query);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(Exception $e) {
        error_log("Warehouses export error: " . $e->getMessage());
        return [];
    }
}

function getUsersData($db, $companyId = null) {
    try {
        $query = "
            SELECT 
                id,
                username,
                email,
                full_name,
                phone,
                user_type,
                company_name,
                truck_license_plate,
                driver_license,
                is_active,
                created_at,
                last_login
            FROM users
            WHERE 1=1
        ";
        
        if ($companyId) {
            $query .= " AND company_id = :company_id";
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($query);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(Exception $e) {
        error_log("Users export error: " . $e->getMessage());
        return [];
    }
}

$database = new Database();
$db = $database->connect();
$user = authenticate();
checkExportAccess($user);

// Check license
if (isset($_SESSION['company_id'])) {
    try {
        checkLicenseMiddleware($_SESSION['company_id']);
    } catch(Exception $e) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Neplatná licence firmy - export není dostupný',
            'code' => 'LICENSE_EXPIRED'
        ]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda není povolena']);
    exit;
}

try {
    $type = $_GET['type'] ?? '';
    $format = $_GET['format'] ?? 'csv';
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $companyId = $user['user_type'] === 'super_admin' ? ($_GET['company_id'] ?? null) : $_SESSION['company_id'];
    
    $data = [];
    $filename = '';
    
    switch ($type) {
        case 'dashboard':
            $data = getDashboardData($db, $companyId);
            $filename = 'dashboard_statistics_' . date('Y-m-d');
            break;
            
        case 'bookings':
            $data = getBookingsData($db, $companyId, $dateFrom, $dateTo);
            $filename = 'bookings_' . date('Y-m-d');
            if ($dateFrom || $dateTo) {
                $filename .= '_' . ($dateFrom ?? 'all') . '_to_' . ($dateTo ?? 'all');
            }
            break;
            
        case 'slots':
            $data = getSlotsData($db, $companyId, $dateFrom, $dateTo);
            $filename = 'time_slots_' . date('Y-m-d');
            if ($dateFrom || $dateTo) {
                $filename .= '_' . ($dateFrom ?? 'all') . '_to_' . ($dateTo ?? 'all');
            }
            break;
            
        case 'warehouses':
            $data = getWarehousesData($db, $companyId);
            $filename = 'warehouses_' . date('Y-m-d');
            break;
            
        case 'users':
            if ($user['user_type'] !== 'super_admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Nemáte oprávnění k exportu uživatelů']);
                exit;
            }
            $data = getUsersData($db, $companyId);
            $filename = 'users_' . date('Y-m-d');
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Neplatný typ exportu',
                'valid_types' => ['dashboard', 'bookings', 'slots', 'warehouses', 'users']
            ]);
            exit;
    }
    
    if (empty($data)) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Žádná data k exportu',
            'type' => $type
        ]);
        exit;
    }
    
    // Log export action
    error_log("Data export: user_id={$user['user_id']}, type={$type}, format={$format}, rows=" . count($data));
    
    switch ($format) {
        case 'csv':
            exportToCSV($data, $filename . '.csv');
            break;
            
        case 'json':
            exportToJSON($data, $filename . '.json');
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Neplatný formát exportu',
                'valid_formats' => ['csv', 'json']
            ]);
            exit;
    }
    
} catch(Exception $e) {
    error_log('Export error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Chyba při exportu dat',
        'message' => $e->getMessage()
    ]);
}
?>