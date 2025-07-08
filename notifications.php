<?php
// api/notifications.php - Notifications management API
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../config/database.php';
include_once '../classes/Notification.php';
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

function logNotificationAction($action, $notification_id, $user_id, $data = null) {
    try {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'notification_id' => $notification_id,
            'user_id' => $user_id,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        error_log('NOTIFICATION_ACTION: ' . json_encode($logEntry));
    } catch (Exception $e) {
        error_log('Notification action log error: ' . $e->getMessage());
    }
}

$database = new Database();
$db = $database->connect();
$notification = new Notification($db);

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
            if (isset($_GET['count'])) {
                // Get unread count
                $count = $notification->getUnreadCount($user['user_id']);
                
                echo json_encode([
                    'success' => true,
                    'unread_count' => $count,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } elseif (isset($_GET['stats'])) {
                // Get notification statistics
                $stats = $notification->getNotificationStats($user['user_id']);
                
                echo json_encode([
                    'success' => true,
                    'stats' => $stats,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } elseif (isset($_GET['recent']) && $user['user_type'] === 'super_admin') {
                // Get recent notifications for admin
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
                $recent = $notification->getRecentNotifications($limit);
                
                echo json_encode([
                    'success' => true,
                    'notifications' => $recent,
                    'count' => count($recent)
                ]);
                
            } elseif (isset($_GET['type'])) {
                // Get notifications by type (admin only)
                if ($user['user_type'] !== 'super_admin') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Nemáte oprávnění k tomuto typu dat']);
                    exit;
                }
                
                $type = $_GET['type'];
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
                $notifications = $notification->getNotificationsByType($type, $limit);
                
                echo json_encode([
                    'success' => true,
                    'notifications' => $notifications,
                    'type' => $type,
                    'count' => count($notifications)
                ]);
                
            } else {
                // Get user notifications
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
                $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';
                
                $notifications = $notification->getUserNotifications($user['user_id'], $limit, $unreadOnly);
                
                echo json_encode([
                    'success' => true,
                    'notifications' => $notifications,
                    'count' => count($notifications),
                    'unread_only' => $unreadOnly
                ]);
            }
            
        } catch(Exception $e) {
            error_log('Notifications GET error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při načítání notifikací',
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
            
            if (isset($data['action'])) {
                switch ($data['action']) {
                    case 'send_notification':
                        // Send new notification (admin only)
                        if (!in_array($user['user_type'], ['super_admin', 'admin'])) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Nemáte oprávnění odesílat notifikace']);
                            exit;
                        }
                        
                        $required = ['user_id', 'title', 'message'];
                        foreach ($required as $field) {
                            if (empty($data[$field])) {
                                http_response_code(400);
                                echo json_encode(['error' => "Pole {$field} je povinné"]);
                                exit;
                            }
                        }
                        
                        $notificationData = [
                            'user_id' => $data['user_id'],
                            'booking_id' => $data['booking_id'] ?? null,
                            'notification_type' => $data['notification_type'] ?? 'system',
                            'title' => $data['title'],
                            'message' => $data['message']
                        ];
                        
                        $notificationId = $notification->create($notificationData);
                        
                        if ($notificationId) {
                            logNotificationAction('created', $notificationId, $user['user_id'], $notificationData);
                            
                            echo json_encode([
                                'success' => true,
                                'notification_id' => $notificationId,
                                'message' => 'Notifikace byla odeslána'
                            ]);
                        } else {
                            http_response_code(500);
                            echo json_encode(['error' => 'Chyba při odesílání notifikace']);
                        }
                        break;
                        
                    case 'send_bulk':
                        // Send bulk notifications (super admin only)
                        if ($user['user_type'] !== 'super_admin') {
                            http_response_code(403);
                            echo json_encode(['error' => 'Nemáte oprávnění k hromadnému odesílání']);
                            exit;
                        }
                        
                        if (empty($data['user_ids']) || empty($data['title']) || empty($data['message'])) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Chybí povinná data pro hromadné odesílání']);
                            exit;
                        }
                        
                        $notificationData = [
                            'booking_id' => $data['booking_id'] ?? null,
                            'notification_type' => $data['notification_type'] ?? 'system',
                            'title' => $data['title'],
                            'message' => $data['message']
                        ];
                        
                        $sentCount = $notification->bulkSendNotifications($data['user_ids'], $notificationData);
                        
                        logNotificationAction('bulk_sent', null, $user['user_id'], [
                            'user_count' => count($data['user_ids']),
                            'sent_count' => $sentCount
                        ]);
                        
                        echo json_encode([
                            'success' => true,
                            'sent_count' => $sentCount,
                            'total_users' => count($data['user_ids']),
                            'message' => "Notifikace byla odeslána {$sentCount} uživatelům"
                        ]);
                        break;
                        
                    case 'send_booking_notification':
                        // Send booking-related notification
                        if (!in_array($user['user_type'], ['super_admin', 'admin', 'logistics'])) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Nemáte oprávnění odesílat rezervační notifikace']);
                            exit;
                        }
                        
                        $required = ['user_id', 'booking_id', 'type'];
                        foreach ($required as $field) {
                            if (empty($data[$field])) {
                                http_response_code(400);
                                echo json_encode(['error' => "Pole {$field} je povinné"]);
                                exit;
                            }
                        }
                        
                        $notificationId = $notification->sendBookingNotification(
                            $data['user_id'],
                            $data['booking_id'],
                            $data['type'],
                            $data['custom_message'] ?? null
                        );
                        
                        if ($notificationId) {
                            echo json_encode([
                                'success' => true,
                                'notification_id' => $notificationId,
                                'message' => 'Rezervační notifikace byla odeslána'
                            ]);
                        } else {
                            http_response_code(500);
                            echo json_encode(['error' => 'Chyba při odesílání rezervační notifikace']);
                        }
                        break;
                        
                    default:
                        http_response_code(400);
                        echo json_encode([
                            'error' => 'Neplatná akce',
                            'valid_actions' => ['send_notification', 'send_bulk', 'send_booking_notification']
                        ]);
                        break;
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Chybí parametr action']);
            }
            
        } catch(Exception $e) {
            error_log('Notifications POST error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při zpracování notifikace',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'PUT':
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Neplatná JSON data']);
                exit;
            }
            
            if (isset($data['action'])) {
                switch ($data['action']) {
                    case 'mark_read':
                        if (isset($data['notification_id'])) {
                            // Mark single notification as read
                            $notificationId = intval($data['notification_id']);
                            $result = $notification->markAsRead($notificationId, $user['user_id']);
                            
                            if ($result) {
                                logNotificationAction('marked_read', $notificationId, $user['user_id']);
                                echo json_encode([
                                    'success' => true,
                                    'message' => 'Notifikace označena jako přečtená'
                                ]);
                            } else {
                                http_response_code(400);
                                echo json_encode(['error' => 'Chyba při označování notifikace']);
                            }
                        } else {
                            http_response_code(400);
                            echo json_encode(['error' => 'Chybí ID notifikace']);
                        }
                        break;
                        
                    case 'mark_all_read':
                        // Mark all notifications as read
                        $result = $notification->markAllAsRead($user['user_id']);
                        
                        if ($result) {
                            logNotificationAction('marked_all_read', null, $user['user_id']);
                            echo json_encode([
                                'success' => true,
                                'message' => 'Všechny notifikace označeny jako přečtené'
                            ]);
                        } else {
                            http_response_code(500);
                            echo json_encode(['error' => 'Chyba při označování notifikací']);
                        }
                        break;
                        
                    default:
                        http_response_code(400);
                        echo json_encode([
                            'error' => 'Neplatná akce',
                            'valid_actions' => ['mark_read', 'mark_all_read']
                        ]);
                        break;
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Chybí parametr action']);
            }
            
        } catch(Exception $e) {
            error_log('Notifications PUT error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při aktualizaci notifikací',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'DELETE':
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data || !isset($data['notification_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID notifikace je povinné']);
                exit;
            }
            
            $notificationId = intval($data['notification_id']);
            $result = $notification->delete($notificationId, $user['user_id']);
            
            if ($result) {
                logNotificationAction('deleted', $notificationId, $user['user_id']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Notifikace byla smazána'
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Chyba při mazání notifikace']);
            }
            
        } catch(Exception $e) {
            error_log('Notifications DELETE error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při mazání notifikace',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'error' => 'Metoda není povolena',
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ]);
        break;
}
                        
                    case 'mark_all_read':
                        // Mark all notifications as read
                        $result = $notification->markAllAsRead($user['user_id']);
                        
                        if ($result) {
                            logNotificationAction('marked_all_read', null, $user['user_id']);
                            echo json_encode([
                                'success' => true,
                                'message' => 'Všechny notifikace označeny jako přečtené'
                            ]);
                        } else {
                            http_response_code(500);
                            echo json_encode(['error' => 'Chyba při označování notifikací']);
                        }
                        break;
                        
                    default:
                        http_response_code(400);
                        echo json_encode([
                            'error' => 'Neplatná akce',
                            'valid_actions' => ['mark_read', 'mark_all_read']
                        ]);
                        break;
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Chybí parametr action']);
            }
            
        } catch(Exception $e) {
            error_log('Notifications PUT error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při aktualizaci notifikací',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'DELETE':
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data || !isset($data['notification_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID notifikace je povinné']);
                exit;
            }
            
            $notificationId = intval($data['notification_id']);
            $result = $notification->delete($notificationId, $user['user_id']);
            
            if ($result) {
                logNotificationAction('deleted', $notificationId, $user['user_id']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Notifikace byla smazána'
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Chyba při mazání notifikace']);
            }
            
        } catch(Exception $e) {
            error_log('Notifications DELETE error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Chyba při mazání notifikace',
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'error' => 'Metoda není povolena',
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ]);
        break;
}
?>