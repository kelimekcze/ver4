<?php
// classes/TimeSlot.php - Time slot management class s podporou kalendáře (KOMPLETNÍ VERZE)
class TimeSlot {
    private $conn;
    private $table = 'time_slots';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new time slot
    public function create($data) {
        try {
            $this->conn->beginTransaction();

            // Check for conflicts
            if ($this->hasConflict($data['warehouse_id'], $data['slot_date'], $data['slot_time'], $data['duration_minutes'])) {
                throw new Exception('Časový slot koliduje s existujícím slotem');
            }

            $query = "INSERT INTO " . $this->table . " 
                     (warehouse_id, slot_date, slot_time, duration_minutes, max_capacity, 
                      slot_type, notes, created_by) 
                     VALUES 
                     (:warehouse_id, :slot_date, :slot_time, :duration_minutes, :max_capacity, 
                      :slot_type, :notes, :created_by)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':warehouse_id', $data['warehouse_id']);
            $stmt->bindParam(':slot_date', $data['slot_date']);
            $stmt->bindParam(':slot_time', $data['slot_time']);
            $stmt->bindParam(':duration_minutes', $data['duration_minutes']);
            $stmt->bindParam(':max_capacity', $data['max_capacity']);
            $stmt->bindParam(':slot_type', $data['slot_type']);
            $stmt->bindParam(':notes', $data['notes']);
            $stmt->bindParam(':created_by', $data['created_by']);

            if ($stmt->execute()) {
                $slotId = $this->conn->lastInsertId();
                $this->conn->commit();
                return $slotId;
            }

            $this->conn->rollback();
            return false;
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Time slot creation error: " . $e->getMessage());
            throw $e;
        }
    }

    // Get slots for date range (for calendar view) - KLÍČOVÁ METODA PRO KALENDÁŘ
    public function getSlotsForDateRange($dateFrom, $dateTo, $companyId = null, $warehouseId = null) {
        try {
            error_log("TimeSlot: getSlotsForDateRange from $dateFrom to $dateTo, company: $companyId, warehouse: $warehouseId");
            
            $query = "SELECT ts.*, w.name as warehouse_name, w.company_id,
                            COALESCE(COUNT(b.id), 0) as current_bookings,
                            u.full_name as created_by_name
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     LEFT JOIN users u ON ts.created_by = u.id
                     WHERE ts.is_active = 1 
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            if ($warehouseId) {
                $query .= " AND ts.warehouse_id = :warehouse_id";
            }

            $query .= " GROUP BY ts.id ORDER BY ts.slot_date DESC, ts.slot_time DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            if ($warehouseId) {
                $stmt->bindParam(':warehouse_id', $warehouseId);
            }
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("TimeSlot: Found " . count($results) . " slots for date range");
            
            return $results;
        } catch(Exception $e) {
            error_log("Get slots for date range error: " . $e->getMessage());
            return [];
        }
    }

    // Get slots for specific date
    public function getSlotsForDate($date, $companyId = null) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name, w.company_id,
                            COALESCE(COUNT(b.id), 0) as current_bookings,
                            b.booking_status,
                            b.id as booking_id
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.is_active = 1 AND ts.slot_date = :date";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " GROUP BY ts.id ORDER BY ts.slot_time";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date', $date);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slots for date error: " . $e->getMessage());
            return [];
        }
    }

    // Get available slots for warehouse and date
    public function getAvailableSlots($warehouseId, $date) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name,
                            COALESCE(COUNT(b.id), 0) as current_bookings
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.warehouse_id = :warehouse_id 
                     AND ts.slot_date = :date 
                     AND ts.is_active = 1
                     GROUP BY ts.id
                     HAVING (ts.max_capacity - COALESCE(COUNT(b.id), 0)) > 0
                     ORDER BY ts.slot_time";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouseId);
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get available slots error: " . $e->getMessage());
            return [];
        }
    }

    // Delete slot (soft delete)
    public function deleteSlot($slotId) {
        try {
            // Check if slot has bookings
            $query = "SELECT COUNT(*) FROM bookings WHERE time_slot_id = :slot_id AND booking_status NOT IN ('cancelled')";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->execute();

            if ($stmt->fetch(PDO::FETCH_COLUMN) > 0) {
                throw new Exception('Nelze smazat slot s aktivními rezervacemi');
            }

            // Soft delete
            $deleteQuery = "UPDATE " . $this->table . " SET is_active = 0, updated_at = NOW() WHERE id = :slot_id";
            $deleteStmt = $this->conn->prepare($deleteQuery);
            $deleteStmt->bindParam(':slot_id', $slotId);

            return $deleteStmt->execute();
        } catch(Exception $e) {
            error_log("Delete slot error: " . $e->getMessage());
            throw $e;
        }
    }

    // Get slot utilization statistics
    public function getSlotUtilization($warehouseId = null, $dateFrom = null, $dateTo = null) {
        try {
            $query = "SELECT 
                        DATE(ts.slot_date) as date,
                        COUNT(ts.id) as total_slots,
                        COUNT(b.id) as booked_slots,
                        ROUND((COUNT(b.id) / COUNT(ts.id)) * 100, 2) as utilization_percent
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('confirmed', 'in_progress', 'completed')
                     WHERE ts.is_active = 1";

            if ($warehouseId) {
                $query .= " AND ts.warehouse_id = :warehouse_id";
            }

            if ($dateFrom) {
                $query .= " AND ts.slot_date >= :date_from";
            }

            if ($dateTo) {
                $query .= " AND ts.slot_date <= :date_to";
            }

            $query .= " GROUP BY DATE(ts.slot_date) ORDER BY date DESC";

            $stmt = $this->conn->prepare($query);

            if ($warehouseId) {
                $stmt->bindParam(':warehouse_id', $warehouseId);
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
            error_log("Get slot utilization error: " . $e->getMessage());
            return [];
        }
    }

    // Bulk create slots
    public function bulkCreateSlots($templateData, $dates) {
        try {
            $this->conn->beginTransaction();
            $created = 0;
            $errors = [];

            foreach ($dates as $date) {
                $slotData = $templateData;
                $slotData['slot_date'] = $date;

                try {
                    $slotId = $this->create($slotData);
                    if ($slotId) {
                        $created++;
                    } else {
                        $errors[] = "Chyba při vytváření slotu pro $date";
                    }
                } catch (Exception $e) {
                    $errors[] = "Chyba pro $date: " . $e->getMessage();
                }
            }

            if ($created > 0) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'created' => $created,
                    'errors' => $errors
                ];
            } else {
                $this->conn->rollback();
                return [
                    'success' => false,
                    'created' => 0,
                    'errors' => $errors
                ];
            }
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Bulk create slots error: " . $e->getMessage());
            return [
                'success' => false,
                'created' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    // Get slots by warehouse
    public function getSlotsByWarehouse($warehouseId, $dateFrom = null, $dateTo = null) {
        try {
            $query = "SELECT ts.*, COALESCE(COUNT(b.id), 0) as current_bookings
                     FROM " . $this->table . " ts
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.warehouse_id = :warehouse_id 
                     AND ts.is_active = 1";

            if ($dateFrom) {
                $query .= " AND ts.slot_date >= :date_from";
            }

            if ($dateTo) {
                $query .= " AND ts.slot_date <= :date_to";
            }

            $query .= " GROUP BY ts.id ORDER BY ts.slot_date, ts.slot_time";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouseId);

            if ($dateFrom) {
                $stmt->bindParam(':date_from', $dateFrom);
            }

            if ($dateTo) {
                $stmt->bindParam(':date_to', $dateTo);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slots by warehouse error: " . $e->getMessage());
            return [];
        }
    }

    // Check warehouse capacity for date
    public function checkWarehouseCapacity($warehouseId, $date) {
        try {
            $query = "SELECT w.max_simultaneous_slots,
                            COUNT(ts.id) as current_slots
                     FROM warehouses w
                     LEFT JOIN " . $this->table . " ts ON w.id = ts.warehouse_id 
                         AND ts.slot_date = :date 
                         AND ts.is_active = 1
                     WHERE w.id = :warehouse_id
                     GROUP BY w.id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouseId);
            $stmt->bindParam(':date', $date);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return [
                    'max_capacity' => $result['max_simultaneous_slots'],
                    'current_slots' => $result['current_slots'],
                    'available_slots' => $result['max_simultaneous_slots'] - $result['current_slots']
                ];
            }

            return null;
        } catch(Exception $e) {
            error_log("Check warehouse capacity error: " . $e->getMessage());
            return null;
        }
    }

    // Get today's slots
    public function getTodaysSlots($companyId = null) {
        try {
            $today = date('Y-m-d');
            return $this->getSlotsForDate($today, $companyId);
        } catch(Exception $e) {
            error_log("Get today's slots error: " . $e->getMessage());
            return [];
        }
    }

    // Get upcoming slots
    public function getUpcomingSlots($days = 7, $companyId = null) {
        try {
            $dateFrom = date('Y-m-d');
            $dateTo = date('Y-m-d', strtotime("+$days days"));

            $query = "SELECT ts.*, w.name as warehouse_name, w.company_id,
                            COALESCE(COUNT(b.id), 0) as current_bookings
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.is_active = 1 
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " GROUP BY ts.id ORDER BY ts.slot_date, ts.slot_time";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get upcoming slots error: " . $e->getMessage());
            return [];
        }
    }

    // Calendar specific methods

    // Get slots with booking details for calendar display
    public function getSlotsWithBookings($dateFrom, $dateTo, $companyId = null, $warehouseId = null) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name, w.company_id,
                            b.id as booking_id,
                            b.booking_status,
                            b.truck_license_plate,
                            b.cargo_type,
                            b.driver_id,
                            u.full_name as driver_name,
                            COUNT(DISTINCT b.id) as current_bookings
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status NOT IN ('cancelled')
                     LEFT JOIN users u ON b.driver_id = u.id
                     WHERE ts.is_active = 1 
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            if ($warehouseId) {
                $query .= " AND ts.warehouse_id = :warehouse_id";
            }

            $query .= " GROUP BY ts.id, b.id ORDER BY ts.slot_date, ts.slot_time";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            if ($warehouseId) {
                $stmt->bindParam(':warehouse_id', $warehouseId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slots with bookings error: " . $e->getMessage());
            return [];
        }
    }

    // Check if slot can be moved to new time
    public function canMoveSlot($slotId, $newDate, $newTime, $duration) {
        try {
            // Get current slot
            $currentSlot = $this->getSlotById($slotId);
            if (!$currentSlot) {
                return false;
            }

            // Check for conflicts excluding current slot
            return !$this->hasConflictExcluding($slotId, $currentSlot['warehouse_id'], $newDate, $newTime, $duration);
        } catch(Exception $e) {
            error_log("Can move slot error: " . $e->getMessage());
            return false;
        }
    }

    // Move slot to new date/time (for drag & drop)
    public function moveSlot($slotId, $newDate, $newTime) {
        try {
            $this->conn->beginTransaction();

            // Get current slot
            $currentSlot = $this->getSlotById($slotId);
            if (!$currentSlot) {
                throw new Exception('Slot nenalezen');
            }

            // Check if slot has active bookings
            if ($currentSlot['current_bookings'] > 0) {
                throw new Exception('Nelze přesunout slot s aktivními rezervacemi');
            }

            // Check for conflicts
            if ($this->hasConflictExcluding($slotId, $currentSlot['warehouse_id'], $newDate, $newTime, $currentSlot['duration_minutes'])) {
                throw new Exception('Kolize s jiným slotem');
            }

            // Update slot
            $query = "UPDATE " . $this->table . " 
                     SET slot_date = :new_date, slot_time = :new_time, updated_at = NOW()
                     WHERE id = :slot_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->bindParam(':new_date', $newDate);
            $stmt->bindParam(':new_time', $newTime);

            if ($stmt->execute()) {
                $this->conn->commit();
                return true;
            }

            $this->conn->rollback();
            return false;
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Move slot error: " . $e->getMessage());
            throw $e;
        }
    }

    // Get slots for calendar grid (optimized for display)
    public function getSlotsForCalendarGrid($dateFrom, $dateTo, $companyId = null, $warehouseId = null) {
        try {
            error_log("TimeSlot: getSlotsForCalendarGrid from $dateFrom to $dateTo");
            
            $query = "SELECT 
                        ts.id,
                        ts.slot_date,
                        ts.slot_time,
                        ts.duration_minutes,
                        ts.max_capacity,
                        ts.slot_type,
                        ts.notes,
                        w.id as warehouse_id,
                        w.name as warehouse_name,
                        w.company_id,
                        COALESCE(COUNT(CASE WHEN b.booking_status IN ('pending', 'confirmed', 'in_progress') THEN b.id END), 0) as current_bookings,
                        COALESCE(COUNT(CASE WHEN b.booking_status = 'pending' THEN b.id END), 0) as pending_bookings,
                        COALESCE(COUNT(CASE WHEN b.booking_status = 'confirmed' THEN b.id END), 0) as confirmed_bookings,
                        COALESCE(COUNT(CASE WHEN b.booking_status = 'in_progress' THEN b.id END), 0) as in_progress_bookings,
                        GROUP_CONCAT(DISTINCT b.truck_license_plate ORDER BY b.created_at SEPARATOR ', ') as truck_plates,
                        ts.created_at,
                        u.full_name as created_by_name
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id
                     LEFT JOIN users u ON ts.created_by = u.id
                     WHERE ts.is_active = 1 
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            if ($warehouseId) {
                $query .= " AND ts.warehouse_id = :warehouse_id";
            }

            $query .= " GROUP BY ts.id ORDER BY ts.slot_date, ts.slot_time";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            if ($warehouseId) {
                $stmt->bindParam(':warehouse_id', $warehouseId);
            }
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("TimeSlot: Calendar grid query returned " . count($results) . " slots");
            
            return $results;
        } catch(Exception $e) {
            error_log("Get slots for calendar grid error: " . $e->getMessage());
            return [];
        }
    }

    // Get slot occupancy for date range (for analytics)
    public function getSlotOccupancy($dateFrom, $dateTo, $companyId = null) {
        try {
            $query = "SELECT 
                        ts.slot_date,
                        COUNT(ts.id) as total_slots,
                        SUM(ts.max_capacity) as total_capacity,
                        COUNT(b.id) as total_bookings,
                        ROUND((COUNT(b.id) / SUM(ts.max_capacity)) * 100, 2) as occupancy_rate
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('confirmed', 'in_progress', 'completed')
                     WHERE ts.is_active = 1 
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " GROUP BY ts.slot_date ORDER BY ts.slot_date";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slot occupancy error: " . $e->getMessage());
            return [];
        }
    }

    // Get warehouse statistics
    public function getWarehouseStats($warehouseId, $dateFrom = null, $dateTo = null) {
        try {
            $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $dateTo ?? date('Y-m-d');

            $query = "SELECT 
                        COUNT(ts.id) as total_slots,
                        SUM(ts.max_capacity) as total_capacity,
                        COUNT(b.id) as total_bookings,
                        COUNT(CASE WHEN b.booking_status = 'completed' THEN b.id END) as completed_bookings,
                        COUNT(CASE WHEN b.booking_status = 'cancelled' THEN b.id END) as cancelled_bookings,
                        ROUND(AVG(ts.max_capacity), 2) as avg_capacity,
                        ROUND((COUNT(b.id) / COUNT(ts.id)) * 100, 2) as booking_rate
                     FROM " . $this->table . " ts
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id
                     WHERE ts.warehouse_id = :warehouse_id 
                     AND ts.is_active = 1
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouseId);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get warehouse stats error: " . $e->getMessage());
            return [];
        }
    }

    // Find optimal time slots for booking
    public function findOptimalSlots($warehouseId, $date, $duration = 60, $cargoType = null) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name,
                            COALESCE(COUNT(b.id), 0) as current_bookings,
                            (ts.max_capacity - COALESCE(COUNT(b.id), 0)) as available_capacity
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.warehouse_id = :warehouse_id 
                     AND ts.slot_date = :date 
                     AND ts.is_active = 1
                     AND ts.duration_minutes >= :duration";

            // Filter by cargo type if specified
            if ($cargoType) {
                $query .= " AND (ts.slot_type = 'both' OR ts.slot_type = :cargo_type)";
            }

            $query .= " GROUP BY ts.id
                       HAVING available_capacity > 0
                       ORDER BY available_capacity DESC, ts.slot_time ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouseId);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':duration', $duration);
            
            if ($cargoType) {
                $stmt->bindParam(':cargo_type', $cargoType);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Find optimal slots error: " . $e->getMessage());
            return [];
        }
    }

    // Get slots that need attention (overbooked, conflicts, etc.)
    public function getSlotsNeedingAttention($companyId = null) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name,
                            COALESCE(COUNT(b.id), 0) as current_bookings,
                            CASE 
                                WHEN COALESCE(COUNT(b.id), 0) > ts.max_capacity THEN 'overbooked'
                                WHEN ts.slot_date < CURDATE() AND COALESCE(COUNT(b.id), 0) = 0 THEN 'unused_past'
                                WHEN ts.slot_date = CURDATE() AND COALESCE(COUNT(b.id), 0) = 0 THEN 'unused_today'
                                ELSE 'normal'
                            END as attention_type
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.is_active = 1";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " GROUP BY ts.id
                       HAVING attention_type != 'normal'
                       ORDER BY 
                           CASE attention_type 
                               WHEN 'overbooked' THEN 1
                               WHEN 'unused_today' THEN 2
                               WHEN 'unused_past' THEN 3
                               ELSE 4
                           END,
                           ts.slot_date DESC, ts.slot_time";

            $stmt = $this->conn->prepare($query);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slots needing attention error: " . $e->getMessage());
            return [];
        }
    }

    // Validate slot data before create/update
    public function validateSlotData($data, $isUpdate = false, $excludeSlotId = null) {
        $errors = [];

        // Check warehouse exists and is active
        if (!empty($data['warehouse_id'])) {
            $warehouseQuery = "SELECT id, is_active FROM warehouses WHERE id = :warehouse_id";
            $stmt = $this->conn->prepare($warehouseQuery);
            $stmt->bindParam(':warehouse_id', $data['warehouse_id']);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                $errors[] = 'Vybraný sklad neexistuje';
            } else {
                $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$warehouse['is_active']) {
                    $errors[] = 'Vybraný sklad není aktivní';
                }
            }
        }

        // Check date is not in the past (for new slots)
        if (!$isUpdate && !empty($data['slot_date'])) {
            if (strtotime($data['slot_date']) < strtotime(date('Y-m-d'))) {
                $errors[] = 'Nelze vytvořit slot v minulosti';
            }
        }

        // Check for time conflicts
        if (!empty($data['warehouse_id']) && !empty($data['slot_date']) && 
            !empty($data['slot_time']) && !empty($data['duration_minutes'])) {
            
            if ($excludeSlotId) {
                $hasConflict = $this->hasConflictExcluding(
                    $excludeSlotId, 
                    $data['warehouse_id'], 
                    $data['slot_date'], 
                    $data['slot_time'], 
                    $data['duration_minutes']
                );
            } else {
                $hasConflict = $this->hasConflict(
                    $data['warehouse_id'], 
                    $data['slot_date'], 
                    $data['slot_time'], 
                    $data['duration_minutes']
                );
            }
            
            if ($hasConflict) {
                $errors[] = 'Časový slot koliduje s existujícím slotem';
            }
        }

        // Check warehouse capacity for the date
        if (!empty($data['warehouse_id']) && !empty($data['slot_date'])) {
            $capacity = $this->checkWarehouseCapacity($data['warehouse_id'], $data['slot_date']);
            if ($capacity && $capacity['available_slots'] <= 0 && !$isUpdate) {
                $errors[] = 'Sklad nemá na tento den dostupnou kapacitu';
            }
        }

        return $errors;
    }

    // Get slots summary for dashboard
    public function getSlotsSummary($companyId = null) {
        try {
            $today = date('Y-m-d');
            $weekFromNow = date('Y-m-d', strtotime('+7 days'));

            $query = "SELECT 
                        COUNT(CASE WHEN ts.slot_date = :today THEN ts.id END) as today_slots,
                        COUNT(CASE WHEN ts.slot_date BETWEEN :today AND :week_from_now THEN ts.id END) as week_slots,
                        COUNT(CASE WHEN ts.slot_date > :week_from_now THEN ts.id END) as future_slots,
                        SUM(CASE WHEN ts.slot_date = :today THEN ts.max_capacity ELSE 0 END) as today_capacity,
                        COUNT(CASE WHEN ts.slot_date = :today AND b.id IS NOT NULL THEN ts.id END) as today_booked
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.is_active = 1";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':today', $today);
            $stmt->bindParam(':week_from_now', $weekFromNow);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slots summary error: " . $e->getMessage());
            return [];
        }
    }

    // Clean up old slots (soft delete slots older than specified days)
    public function cleanupOldSlots($daysOld = 90, $companyId = null) {
        try {
            $cutoffDate = date('Y-m-d', strtotime("-$daysOld days"));

            $query = "UPDATE " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     SET ts.is_active = 0, ts.updated_at = NOW()
                     WHERE ts.slot_date < :cutoff_date
                     AND ts.is_active = 1
                     AND NOT EXISTS (
                         SELECT 1 FROM bookings b 
                         WHERE b.time_slot_id = ts.id 
                         AND b.booking_status NOT IN ('cancelled')
                     )";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':cutoff_date', $cutoffDate);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->rowCount();
        } catch(Exception $e) {
            error_log("Cleanup old slots error: " . $e->getMessage());
            return 0;
        }
    }

    // Get performance metrics
    public function getPerformanceMetrics($dateFrom, $dateTo, $companyId = null) {
        try {
            $query = "SELECT 
                        COUNT(ts.id) as total_slots_created,
                        SUM(ts.max_capacity) as total_capacity_offered,
                        COUNT(b.id) as total_bookings_made,
                        COUNT(CASE WHEN b.booking_status = 'completed' THEN b.id END) as completed_bookings,
                        COUNT(CASE WHEN b.booking_status = 'cancelled' THEN b.id END) as cancelled_bookings,
                        ROUND((COUNT(b.id) / SUM(ts.max_capacity)) * 100, 2) as utilization_rate,
                        ROUND((COUNT(CASE WHEN b.booking_status = 'completed' THEN b.id END) / COUNT(b.id)) * 100, 2) as completion_rate
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id
                     WHERE ts.is_active = 1 
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get performance metrics error: " . $e->getMessage());
            return [];
        }
    }

    // Export slots to CSV format
    public function exportSlots($dateFrom, $dateTo, $companyId = null, $warehouseId = null) {
        try {
            $query = "SELECT 
                        ts.id,
                        ts.slot_date as 'Datum',
                        ts.slot_time as 'Čas',
                        w.name as 'Sklad',
                        ts.slot_type as 'Typ',
                        ts.duration_minutes as 'Délka (min)',
                        ts.max_capacity as 'Kapacita',
                        COALESCE(COUNT(b.id), 0) as 'Rezervace',
                        ts.notes as 'Poznámky',
                        ts.created_at as 'Vytvořeno'
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     WHERE ts.is_active = 1 
                     AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            if ($warehouseId) {
                $query .= " AND ts.warehouse_id = :warehouse_id";
            }

            $query .= " GROUP BY ts.id ORDER BY ts.slot_date, ts.slot_time";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            if ($warehouseId) {
                $stmt->bindParam(':warehouse_id', $warehouseId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Export slots error: " . $e->getMessage());
            return [];
        }
    }

    // Get slots by status for reporting
    public function getSlotsByStatus($status, $companyId = null, $limit = 50) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name,
                            COALESCE(COUNT(b.id), 0) as current_bookings,
                            u.full_name as created_by_name
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     LEFT JOIN users u ON ts.created_by = u.id
                     WHERE ts.is_active = 1";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            // Apply status filter
            switch($status) {
                case 'available':
                    $query .= " GROUP BY ts.id HAVING current_bookings < ts.max_capacity";
                    break;
                case 'full':
                    $query .= " GROUP BY ts.id HAVING current_bookings >= ts.max_capacity";
                    break;
                case 'empty':
                    $query .= " GROUP BY ts.id HAVING current_bookings = 0";
                    break;
                case 'today':
                    $query .= " AND ts.slot_date = CURDATE() GROUP BY ts.id";
                    break;
                case 'upcoming':
                    $query .= " AND ts.slot_date > CURDATE() GROUP BY ts.id";
                    break;
                case 'past':
                    $query .= " AND ts.slot_date < CURDATE() GROUP BY ts.id";
                    break;
                default:
                    $query .= " GROUP BY ts.id";
            }

            $query .= " ORDER BY ts.slot_date DESC, ts.slot_time DESC LIMIT :limit";

            $stmt = $this->conn->prepare($query);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slots by status error: " . $e->getMessage());
            return [];
        }
    }

    // Batch update slots
    public function batchUpdateSlots($slotIds, $updateData, $userId) {
        try {
            $this->conn->beginTransaction();
            $updated = 0;
            $errors = [];

            foreach ($slotIds as $slotId) {
                try {
                    if ($this->updateSlot($slotId, $updateData, $userId)) {
                        $updated++;
                    } else {
                        $errors[] = "Chyba při aktualizaci slotu ID: $slotId";
                    }
                } catch (Exception $e) {
                    $errors[] = "Slot ID $slotId: " . $e->getMessage();
                }
            }

            if ($updated > 0) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'updated' => $updated,
                    'errors' => $errors
                ];
            } else {
                $this->conn->rollback();
                return [
                    'success' => false,
                    'updated' => 0,
                    'errors' => $errors
                ];
            }
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Batch update slots error: " . $e->getMessage());
            return [
                'success' => false,
                'updated' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    // Get slots that can be optimized (suggestions for better scheduling)
    public function getOptimizationSuggestions($companyId = null, $days = 7) {
        try {
            $dateFrom = date('Y-m-d');
            $dateTo = date('Y-m-d', strtotime("+$days days"));

            $suggestions = [];

            // Find underutilized slots
            $underutilizedQuery = "SELECT ts.*, w.name as warehouse_name,
                                          COALESCE(COUNT(b.id), 0) as current_bookings,
                                          ROUND((COALESCE(COUNT(b.id), 0) / ts.max_capacity) * 100, 1) as utilization_percent
                                   FROM " . $this->table . " ts
                                   JOIN warehouses w ON ts.warehouse_id = w.id
                                   LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                                       AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                                   WHERE ts.is_active = 1 
                                   AND ts.slot_date BETWEEN :date_from AND :date_to";

            if ($companyId) {
                $underutilizedQuery .= " AND w.company_id = :company_id";
            }

            $underutilizedQuery .= " GROUP BY ts.id
                                    HAVING utilization_percent < 50 AND ts.max_capacity > 1
                                    ORDER BY utilization_percent ASC
                                    LIMIT 10";

            $stmt = $this->conn->prepare($underutilizedQuery);
            $stmt->bindParam(':date_from', $dateFrom);
            $stmt->bindParam(':date_to', $dateTo);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            $underutilized = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($underutilized as $slot) {
                $suggestions[] = [
                    'type' => 'underutilized',
                    'slot' => $slot,
                    'suggestion' => 'Zvažte snížení kapacity nebo sloučení s jiným slotem',
                    'priority' => 'low'
                ];
            }

            return $suggestions;
        } catch(Exception $e) {
            error_log("Get optimization suggestions error: " . $e->getMessage());
            return [];
        }
    }

    // Archive old completed slots (move to archive table or mark as archived)
    public function archiveCompletedSlots($daysOld = 365, $companyId = null) {
        try {
            $cutoffDate = date('Y-m-d', strtotime("-$daysOld days"));

            // Update slots to archived status instead of deleting
            $query = "UPDATE " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     SET ts.is_active = 0, ts.notes = CONCAT(COALESCE(ts.notes, ''), ' [ARCHIVED]'), ts.updated_at = NOW()
                     WHERE ts.slot_date < :cutoff_date
                     AND ts.is_active = 1
                     AND EXISTS (
                         SELECT 1 FROM bookings b 
                         WHERE b.time_slot_id = ts.id 
                         AND b.booking_status = 'completed'
                     )";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':cutoff_date', $cutoffDate);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->rowCount();
        } catch(Exception $e) {
            error_log("Archive completed slots error: " . $e->getMessage());
            return 0;
        }
    }

    // Get slot conflicts report
    public function getConflictsReport($companyId = null) {
        try {
            $query = "SELECT 
                        ts1.id as slot1_id,
                        ts1.slot_date,
                        ts1.slot_time as slot1_time,
                        ts1.duration_minutes as slot1_duration,
                        ts2.id as slot2_id,
                        ts2.slot_time as slot2_time,
                        ts2.duration_minutes as slot2_duration,
                        w.name as warehouse_name
                     FROM " . $this->table . " ts1
                     JOIN " . $this->table . " ts2 ON ts1.warehouse_id = ts2.warehouse_id 
                         AND ts1.slot_date = ts2.slot_date 
                         AND ts1.id < ts2.id
                     JOIN warehouses w ON ts1.warehouse_id = w.id
                     WHERE ts1.is_active = 1 AND ts2.is_active = 1
                     AND (
                         (ts1.slot_time <= ts2.slot_time AND ADDTIME(ts1.slot_time, SEC_TO_TIME(ts1.duration_minutes * 60)) > ts2.slot_time)
                         OR 
                         (ts2.slot_time <= ts1.slot_time AND ADDTIME(ts2.slot_time, SEC_TO_TIME(ts2.duration_minutes * 60)) > ts1.slot_time)
                     )";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " ORDER BY ts1.slot_date, ts1.slot_time";

            $stmt = $this->conn->prepare($query);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get conflicts report error: " . $e->getMessage());
            return [];
        }
    }

    // Get duplicate slots (same warehouse, date, time)
    public function getDuplicateSlots($companyId = null) {
        try {
            $query = "SELECT 
                        warehouse_id,
                        slot_date,
                        slot_time,
                        COUNT(*) as duplicate_count,
                        GROUP_CONCAT(id) as slot_ids,
                        w.name as warehouse_name
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     WHERE ts.is_active = 1";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " GROUP BY warehouse_id, slot_date, slot_time
                       HAVING COUNT(*) > 1
                       ORDER BY slot_date DESC, slot_time";

            $stmt = $this->conn->prepare($query);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get duplicate slots error: " . $e->getMessage());
            return [];
        }
    }

    // Health check for slots system
    public function healthCheck($companyId = null) {
        try {
            $health = [
                'status' => 'healthy',
                'checks' => [],
                'warnings' => [],
                'errors' => []
            ];

            // Check for conflicts
            $conflicts = $this->getConflictsReport($companyId);
            if (count($conflicts) > 0) {
                $health['errors'][] = count($conflicts) . " konfliktních slotů nalezeno";
                $health['status'] = 'error';
            }

            // Check for duplicates
            $duplicates = $this->getDuplicateSlots($companyId);
            if (count($duplicates) > 0) {
                $health['warnings'][] = count($duplicates) . " duplicitních slotů nalezeno";
                if ($health['status'] === 'healthy') {
                    $health['status'] = 'warning';
                }
            }

            // Check for overbooked slots
            $overbooked = $this->getSlotsNeedingAttention($companyId);
            $overbookedCount = count(array_filter($overbooked, function($slot) {
                return $slot['attention_type'] === 'overbooked';
            }));
            
            if ($overbookedCount > 0) {
                $health['errors'][] = $overbookedCount . " přeplněných slotů";
                $health['status'] = 'error';
            }

            // Check database connection
            $testQuery = "SELECT COUNT(*) FROM " . $this->table . " WHERE is_active = 1 LIMIT 1";
            $stmt = $this->conn->prepare($testQuery);
            $stmt->execute();
            $health['checks'][] = "Databázové připojení: OK";

            // Check for recent activity
            $recentQuery = "SELECT COUNT(*) FROM " . $this->table . " WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $stmt = $this->conn->prepare($recentQuery);
            $stmt->execute();
            $recentCount = $stmt->fetch(PDO::FETCH_COLUMN);
            $health['checks'][] = "Nové sloty za 24h: " . $recentCount;

            return $health;
        } catch(Exception $e) {
            error_log("Slots health check error: " . $e->getMessage());
            return [
                'status' => 'error',
                'checks' => [],
                'warnings' => [],
                'errors' => ['Chyba při kontrole zdraví systému: ' . $e->getMessage()]
            ];
        }
    }

    // Update existing time slot
    public function updateSlot($slotId, $data, $userId) {
        try {
            $this->conn->beginTransaction();

            // Get current slot data
            $currentSlot = $this->getSlotById($slotId);
            if (!$currentSlot) {
                throw new Exception('Slot nenalezen');
            }

            // Prepare update data with current values as defaults
            $updateData = [
                'warehouse_id' => $data['warehouse_id'] ?? $currentSlot['warehouse_id'],
                'slot_date' => $data['slot_date'] ?? $currentSlot['slot_date'],
                'slot_time' => $data['slot_time'] ?? $currentSlot['slot_time'],
                'duration_minutes' => $data['duration_minutes'] ?? $currentSlot['duration_minutes'],
                'max_capacity' => $data['max_capacity'] ?? $currentSlot['max_capacity'],
                'slot_type' => $data['slot_type'] ?? $currentSlot['slot_type'],
                'notes' => $data['notes'] ?? $currentSlot['notes']
            ];

            // Check for conflicts if time/date/warehouse changed
            if ($updateData['warehouse_id'] != $currentSlot['warehouse_id'] ||
                $updateData['slot_date'] != $currentSlot['slot_date'] ||
                $updateData['slot_time'] != $currentSlot['slot_time'] ||
                $updateData['duration_minutes'] != $currentSlot['duration_minutes']) {
                
                if ($this->hasConflictExcluding($slotId, $updateData['warehouse_id'], 
                    $updateData['slot_date'], $updateData['slot_time'], $updateData['duration_minutes'])) {
                    throw new Exception('Časový slot koliduje s existujícím slotem');
                }
            }

            $query = "UPDATE " . $this->table . " 
                     SET warehouse_id = :warehouse_id,
                         slot_date = :slot_date,
                         slot_time = :slot_time,
                         duration_minutes = :duration_minutes,
                         max_capacity = :max_capacity,
                         slot_type = :slot_type,
                         notes = :notes,
                         updated_at = NOW()
                     WHERE id = :slot_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->bindParam(':warehouse_id', $updateData['warehouse_id']);
            $stmt->bindParam(':slot_date', $updateData['slot_date']);
            $stmt->bindParam(':slot_time', $updateData['slot_time']);
            $stmt->bindParam(':duration_minutes', $updateData['duration_minutes']);
            $stmt->bindParam(':max_capacity', $updateData['max_capacity']);
            $stmt->bindParam(':slot_type', $updateData['slot_type']);
            $stmt->bindParam(':notes', $updateData['notes']);

            if ($stmt->execute()) {
                $this->conn->commit();
                return true;
            }

            $this->conn->rollback();
            return false;
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Update slot error: " . $e->getMessage());
            throw $e;
        }
    }

    // Get slot by ID with full details including bookings
    public function getSlotById($slotId) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name, w.company_id,
                            COALESCE(COUNT(b.id), 0) as current_bookings,
                            u.full_name as created_by_name,
                            GROUP_CONCAT(DISTINCT b.booking_status) as booking_statuses
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     LEFT JOIN users u ON ts.created_by = u.id
                     WHERE ts.id = :slot_id AND ts.is_active = 1
                     GROUP BY ts.id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }

            return null;
        } catch(Exception $e) {
            error_log("Get slot by ID error: " . $e->getMessage());
            return null;
        }
    }

    // Get count of active bookings for slot
    public function getActiveBookingsCount($slotId) {
        try {
            $query = "SELECT COUNT(*) FROM bookings 
                     WHERE time_slot_id = :slot_id 
                     AND booking_status IN ('pending', 'confirmed', 'in_progress')";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_COLUMN);
        } catch(Exception $e) {
            error_log("Get active bookings count error: " . $e->getMessage());
            return 0;
        }
    }

    // Get detailed bookings for slot
    public function getSlotBookingsDetailed($slotId) {
        try {
            $query = "SELECT b.*, u.full_name as driver_name, u.phone as driver_phone, u.email as driver_email
                     FROM bookings b
                     JOIN users u ON b.driver_id = u.id
                     WHERE b.time_slot_id = :slot_id
                     AND b.booking_status NOT IN ('cancelled')
                     ORDER BY b.created_at";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':slot_id', $slotId);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get slot bookings detailed error: " . $e->getMessage());
            return [];
        }
    }

    // Check for time conflicts
    private function hasConflict($warehouseId, $date, $time, $duration) {
        try {
            $endTime = $this->addMinutes($time, $duration);

            $query = "SELECT COUNT(*) FROM " . $this->table . " 
                     WHERE warehouse_id = :warehouse_id 
                     AND slot_date = :slot_date 
                     AND is_active = 1
                     AND (
                         (slot_time <= :start_time AND ADDTIME(slot_time, SEC_TO_TIME(duration_minutes * 60)) > :start_time)
                         OR 
                         (slot_time < :end_time AND ADDTIME(slot_time, SEC_TO_TIME(duration_minutes * 60)) >= :end_time)
                         OR
                         (slot_time >= :start_time AND slot_time < :end_time)
                     )";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':warehouse_id', $warehouseId);
            $stmt->bindParam(':slot_date', $date);
            $stmt->bindParam(':start_time', $time);
            $stmt->bindParam(':end_time', $endTime);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_COLUMN) > 0;
        } catch(Exception $e) {
            error_log("Conflict check error: " . $e->getMessage());
            return true; // Safe side - assume conflict
        }
    }

    // Check for conflicts excluding specific slot
    private function hasConflictExcluding($excludeSlotId, $warehouseId, $date, $time, $duration) {
        try {
            $endTime = $this->addMinutes($time, $duration);

            $query = "SELECT COUNT(*) FROM " . $this->table . " 
                     WHERE warehouse_id = :warehouse_id 
                     AND slot_date = :slot_date 
                     AND is_active = 1
                     AND id != :exclude_slot_id
                     AND (
                         (slot_time <= :start_time AND ADDTIME(slot_time, SEC_TO_TIME(duration_minutes * 60)) > :start_time)
                         OR 
                         (slot_time < :end_time AND ADDTIME(slot_time, SEC_TO_TIME(duration_minutes * 60)) >= :end_time)
                         OR
                         (slot_time >= :start_time AND slot_time < :end_time)
                     )";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':exclude_slot_id', $excludeSlotId);
            $stmt->bindParam(':warehouse_id', $warehouseId);
            $stmt->bindParam(':slot_date', $date);
            $stmt->bindParam(':start_time', $time);
            $stmt->bindParam(':end_time', $endTime);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_COLUMN) > 0;
        } catch(Exception $e) {
            error_log("Conflict check excluding error: " . $e->getMessage());
            return true;
        }
    }

    // Add minutes to time
    private function addMinutes($time, $minutes) {
        $timestamp = strtotime($time);
        $newTimestamp = $timestamp + ($minutes * 60);
        return date('H:i:s', $newTimestamp);
    }

    // Get all slots
    public function getAllSlots($companyId = null) {
        try {
            $query = "SELECT ts.*, w.name as warehouse_name, w.company_id,
                            COALESCE(COUNT(b.id), 0) as current_bookings,
                            u.full_name as created_by_name
                     FROM " . $this->table . " ts
                     JOIN warehouses w ON ts.warehouse_id = w.id
                     LEFT JOIN bookings b ON ts.id = b.time_slot_id 
                         AND b.booking_status IN ('pending', 'confirmed', 'in_progress')
                     LEFT JOIN users u ON ts.created_by = u.id
                     WHERE ts.is_active = 1";

            if ($companyId) {
                $query .= " AND w.company_id = :company_id";
            }

            $query .= " GROUP BY ts.id ORDER BY ts.slot_date DESC, ts.slot_time DESC";

            $stmt = $this->conn->prepare($query);
            
            if ($companyId) {
                $stmt->bindParam(':company_id', $companyId);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get all slots error: " . $e->getMessage());
            return [];
        }
    }
}
?>