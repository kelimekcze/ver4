<?php
// classes/User.php - User management class
class User {
    private $conn;
    private $table = 'users';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Register new user
    public function register($data) {
        try {
            $query = "INSERT INTO " . $this->table . " 
                     (username, email, password_hash, full_name, phone, user_type, 
                      company_name, truck_license_plate, driver_license, created_at) 
                     VALUES 
                     (:username, :email, :password_hash, :full_name, :phone, :user_type, 
                      :company_name, :truck_license_plate, :driver_license, NOW())";

            $stmt = $this->conn->prepare($query);

            // Hash password
            $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);

            $stmt->bindParam(':username', $data['username']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password_hash', $password_hash);
            $stmt->bindParam(':full_name', $data['full_name']);
            $stmt->bindParam(':phone', $data['phone']);
            $stmt->bindParam(':user_type', $data['user_type']);
            $stmt->bindParam(':company_name', $data['company_name']);
            $stmt->bindParam(':truck_license_plate', $data['truck_license_plate']);
            $stmt->bindParam(':driver_license', $data['driver_license']);

            if ($stmt->execute()) {
                return $this->conn->lastInsertId();
            }

            return false;
        } catch(PDOException $e) {
            error_log("User registration error: " . $e->getMessage());
            return false;
        }
    }

    // Login user
    public function login($email, $password) {
        try {
            $query = "SELECT id, username, email, password_hash, full_name, phone, user_type, 
                            company_id, company_name, truck_license_plate, driver_license, 
                            is_active, is_company_admin, created_at 
                     FROM " . $this->table . " 
                     WHERE email = :email AND is_active = 1 
                     LIMIT 1";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Verify password
                if (password_verify($password, $user['password_hash'])) {
                    // Remove password hash from returned data
                    unset($user['password_hash']);
                    
                    // Update last login
                    $this->updateLastLogin($user['id']);
                    
                    return $user;
                }
            }

            return false;
        } catch(PDOException $e) {
            error_log("User login error: " . $e->getMessage());
            return false;
        }
    }

    // Update last login timestamp
    private function updateLastLogin($user_id) {
        try {
            $query = "UPDATE " . $this->table . " SET last_login = NOW() WHERE id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
        } catch(PDOException $e) {
            error_log("Update last login error: " . $e->getMessage());
        }
    }

    // Get user by ID
    public function getUserById($user_id) {
        try {
            $query = "SELECT id, username, email, full_name, phone, user_type, 
                            company_id, company_name, truck_license_plate, driver_license, 
                            is_active, is_company_admin, created_at, last_login 
                     FROM " . $this->table . " 
                     WHERE id = :user_id 
                     LIMIT 1";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }

            return false;
        } catch(PDOException $e) {
            error_log("Get user by ID error: " . $e->getMessage());
            return false;
        }
    }

    // Get all users for admin
    public function getAllUsers($company_id = null) {
        try {
            $query = "SELECT id, username, email, full_name, phone, user_type, 
                            company_id, company_name, truck_license_plate, driver_license, 
                            is_active, is_company_admin, created_at, last_login 
                     FROM " . $this->table . " 
                     WHERE 1=1";

            if ($company_id) {
                $query .= " AND company_id = :company_id";
            }

            $query .= " ORDER BY created_at DESC";

            $stmt = $this->conn->prepare($query);
            
            if ($company_id) {
                $stmt->bindParam(':company_id', $company_id);
            }
            
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Get all users error: " . $e->getMessage());
            return [];
        }
    }

    // Update user profile
    public function updateProfile($user_id, $data) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET full_name = :full_name, 
                         phone = :phone, 
                         company_name = :company_name, 
                         truck_license_plate = :truck_license_plate, 
                         driver_license = :driver_license 
                     WHERE id = :user_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':full_name', $data['full_name']);
            $stmt->bindParam(':phone', $data['phone']);
            $stmt->bindParam(':company_name', $data['company_name']);
            $stmt->bindParam(':truck_license_plate', $data['truck_license_plate']);
            $stmt->bindParam(':driver_license', $data['driver_license']);

            return $stmt->execute();
        } catch(PDOException $e) {
            error_log("Update profile error: " . $e->getMessage());
            return false;
        }
    }

    // Change password
    public function changePassword($user_id, $old_password, $new_password) {
        try {
            // First verify old password
            $query = "SELECT password_hash FROM " . $this->table . " WHERE id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($old_password, $user['password_hash'])) {
                    // Update with new password
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $updateQuery = "UPDATE " . $this->table . " 
                                   SET password_hash = :password_hash 
                                   WHERE id = :user_id";
                    
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->bindParam(':password_hash', $new_hash);
                    $updateStmt->bindParam(':user_id', $user_id);
                    
                    return $updateStmt->execute();
                }
            }

            return false;
        } catch(PDOException $e) {
            error_log("Change password error: " . $e->getMessage());
            return false;
        }
    }

    // Activate/Deactivate user
    public function setUserStatus($user_id, $is_active) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET is_active = :is_active 
                     WHERE id = :user_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':is_active', $is_active);

            return $stmt->execute();
        } catch(PDOException $e) {
            error_log("Set user status error: " . $e->getMessage());
            return false;
        }
    }

    // Assign user to company
    public function assignToCompany($user_id, $company_id) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET company_id = :company_id 
                     WHERE id = :user_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':company_id', $company_id);

            return $stmt->execute();
        } catch(PDOException $e) {
            error_log("Assign to company error: " . $e->getMessage());
            return false;
        }
    }

    // Set company admin status
    public function setCompanyAdmin($user_id, $is_admin) {
        try {
            $query = "UPDATE " . $this->table . " 
                     SET is_company_admin = :is_admin 
                     WHERE id = :user_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':is_admin', $is_admin);

            return $stmt->execute();
        } catch(PDOException $e) {
            error_log("Set company admin error: " . $e->getMessage());
            return false;
        }
    }

    // Get drivers for company
    public function getCompanyDrivers($company_id) {
        try {
            $query = "SELECT id, username, email, full_name, phone, 
                            truck_license_plate, driver_license, is_active 
                     FROM " . $this->table . " 
                     WHERE company_id = :company_id 
                     AND user_type = 'driver' 
                     ORDER BY full_name";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':company_id', $company_id);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Get company drivers error: " . $e->getMessage());
            return [];
        }
    }

    // Check if email exists
    public function emailExists($email, $exclude_user_id = null) {
        try {
            $query = "SELECT id FROM " . $this->table . " WHERE email = :email";
            
            if ($exclude_user_id) {
                $query .= " AND id != :exclude_user_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            
            if ($exclude_user_id) {
                $stmt->bindParam(':exclude_user_id', $exclude_user_id);
            }
            
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            error_log("Email exists check error: " . $e->getMessage());
            return false;
        }
    }

    // Check if username exists
    public function usernameExists($username, $exclude_user_id = null) {
        try {
            $query = "SELECT id FROM " . $this->table . " WHERE username = :username";
            
            if ($exclude_user_id) {
                $query .= " AND id != :exclude_user_id";
            }

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            
            if ($exclude_user_id) {
                $stmt->bindParam(':exclude_user_id', $exclude_user_id);
            }
            
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            error_log("Username exists check error: " . $e->getMessage());
            return false;
        }
    }
}
?>