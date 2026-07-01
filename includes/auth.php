<?php
/**
 * includes/auth.php  Session-based authentication + role-based access control.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   requireLogin();                          // any authenticated user
 *   requireRole(['Manager','Administrator']);         // Manager OR Administrator
 *   requireCap('canManageInventory');         // capability check
 *
 * Role hierarchy (from spec):
 *   Administrator   read-only dashboards & reports
 *   Manager         full access, user management, audit logs
 *   Manager         dashboard, adjustments, analysis, forecasts, exports
 *   InventoryOfficer  product listings, stock movements, inventory reports
 *   SalesCashier    record sales, product lookup, limited dashboard
 *   WarehouseStaff  stock movements, stock list, low-stock alerts
 *   Accountant      sales reports & exports (read-only)
 *   Auditor         read-only reports + audit trail
 *   ITSupport       dashboard/settings, no business data editing
 */

if (session_status() === PHP_SESSION_NONE)
    session_start();

// 
// Core session helpers
// 

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

function currentUser(): array
{
    $avatar = null;
    if (!empty($_SESSION['user_id'])) {
        try {
            $db = getDB();
            $db->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([$_SESSION['user_id']]);
            $stmt = $db->prepare('SELECT avatar FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $avatar = $stmt->fetchColumn() ?: null;
        } catch (Throwable $t) { /* ignore */ }
    }

    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'avatar' => $avatar,
    ];
}

function userRole(): string
{
    return $_SESSION['role'] ?? '';
}

// 
// Access gates
// 

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

/**
 * Require one of the given roles. Pass a string or an array of roles.
 * If the user doesn't qualify, redirect with access_denied.
 */
function requireRole(array|string $roles): void
{
    requireLogin();
    $roles = (array) $roles;
    if (!in_array(userRole(), $roles, true)) {
        $landing = defined('BASE_URL') ? BASE_URL . roleLandingPage() : 'login.php';
        if (basename($_SERVER['PHP_SELF']) === roleLandingPage()) {
            die('Access Denied: You do not have permission to view this page.');
        }
        header('Location: ' . $landing . '?error=access_denied');
        exit;
    }
}

/**
 * Require a named capability. Redirects if not allowed.
 * Capabilities are defined as canXxx() functions below.
 */
function requireCap(string $cap): void
{
    requireLogin();
    if (!call_user_func($cap)) {
        $landing = defined('BASE_URL') ? BASE_URL . roleLandingPage() : 'login.php';
        if (basename($_SERVER['PHP_SELF']) === roleLandingPage()) {
            die('Access Denied: You do not have permission to view this page.');
        }
        header('Location: ' . $landing . '?error=access_denied');
        exit;
    }
}

// 
// Capability functions (return bool)
// 

function isAdmin(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager'], true);
}

// Dashboard access  overview KPIs
function canViewDashboard(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager'], true);
}

// Inventory product CRUD (add/edit/delete products)
function canManageProducts(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'InventoryOfficer'], true);
}

// Category viewing
function canViewCategories(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'InventoryOfficer'], true);
}

// Category management (add/edit/delete categories)
function canManageCategories(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'InventoryOfficer'], true);
}

// Stock adjustments (IN/OUT/ADJUSTMENT)
function canAdjustStock(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'InventoryOfficer'], true);
}

// View inventory list and reports
function canViewInventory(): bool
{
    // Online Agent and Cashier can view inventory too
    return in_array(userRole(), ['Administrator', 'Manager', 'InventoryOfficer', 'SalesCashier', 'OnlineAgent'], true);
}

// Quotation Feature
function canCreateQuotation(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'OnlineAgent'], true);
}

// Record sales transactions
function canRecordSales(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'SalesCashier'], true);
}

// View sales records / history
function canViewSales(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'SalesCashier'], true);
}

// Reports and analytics (read)
function canViewReports(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager'], true);
}

// Predictions / forecasting
function canViewPredictions(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'InventoryOfficer'], true);
}

// User management (add/edit/deactivate users)
function canManageUsers(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager'], true);
}

// Audit log viewer
function canViewAuditLog(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager'], true);
}

// Export reports to CSV
function canExportReports(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager'], true);
}

// Agent commission dashboard
function canViewCommissions(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager'], true);
}

// Receive personal commissions
function canReceiveCommission(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'OnlineAgent'], true);
}

// Release/Mark commission as paid (Administrator only)
function canMarkCommissionPaid(): bool
{
    return userRole() === 'Administrator';
}

// Stock movements history viewer
function canViewMovements(): bool
{
    return in_array(userRole(), ['Administrator', 'Manager', 'InventoryOfficer'], true);
}

// 
// Role-based landing page (used by login.php)
// 

function roleLandingPage(): string
{
    return match (userRole()) {
        'Administrator', 'Manager' => 'dashboard.php',
        'SalesCashier' => 'sales.php',
        'InventoryOfficer' => 'inventory.php',
        'OnlineAgent' => 'quotation.php',
        default => 'logout.php',
    };
}

// 
// Audit logging helper
// 

/**
 * Write an entry to audit_log.
 *
 * @param PDO    $db         Active DB connection
 * @param string $action     e.g. 'product.add', 'sale.record', 'user.delete'
 * @param string $targetType e.g. 'product', 'sale', 'user'
 * @param int    $targetId   ID of the affected record (0 if N/A)
 * @param string $detail     Human-readable description or JSON
 */
function logAudit(
    PDO $db,
    string $action,
    string $targetType = '',
    int $targetId = 0,
    string $detail = ''
): void {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId)
        return; // no session, skip

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';

    $stmt = $db->prepare(
        'INSERT INTO audit_log (user_id, action, target_type, target_id, detail, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $action,
        $targetType,
        $targetId ?: null,
        $detail,
        substr($ip, 0, 45),
    ]);
}

/**
 * Write an entry to stock_movements.
 */
function logStockMovement(
    PDO $db,
    int $productId,
    string $type,     // 'IN','OUT','ADJUSTMENT','SALE'
    int $quantity,
    string $notes = ''
): void {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId || !$productId)
        return;

    $stmt = $db->prepare(
        'INSERT INTO stock_movements (product_id, user_id, type, quantity, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$productId, $userId, $type, $quantity, $notes]);
}

