<?php
// classes/Notification.php - Notification management class
class Notification {
    private $conn;
    private $table = 'notifications';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new notification
    public function create($data) {
        try {
            $query = "INSERT INTO " . $this->table . " 
                     (user_id, booking_id, notification_type, title, message) 
                     VALUES 
                     (:user_id, :booking_id, :notification_type, :title, :message)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $data['user_id']);
            $stmt->bindParam(':booking_id', $data['booking_id']);
            $stmt->bindParam(':notification_type', $data['notification_type']);
            $stmt->bindParam(':title', $data['title']);
            $stmt->bindParam(':message', $data['message']);

            if ($stmt->execute()) {
                return $this->conn->lastInsertId();
            }

            return false;
        } catch(Exception $e) {
            error_log("Notification creation error: " . $e->getMessage());
            return false;
        }
    }

    // Get user notifications
    public function getUserNotifications($userId, $limit = 20, $unreadOnly = false) {
        try {
            $query = "SELECT n.*, b.truck_license_plate, 
                            DATE_FORMAT(n.created_at, '%d.%m.%Y %H:%i') as formatted_date
                     FROM " . $this->table . " n
                     LEFT JOIN bookings b ON n.booking_id = b.id
                     WHERE n.user_id = :user_id";

            if ($unreadOnly) {
                $query .= " AND n.is_read = 0";
            }

            $query .= " ORDER BY n.created_at DESC LIMIT :limit";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get user notifications error: " . $e->getMessage());
            return [];
        }
    }

    // Mark notification as read
    public function markAsRead($notificationId, $userId = null) {
        try {
            $query = "UPDATE " . $this->table . " SET is_read = 1 WHERE id = :notification_id";
            
            if ($userId) {
                $query .= " AND user_id = :user_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':notification_id', $notificationId);
            
            if ($userId) {
                $stmt->bindParam(':user_id', $userId);
            }

            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Mark notification as read error: " . $e->getMessage());
            return false;
        }
    }

    // Mark all notifications as read
    public function markAllAsRead($userId) {
        try {
            $query = "UPDATE " . $this->table . " SET is_read = 1 WHERE user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);

            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Mark all notifications as read error: " . $e->getMessage());
            return false;
        }
    }

    // Get unread count
    public function getUnreadCount($userId) {
        try {
            $query = "SELECT COUNT(*) FROM " . $this->table . " WHERE user_id = :user_id AND is_read = 0";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_COLUMN);
        } catch(Exception $e) {
            error_log("Get unread count error: " . $e->getMessage());
            return 0;
        }
    }

    // Delete notification
    public function delete($notificationId, $userId = null) {
        try {
            $query = "DELETE FROM " . $this->table . " WHERE id = :notification_id";
            
            if ($userId) {
                $query .= " AND user_id = :user_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':notification_id', $notificationId);
            
            if ($userId) {
                $stmt->bindParam(':user_id', $userId);
            }

            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Delete notification error: " . $e->getMessage());
            return false;
        }
    }

    // Clean old notifications
    public function cleanOldNotifications($days = 30) {
        try {
            $query = "DELETE FROM " . $this->table . " 
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':days', $days);

            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Clean old notifications error: " . $e->getMessage());
            return false;
        }
    }

    // Send booking notification
    public function sendBookingNotification($userId, $bookingId, $type, $customMessage = null) {
        try {
            $messages = [
                'booking_confirmed' => [
                    'title' => 'Rezervace potvrzena',
                    'message' => 'Vaše rezervace byla potvrzena.'
                ],
                'booking_cancelled' => [
                    'title' => 'Rezervace zrušena',
                    'message' => 'Vaše rezervace byla zrušena.'
                ],
                'slot_reminder' => [
                    'title' => 'Připomenutí rezervace',
                    'message' => 'Vaše rezervace se blíží.'
                ]
            ];

            $notificationData = [
                'user_id' => $userId,
                'booking_id' => $bookingId,
                'notification_type' => $type,
                'title' => $messages[$type]['title'] ?? 'Notifikace',
                'message' => $customMessage ?? $messages[$type]['message'] ?? 'Systémová notifikace'
            ];

            return $this->create($notificationData);
        } catch(Exception $e) {
            error_log("Send booking notification error: " . $e->getMessage());
            return false;
        }
    }

    // Send license expiration notification
    public function sendLicenseNotification($userId, $companyName, $daysRemaining) {
        try {
            $title = "Upozornění na expiraci licence";
            $message = "Licence pro firmu {$companyName} vyprší za {$daysRemaining} dní.";

            if ($daysRemaining <= 0) {
                $title = "Licence vypršela";
                $message = "Licence pro firmu {$companyName} vypršela.";
            } elseif ($daysRemaining <= 3) {
                $title = "Licence brzy vyprší!";
            }

            $notificationData = [
                'user_id' => $userId,
                'booking_id' => null,
                'notification_type' => 'license_expiring',
                'title' => $title,
                'message' => $message
            ];

            return $this->create($notificationData);
        } catch(Exception $e) {
            error_log("Send license notification error: " . $e->getMessage());
            return false;
        }
    }

    // Send system notification
    public function sendSystemNotification($userId, $title, $message) {
        try {
            $notificationData = [
                'user_id' => $userId,
                'booking_id' => null,
                'notification_type' => 'system',
                'title' => $title,
                'message' => $message
            ];

            return $this->create($notificationData);
        } catch(Exception $e) {
            error_log("Send system notification error: " . $e->getMessage());
            return false;
        }
    }

    // Bulk send notifications
    public function bulkSendNotifications($userIds, $notificationData) {
        try {
            $this->conn->beginTransaction();
            $sent = 0;

            foreach ($userIds as $userId) {
                $data = $notificationData;
                $data['user_id'] = $userId;

                if ($this->create($data)) {
                    $sent++;
                }
            }

            $this->conn->commit();
            return $sent;
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Bulk send notifications error: " . $e->getMessage());
            return 0;
        }
    }

    // Get notification statistics
    public function getNotificationStats($userId = null) {
        try {
            $stats = [];

            if ($userId) {
                // User-specific stats
                $query = "SELECT 
                            COUNT(*) as total,
                            COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread,
                            COUNT(CASE WHEN notification_type = 'booking_confirmed' THEN 1 END) as booking_notifications,
                            COUNT(CASE WHEN notification_type = 'system' THEN 1 END) as system_notifications
                         FROM " . $this->table . " 
                         WHERE user_id = :user_id";

                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $userId);
            } else {
                // System-wide stats
                $query = "SELECT 
                            COUNT(*) as total,
                            COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread,
                            COUNT(DISTINCT user_id) as unique_users,
                            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h
                         FROM " . $this->table;

                $stmt = $this->conn->prepare($query);
            }

            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            return $stats;
        } catch(Exception $e) {
            error_log("Get notification stats error: " . $e->getMessage());
            return [];
        }
    }

    // Get recent notifications for admin
    public function getRecentNotifications($limit = 50) {
        try {
            $query = "SELECT n.*, u.full_name as user_name,
                            DATE_FORMAT(n.created_at, '%d.%m.%Y %H:%i') as formatted_date
                     FROM " . $this->table . " n
                     JOIN users u ON n.user_id = u.id
                     ORDER BY n.created_at DESC
                     LIMIT :limit";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get recent notifications error: " . $e->getMessage());
            return [];
        }
    }

    // Schedule notification (for future implementation)
    public function scheduleNotification($userId, $scheduleTime, $notificationData) {
        try {
            // This would require a separate scheduled_notifications table
            // For now, just create immediately if schedule time is past
            if (strtotime($scheduleTime) <= time()) {
                $notificationData['user_id'] = $userId;
                return $this->create($notificationData);
            }

            // In a full implementation, you would store this in a scheduled_notifications table
            // and have a cron job process them
            return true;
        } catch(Exception $e) {
            error_log("Schedule notification error: " . $e->getMessage());
            return false;
        }
    }

    // Get notifications by type
    public function getNotificationsByType($type, $limit = 20) {
        try {
            $query = "SELECT n.*, u.full_name as user_name,
                            DATE_FORMAT(n.created_at, '%d.%m.%Y %H:%i') as formatted_date
                     FROM " . $this->table . " n
                     JOIN users u ON n.user_id = u.id
                     WHERE n.notification_type = :type
                     ORDER BY n.created_at DESC
                     LIMIT :limit";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':type', $type);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get notifications by type error: " . $e->getMessage());
            return [];
        }
    }

    // Update notification preferences (for future enhancement)
    public function updateUserPreferences($userId, $preferences) {
        try {
            // This would require a user_notification_preferences table
            // For now, just return true as placeholder
            return true;
        } catch(Exception $e) {
            error_log("Update notification preferences error: " . $e->getMessage());
            return false;
        }
    }
}
?>