<?php
// chat/config.php - настройки базы данных и сессии

// Включаем буферизацию вывода ДО любого вывода
ob_start();

// НАСТРОЙКИ СЕССИИ ДОЛЖНЫ БЫТЬ ДО session_start()
if (!isset($_SESSION)) {
    // Настройки безопасности сессии
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.gc_maxlifetime', 86400);
    
    // Параметры cookies сессии
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Запускаем сессию
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Регенерация ID сессии для безопасности
if (!isset($_SESSION['created'])) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Настройки базы данных
$db_host = 'localhost';
$db_user = 'host1882872';
$db_pass = '6IP9PTP2TC';
$db_name = 'host1882872';

// Подключение к базе данных
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    // Очищаем буфер вывода перед отправкой JSON
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Ошибка подключения к базе данных']);
    exit;
}

// Функция для чистого JSON ответа
function sendJsonResponse($data) {
    // Очищаем все предыдущие выводы
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Функция для проверки AJAX запроса
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
?>