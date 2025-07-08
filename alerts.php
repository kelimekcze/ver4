<?php
// api/alerts.php - System alerts and notifications API
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../config/database.php';
include_once '../classes/LicenseManager.php';
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

function checkCriticalIssues($db, $companyId = null, $userId = null) {
    $alerts = [];
    
    try {
        // Check license expiry
        if ($companyId) {
            $licenseManager = new LicenseManager($db);
            $licenseInfo = $licenseManager->getCompanyLicenseInfo($companyId);
            
            if ($licenseInfo) {
                $daysRemaining = $licenseInfo['days_remaining'] ?? 0;
                
                if ($daysRemaining <= 0) {
                    $alerts[] = [
                        'type' => 'error',
                        'category' => 'license',
                        'title' => 'Licence vypršela',
                        'message' => 'Licence vaší firmy vypršela. Kontaktujte administrátora.',
                        'priority' => 'critical',
                        'action_required' => true
                    ];
                } elseif ($daysRemaining <= 3) {
                    $alerts[] = [
                        'type' => 'warning',
                        'category' => 'license',
                        'title' => 'Licence brzy vyprší',
                        'message' => "Licence vaší firmy vyprší za {$daysRemaining} dní.",
                        'priority' => 'high',
                        'action_required' => true
                    ];
                } elseif ($daysRemaining <= 7) {
                    $alerts[] = [
                        'type' => 'info',
                        'category' => 'license',
                        'title' => 'Upozornění na expiraci licence',
                        'message' => "Licence vaší firmy vyprší za {$daysRemaining} dní.",
                        'priority' => 'medium',
                        'action_required' => false
                    ];
                }
            }
        }
        
        // Check for conflicts in time slots
        $conflictsQuery = "
            SELECT COUNT(*) as conflicts
            FROM time_slots ts1
            JOIN time_slots ts2 ON ts1.warehouse_id = ts2.warehouse_id 
                AND ts1.slot_date = ts2.slot_date 
                AND ts1.id != ts2.id
                AND ts1.is_active = 1 AND ts2.is_active = 1
            WHERE (
                (ts1.slot_time <= ts2.slot_time AND ADDTIME(ts1.slot_time, SEC_TO_TIME(ts1.duration_minutes * 60)) > ts2.slot_time)
                OR 
                (ts2.slot_time <= ts1.slot_time AND ADDTIME(ts2.slot_time, SEC_TO_TIME(ts2.duration_minutes * 60)) > ts1.slot_time)
            )
        ";
        
        if ($companyId) {
            $conflictsQuery .= " AND EXISTS (SELECT 1 FROM warehouses w WHERE w.id = ts1.warehouse_id AND w.company_id = :company_id)";
        }
        
        $stmt = $db->prepare($conflictsQuery);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        $conflicts = $stmt->fetch(PDO::FETCH_COLUMN);
        
        if ($conflicts > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'slots',
                'title' => 'Konfliktní časové sloty',
                'message' => "Nalezeno {$conflicts} konfliktních časových slotů.",
                'priority' => 'medium',
                'action_required' => true
            ];
        }
        
        // Check for overdue bookings
        $overdueQuery = "
            SELECT COUNT(*) as overdue
            FROM bookings b
            JOIN time_slots ts ON b.time_slot_id = ts.id
            JOIN warehouses w ON ts.warehouse_id = w.id
            WHERE b.booking_status IN ('confirmed', 'in_progress')
            AND CONCAT(ts.slot_date, ' ', ts.slot_time) < NOW() - INTERVAL 2 HOUR
        ";
        
        if ($companyId) {
            $overdueQuery .= " AND w.company_id = :company_id";
        }
        
        $stmt = $db->prepare($overdueQuery);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        $overdue = $stmt->fetch(PDO::FETCH_COLUMN);
        
        if ($overdue > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'bookings',
                'title' => 'Zpožděné rezervace',
                'message' => "{$overdue} rezervací je více než 2 hodiny po plánovaném čase.",
                'priority' => 'high',
                'action_required' => true
            ];
        }
        
        // Check warehouse capacity issues
        $capacityQuery = "
            SELECT w.name, COUNT(ts.id) as active_slots, w.max_simultaneous_slots
            FROM warehouses w
            LEFT JOIN time_slots ts ON w.id = ts.warehouse_id 
                AND ts.slot_date = CURDATE() 
                AND ts.is_active = 1
            WHERE w.is_active = 1
        ";
        
        if ($companyId) {
            $capacityQuery .= " AND w.company_id = :company_id";
        }
        
        $capacityQuery .= " GROUP BY w.id HAVING active_slots > max_simultaneous_slots";
        
        $stmt = $db->prepare($capacityQuery);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        $overCapacity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($overCapacity as $warehouse) {
            $alerts[] = [
                'type' => 'error',
                'category' => 'capacity',
                'title' => 'Překročena kapacita skladu',
                'message' => "Sklad {$warehouse['name']} má {$warehouse['active_slots']} slotů, ale maximální kapacita je {$warehouse['max_simultaneous_slots']}.",
                'priority' => 'critical',
                'action_required' => true
            ];
        }
        
        // Check for pending bookings older than 24 hours
        $pendingQuery = "
            SELECT COUNT(*) as old_pending
            FROM bookings b
            JOIN time_slots ts ON b.time_slot_id = ts.id
            JOIN warehouses w ON ts.warehouse_id = w.id
            WHERE b.booking_status = 'pending'
            AND b.created_at < NOW() - INTERVAL 24 HOUR
        ";
        
        if ($companyId) {
            $pendingQuery .= " AND w.company_id = :company_id";
        }
        
        $stmt = $db->prepare($pendingQuery);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        $oldPending = $stmt->fetch(PDO::FETCH_COLUMN);
        
        if ($oldPending > 0) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'bookings',
                'title' => 'Dlouho čekající rezervace',
                'message' => "{$oldPending} rezervací čeká na potvrzení více než 24 hodin.",
                'priority' => 'low',
                'action_required' => false
            ];
        }
        
    } catch(Exception $e) {
        error_log("Critical issues check error: " . $e->getMessage());
        $alerts[] = [
            'type' => 'error',
            'category' => 'system',
            'title' => 'Chyba systému',
            'message' => 'Nepodařilo se zkontrolovat stav systému.',
            'priority' => 'medium',
            'action_required' => false
        ];
    }
    
    return $alerts;
}

function getSystemHealth($db, $companyId = null) {
    $health = [
        'overall_status' => 'healthy',
        'database' => 'connected',
        'api_response_time' => round(microtime(true) * 1000),
        'active_users' => 0,
        'active_bookings' => 0,
        'system_load' => 'normal'
    ];
    
    try {
        // Check active users (logged in last 24 hours)
        $usersQuery = "SELECT COUNT(*) FROM users WHERE last_login > NOW() - INTERVAL 24 HOUR AND is_active = 1";
        if ($companyId) {
            $usersQuery .= " AND company_id = :company_id";
        }
        
        $stmt = $db->prepare($usersQuery);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        $health['active_users'] = $stmt->fetch(PDO::FETCH_COLUMN);
        
        // Check active bookings
        $bookingsQuery = "
            SELECT COUNT(*) FROM bookings b
            JOIN time_slots ts ON b.time_slot_id = ts.id
            JOIN warehouses w ON ts.warehouse_id = w.id
            WHERE b.booking_status IN ('confirmed', 'in_progress')
            AND ts.slot_date >= CURDATE()
        ";
        
        if ($companyId) {
            $bookingsQuery .= " AND w.company_id = :company_id";
        }
        
        $stmt = $db->prepare($bookingsQuery);
        if ($companyId) {
            $stmt->bindParam(':company_id', $companyId);
        }
        $stmt->execute();
        $health['active_bookings'] = $stmt->fetch(PDO::FETCH_COLUMN);
        
        // Determine overall status based on issues
        $alerts = checkCriticalIssues($db, $companyId);
        $criticalCount = count(array_filter($alerts, function($alert) {
            return $alert['priority'] === 'critical';
        }));
        
        if ($criticalCount > 0) {
            $health['overall_status'] = 'critical';
        } elseif (count($alerts) > 3) {
            $health['overall_status'] = 'warning';
        }
        
    } catch(Exception $e) {
        error_log("System health check error: " . $e->getMessage());
        $health['overall_status'] = 'error';
        $health['database'] = 'error';
    }
    
    return $health;
}

$database = new Database();
$db = $database->connect();
$user = authenticate();

// Check license
if (isset($_SESSION['company_id'])) {
    try {
        checkLicenseMiddleware($_SESSION['company_id']);
    } catch(Exception $e) {
        // License expired - still show alerts but with warning
    }
}

switch($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        try {
            $companyId = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
            
            if (isset($_GET['critical']) && $_GET['critical'] == '1') {
                // Get critical alerts only
                $alerts = checkCriticalIssues($db, $companyId, $user['user_id']);
                
                // Filter only critical and high priority
                $criticalAlerts = array_filter($alerts, function($alert) {
                    return in_array($alert['priority'], ['critical', 'high']);
                });
                
                echo json_encode([
                    'success' => true,
                    'alerts' => array_values($criticalAlerts),
                    'count' => count($criticalAlerts),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } elseif (isset($_GET['health'])) {
                // Get system health status
                $health = getSystemHealth($db, $companyId);
                
                echo json_encode([
                    'success' => true,
                    'health' => $health,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } else {
                // Get all alerts
                $alerts = checkCriticalIssues($db, $companyId, $user['user_id']);
                
                echo json_encode([
                    'success' => true,
                    'alerts' => $alerts,
                    'count' => count($alerts),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
            
        } catch(Exception $e) {
            error_log('Alerts GET error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při načítání upozornění',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'POST':
        // Mark alert as read or dismissed
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Neplatná JSON data']);
                exit;
            }
            
            if (isset($data['action']) && $data['action'] === 'dismiss_alert') {
                // In a real implementation, you'd store dismissed alerts in database
                // For now, just return success
                echo json_encode([
                    'success' => true,
                    'message' => 'Upozornění bylo označeno jako přečtené',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Neplatná akce']);
            }
            
        } catch(Exception $e) {
            error_log('Alerts POST error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při zpracování požadavku',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'error' => 'Metoda není povolena',
            'allowed_methods' => ['GET', 'POST']
        ]);
        break;
}
?>