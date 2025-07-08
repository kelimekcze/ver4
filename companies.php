<?php
// api/companies.php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
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
    case 'GET':
        if (isset($_GET['overview'])) {
            checkSuperAdminAccess();
            
            try {
                $filters = [
                    'status' => $_GET['status'] ?? '',
                    'search' => $_GET['search'] ?? ''
                ];
                
                $companies = $licenseManager->getAllCompanies($filters);
                
                echo json_encode([
                    'success' => true,
                    'companies' => $companies
                ]);
            } catch(Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            
        } elseif (isset($_GET['company_id'])) {
            try {
                $companyId = intval($_GET['company_id']);
                
                if ($_SESSION['user_type'] !== 'super_admin') {
                    if (!isset($_SESSION['company_id']) || $_SESSION['company_id'] != $companyId) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Nemáte oprávnění k této firmě']);
                        exit;
                    }
                }
                
                $licenseInfo = $licenseManager->getCompanyLicenseInfo($companyId);
                
                if ($licenseInfo) {
                    echo json_encode([
                        'success' => true,
                        'license_info' => $licenseInfo
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Firma nenalezena']);
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
                    'companies' => $companies
                ]);
            } catch(Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        }
        break;
        
    case 'POST':
        checkSuperAdminAccess();
        
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data['company_name']) || empty($data['contact_email'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Název firmy a email jsou povinné']);
                break;
            }
            
            if (!filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Neplatný formát emailu']);
                break;
            }
            
            $result = $licenseManager->createCompany($data);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Firma byla úspěšně vytvořena',
                    'company_id' => $result['company_id'],
                    'company_code' => $result['company_code']
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Chyba při vytváření firmy']);
            }
        } catch(Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'PUT':
        checkSuperAdminAccess();
        
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            $companyId = intval($data['company_id']);
            $action = $data['action'];
            
            if ($action === 'activate') {
                $result = $licenseManager->activateCompany($companyId);
                $message = 'Firma byla aktivována';
            } elseif ($action === 'deactivate') {
                $result = $licenseManager->deactivateCompany($companyId, $data['reason'] ?? '');
                $message = 'Firma byla deaktivována';
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Neplatná akce']);
                break;
            }
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => $message
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Chyba při provádění akce']);
            }
        } catch(Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Metoda není povolena']);
        break;
}
?>