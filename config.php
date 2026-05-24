<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');

// Dynamic Environment Configuration (XAMPP Local vs Hostinger Production)
if (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'montien.tech') !== false || $_SERVER['HTTP_HOST'] === 'pnp-edu.montien.tech')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u651170081_pnp_academic');
    define('DB_USER', 'u651170081_pnp_academic');
    define('DB_PASS', 'a1d9GH10%');
} else {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'pnp_academic');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    
    // Auto-initialize branding settings
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS branding_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meta_key VARCHAR(50) UNIQUE NOT NULL,
            meta_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $pdo->exec("INSERT IGNORE INTO branding_settings (meta_key, meta_value) VALUES 
            ('system_name', 'ระบบบริหารงานวิชาการ'),
            ('college_name', 'วิทยาลัยการอาชีพพนมไพร'),
            ('logo_path', ''),
            ('logo_text', 'PNP'),
            ('theme_color', 'dark-blue')
        ");
        
        // Auto-migrate: check if 'department' column exists in 'users' table, if not add it
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'department'")->fetchAll();
        if (empty($columns)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN department VARCHAR(255) NULL AFTER fullname");
        }
    } catch (Exception $e) {
        // Fail silently
    }
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ');
}

// Global helper for branding settings
function get_branding_settings(bool $force_reload = false): array {
    global $pdo;
    static $settings = null;
    if ($settings !== null && !$force_reload) {
        return $settings;
    }
    
    try {
        $rows = $pdo->query("SELECT meta_key, meta_value FROM branding_settings")->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['meta_key']] = $row['meta_value'];
        }
        
        // Fill defaults if missing
        $defaults = [
            'system_name' => 'ระบบบริหารงานวิชาการ',
            'college_name' => 'วิทยาลัยการอาชีพพนมไพร',
            'logo_path' => '',
            'logo_text' => 'PNP',
            'theme_color' => 'dark-blue'
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($settings[$k])) {
                $settings[$k] = $v;
            }
        }
    } catch (Exception $e) {
        $settings = [
            'system_name' => 'ระบบบริหารงานวิชาการ',
            'college_name' => 'วิทยาลัยการอาชีพพนมไพร',
            'logo_path' => '',
            'logo_text' => 'PNP',
            'theme_color' => 'dark-blue'
        ];
    }
    return $settings;
}

// Security escape helper
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

