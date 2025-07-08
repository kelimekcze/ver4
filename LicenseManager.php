<?php
// classes/LicenseManager.php - License management class
class LicenseManager {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Check license validity for a company
    public function checkLicenseValidity($company_id) {
        try {
            $query = "SELECT l.*, c.company_name 
                     FROM licenses l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.company_id = :company_id 
                     AND l.is_active = 1 
                     ORDER BY l.valid_until DESC 
                     LIMIT 1";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $license = $stmt->fetch(PDO::FETCH_ASSOC);
                $today = new DateTime();
                $validUntil = new DateTime($license['valid_until']);

                if ($validUntil >= $today) {
                    return [
                        'is_valid' => true,
                        'message' => 'Licence je platná',
                        'expires_in_days' => $today->diff($validUntil)->days,
                        'license' => $license
                    ];
                } else {
                    return [
                        'is_valid' => false,
                        'message' => 'Licence vypršela dne ' . $license['valid_until'],
                        'expires_in_days' => -$today->diff($validUntil)->days,
                        'license' => $license
                    ];
                }
            } else {
                return [
                    'is_valid' => false,
                    'message' => 'Nebyla nalezena žádná aktivní licence',
                    'expires_in_days' => 0,
                    'license' => null
                ];
            }
        } catch(Exception $e) {
            error_log("License validity check error: " . $e->getMessage());
            return [
                'is_valid' => false,
                'message' => 'Chyba při kontrole licence',
                'expires_in_days' => 0,
                'license' => null
            ];
        }
    }

    // Get company license info
    public function getCompanyLicenseInfo($company_id) {
        try {
            $query = "SELECT c.*, l.license_type, l.valid_from, l.valid_until, 
                            l.max_users, l.max_warehouses, l.features, l.is_active as license_active,
                            DATEDIFF(l.valid_until, CURDATE()) as days_remaining
                     FROM companies c 
                     LEFT JOIN licenses l ON c.id = l.company_id AND l.is_active = 1
                     WHERE c.id = :company_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Determine status
                if ($result['license_active']) {
                    if ($result['days_remaining'] > 30) {
                        $result['status'] = 'active';
                    } elseif ($result['days_remaining'] > 7) {
                        $result['status'] = 'expiring_month';
                    } elseif ($result['days_remaining'] > 0) {
                        $result['status'] = 'expiring_soon';
                    } else {
                        $result['status'] = 'expired';
                    }
                } else {
                    $result['status'] = 'no_license';
                }

                // Parse features if JSON
                if ($result['features']) {
                    $result['features'] = json_decode($result['features'], true);
                }

                return $result;
            }

            return null;
        } catch(Exception $e) {
            error_log("Get company license info error: " . $e->getMessage());
            return null;
        }
    }

    // Get all companies with license info
    public function getAllCompanies($filters = []) {
        try {
            $query = "SELECT c.*, l.license_type, l.valid_from, l.valid_until, 
                            l.max_users, l.max_warehouses, l.is_active as license_active,
                            DATEDIFF(l.valid_until, CURDATE()) as days_remaining,
                            COUNT(u.id) as user_count
                     FROM companies c 
                     LEFT JOIN licenses l ON c.id = l.company_id AND l.is_active = 1
                     LEFT JOIN users u ON c.id = u.company_id AND u.is_active = 1
                     WHERE c.is_active = 1";

            if (!empty($filters['status'])) {
                switch($filters['status']) {
                    case 'active':
                        $query .= " AND l.valid_until > CURDATE()";
                        break;
                    case 'expiring':
                        $query .= " AND l.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
                        break;
                    case 'expired':
                        $query .= " AND l.valid_until < CURDATE()";
                        break;
                }
            }

            if (!empty($filters['search'])) {
                $query .= " AND (c.company_name LIKE :search OR c.contact_email LIKE :search)";
            }

            $query .= " GROUP BY c.id ORDER BY c.company_name";

            $stmt = $this->conn->prepare($query);

            if (!empty($filters['search'])) {
                $searchTerm = '%' . $filters['search'] . '%';
                $stmt->bindParam(':search', $searchTerm);
            }

            $stmt->execute();
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add status to each company
            foreach ($companies as &$company) {
                if ($company['license_active']) {
                    if ($company['days_remaining'] > 30) {
                        $company['status'] = 'active';
                    } elseif ($company['days_remaining'] > 7) {
                        $company['status'] = 'expiring_month';
                    } elseif ($company['days_remaining'] > 0) {
                        $company['status'] = 'expiring_soon';
                    } else {
                        $company['status'] = 'expired';
                    }
                } else {
                    $company['status'] = 'no_license';
                }
            }

            return $companies;
        } catch(Exception $e) {
            error_log("Get all companies error: " . $e->getMessage());
            return [];
        }
    }

    // Create new company
    public function createCompany($data) {
        try {
            $this->conn->beginTransaction();

            // Generate unique company code
            $companyCode = $this->generateCompanyCode($data['company_name']);

            // Insert company
            $query = "INSERT INTO companies (company_name, company_code, contact_email, contact_phone, address) 
                     VALUES (:company_name, :company_code, :contact_email, :contact_phone, :address)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':company_name', $data['company_name']);
            $stmt->bindParam(':company_code', $companyCode);
            $stmt->bindParam(':contact_email', $data['contact_email']);
            $stmt->bindParam(':contact_phone', $data['contact_phone'] ?? null);
            $stmt->bindParam(':address', $data['address'] ?? null);

            if ($stmt->execute()) {
                $companyId = $this->conn->lastInsertId();

                // Create trial license if requested
                if (isset($data['create_trial']) && $data['create_trial']) {
                    $this->createTrialLicense($companyId);
                }

                $this->conn->commit();

                return [
                    'success' => true,
                    'company_id' => $companyId,
                    'company_code' => $companyCode
                ];
            }

            $this->conn->rollback();
            return ['success' => false];

        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Create company error: " . $e->getMessage());
            return ['success' => false];
        }
    }

    // Generate unique company code
    private function generateCompanyCode($companyName) {
        $prefix = 'CRM';
        $number = 1;

        // Try to generate from company name
        $nameCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $companyName), 0, 3));
        if (strlen($nameCode) < 3) {
            $nameCode = $prefix;
        }

        // Find highest existing number
        $query = "SELECT company_code FROM companies WHERE company_code LIKE :prefix ORDER BY company_code DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':prefix', $nameCode . '%');
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $lastCode = $stmt->fetch(PDO::FETCH_COLUMN);
            $lastNumber = intval(substr($lastCode, 3));
            $number = $lastNumber + 1;
        }

        return $nameCode . str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    // Create trial license
    private function createTrialLicense($companyId) {
        $query = "INSERT INTO licenses (company_id, license_type, valid_from, valid_until, max_users, max_warehouses, features) 
                 VALUES (:company_id, 'trial', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 5, 2, :features)";

        $features = json_encode([
            'calendar' => true,
            'bookings' => true,
            'analytics' => false
        ]);

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':company_id', $companyId);
        $stmt->bindParam(':features', $features);
        
        return $stmt->execute();
    }

    // Extend license
    public function extendLicense($companyId, $days, $extendedBy, $note = '') {
        try {
            $this->conn->beginTransaction();

            // Get current license
            $query = "SELECT * FROM licenses WHERE company_id = :company_id AND is_active = 1 ORDER BY valid_until DESC LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':company_id', $companyId);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $license = $stmt->fetch(PDO::FETCH_ASSOC);
                $oldValidUntil = $license['valid_until'];
                
                // Calculate new expiration date
                $currentExpiry = new DateTime($license['valid_until']);
                $today = new DateTime();
                
                // If license is already expired, extend from today
                if ($currentExpiry < $today) {
                    $newExpiry = $today->add(new DateInterval('P' . $days . 'D'));
                } else {
                    $newExpiry = $currentExpiry->add(new DateInterval('P' . $days . 'D'));
                }
                
                // Update license
                $updateQuery = "UPDATE licenses SET valid_until = :new_expiry WHERE id = :license_id";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(':new_expiry', $newExpiry->format('Y-m-d'));
                $updateStmt->bindParam(':license_id', $license['id']);
                
                if ($updateStmt->execute()) {
                    // Log the extension
                    $this->logLicenseExtension($companyId, $extendedBy, $days, $oldValidUntil, $newExpiry->format('Y-m-d'), $note);
                    
                    $this->conn->commit();
                    
                    return [
                        'success' => true,
                        'old_valid_until' => $oldValidUntil,
                        'new_valid_until' => $newExpiry->format('Y-m-d')
                    ];
                }
            }
            
            $this->conn->rollback();
            return ['success' => false];
            
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Extend license error: " . $e->getMessage());
            return ['success' => false];
        }
    }

    // Log license extension
    private function logLicenseExtension($companyId, $extendedBy, $days, $oldDate, $newDate, $note) {
        try {
            $query = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address) 
                     VALUES (:user_id, 'license_extended', 'licenses', :company_id, :old_values, :new_values, :ip)";
            
            $oldValues = json_encode(['valid_until' => $oldDate]);
            $newValues = json_encode(['valid_until' => $newDate, 'days_added' => $days, 'note' => $note]);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $extendedBy);
            $stmt->bindParam(':company_id', $companyId);
            $stmt->bindParam(':old_values', $oldValues);
            $stmt->bindParam(':new_values', $newValues);
            $stmt->bindParam(':ip', $ip);
            
            $stmt->execute();
        } catch(Exception $e) {
            error_log("License extension log error: " . $e->getMessage());
        }
    }

    // Activate company
    public function activateCompany($companyId) {
        try {
            $query = "UPDATE companies SET is_active = 1 WHERE id = :company_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':company_id', $companyId);
            
            return $stmt->execute();
        } catch(Exception $e) {
            error_log("Activate company error: " . $e->getMessage());
            return false;
        }
    }

    // Deactivate company
    public function deactivateCompany($companyId, $reason = '') {
        try {
            $this->conn->beginTransaction();
            
            // Deactivate company
            $query = "UPDATE companies SET is_active = 0 WHERE id = :company_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':company_id', $companyId);
            
            if ($stmt->execute()) {
                // Deactivate all users
                $userQuery = "UPDATE users SET is_active = 0 WHERE company_id = :company_id";
                $userStmt = $this->conn->prepare($userQuery);
                $userStmt->bindParam(':company_id', $companyId);
                $userStmt->execute();
                
                // Deactivate license
                $licenseQuery = "UPDATE licenses SET is_active = 0 WHERE company_id = :company_id";
                $licenseStmt = $this->conn->prepare($licenseQuery);
                $licenseStmt->bindParam(':company_id', $companyId);
                $licenseStmt->execute();
                
                $this->conn->commit();
                return true;
            }
            
            $this->conn->rollback();
            return false;
            
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Deactivate company error: " . $e->getMessage());
            return false;
        }
    }

    // Get license statistics
    public function getLicenseStatistics() {
        try {
            $stats = [];
            
            // Total companies
            $query = "SELECT COUNT(*) as total FROM companies WHERE is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $stats['total_companies'] = $stmt->fetch(PDO::FETCH_COLUMN);
            
            // Active licenses
            $query = "SELECT COUNT(*) as active FROM licenses l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.is_active = 1 AND l.valid_until > CURDATE() AND c.is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $stats['active_licenses'] = $stmt->fetch(PDO::FETCH_COLUMN);
            
            // Expiring soon (next 30 days)
            $query = "SELECT COUNT(*) as expiring FROM licenses l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.is_active = 1 AND l.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND c.is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $stats['expiring_licenses'] = $stmt->fetch(PDO::FETCH_COLUMN);
            
            // Expired licenses
            $query = "SELECT COUNT(*) as expired FROM licenses l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.is_active = 1 AND l.valid_until < CURDATE() AND c.is_active = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $stats['expired_licenses'] = $stmt->fetch(PDO::FETCH_COLUMN);
            
            // Revenue estimate (simplified)
            $monthly_price = 500; // CZK per month
            $yearly_price = 5000; // CZK per year
            
            $query = "SELECT license_type, COUNT(*) as count FROM licenses l 
                     JOIN companies c ON l.company_id = c.id 
                     WHERE l.is_active = 1 AND l.valid_until > CURDATE() AND c.is_active = 1 
                     GROUP BY license_type";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $monthly_revenue = 0;
            foreach ($licenses as $license) {
                if ($license['license_type'] === 'monthly') {
                    $monthly_revenue += $license['count'] * $monthly_price;
                } elseif ($license['license_type'] === 'yearly') {
                    $monthly_revenue += $license['count'] * ($yearly_price / 12);
                }
            }
            
            $stats['estimated_monthly_revenue'] = $monthly_revenue;
            
            return $stats;
        } catch(Exception $e) {
            error_log("Get license statistics error: " . $e->getMessage());
            return [];
        }
    }

    // Check feature access
    public function hasFeatureAccess($companyId, $feature) {
        try {
            $licenseInfo = $this->getCompanyLicenseInfo($companyId);
            
            if (!$licenseInfo || $licenseInfo['status'] === 'expired') {
                return false;
            }
            
            $features = $licenseInfo['features'] ?? [];
            return isset($features[$feature]) && $features[$feature] === true;
        } catch(Exception $e) {
            error_log("Check feature access error: " . $e->getMessage());
            return false;
        }
    }

    // Get companies with expiring licenses
    public function getExpiringLicenses($days = 30) {
        try {
            $query = "SELECT c.*, l.valid_until, DATEDIFF(l.valid_until, CURDATE()) as days_remaining
                     FROM companies c 
                     JOIN licenses l ON c.id = l.company_id 
                     WHERE l.is_active = 1 AND c.is_active = 1
                     AND l.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
                     ORDER BY l.valid_until ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':days', $days);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {
            error_log("Get expiring licenses error: " . $e->getMessage());
            return [];
        }
    }
}
?>