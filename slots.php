<?php
// api/slots.php - Complete Time Slots API with Calendar Support (KOMPLETNÍ OPRAVENÁ VERZE)
session_start();

// Headers pro CORS a JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
try {
    include_once '../config/database.php';
    include_once '../classes/TimeSlot.php';
    
    // License check je volitelný
    if (file_exists('../middleware/license_check.php')) {
        include_once '../middleware/license_check.php';
    }
} catch (Exception $e) {
    error_log("SLOTS API: Include error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server configuration error',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Authentication function
 */
function authenticate() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Neautorizovaný přístup',
            'code' => 'UNAUTHORIZED',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    return $_SESSION;
}

/**
 * Check admin access for create/update/delete operations
 */
function checkAdminAccess($user) {
    if (!in_array($user['user_type'], ['super_admin', 'admin', 'logistics'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Nemáte oprávnění ke správě slotů',
            'code' => 'INSUFFICIENT_PERMISSIONS',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}

/**
 * Validate slot data
 */
function validateSlotData($data, $isUpdate = false) {
    $errors = [];
    
    // Warehouse validation
    if (!$isUpdate || isset($data['warehouse_id'])) {
        if (empty($data['warehouse_id'])) {
            $errors[] = 'Sklad je povinný';
        } elseif (!is_numeric($data['warehouse_id'])) {
            $errors[] = 'Neplatné ID skladu';
        }
    }
    
    // Date validation
    if (!$isUpdate || isset($data['slot_date'])) {
        if (empty($data['slot_date'])) {
            $errors[] = 'Datum je povinné';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['slot_date'])) {
            $errors[] = 'Neplatný formát data (YYYY-MM-DD)';
        } elseif (!$isUpdate && strtotime($data['slot_date']) < strtotime(date('Y-m-d'))) {
            $errors[] = 'Nelze vytvořit slot v minulosti';
        }
    }
    
    // Time validation
    if (!$isUpdate || isset($data['slot_time'])) {
        if (empty($data['slot_time'])) {
            $errors[] = 'Čas je povinný';
        } elseif (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $data['slot_time'])) {
            $errors[] = 'Neplatný formát času (HH:MM nebo HH:MM:SS)';
        }
    }
    
    // Duration validation
    if (isset($data['duration_minutes'])) {
        $duration = intval($data['duration_minutes']);
        if ($duration < 15 || $duration > 480) {
            $errors[] = 'Délka slotu musí být mezi 15 a 480 minuty';
        }
    }
    
    // Capacity validation
    if (isset($data['max_capacity'])) {
        $capacity = intval($data['max_capacity']);
        if ($capacity < 1 || $capacity > 50) {
            $errors[] = 'Kapacita musí být mezi 1 a 50';
        }
    }
    
    // Slot type validation
    if (isset($data['slot_type'])) {
        $allowedTypes = ['loading', 'unloading', 'both'];
        if (!in_array($data['slot_type'], $allowedTypes)) {
            $errors[] = 'Neplatný typ slotu (loading/unloading/both)';
        }
    }
    
    return $errors;
}

/**
 * Log slot actions for auditing
 */
function logSlotAction($action, $slot_id, $user_id, $old_data = null, $new_data = null) {
    try {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'slot_id' => $slot_id,
            'user_id' => $user_id,
            'old_data' => $old_data,
            'new_data' => $new_data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        error_log('SLOT_ACTION: ' . json_encode($logEntry));
    } catch (Exception $e) {
        error_log('Slot action log error: ' . $e->getMessage());
    }
}

/**
 * Format response consistently
 */
function sendResponse($success, $data = null, $error = null, $httpCode = 200) {
    http_response_code($httpCode);
    
    $response = [
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($success && $data !== null) {
        $response = array_merge($response, $data);
    }
    
    if (!$success && $error !== null) {
        $response['error'] = $error;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Initialize database and objects
try {
    $database = new Database();
    $db = $database->connect();
    $timeSlot = new TimeSlot($db);
} catch (Exception $e) {
    error_log("SLOTS API: Database connection error: " . $e->getMessage());
    sendResponse(false, null, 'Chyba připojení k databázi', 500);
}

// Authenticate user
$user = authenticate();

// Check license for company users (if middleware exists)
if (isset($_SESSION['company_id']) && function_exists('checkLicenseMiddleware')) {
    try {
        checkLicenseMiddleware($_SESSION['company_id'], 'calendar');
    } catch(Exception $e) {
        sendResponse(false, null, 'Neplatná licence firmy: ' . $e->getMessage(), 403);
    }
}

// Main API logic based on request method
switch($_SERVER['REQUEST_METHOD']) {
    
    case 'GET':
        try {
            error_log("SLOTS API GET: " . $_SERVER['QUERY_STRING']);
            
            // Get specific slot by ID
            if (isset($_GET['id'])) {
                $slot_id = intval($_GET['id']);
                $slot = $timeSlot->getSlotById($slot_id);
                
                if ($slot) {
                    // Check company access
                    if ($user['user_type'] !== 'super_admin' && 
                        isset($_SESSION['company_id']) && 
                        $slot['company_id'] != $_SESSION['company_id']) {
                        sendResponse(false, null, 'Nemáte oprávnění k tomuto slotu', 403);
                    }
                    
                    sendResponse(true, ['slot' => $slot]);
                } else {
                    sendResponse(false, null, 'Slot nenalezen', 404);
                }
            }
            
            // Get slots for date range (KLÍČOVÉ PRO KALENDÁŘ)
            elseif (isset($_GET['date_from']) && isset($_GET['date_to'])) {
                $date_from = $_GET['date_from'];
                $date_to = $_GET['date_to'];
                $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
                
                // Validate date format
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || 
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
                    sendResponse(false, null, 'Neplatný formát data (YYYY-MM-DD)', 400);
                }
                
                error_log("SLOTS API: Loading slots from $date_from to $date_to" . 
                         ($warehouse_id ? " for warehouse $warehouse_id" : ""));
                
                $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                $slots = $timeSlot->getSlotsForDateRange($date_from, $date_to, $company_id, $warehouse_id);
                
                error_log("SLOTS API: Found " . count($slots) . " slots");
                
                sendResponse(true, ['slots' => $slots]);
            }
            
            // Get available slots for specific warehouse and date
            elseif (isset($_GET['action']) && $_GET['action'] === 'available') {
                $warehouse_id = intval($_GET['warehouse_id'] ?? 0);
                $date = $_GET['date'] ?? '';
                
                if (!$warehouse_id || !$date) {
                    sendResponse(false, null, 'Warehouse ID a datum jsou povinné', 400);
                }
                
                $slots = $timeSlot->getAvailableSlots($warehouse_id, $date);
                sendResponse(true, ['available_slots' => $slots]);
            }
            
            // Get today's slots
            elseif (isset($_GET['action']) && $_GET['action'] === 'today') {
                $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                $slots = $timeSlot->getTodaysSlots($company_id);
                sendResponse(true, ['today_slots' => $slots]);
            }
            
            // Get upcoming slots
            elseif (isset($_GET['action']) && $_GET['action'] === 'upcoming') {
                $days = intval($_GET['days'] ?? 7);
                $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                $slots = $timeSlot->getUpcomingSlots($days, $company_id);
                sendResponse(true, ['upcoming_slots' => $slots]);
            }
            
            // Get slot statistics for dashboard
            elseif (isset($_GET['action']) && $_GET['action'] === 'summary') {
                $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                $summary = $timeSlot->getSlotsSummary($company_id);
                sendResponse(true, ['summary' => $summary]);
            }
            
            // Get slot utilization stats
            elseif (isset($_GET['action']) && $_GET['action'] === 'utilization') {
                $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
                $date_from = $_GET['date_from'] ?? null;
                $date_to = $_GET['date_to'] ?? null;
                
                $utilization = $timeSlot->getSlotUtilization($warehouse_id, $date_from, $date_to);
                sendResponse(true, ['utilization' => $utilization]);
            }
            
            // Get all slots (default)
            else {
                $company_id = $user['user_type'] === 'super_admin' ? null : $_SESSION['company_id'];
                $slots = $timeSlot->getAllSlots($company_id);
                sendResponse(true, ['slots' => $slots]);
            }
            
        } catch(Exception $e) {
            error_log('SLOTS API GET error: ' . $e->getMessage());
            sendResponse(false, null, 'Chyba při načítání slotů: ' . $e->getMessage(), 500);
        }
        break;
        
    case 'POST':
        checkAdminAccess($user);
        
        try {
            $input = file_get_contents("php://input");
            $data = json_decode($input, true);
            
            if (!$data) {
                sendResponse(false, null, 'Neplatná JSON data', 400);
            }
            
            error_log('SLOTS API POST: ' . json_encode($data));
            
            // Bulk create slots
            if (isset($data['action']) && $data['action'] === 'bulk_create') {
                $template = $data['template'] ?? [];
                $dates = $data['dates'] ?? [];
                
                if (empty($template) || empty($dates)) {
                    sendResponse(false, null, 'Template a data jsou povinné pro bulk vytváření', 400);
                }
                
                $template['created_by'] = $user['user_id'];
                $result = $timeSlot->bulkCreateSlots($template, $dates);
                
                if ($result['success']) {
                    logSlotAction('bulk_created', null, $user['user_id'], null, $result);
                    sendResponse(true, [
                        'message' => "Vytvořeno {$result['created']} slotů",
                        'created' => $result['created'],
                        'errors' => $result['errors']
                    ]);
                } else {
                    sendResponse(false, null, 'Chyba při bulk vytváření: ' . implode(', ', $result['errors']), 400);
                }
            }
            
            // Regular slot creation
            else {
                // Validation
                $errors = validateSlotData($data);
                if (!empty($errors)) {
                    sendResponse(false, null, 'Chyby ve validaci: ' . implode(', ', $errors), 400);
                }
                
                // Set default values
                $data['duration_minutes'] = $data['duration_minutes'] ?? 60;
                $data['max_capacity'] = $data['max_capacity'] ?? 1;
                $data['slot_type'] = $data['slot_type'] ?? 'unloading';
                $data['notes'] = $data['notes'] ?? '';
                $data['created_by'] = $user['user_id'];
                
                // Ensure time format includes seconds
                if (isset($data['slot_time']) && strlen($data['slot_time']) === 5) {
                    $data['slot_time'] .= ':00';
                }
                
                $result = $timeSlot->create($data);
                
                if ($result) {
                    logSlotAction('created', $result, $user['user_id'], null, $data);
                    sendResponse(true, [
                        'message' => 'Časový slot byl úspěšně vytvořen',
                        'slot_id' => $result
                    ]);
                } else {
                    sendResponse(false, null, 'Chyba při vytváření slotu', 400);
                }
            }
            
        } catch(Exception $e) {
            error_log('SLOTS API POST error: ' . $e->getMessage());
            sendResponse(false, null, 'Chyba při vytváření slotu: ' . $e->getMessage(), 500);
        }
        break;
        
    case 'PUT':
        checkAdminAccess($user);
        
        try {
            $input = file_get_contents("php://input");
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['slot_id'])) {
                sendResponse(false, null, 'ID slotu je povinné', 400);
            }
            
            $slot_id = intval($data['slot_id']);
            error_log('SLOTS API PUT: slot_id=' . $slot_id . ', data=' . json_encode($data));
            
            // Get current slot data
            $currentSlot = $timeSlot->getSlotById($slot_id);
            if (!$currentSlot) {
                sendResponse(false, null, 'Slot nenalezen', 404);
            }
            
            // Check company access
            if ($user['user_type'] !== 'super_admin' && 
                isset($_SESSION['company_id']) && 
                $currentSlot['company_id'] != $_SESSION['company_id']) {
                sendResponse(false, null, 'Nemáte oprávnění k tomuto slotu', 403);
            }
            
            // Check if slot has active bookings
            $activeBookings = $timeSlot->getActiveBookingsCount($slot_id);
            if ($activeBookings > 0) {
                // If there are active bookings, limit what can be changed
                $allowedChanges = ['notes', 'max_capacity'];
                $hasRestrictedChanges = false;
                
                foreach ($data as $key => $value) {
                    if ($key !== 'slot_id' && !in_array($key, $allowedChanges)) {
                        if (isset($currentSlot[$key]) && $currentSlot[$key] != $value) {
                            $hasRestrictedChanges = true;
                            break;
                        }
                    }
                }
                
                if ($hasRestrictedChanges) {
                    sendResponse(false, null, 'Slot má aktivní rezervace. Můžete změnit pouze poznámky a kapacitu.', 400);
                }
                
                // Check if new capacity is not lower than current bookings
                if (isset($data['max_capacity']) && $data['max_capacity'] < $activeBookings) {
                    sendResponse(false, null, "Kapacita nemůže být nižší než počet aktivních rezervací ({$activeBookings})", 400);
                }
            }
            
            // Validation for updates
            $errors = validateSlotData($data, true);
            if (!empty($errors)) {
                sendResponse(false, null, 'Chyby ve validaci: ' . implode(', ', $errors), 400);
            }
            
            // Ensure time format is correct
            if (isset($data['slot_time']) && strlen($data['slot_time']) === 5) {
                $data['slot_time'] .= ':00';
            }
            
            $result = $timeSlot->updateSlot($slot_id, $data, $user['user_id']);
            
            if ($result) {
                logSlotAction('updated', $slot_id, $user['user_id'], $currentSlot, $data);
                sendResponse(true, [
                    'message' => 'Časový slot byl aktualizován',
                    'active_bookings' => $activeBookings
                ]);
            } else {
                sendResponse(false, null, 'Chyba při aktualizaci slotu', 400);
            }
            
        } catch(Exception $e) {
            error_log('SLOTS API PUT error: ' . $e->getMessage());
            sendResponse(false, null, 'Chyba při aktualizaci slotu: ' . $e->getMessage(), 500);
        }
        break;
        
    case 'DELETE':
        checkAdminAccess($user);
        
        try {
            $input = file_get_contents("php://input");
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['slot_id'])) {
                sendResponse(false, null, 'ID slotu je povinné', 400);
            }
            
            $slot_id = intval($data['slot_id']);
            error_log('SLOTS API DELETE: slot_id=' . $slot_id);
            
            // Get current slot data for logging
            $currentSlot = $timeSlot->getSlotById($slot_id);
            if (!$currentSlot) {
                sendResponse(false, null, 'Slot nenalezen', 404);
            }
            
            // Check company access
            if ($user['user_type'] !== 'super_admin' && 
                isset($_SESSION['company_id']) && 
                $currentSlot['company_id'] != $_SESSION['company_id']) {
                sendResponse(false, null, 'Nemáte oprávnění k tomuto slotu', 403);
            }
            
            $result = $timeSlot->deleteSlot($slot_id);
            
            if ($result) {
                logSlotAction('deleted', $slot_id, $user['user_id'], $currentSlot, null);
                sendResponse(true, ['message' => 'Časový slot byl smazán']);
            } else {
                sendResponse(false, null, 'Chyba při mazání slotu', 400);
            }
            
        } catch(Exception $e) {
            error_log('SLOTS API DELETE error: ' . $e->getMessage());
            sendResponse(false, null, 'Chyba při mazání slotu: ' . $e->getMessage(), 500);
        }
        break;
        
    default:
        sendResponse(false, null, 'Metoda není povolena. Podporované: GET, POST, PUT, DELETE', 405);
        break;
}

error_log("SLOTS API: Request completed successfully");
?>