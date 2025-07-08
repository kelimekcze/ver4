<?php
// config/database.php - Forpsi hosting
class Database {
    private $host = 'a066um.forpsi.com';
    private $db_name = 'f189871';
    private $username = 'f189871';
    private $password = 'urwFctSN';
    private $conn;

    public function connect() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
                )
            );
        } catch(PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Chyba připojení k databázi. Kontaktujte administrátora.");
        }
        return $this->conn;
    }

    public function getSetting($key, $default = null) {
        try {
            $conn = $this->connect();
            $query = "SELECT setting_value FROM system_settings WHERE setting_key = :key";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':key', $key);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_COLUMN);
            return $result !== false ? $result : $default;
        } catch(Exception $e) {
            error_log("Error getting setting {$key}: " . $e->getMessage());
            return $default;
        }
    }
}
?>