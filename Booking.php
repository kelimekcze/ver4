<?php
// classes/Booking.php - Booking management class
class Booking {
    private $conn;
    private $table = 'bookings';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new booking
    public function create($data) {
        try {
            $this->conn->beginTransaction();

            // Check if slot is available
            if (!$this->isSlotAvailable($data['time_slot_id'])) {
                throw new Exception('Časový slot není dostupný');
            }

            $query = "INSERT INTO " . $this->table . " 
                     (time_slot_id, driver_id, truck_license_plate, cargo_type, cargo_weight, 
                      estimated_duration, special_requirements, booking_notes, booking_status) 
                     VALUES 
                     (:time_slot_id, :driver_id, :truck_license_plate, :cargo_type, :cargo_weight, 
                      :estimated_duration, :special_requirements, :booking_notes, 'pending')";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':time_slot_id', $data['time_slot_id']);
            $stmt->bindParam(':driver_id', $data['driver_id']);
            $stmt->bindParam(':truck_license_plate', $data['truck_license_plate']);
            $stmt->bindParam(':cargo_type', $data['cargo_type']);
            $stmt->bindParam(':cargo_weight', $data['cargo_weight']);
            $stmt->bindParam(':estimated_duration', $data['estimated_duration']);
            $stmt->bindParam(':special_requirements', $data['special_requirements']);
            $stmt->bindParam(':booking_notes', $data['booking_notes']);

            if ($stmt->execute()) {
                $bookingId = $this->conn->lastInsertId();
                $this->conn->commit();
                return $bookingId;
            }

            $this->conn->rollback();
            return false;
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Booking creation error: " . $e->getMessage());
            return false;
        }
    }

    // Check if slot is available
    private function isSlotAvailable($timeSlotId) {
        try {
            $query = "SELECT ts.max_capacity, COUNT(b.id) as current_bookings
                     FROM time_slots ts
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.id = :time_slot_id
                     GROUP BY ts.id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':time_slot_id', $timeSlotId);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result['current_bookings'] < $result['max_capacity'];
            }

            return false;
        } catch(Exception $e) {
            error_log("Check slot availability error: " . $e->getMessage());
            return false;
        }
    }

    // Get all bookings
    public function getAllBookings($companyId = null) {
        try {
            $query = "SELECT b.*, ts.slot_date, ts.slot_time, ts.duration_minutes,
                            w.name as warehouse_name, w.address as warehouse_address,
                            u.full_name as driver_name, u.phone as driver_phone
                     FROM " . $this->table . " b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     JOIN users u ON b.driver_id = u.id";

            if ($companyId) {
                $query .= " WHERE w.company_id = :company_id";
            }

            $query .= " ORDER BY ts.slot_date DESC, ts.slot_time DESC";

            $stmt = $this->conn->prepare($query);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get all bookings error: " . $e->getMessage());
            return [];
        }
    }

    // Get driver bookings
    public function getDriverBookings($driverId) {
        try {
            $query = "SELECT b.*, ts.slot_date, ts.slot_time, ts.duration_minutes,
                            w.name as warehouse_name, w.address as warehouse_address,
                            w.contact_person, w.contact_phone
                     FROM " . $this->table . " b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     WHERE b.driver_id = :driver_id
                     ORDER BY ts.slot_date DESC, ts.slot_time DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':driver_id', $driverId);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get driver bookings error: " . $e->getMessage());
            return [];
        }
    }

    // Update booking status
    public function updateStatus($bookingId, $status, $userId) {
        try {
            $validStatuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
            
            if (!in_array($status, $validStatuses)) {
                throw new Exception('Neplatný stav rezervace');
            }

            $query = "UPDATE " . $this->table . " SET booking_status = :status";
            
            // Add timestamps for specific status changes
            if ($status === 'in_progress') {
                $query .= ", check_in_time = NOW()";
            } elseif ($status === 'completed') {
                $query .= ", check_out_time = NOW()";
            }
            
            $query .= " WHERE id = :booking_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':booking_id', $bookingId);

            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Update booking status error: " . $e->getMessage());
            return false;
        }
    }

    // Cancel booking
    public function cancelBooking($bookingId, $userId) {
        return $this->updateStatus($bookingId, 'cancelled', $userId);
    }

    // Check in booking
    public function checkIn($bookingId) {
        return $this->updateStatus($bookingId, 'in_progress', null);
    }

    // Check out booking
    public function checkOut($bookingId) {
        return $this->updateStatus($bookingId, 'completed', null);
    }

    // Get booking by ID
    public function getBookingById($bookingId) {
        try {
            $query = "SELECT b.*, ts.slot_date, ts.slot_time, ts.duration_minutes,
                            w.name as warehouse_name, w.address as warehouse_address,
                            w.contact_person, w.contact_phone,
                            u.full_name as driver_name, u.phone as driver_phone, u.email as driver_email
                     FROM " . $this->table . " b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     JOIN users u ON b.driver_id = u.id
                     WHERE b.id = :booking_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }

            return null;
        } catch(Exception $e) {
            error_log("Get booking by ID error: " . $e->getMessage());
            return null;
        }
    }

    // Get bookings for specific slot
    public function getSlotBookings($slotId) {
        try {
            $query = "SELECT b.*, u.full_name as driver_name, u.phone as driver_phone
                     FROM " . $this->table . " b
                     JOIN users u ON b.driver_id = u.id
                     WHERE b.time_slot_id = :slot_id
                     AND b.booking_status NOT IN ('cancelled')
                     ORDER BY b.created_at";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slot bookings error: " . $e->getMessage());
            return [];
        }
    }

    // Get upcoming bookings
    public function getUpcomingBookings($limit = 10, $companyId = null) {
        try {
            $query = "SELECT b.*, ts.slot_date, ts.slot_time, ts.duration_minutes,
                            w.name as warehouse_name,
                            u.full_name as driver_name
                     FROM " . $this->table . " b
                     JOIN time_slots ts ON b.time_slot_id = ts.id
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     JOIN users u ON b.driver_id = u.id
                     WHERE b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     AND CONCAT(ts.slot_date, ' ', ts.slot_time) >= NOW()";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " ORDER BY ts.slot_date ASC, ts.slot_time ASC LIMIT :limit";

            $stmt = $this->conn->prepare($query);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get upcoming bookings error: " . $e->getMessage());
            return [];
        }
    }

    // Get booking statistics
    public function getBookingStats($companyId = null) {
        try {
            $stats = [
                'pending' => 0,
                'confirmed' => 0,
                'in_progress' => 0,
                'completed_today' => 0,
                'total_bookings' => 0
            ];

            // Status counts
            $query = "SELECT booking_status, COUNT(*) as count 
                     FROM " . $this->table . " b 
                     JOIN time_slots ts ON b.time_slot_id = ts.id 
                     JOIN warehouses w ON ts.warehouse_id = w.id";
            
            if ($companyId) {
                $query .= " WHERE w.company_id = :company_id";
            }
            
            $query .= " AND b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY booking_status";
            
            $stmt = $this->conn->prepare($query);
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            $stmt->execute();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stats[$row['booking_status']] = $row['count'];
                $stats['total_bookings'] += $row['count'];
            }
            
            // Today's completed bookings
            $todayQuery = "SELECT COUNT(*) as count FROM " . $this->table . " b 
                          JOIN time_slots ts ON b.time_slot_id = ts.id 
                          JOIN warehouses w ON ts.warehouse_id = w.id
                          WHERE b.booking_status = 'completed' 
                          AND DATE(b.check_out_time) = CURDATE()";
            
            if ($companyId) {
                $todayQuery .= " AND w.company_id = :company_id";
            }
            
            $todayStmt = $this->conn->prepare($todayQuery);
            if ($companyId) {
                $todayStmt->bindParam(':company_id', $companyId);
            }
            $todayStmt->execute();
            $stats['completed_today'] = $todayStmt->fetch(PDO::FETCH_COLUMN);
            
            return $stats;
        } catch(Exception $e) {
            error_log("Get booking stats error: " . $e->getMessage());
            return [];
        }
    }

    // Update booking details
    public function updateBooking($bookingId, $data) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET cargo_type = :cargo_type, 
                         cargo_weight = :cargo_weight,
                         estimated_duration = :estimated_duration,
                         special_requirements = :special_requirements,
                         booking_notes = :booking_notes
                     WHERE id = :booking_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->bindParam(':cargo_type', $data['cargo_type']);
            $stmt->bindParam(':cargo_weight', $data['cargo_weight']);
            $stmt->bindParam(':estimated_duration', $data['estimated_duration']);
            $stmt->bindParam(':special_requirements', $data['special_requirements']);
            $stmt->bindParam(':booking_notes', $data['booking_notes']);

            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Update booking error: " . $e->getMessage());
            return false;
        }
    }

    // Add feedback and rating
    public function addFeedback($bookingId, $rating, $feedback) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET rating = :rating, feedback = :feedback 
                     WHERE id = :booking_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':booking_id', $bookingId);
            $stmt->bindParam(':rating', $rating);
            $stmt->bindParam(':feedback', $feedback);

            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Add feedback error: " . $e->getMessage());
            return false;
        }
    }
}
?>