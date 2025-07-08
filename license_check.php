<?php
// middleware/license_check.php
function checkLicenseMiddleware($companyId, $feature = null) {
    include_once __DIR__ . '/../config/database.php';
    include_once __DIR__ . '/../classes/LicenseManager.php';
    
    $database = new Database();
    $db = $database->connect();
    $licenseManager = new LicenseManager($db);
    
    // Kontrola platnosti licence
    $validity = $licenseManager->checkLicenseValidity($companyId);
    
    if (!$validity['is_valid']) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Neplatná licence',
            'message' => $validity['message'],
            'license_expired' => true
        ]);
        exit;
    }
    
    // Kontrola přístupu k funkci
    if ($feature && !checkFeatureAccess($companyId, $feature, $licenseManager)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Funkce není dostupná v této licenci',
            'feature' => $feature,
            'upgrade_required' => true
        ]);
        exit;
    }
    
    return true;
}

function checkFeatureAccess($companyId, $feature, $licenseManager) {
    $licenseInfo = $licenseManager->getCompanyLicenseInfo($companyId);
    
    if (!$licenseInfo || $licenseInfo['status'] === 'expired') {
        return false;
    }
    
    $features = $licenseInfo['features'] ?? [];
    return isset($features[$feature]) && $features[$feature] === true;
}

function requireValidLicense($companyId) {
    checkLicenseMiddleware($companyId);
}

function requireFeature($companyId, $feature) {
    checkLicenseMiddleware($companyId, $feature);
}
?>