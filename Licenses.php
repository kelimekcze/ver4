<?php
// api/licenses.php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../config/database.php';
include_once '../classes/LicenseManager.php';

function checkSuperAdminAccess() {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup pouze pro super administrátory']);
        exit;
    }
}

$database = new Database();
$db = $database->connect();
$licenseManager = new LicenseManager($db);

switch($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (isset($data['action']) && $data['action'] === 'extend') {
            checkSuperAdminAccess();
            
            try {
                $companyId = intval($data['company_id']);
                $days = intval($data['days']);
                $note = $data['note'] ?? '';
                $extendedBy = $_SESSION['user_id'];
                
                if ($days <= 0 || $days > 3650) { // max 10 let
                    http_response_code(400);
                    echo json_encode(['error' => 'Neplatný počet dní (1-3650)']);
                    exit;
                }
                
                $result = $licenseManager->extendLicense($companyId, $days, $extendedBy, $note);
                
                echo json_encode([
                    'success' => true,
                    'message' => "Licence prodloužena o {$days} dní",
                    'old_valid_until' => $result['old_valid_until'],
                    'new_valid_until' => $result['new_valid_until']
                ]);
                
            } catch(Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Neplatná akce']);
        }
        break;
        
    case 'GET':
        if (isset($_GET['check_validity'])) {
            try {
                $companyId = intval($_GET['company_id']);
                
                if ($_SESSION['user_type'] !== 'super_admin') {
                    if (!isset($_SESSION['company_id']) || $_SESSION['company_id'] != $companyId) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Nemáte oprávnění']);
                        exit;
                    }
                }
                
                $validity = $licenseManager->checkLicenseValidity($companyId);
                
                echo json_encode([
                    'success' => true,
                    'is_valid' => $validity['is_valid'],
                    'message' => $validity['message']
                ]);
                
            } catch(Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } elseif (isset($_GET['company_info'])) {
            try {
                $companyId = $_SESSION['company_id'] ?? intval($_GET['company_id']);
                
                if ($_SESSION['user_type'] !== 'super_admin' && $_SESSION['company_id'] != $companyId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Nemáte oprávnění']);
                    exit;
                }
                
                $licenseInfo = $licenseManager->getCompanyLicenseInfo($companyId);
                
                if ($licenseInfo) {
                    echo json_encode([
                        'success' => true,
                        'license_info' => $licenseInfo
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Informace o licenci nenalezeny']);
                }
                
            } catch(Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } else {
            checkSuperAdminAccess();
            
            try {
                $companies = $licenseManager->getAllCompanies();
                echo json_encode([
                    'success' => true,
                    'licenses' => $companies
                ]);
            } catch(Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Metoda není povolena']);
        break;
}
?>