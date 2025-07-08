<?php
// test_warehouse.php - Script pro testování warehouse API
session_start();

// Simulate login (pro testování - ODSTRAŇTE V PRODUKCI!)
$_SESSION['user_id'] = 1;
$_SESSION['user_type'] = 'super_admin';
$_SESSION['company_id'] = 1;

echo "<h1>Warehouse API Test</h1>";

// Test data
$testData = [
    'name' => 'Test Sklad',
    'address' => 'Test adresa 123',
    'contact_person' => 'Jan Novák',
    'contact_phone' => '123456789',
    'contact_email' => 'test@example.com',
    'working_hours_start' => '08:00',
    'working_hours_end' => '16:00',
    'max_simultaneous_slots' => 5
];

echo "<h2>Test Data:</h2>";
echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";

echo "<h2>Session Data:</h2>";
echo "<pre>" . json_encode($_SESSION, JSON_PRETTY_PRINT) . "</pre>";

// Test 1: GET request (list warehouses)
echo "<h2>Test 1: GET Request (List Warehouses)</h2>";
$url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/api/warehouses.php";
echo "URL: $url<br>";

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            'Accept: application/json',
            'Cookie: ' . http_build_query($_COOKIE, '', '; ')
        ]
    ]
]);

$response = file_get_contents($url, false, $context);
echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";

// Test 2: POST request (create warehouse)
echo "<h2>Test 2: POST Request (Create Warehouse)</h2>";

$postData = json_encode($testData);
echo "POST Data: <pre>" . htmlspecialchars($postData) . "</pre>";

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cookie: ' . http_build_query($_COOKIE, '', '; ')
        ],
        'content' => $postData
    ]
]);

$response = file_get_contents($url, false, $context);
echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";

// Debug info
echo "<h2>Debug Info:</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "Script Path: " . __FILE__ . "<br>";
echo "Current Working Directory: " . getcwd() . "<br>";

// Check if files exist
$files = [
    '../config/database.php',
    '../middleware/license_check.php',
    'api/warehouses.php'
];

echo "<h3>File Check:</h3>";
foreach ($files as $file) {
    $fullPath = __DIR__ . '/' . $file;
    echo "$file: " . (file_exists($fullPath) ? "✅ EXISTS" : "❌ NOT FOUND") . " ($fullPath)<br>";
}

// Check database connection
echo "<h3>Database Connection Test:</h3>";
try {
    $dbPath = __DIR__ . '/../config/database.php';
    if (file_exists($dbPath)) {
        include_once $dbPath;
        $database = new Database();
        $db = $database->connect();
        echo "✅ Database connection successful<br>";
        
        // Test warehouses table
        $stmt = $db->query("SHOW TABLES LIKE 'warehouses'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Warehouses table exists<br>";
            
            // Check table structure
            $stmt = $db->query("DESCRIBE warehouses");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "Table structure:<br>";
            foreach ($columns as $column) {
                echo "- {$column['Field']} ({$column['Type']})<br>";
            }
        } else {
            echo "❌ Warehouses table does not exist<br>";
        }
        
    } else {
        echo "❌ Database config file not found<br>";
    }
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
}

?>
<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h1, h2, h3 { color: #333; }
pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
.success { color: green; }
.error { color: red; }
</style>