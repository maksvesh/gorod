<?php
class SecureDatabase {
    private $pdo;
    
    public function __construct() {
        $credentials = SecurityConfig::getDbCredentials();
        $config = SecurityConfig::DB_CONFIG;
        
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        $this->pdo = new PDO($dsn, $credentials['user'], $credentials['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    
    public function secureQuery($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        
        $stmt->execute();
        return $stmt;
    }
    
    public function getPdo() {
        return $this->pdo;
    }
}

class XSSProtection {
    public static function sanitizeOutput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeOutput'], $data);
        }
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    public static function sanitizeInput($input) {
        return strip_tags(trim($input));
    }
    
    public static function jsonSafe($data) {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
}

class SecureSession {
    public static function start() {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', 1);
        
        session_start();
        
        if (empty($_SESSION['created'])) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        } elseif (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
    
    public static function validate() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (empty($_SESSION['fingerprint'])) {
            $_SESSION['fingerprint'] = hash('sha256', $ip . $userAgent);
        } elseif ($_SESSION['fingerprint'] !== hash('sha256', $ip . $userAgent)) {
            session_destroy();
            throw new Exception('Session validation failed');
        }
    }
}

class SecurityHeaders {
    public static function set() {
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

class RateLimiter {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function checkLimit($action, $identifier, $maxAttempts, $timeWindow) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$timeWindow]);
            
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as attempts FROM rate_limits WHERE action = ? AND identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$action, $identifier, $timeWindow]);
            $result = $stmt->fetch();
            
            if ($result['attempts'] >= $maxAttempts) {
                return false;
            }
            
            $stmt = $this->pdo->prepare("INSERT INTO rate_limits (action, identifier, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$action, $identifier]);
            
            return true;
        } catch (Exception $e) {
            return true;
        }
    }
}
?>