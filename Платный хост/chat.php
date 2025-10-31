<?php
ob_start();

// Более строгая обработка ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0); // Не показывать ошибки пользователям
ini_set('log_errors', 1);

session_start();

// Настройки базы данных
$db_host = 'localhost';
$db_user = 'host1882872';
$db_pass = '6IP9PTP2TC';
$db_name = 'host1882872';

// Функция для отправки JSON ответов
function sendJsonResponse($data) {
    // Очищаем все буферы вывода
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Подключение к базе данных
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Создаем таблицы если они не существуют
    createTablesIfNotExist($pdo);
    
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    sendJsonResponse(['status' => 'error', 'message' => 'Ошибка подключения к базе данных']);
}

// Функция создания таблиц если они не существуют
function createTablesIfNotExist($pdo) {
    $tables = [
        "agent_users" => "CREATE TABLE IF NOT EXISTS agent_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            role ENUM('user','admin') DEFAULT 'user',
            registration_ip VARCHAR(45),
            last_ip VARCHAR(45),
            last_activity DATETIME,
            is_banned BOOLEAN DEFAULT FALSE,
            ban_reason TEXT,
            banned_by VARCHAR(50),
            banned_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "agent_chat" => "CREATE TABLE IF NOT EXISTS agent_chat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            username VARCHAR(50) NOT NULL,
            message TEXT,
            user_ip VARCHAR(45),
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted BOOLEAN DEFAULT FALSE,
            FOREIGN KEY (user_id) REFERENCES agent_users(id) ON DELETE SET NULL
        )",
        
        "agent_banned_ips" => "CREATE TABLE IF NOT EXISTS agent_banned_ips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) UNIQUE NOT NULL,
            reason TEXT,
            banned_by VARCHAR(50),
            banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL
        )",
        
        "agent_muted_users" => "CREATE TABLE IF NOT EXISTS agent_muted_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNIQUE,
            muted_by VARCHAR(50),
            mute_reason TEXT,
            muted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            mute_expires DATETIME NULL,
            FOREIGN KEY (user_id) REFERENCES agent_users(id) ON DELETE CASCADE
        )",
        
        "agent_chat_settings" => "CREATE TABLE IF NOT EXISTS agent_chat_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    ];
    
    foreach ($tables as $tableName => $createSQL) {
        try {
            $pdo->exec($createSQL);
        } catch (PDOException $e) {
            error_log("Error creating table $tableName: " . $e->getMessage());
        }
    }
    
    // Инициализируем настройки чата
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO agent_chat_settings (setting_key, setting_value) VALUES ('chat_enabled', '1')");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error initializing chat settings: " . $e->getMessage());
    }
    
    // Создаем администратора по умолчанию если нет пользователей
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM agent_users");
        $result = $stmt->fetch();
        if ($result['count'] == 0) {
            $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO agent_users (username, password, role) VALUES (?, ?, 'admin')");
            $stmt->execute(['admin', $hashed_password]);
        }
    } catch (PDOException $e) {
        error_log("Error creating default admin: " . $e->getMessage());
    }
}

// Функция проверки авторизации
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Функция обновления активности
function updateUserActivity($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE agent_users SET last_activity = NOW() WHERE id = ?");
    $stmt->execute([$user_id]);
}

// Обновляем активность пользователя если авторизован
if (isLoggedIn()) {
    updateUserActivity($pdo, $_SESSION['user_id']);
}

// Функции
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

function isIPBanned($pdo, $ip) {
    $stmt = $pdo->prepare("SELECT * FROM agent_banned_ips WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())");
    $stmt->execute([$ip]);
    return $stmt->fetch();
}

function isUsernameTaken($pdo, $username) {
    $stmt = $pdo->prepare("SELECT id FROM agent_users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

function isUserBanned($pdo, $username) {
    $stmt = $pdo->prepare("SELECT is_banned, ban_reason, banned_by, banned_at FROM agent_users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

function isUserMuted($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM agent_muted_users WHERE user_id = ? AND (mute_expires IS NULL OR mute_expires > NOW())");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function getMuteTimeLeft($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), mute_expires) as seconds_left FROM agent_muted_users WHERE user_id = ? AND mute_expires > NOW()");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? $result['seconds_left'] : 0;
}

function isChatEnabled($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM agent_chat_settings WHERE setting_key = 'chat_enabled'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result && $result['setting_value'] == '1';
}

function setChatEnabled($pdo, $enabled) {
    $value = $enabled ? '1' : '0';
    $stmt = $pdo->prepare("UPDATE agent_chat_settings SET setting_value = ? WHERE setting_key = 'chat_enabled'");
    return $stmt->execute([$value]);
}

// Функция получения списка пользователей
function getUsersList($pdo) {
    $stmt = $pdo->prepare("
        SELECT username, role, last_activity, is_banned, registration_ip, last_ip,
               CASE 
                   WHEN last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'online'
                   ELSE 'offline'
               END as status
        FROM agent_users 
        ORDER BY 
            CASE 
                WHEN last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 
                ELSE 2 
            END,
            username
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функция обновления роли в сессии
function updateUserRoleInSession($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT role FROM agent_users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user && isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
        $_SESSION['role'] = $user['role'];
        return true;
    }
    return false;
}

// Функции для разбана
function unbanUser($pdo, $username) {
    $stmt = $pdo->prepare("UPDATE agent_users SET is_banned = FALSE, ban_reason = NULL, banned_by = NULL, banned_at = NULL WHERE username = ?");
    return $stmt->execute([$username]);
}

function unbanIP($pdo, $ip_address) {
    $stmt = $pdo->prepare("DELETE FROM agent_banned_ips WHERE ip_address = ?");
    return $stmt->execute([$ip_address]);
}

function getBannedUsers($pdo) {
    $stmt = $pdo->prepare("
        SELECT username, registration_ip, last_ip, last_activity, ban_reason, banned_by, banned_at 
        FROM agent_users 
        WHERE is_banned = TRUE 
        ORDER BY banned_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBannedIPs($pdo) {
    $stmt = $pdo->prepare("
        SELECT ip_address, reason, banned_by, banned_at, expires_at 
        FROM agent_banned_ips 
        WHERE expires_at IS NULL OR expires_at > NOW()
        ORDER BY banned_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Проверка бана по IP
$client_ip = getClientIP();
if ($banned_ip = isIPBanned($pdo, $client_ip)) {
    die("
        <div style='
            background: #0a0a12; 
            color: #ff0000; 
            font-family: Courier New; 
            padding: 50px; 
            text-align: center;
            border: 2px solid #ff0000;
            margin: 100px;
        '>
            <h1>🚫 ДОСТУП ЗАБЛОКИРОВАН</h1>
            <p>Ваш IP адрес был заблокирован системой безопасности.</p>
            <p>Причина: " . htmlspecialchars($banned_ip['reason']) . "</p>
            <p>Время блокировки: " . $banned_ip['banned_at'] . "</p>
        </div>
    ");
}

// Обработка AJAX запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Очищаем буфер вывода перед обработкой AJAX
    ob_clean();
    
    try {
        // Проверка свободного логина
        if ($_POST['action'] === 'check_username') {
            $username = trim($_POST['username']);
            
            if (empty($username)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Введите имя пользователя']);
            }
            
            if (strlen($username) < 3 || strlen($username) > 20) {
                sendJsonResponse(['status' => 'error', 'message' => 'Имя пользователя должно быть от 3 до 20 символов']);
            }
            
            if (isUsernameTaken($pdo, $username)) {
                sendJsonResponse(['status' => 'taken', 'message' => 'Это имя пользователя уже занято']);
            } else {
                sendJsonResponse(['status' => 'available', 'message' => 'Имя пользователя свободно']);
            }
        }
        
        // Регистрация
        if ($_POST['action'] === 'register') {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $email = trim($_POST['email'] ?? '');
            
            if (empty($username) || empty($password)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Заполните все поля']);
            }
            
            if (strlen($username) < 3 || strlen($username) > 20) {
                sendJsonResponse(['status' => 'error', 'message' => 'Имя пользователя должно быть от 3 до 20 символов']);
            }
            
            if (strlen($password) < 6) {
                sendJsonResponse(['status' => 'error', 'message' => 'Пароль должен быть не менее 6 символов']);
            }
            
            if (isUsernameTaken($pdo, $username)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Это имя пользователя уже занято']);
            }
            
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $registration_ip = getClientIP();
            
            $stmt = $pdo->prepare("INSERT INTO agent_users (username, password, email, registration_ip, last_ip, last_activity, role) VALUES (?, ?, ?, ?, ?, NOW(), 'user')");
            $stmt->execute([$username, $hashed_password, $email, $registration_ip, $registration_ip]);
            
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['role'] = 'user';
            
            sendJsonResponse(['status' => 'success', 'message' => 'Регистрация успешна']);
        }
        
        // Авторизация
        if ($_POST['action'] === 'login') {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            
            if (empty($username) || empty($password)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Заполните все поля']);
            }
            
            // Проверяем бан пользователя
            if ($ban_info = isUserBanned($pdo, $username)) {
                if ($ban_info['is_banned']) {
                    $ban_message = "Ваш аккаунт заблокирован";
                    if ($ban_info['ban_reason']) {
                        $ban_message .= ". Причина: " . $ban_info['ban_reason'];
                    }
                    if ($ban_info['banned_by']) {
                        $ban_message .= ". Заблокировал: " . $ban_info['banned_by'];
                    }
                    sendJsonResponse(['status' => 'error', 'message' => $ban_message]);
                }
            }
            
            $stmt = $pdo->prepare("SELECT * FROM agent_users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Обновляем последний IP
                $current_ip = getClientIP();
                $stmt = $pdo->prepare("UPDATE agent_users SET last_ip = ?, last_activity = NOW() WHERE id = ?");
                $stmt->execute([$current_ip, $user['id']]);
                
                // Устанавливаем сессию
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                // Устанавливаем длительность сессии
                if ($remember_me) {
                    ini_set('session.gc_maxlifetime', 2592000);
                    session_set_cookie_params(2592000);
                } else {
                    ini_set('session.gc_maxlifetime', 86400);
                    session_set_cookie_params(86400);
                }
                
                // Обновляем активность
                updateUserActivity($pdo, $user['id']);
                
                sendJsonResponse([
                    'status' => 'success', 
                    'message' => 'Вход выполнен',
                    'username' => $user['username'],
                    'role' => $user['role']
                ]);
            } else {
                sendJsonResponse(['status' => 'error', 'message' => 'Неверное имя пользователя или пароль']);
            }
        }
        
        // Выход
        if ($_POST['action'] === 'logout') {
            session_destroy();
            sendJsonResponse(['status' => 'success', 'message' => 'Выход выполнен']);
        }
        
        // Смена пароля
        if ($_POST['action'] === 'change_password') {
            if (!isset($_SESSION['user_id'])) {
                sendJsonResponse(['status' => 'error', 'message' => 'Необходима авторизация']);
            }
            
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            
            if (strlen($new_password) < 6) {
                sendJsonResponse(['status' => 'error', 'message' => 'Новый пароль должен быть не менее 6 символов']);
            }
            
            $stmt = $pdo->prepare("SELECT password FROM agent_users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($current_password, $user['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE agent_users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                
                sendJsonResponse(['status' => 'success', 'message' => 'Пароль успешно изменен']);
            } else {
                sendJsonResponse(['status' => 'error', 'message' => 'Неверный текущий пароль']);
            }
        }
        
        // Отправка сообщения
        if ($_POST['action'] === 'send_message' && isset($_SESSION['user_id'])) {
            // Проверяем бан пользователя
            if ($ban_info = isUserBanned($pdo, $_SESSION['username'])) {
                if ($ban_info['is_banned']) {
                    sendJsonResponse(['status' => 'error', 'message' => 'Ваш аккаунт заблокирован. Вы не можете отправлять сообщения.']);
                }
            }
            
            if (!isChatEnabled($pdo)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Чат временно отключен администратором']);
            }
            
            // Проверка мута
            if ($mute_info = isUserMuted($pdo, $_SESSION['user_id'])) {
                $time_left = getMuteTimeLeft($pdo, $_SESSION['user_id']);
                if ($time_left > 0) {
                    $minutes = floor($time_left / 60);
                    $seconds = $time_left % 60;
                    sendJsonResponse(['status' => 'error', 'message' => 'Вы в муте. Осталось: ' . $minutes . 'м ' . $seconds . 'с']);
                }
            }
            
            $message = trim($_POST['message'] ?? '');
            $image_data = $_POST['image_data'] ?? '';
            
            // Разрешаем отправку либо текста, либо изображения, либо обоих
            if (empty($message) && empty($image_data)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Сообщение не может быть полностью пустым']);
            }
            
            // Обработка текстового сообщения
            if (!empty($message)) {
                $message = htmlspecialchars($message);
            }
            
            // Обработка изображения
            if (!empty($image_data) && strpos($image_data, 'data:image') === 0) {
                // Сохраняем изображение как есть (base64)
                if (empty($message)) {
                    $message = $image_data;
                } else {
                    // Если есть и текст и изображение, объединяем их
                    $message = $message . '|||IMAGE|||' . $image_data;
                }
            }
            
            $stmt = $pdo->prepare("INSERT INTO agent_chat (user_id, username, message, user_ip) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['username'], $message, getClientIP()]);
            
            // Обновляем активность после отправки сообщения
            updateUserActivity($pdo, $_SESSION['user_id']);
            
            sendJsonResponse(['status' => 'success']);
        }
        
        // Получение сообщений
        if ($_POST['action'] === 'get_messages') {
            // Проверяем бан пользователя
            if (isset($_SESSION['username'])) {
                if ($ban_info = isUserBanned($pdo, $_SESSION['username'])) {
                    if ($ban_info['is_banned']) {
                        // Забаненные пользователи видят только системное сообщение о бане
                        sendJsonResponse([[
                            'id' => 0,
                            'username' => 'Система',
                            'message' => '🚫 Ваш аккаунт заблокирован. Доступ к чату ограничен.' . 
                                        ($ban_info['ban_reason'] ? ' Причина: ' . $ban_info['ban_reason'] : '') . 
                                        ($ban_info['banned_by'] ? ' Заблокировал: ' . $ban_info['banned_by'] : ''),
                            'timestamp' => date('H:i:s'),
                            'is_deleted' => false,
                            'role' => 'system'
                        ]]);
                    }
                }
            }
            
            $stmt = $pdo->prepare("
                SELECT c.id, c.username, c.message, DATE_FORMAT(c.timestamp, '%H:%i:%s') as timestamp, 
                       c.is_deleted, u.role, c.user_ip
                FROM agent_chat c 
                LEFT JOIN agent_users u ON c.username = u.username 
                WHERE c.is_deleted = FALSE 
                ORDER BY c.timestamp DESC 
                LIMIT 50
            ");
            $stmt->execute();
            $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
            sendJsonResponse($messages);
        }
        
        // Получение списка пользователей
        if ($_POST['action'] === 'get_users') {
            $users = getUsersList($pdo);
            sendJsonResponse($users);
        }
        
        // Удаление сообщения (только для админов)
        if ($_POST['action'] === 'delete_message' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $message_id = intval($_POST['message_id']);
            $stmt = $pdo->prepare("UPDATE agent_chat SET is_deleted = TRUE WHERE id = ?");
            $stmt->execute([$message_id]);
            sendJsonResponse(['status' => 'success']);
        }
        
        // Бан пользователя (только для админов)
        if ($_POST['action'] === 'ban_user' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $username = trim($_POST['username']);
            $reason = htmlspecialchars(trim($_POST['reason']));
            
            $stmt = $pdo->prepare("UPDATE agent_users SET is_banned = TRUE, ban_reason = ?, banned_by = ?, banned_at = NOW() WHERE username = ?");
            $stmt->execute([$reason, $_SESSION['username'], $username]);
            
            sendJsonResponse(['status' => 'success', 'message' => 'Пользователь заблокирован']);
        }
        
        // Разбан пользователя (только для админов)
        if ($_POST['action'] === 'unban_user' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $username = trim($_POST['username']);
            
            if (unbanUser($pdo, $username)) {
                sendJsonResponse(['status' => 'success', 'message' => 'Пользователь разблокирован']);
            } else {
                sendJsonResponse(['status' => 'error', 'message' => 'Ошибка разблокировки пользователя']);
            }
        }
        
        // Мут пользователя (только для админов)
        if ($_POST['action'] === 'mute_user' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $username = trim($_POST['username']);
            $duration = intval($_POST['duration']);
            $reason = htmlspecialchars(trim($_POST['reason']));
            
            // Получаем ID пользователя
            $stmt = $pdo->prepare("SELECT id FROM agent_users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                sendJsonResponse(['status' => 'error', 'message' => 'Пользователь не найден']);
            }
            
            $mute_expires = $duration > 0 ? date('Y-m-d H:i:s', strtotime("+$duration minutes")) : null;
            
            // Удаляем старый мут если есть
            $stmt = $pdo->prepare("DELETE FROM agent_muted_users WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            
            // Добавляем новый мут
            $stmt = $pdo->prepare("INSERT INTO agent_muted_users (user_id, muted_by, mute_reason, mute_expires) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user['id'], $_SESSION['username'], $reason, $mute_expires]);
            
            sendJsonResponse(['status' => 'success', 'message' => 'Пользователь замьючен']);
        }
        
        // Размут пользователя (только для админов)
        if ($_POST['action'] === 'unmute_user' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $username = trim($_POST['username']);
            
            // Получаем ID пользователя
            $stmt = $pdo->prepare("SELECT id FROM agent_users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                sendJsonResponse(['status' => 'error', 'message' => 'Пользователь не найден']);
            }
            
            $stmt = $pdo->prepare("DELETE FROM agent_muted_users WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            
            sendJsonResponse(['status' => 'success', 'message' => 'Пользователь размучен']);
        }
        
        // Получение информации о муте
        if ($_POST['action'] === 'get_mute_info' && isset($_SESSION['user_id'])) {
            if ($mute_info = isUserMuted($pdo, $_SESSION['user_id'])) {
                $time_left = getMuteTimeLeft($pdo, $_SESSION['user_id']);
                sendJsonResponse([
                    'muted' => true,
                    'reason' => $mute_info['mute_reason'],
                    'time_left' => $time_left,
                    'muted_by' => $mute_info['muted_by'],
                    'muted_at' => $mute_info['muted_at']
                ]);
            } else {
                sendJsonResponse(['muted' => false]);
            }
        }
        
        // Бан по IP (только для админов)
        if ($_POST['action'] === 'ban_ip' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $ip_address = trim($_POST['ip_address']);
            $reason = htmlspecialchars(trim($_POST['reason']));
            
            $stmt = $pdo->prepare("INSERT INTO agent_banned_ips (ip_address, reason, banned_by) VALUES (?, ?, ?)");
            $stmt->execute([$ip_address, $reason, $_SESSION['username']]);
            
            sendJsonResponse(['status' => 'success', 'message' => 'IP адрес заблокирован']);
        }
        
        // Разбан IP адреса (только для админов)
        if ($_POST['action'] === 'unban_ip' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $ip_address = trim($_POST['ip_address']);
            
            if (unbanIP($pdo, $ip_address)) {
                sendJsonResponse(['status' => 'success', 'message' => 'IP адрес разблокирован']);
            } else {
                sendJsonResponse(['status' => 'error', 'message' => 'Ошибка разблокировки IP адреса']);
            }
        }
        
        // Получение списка забаненных пользователей (только для админов)
        if ($_POST['action'] === 'get_banned_users' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $banned_users = getBannedUsers($pdo);
            sendJsonResponse($banned_users);
        }
        
        // Получение списка забаненных IP (только для админов)
        if ($_POST['action'] === 'get_banned_ips' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $banned_ips = getBannedIPs($pdo);
            sendJsonResponse($banned_ips);
        }
        
        // Получение информации о пользователе для админов
        if ($_POST['action'] === 'get_user_info' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $username = trim($_POST['username']);
            
            $stmt = $pdo->prepare("
                SELECT u.username, u.role, u.registration_ip, u.last_ip, u.last_activity, 
                       u.is_banned, u.ban_reason, u.banned_by, u.banned_at,
                       m.mute_reason, m.muted_by, m.mute_expires
                FROM agent_users u 
                LEFT JOIN agent_muted_users m ON u.id = m.user_id AND (m.mute_expires IS NULL OR m.mute_expires > NOW())
                WHERE u.username = ?
            ");
            $stmt->execute([$username]);
            $user_info = $stmt->fetch();
            
            if ($user_info) {
                sendJsonResponse(['status' => 'success', 'user_info' => $user_info]);
            } else {
                sendJsonResponse(['status' => 'error', 'message' => 'Пользователь не найден']);
            }
        }
        
        // Включение/отключение чата (только для админов)
        if ($_POST['action'] === 'toggle_chat' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            $enabled = $_POST['enabled'] === 'true' || $_POST['enabled'] === true;
            
            if (setChatEnabled($pdo, $enabled)) {
                $status = $enabled ? 'включен' : 'отключен';
                sendJsonResponse([
                    'status' => 'success', 
                    'message' => 'Чат успешно ' . $status,
                    'enabled' => $enabled
                ]);
            } else {
                sendJsonResponse(['status' => 'error', 'message' => 'Ошибка изменения статуса чата']);
            }
        }
        
        // Получение статуса чата
        if ($_POST['action'] === 'get_chat_status') {
            sendJsonResponse(['enabled' => isChatEnabled($pdo)]);
        }
        
        // Проверка авторизации
        if ($_POST['action'] === 'check_auth') {
            if (isset($_SESSION['user_id'])) {
                // Проверяем бан пользователя
                $ban_info = isUserBanned($pdo, $_SESSION['username']);
                $is_banned = $ban_info && $ban_info['is_banned'];
                
                // Проверяем мут
                $mute_info = isUserMuted($pdo, $_SESSION['user_id']);
                $mute_time_left = $mute_info ? getMuteTimeLeft($pdo, $_SESSION['user_id']) : 0;
                
                sendJsonResponse([
                    'authenticated' => true,
                    'username' => $_SESSION['username'],
                    'role' => $_SESSION['role'],
                    'banned' => $is_banned,
                    'ban_info' => $ban_info,
                    'muted' => $mute_info && $mute_time_left > 0,
                    'mute_time_left' => $mute_time_left
                ]);
            } else {
                sendJsonResponse(['authenticated' => false]);
            }
        }
        
        // Обновление активности
        if ($_POST['action'] === 'update_activity' && isset($_SESSION['user_id'])) {
            updateUserActivity($pdo, $_SESSION['user_id']);
            sendJsonResponse(['status' => 'success']);
        }

        // Проверка и обновление роли пользователя
        if ($_POST['action'] === 'check_and_update_role') {
            if (isset($_SESSION['user_id'])) {
                if (updateUserRoleInSession($pdo, $_SESSION['user_id'])) {
                    sendJsonResponse([
                        'status' => 'success', 
                        'role' => $_SESSION['role'],
                        'username' => $_SESSION['username']
                    ]);
                } else {
                    sendJsonResponse(['status' => 'error', 'message' => 'Ошибка обновления роли']);
                }
            } else {
                sendJsonResponse(['status' => 'error', 'message' => 'Пользователь не авторизован']);
            }
        }
        
        // Если действие не распознано
        sendJsonResponse(['status' => 'error', 'message' => 'Неизвестное действие']);
        
    } catch (Exception $e) {
        error_log("Error in AJAX handler: " . $e->getMessage());
        sendJsonResponse(['status' => 'error', 'message' => 'Внутренняя ошибка сервера: ' . $e->getMessage()]);
    }
}

// Получаем текущий статус чата
$chat_enabled = isChatEnabled($pdo);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Темный город | Оперативный чат</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Courier New', monospace;
        }

        body {
            background: #0a0a12;
            color: #c0c0c0;
            overflow-x: hidden;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 15px;
        }

        /* Улучшенная адаптация для мобильных устройств */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .title {
                font-size: 2rem !important;
                text-align: center;
            }
            
            .subtitle {
                font-size: 1rem !important;
                text-align: center;
            }
            
            .navigation-buttons {
                flex-direction: column;
                gap: 15px !important;
                margin: 20px 0 !important;
            }
            
            .nav-btn {
                width: 100%;
                justify-content: center;
                padding: 12px 20px !important;
                font-size: 0.9rem !important;
            }
            
            .chat-wrapper {
                grid-template-columns: 1fr !important;
                gap: 15px !important;
                margin: 20px 0 !important;
            }
            
            .chat-container, .users-panel {
                min-height: 400px !important;
                max-height: 500px !important;
            }
            
            .chat-messages {
                max-height: 350px !important;
                min-height: 250px !important;
            }
            
            .message {
                max-width: 95% !important;
            }
            
            .user-panel {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .admin-panel {
                padding: 15px !important;
            }
            
            .input-group {
                flex-direction: column;
                gap: 10px !important;
            }
            
            .form-input {
                width: 100% !important;
            }
            
            /* Улучшенное отображение кнопок на мобильных */
            .form-buttons {
                flex-direction: column;
                gap: 10px !important;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .users-controls {
                flex-direction: column;
                gap: 10px;
            }
            
            .users-toggle {
                justify-content: center;
            }
            
            .admin-controls-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
        }

        @media (max-width: 480px) {
            .title {
                font-size: 1.8rem !important;
            }
            
            .chat-header h2, .users-header h3 {
                font-size: 1rem !important;
            }
            
            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .username {
                max-width: 120px !important;
            }
            
            .input-area {
                flex-wrap: wrap;
            }
            
            .message-textarea {
                font-size: 16px !important;
            }
            
            .admin-section {
                padding: 10px !important;
            }
            
            .admin-control-btn {
                padding: 8px 12px !important;
                font-size: 0.8rem !important;
            }
        }

        /* Стили для textarea */
        .message-textarea {
            flex: 1;
            padding: 12px 15px;
            background: rgba(10, 30, 50, 0.8);
            border: 1px solid #004466;
            border-radius: 3px;
            color: #c0c0c0;
            font-size: 1rem;
            resize: vertical;
            min-height: 45px;
            max-height: 120px;
            font-family: 'Courier New', monospace;
            line-height: 1.4;
            box-sizing: border-box;
        }

        .message-textarea:focus {
            outline: none;
            border-color: #00aaff;
            box-shadow: 0 0 5px rgba(0, 170, 255, 0.5);
            background: rgba(20, 40, 60, 0.9);
        }

        .message-textarea:disabled {
            background: rgba(30, 30, 30, 0.8);
            color: #666;
            cursor: not-allowed;
        }

        /* Контейнер для кнопки отправки на мобильных */
        .mobile-send-container {
            display: none;
            width: 100%;
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            .mobile-send-container {
                display: block;
            }
            
            .desktop-send-btn {
                display: none;
            }
            
            .input-area {
                align-items: stretch;
            }
            
            .message-textarea {
                min-height: 60px;
            }
        }

        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: 
                radial-gradient(circle at 20% 30%, rgba(30, 30, 60, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(40, 40, 80, 0.3) 0%, transparent 50%),
                linear-gradient(135deg, #0a0a12 0%, #1a1a2e 100%);
            animation: pulse 15s infinite alternate;
        }

        @keyframes pulse {
            0% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        .scan-lines {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to bottom, 
                transparent 50%, 
                rgba(0, 255, 255, 0.03) 50%);
            background-size: 100% 4px;
            z-index: -1;
            pointer-events: none;
            animation: scan 2s linear infinite;
        }

        @keyframes scan {
            0% { transform: translateY(0); }
            100% { transform: translateY(4px); }
        }

        .header {
            text-align: center;
            padding: 30px 0;
            position: relative;
        }

        .title {
            font-size: 3rem;
            color: #00ffff;
            text-shadow: 0 0 10px #00ffff, 0 0 20px #00ffff;
            margin-bottom: 15px;
            letter-spacing: 2px;
            text-transform: uppercase;
            animation: flicker 3s infinite alternate;
        }

        @keyframes flicker {
            0%, 19%, 21%, 23%, 25%, 54%, 56%, 100% {
                opacity: 1;
                text-shadow: 0 0 10px #00ffff, 0 0 20px #00ffff;
            }
            20%, 24%, 55% {
                opacity: 0.8;
                text-shadow: 0 0 5px #00ffff, 0 0 10px #00ffff;
            }
        }

        .subtitle {
            font-size: 1.1rem;
            color: #80ffff;
            margin-bottom: 25px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
        }

        .greeting {
            background: rgba(0, 20, 40, 0.7);
            border-left: 3px solid #00ffff;
            padding: 15px;
            margin: 20px 0;
            box-shadow: 0 0 15px rgba(0, 255, 255, 0.2);
            transition: all 0.3s;
        }

        .greeting:hover {
            box-shadow: 0 0 25px rgba(0, 255, 255, 0.4);
        }

        .navigation-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: linear-gradient(45deg, #004466, #0088aa);
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
            border: 1px solid #00ffff;
            box-shadow: 0 0 15px rgba(0, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .nav-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 200, 255, 0.4);
            background: linear-gradient(45deg, #006688, #00aacc);
            border-color: #00ff88;
        }

        .nav-btn:hover::before {
            left: 100%;
        }

        .btn-icon {
            font-size: 1.3rem;
            filter: drop-shadow(0 0 5px rgba(255, 255, 255, 0.5));
        }

        .auth-form {
            background: rgba(10, 20, 40, 0.9);
            border: 1px solid #004466;
            border-radius: 5px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            border-left: 3px solid #00aaff;
        }

        .auth-form h3 {
            color: #00aaff;
            margin-bottom: 15px;
            text-align: center;
            font-size: 1.2rem;
            text-shadow: 0 0 10px rgba(0, 170, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #80ffff;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.85rem;
        }

        .form-group input {
            width: 100%;
            padding: 10px 12px;
            background: rgba(10, 30, 50, 0.8);
            border: 1px solid #004466;
            border-radius: 3px;
            color: #c0c0c0;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #00aaff;
            box-shadow: 0 0 10px rgba(0, 170, 255, 0.5);
            background: rgba(20, 40, 60, 0.9);
        }

        .form-buttons {
            display: flex;
            gap: 12px;
            justify-content: space-between;
            margin-top: 20px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.85rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(45deg, #004466, #0088aa);
            color: white;
            border: 1px solid #00ffff;
            box-shadow: 0 0 10px rgba(0, 255, 255, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(45deg, #006688, #00aacc);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 200, 255, 0.4);
        }

        .btn-danger {
            background: linear-gradient(45deg, #660000, #aa0000);
            color: white;
            border: 1px solid #ff0000;
            box-shadow: 0 0 10px rgba(255, 0, 0, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(45deg, #880000, #cc0000);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 0, 0, 0.4);
        }

        .btn-success {
            background: linear-gradient(45deg, #006600, #00aa00);
            color: white;
            border: 1px solid #00ff00;
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(45deg, #008800, #00cc00);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 255, 0, 0.4);
        }

        .btn-warning {
            background: linear-gradient(45deg, #664400, #aa7700);
            color: white;
            border: 1px solid #ffaa00;
            box-shadow: 0 0 10px rgba(255, 170, 0, 0.3);
        }

        .btn-warning:hover {
            background: linear-gradient(45deg, #886600, #cc9900);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 170, 0, 0.4);
        }

        .btn-info {
            background: linear-gradient(45deg, #004466, #006688);
            color: white;
            border: 1px solid #0088ff;
            box-shadow: 0 0 10px rgba(0, 136, 255, 0.3);
        }

        .btn-info:hover {
            background: linear-gradient(45deg, #006688, #0088aa);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 136, 255, 0.4);
        }

        .user-panel {
            background: rgba(0, 30, 60, 0.9);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #004466;
            border-left: 3px solid #00ffff;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
        }

        .user-info {
            color: #00ffff;
            font-size: 1rem;
            font-weight: bold;
        }

        .admin-badge {
            background: linear-gradient(45deg, #ff8000, #ffaa00);
            color: black;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-left: 8px;
            text-transform: uppercase;
            font-weight: bold;
            box-shadow: 0 0 10px rgba(255, 128, 0, 0.5);
        }

        .message-actions {
            margin-top: 6px;
            display: flex;
            gap: 6px;
        }

        .btn-small {
            padding: 3px 8px;
            font-size: 0.65rem;
        }

        .admin-panel {
            background: rgba(30, 15, 0, 0.9);
            border: 1px solid #ff8000;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
            border-left: 3px solid #ff8000;
            box-shadow: 0 5px 15px rgba(255, 128, 0, 0.3);
        }

        .admin-panel h3 {
            color: #ff8000;
            margin-bottom: 15px;
            text-align: center;
            font-size: 1.1rem;
            text-shadow: 0 0 10px rgba(255, 128, 0, 0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .admin-section {
            margin-bottom: 20px;
            padding: 12px;
            background: rgba(40, 20, 0, 0.6);
            border-radius: 3px;
            border: 1px solid #ffaa00;
        }

        .admin-section h4 {
            color: #ffaa00;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }

        .chat-status {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            animation: pulse-status 2s infinite;
        }

        input#remember-me {
            width: auto;
            margin-right: 6px;
        }

        .status-online {
            background-color: #00ff00;
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
        }

        .status-offline {
            background-color: #ff0000;
            box-shadow: 0 0 10px rgba(255, 0, 0, 0.5);
        }

        @keyframes pulse-status {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .chat-toggle-buttons {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        .chat-status-info {
            margin-top: 8px;
            font-size: 0.75rem;
            color: #808080;
        }

        .input-group {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .form-input {
            padding: 6px 10px;
            background: rgba(10, 30, 50, 0.8);
            border: 1px solid #004466;
            border-radius: 3px;
            color: #c0c0c0;
            min-width: 120px;
        }

        /* Основной контейнер чата и пользователей */
        .chat-wrapper {
            display: grid;
            grid-template-columns: 1fr 280px;
            gap: 15px;
            margin: 25px 0;
        }

        .chat-container {
            background: rgba(10, 20, 40, 0.8);
            border: 1px solid #004466;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            border-left: 3px solid #00aaff;
            min-height: 450px;
            max-height: 550px;
            display: flex;
            flex-direction: column;
        }

        .chat-container:hover {
            box-shadow: 0 10px 25px rgba(0, 100, 255, 0.4);
            border-color: #00aaff;
        }

        .chat-header {
            background: rgba(0, 30, 60, 0.9);
            padding: 12px 15px;
            border-bottom: 1px solid #004466;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .chat-header h2 {
            color: #00aaff;
            font-size: 1.1rem;
            text-shadow: 0 0 5px rgba(0, 170, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .connection-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: rgba(5, 10, 20, 0.6);
            min-height: 250px;
            max-height: 350px;
            contain: layout style paint;
            scroll-behavior: smooth;
        }

        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: rgba(0, 20, 40, 0.5);
            border-radius: 3px;
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: rgba(0, 170, 255, 0.5);
            border-radius: 3px;
        }

        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 170, 255, 0.7);
        }

        .message {
            padding: 10px 12px;
            border-radius: 5px;
            max-width: 80%;
            position: relative;
            border-left: 3px solid;
            min-height: auto;
        }

        .message.own {
            align-self: flex-end;
            background: rgba(0, 50, 100, 0.7);
            border-left-color: #00aaff;
            margin-left: auto;
        }

        .message.other {
            align-self: flex-start;
            background: rgba(30, 30, 60, 0.7);
            border-left-color: #8040ff;
            margin-right: auto;
        }

        .message.system {
            align-self: center;
            background: rgba(60, 30, 0, 0.7);
            border-left-color: #ff8000;
            max-width: 90%;
            text-align: center;
            margin: 0 auto;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 0.75rem;
            min-height: 18px;
            align-items: center;
        }

        .username {
            font-weight: bold;
            color: #00ffff;
            text-shadow: 0 0 5px rgba(0, 255, 255, 0.5);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 130px;
        }

        .timestamp {
            color: #808080;
            font-size: 0.65rem;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .message-content {
            word-wrap: break-word;
            line-height: 1.3;
            min-height: 18px;
        }

        .message-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 5px;
            margin-top: 5px;
            cursor: pointer;
            transition: transform 0.2s;
            object-fit: contain;
        }

        .message-image.fullscreen {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 90vw;
            max-height: 90vh;
            z-index: 10000;
            background: rgba(0, 0, 0, 0.9);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 0 50px rgba(0, 255, 255, 0.5);
        }

        .image-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 9999;
            display: none;
        }

        .system-message {
            text-align: center;
            color: #ff8000;
            font-style: italic;
            padding: 4px;
        }

        .chat-input-container {
            padding: 12px;
            background: rgba(0, 20, 40, 0.7);
            border-top: 1px solid #004466;
            flex-shrink: 0;
        }

        .input-area {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .image-preview {
            max-width: 180px;
            max-height: 180px;
            border-radius: 5px;
            margin-bottom: 8px;
            display: none;
            object-fit: contain;
            cursor: pointer;
        }

        .image-preview-container {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .remove-image-btn {
            background: rgba(255, 0, 0, 0.7);
            color: white;
            border: none;
            border-radius: 3px;
            padding: 4px 8px;
            cursor: pointer;
            margin-top: 4px;
            font-size: 0.75rem;
        }

        .remove-image-btn:hover {
            background: rgba(255, 0, 0, 0.9);
        }

        .chat-disabled {
            opacity: 0.7;
            position: relative;
        }

        .chat-disabled::after {
            content: 'ЧАТ ОТКЛЮЧЕН АДМИНИСТРАТОРОМ';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 0, 0, 0.9);
            color: white;
            padding: 15px 30px;
            border-radius: 5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            z-index: 10;
            border: 2px solid #ff0000;
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.5);
        }

        /* Панель пользователей */
        .users-panel {
            background: rgba(10, 20, 40, 0.8);
            border: 1px solid #004466;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            border-left: 3px solid #8040ff;
            min-height: 450px;
            max-height: 550px;
            display: flex;
            flex-direction: column;
        }

        .users-header {
            background: rgba(30, 20, 60, 0.9);
            padding: 12px 15px;
            border-bottom: 1px solid #004466;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .users-header h3 {
            color: #8040ff;
            font-size: 1rem;
            text-shadow: 0 0 5px rgba(128, 64, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .users-count {
            background: rgba(128, 64, 255, 0.3);
            color: #c0a0ff;
            padding: 3px 6px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: rgba(5, 10, 20, 0.6);
        }

        .users-list::-webkit-scrollbar {
            width: 4px;
        }

        .users-list::-webkit-scrollbar-track {
            background: rgba(0, 20, 40, 0.5);
            border-radius: 2px;
        }

        .users-list::-webkit-scrollbar-thumb {
            background: rgba(128, 64, 255, 0.5);
            border-radius: 2px;
        }

        .users-list::-webkit-scrollbar-thumb:hover {
            background: rgba(128, 64, 255, 0.7);
        }

        .user-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            background: rgba(20, 20, 40, 0.6);
            border-radius: 5px;
            border-left: 3px solid;
            transition: all 0.2s;
        }

        .user-item.online {
            border-left-color: #00ff00;
            background: rgba(0, 40, 0, 0.4);
        }

        .user-item.offline {
            border-left-color: #666;
            opacity: 0.7;
        }

        .user-status {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .user-status.online {
            background-color: #00ff00;
            box-shadow: 0 0 5px rgba(0, 255, 0, 0.5);
            animation: pulse-status 2s infinite;
        }

        .user-status.offline {
            background-color: #666;
        }

        .user-name {
            flex: 1;
            font-weight: bold;
            color: #c0c0c0;
            font-size: 0.85rem;
        }

        .user-role {
            background: rgba(255, 128, 0, 0.3);
            color: #ffaa00;
            padding: 2px 5px;
            border-radius: 6px;
            font-size: 0.65rem;
            text-transform: uppercase;
        }

        .users-controls {
            padding: 8px 12px;
            background: rgba(0, 20, 40, 0.7);
            border-top: 1px solid #004466;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .users-toggle {
            display: flex;
            gap: 4px;
        }

        .toggle-btn {
            padding: 4px 8px;
            font-size: 0.75rem;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .toggle-btn.active {
            background: #00aaff;
            color: white;
        }

        .toggle-btn:not(.active) {
            background: rgba(100, 100, 100, 0.5);
            color: #ccc;
        }

        .agent-effect {
            position: fixed;
            bottom: 15px;
            right: 15px;
            width: 80px;
            height: 80px;
            background: radial-gradient(circle, rgba(0, 255, 255, 0.3) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulse-glow 3s infinite alternate;
            pointer-events: none;
            z-index: -1;
        }

        @keyframes pulse-glow {
            0% { transform: scale(1); opacity: 0.5; }
            100% { transform: scale(1.2); opacity: 0.8; }
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .notification {
            position: fixed;
            top: 15px;
            right: 15px;
            background: rgba(0, 100, 0, 0.9);
            color: white;
            padding: 12px 15px;
            border-radius: 5px;
            border: 1px solid #00ff00;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            z-index: 10000;
            font-family: 'Courier New', monospace;
            max-width: 280px;
            animation: slideIn 0.3s ease-out;
        }

        .notification-error {
            background: rgba(100, 0, 0, 0.9);
            border: 1px solid #ff0000;
        }

        /* Стили для проверки логина */
        .username-status {
            margin-top: 4px;
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 3px;
            display: none;
        }

        .username-available {
            background: rgba(0, 100, 0, 0.3);
            color: #00ff00;
            border: 1px solid #00ff00;
        }

        .username-taken {
            background: rgba(100, 0, 0, 0.3);
            color: #ff0000;
            border: 1px solid #ff0000;
        }

        .username-checking {
            background: rgba(100, 100, 0, 0.3);
            color: #ffff00;
            border: 1px solid #ffff00;
        }

        /* Стили для информации о муте */
        .mute-info {
            background: rgba(100, 50, 0, 0.8);
            border: 1px solid #ff8000;
            padding: 8px;
            margin: 8px 0;
            border-radius: 5px;
            text-align: center;
            font-size: 0.85rem;
        }

        .mute-warning {
            color: #ff8000;
            font-weight: bold;
        }

        /* Стили для IP информации в сообщениях */
        .ip-info {
            font-size: 0.65rem;
            color: #808080;
            margin-top: 4px;
        }

        .admin-ip {
            color: #ff8000;
            font-weight: bold;
        }

        /* Стили для информации о пользователе в админке */
        .user-info-panel {
            background: rgba(30, 30, 60, 0.9);
            border: 1px solid #8040ff;
            border-radius: 5px;
            padding: 12px;
            margin: 8px 0;
        }

        .user-info-row {
            display: flex;
            justify-content: space-between;
            margin: 4px 0;
            padding: 2px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 0.85rem;
        }

        .user-info-label {
            color: #80ffff;
            font-weight: bold;
        }

        .user-info-value {
            color: #c0c0c0;
        }

        /* Стили для кнопки проверки логина */
        .check-username-btn {
            margin-top: 4px;
            padding: 4px 8px;
            font-size: 0.75rem;
        }

        /* Стили для загрузки изображений */
        .image-upload-btn {
            background: linear-gradient(45deg, #008866, #00aa88);
            color: white;
            border: 1px solid #00ffaa;
            box-shadow: 0 0 10px rgba(0, 255, 170, 0.3);
        }

        .image-upload-btn:hover {
            background: linear-gradient(45deg, #00aa88, #00ccaa);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 255, 170, 0.4);
        }

        /* НОВЫЕ СТИЛИ ДЛЯ СИСТЕМЫ РАЗБАНА */
        .banned-users-list, .banned-ips-list {
            max-height: 200px;
            overflow-y: auto;
            background: rgba(20, 10, 0, 0.6);
            border: 1px solid #ff5500;
            border-radius: 5px;
            padding: 8px;
            margin: 8px 0;
        }

        .banned-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 8px;
            margin: 4px 0;
            background: rgba(40, 20, 0, 0.5);
            border-radius: 3px;
            border-left: 3px solid #ff5500;
        }

        .banned-info {
            flex: 1;
        }

        .banned-name {
            font-weight: bold;
            color: #ff8000;
        }

        .banned-details {
            font-size: 0.7rem;
            color: #aaa;
        }

        .unban-btn {
            background: linear-gradient(45deg, #008800, #00aa00);
            color: white;
            border: none;
            border-radius: 3px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 0.7rem;
            margin-left: 8px;
        }

        .unban-btn:hover {
            background: linear-gradient(45deg, #00aa00, #00cc00);
        }

        .refresh-bans-btn {
            background: linear-gradient(45deg, #004466, #006688);
            color: white;
            border: none;
            border-radius: 3px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 0.75rem;
            margin-top: 8px;
        }

        .refresh-bans-btn:hover {
            background: linear-gradient(45deg, #006688, #0088aa);
        }

        /* НОВЫЕ СТИЛИ ДЛЯ КНОПОЧНОЙ АДМИН-ПАНЕЛИ */
        .admin-controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }

        .admin-control-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 15px;
            background: rgba(40, 20, 0, 0.7);
            border: 1px solid #ff8000;
            border-radius: 8px;
            color: #ffaa00;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            min-height: 100px;
        }

        .admin-control-btn:hover {
            background: rgba(60, 30, 0, 0.9);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(255, 128, 0, 0.3);
            border-color: #ffaa00;
        }

        .admin-control-btn.active {
            background: rgba(80, 40, 0, 0.9);
            border-color: #00ff00;
            color: #00ff00;
        }

        .admin-control-icon {
            font-size: 2rem;
            margin-bottom: 8px;
            filter: drop-shadow(0 0 5px rgba(255, 170, 0, 0.5));
        }

        .admin-control-btn.active .admin-control-icon {
            filter: drop-shadow(0 0 8px rgba(0, 255, 0, 0.7));
        }

        .admin-control-title {
            font-weight: bold;
            font-size: 0.9rem;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .admin-control-desc {
            font-size: 0.75rem;
            color: #cccccc;
        }

        .ban-reason-display {
            background: rgba(255, 0, 0, 0.2);
            border: 1px solid #ff0000;
            border-radius: 5px;
            padding: 10px;
            margin: 10px 0;
            text-align: center;
        }

        .ban-reason-display h4 {
            color: #ff0000;
            margin-bottom: 5px;
        }

        .banned-user-view {
            background: rgba(100, 0, 0, 0.3);
            border: 2px solid #ff0000;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            text-align: center;
        }

        .banned-user-view h3 {
            color: #ff0000;
            margin-bottom: 10px;
        }

        .banned-user-view p {
            margin: 5px 0;
            color: #ff8080;
        }
    </style>
</head>
<body>
    <div class="bg-animation"></div>
    <div class="scan-lines"></div>
    <div class="agent-effect"></div>

    <!-- Оверлей для полноэкранного просмотра изображений -->
    <div class="image-overlay" id="image-overlay"></div>

    <div class="container">
        <header class="header">
            <h1 class="title">Темный город</h1>
            <p class="subtitle">Оперативный чат. Агент на связи.</p>
            
            <div class="greeting">
                <p>🛡️ Защищенный канал связи. Все сообщения шифруются и отслеживаются.</p>
                <p>⚡ Многопользовательский режим. Реальная связь между агентами.</p>
            </div>
        </header>

        <!-- Навигационные кнопки -->
        <div class="navigation-buttons">
            <a href="index.html" class="nav-btn">
                <span class="btn-icon">🏠</span>
                Главная
            </a>
            <a href="sotrud.html" class="nav-btn">
                <span class="btn-icon">👥</span>
                Сотрудники
            </a>
            <a href="games.html" class="nav-btn">
                <span class="btn-icon">🎮</span>
                Игровая зона
            </a>
            <a href="missions.html" class="nav-btn">
                <span class="btn-icon">📋</span>
                Миссии
            </a>
            <a href="gallery.html" class="nav-btn">
                <span class="btn-icon">🖼️</span>
                Галерея
            </a>
        </div>

        <!-- Панель пользователя -->
        <div class="user-panel" id="user-panel" style="display: none;">
            <div class="user-info">
                🕵️ Агент: <span id="current-username"></span>
                <span id="user-role" class="admin-badge" style="display: none;">АДМИН</span>
                <div id="mute-info" class="mute-info" style="display: none;"></div>
            </div>
            <div class="user-actions">
                <button class="btn btn-warning" onclick="showChangePassword()">🔄 Смена кода</button>
                <button class="btn btn-danger" onclick="logout()">🚪 Выход</button>
            </div>
        </div>

        <!-- Формы авторизации -->
        <div id="auth-forms">
            <div class="auth-form" id="login-form">
                <h3>🔐 ВХОД В СИСТЕМУ</h3>
                <div class="form-group">
                    <label>🕵️ Оперативный псевдоним:</label>
                    <input type="text" id="login-username" placeholder="Введите ваш псевдоним">
                </div>
                <div class="form-group">
                    <label>🔑 Код доступа:</label>
                    <input type="password" id="login-password" placeholder="Введите ваш пароль">
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" id="remember-me" checked>
                            📝 Запомнить меня
                        </label>
                    </div>
                </div>
                <div class="form-buttons">
                    <button class="btn btn-primary" onclick="login()">🚀 Войти в систему</button>
                    <button class="btn btn-success" onclick="showRegisterForm()">📝 Регистрация агента</button>
                </div>
            </div>

            <div class="auth-form" id="register-form" style="display: none;">
                <h3>📋 РЕГИСТРАЦИЯ АГЕНТА</h3>
                <div class="form-group">
                    <label>🕵️ Оперативный псевдоним (3-20 символов):</label>
                    <input type="text" id="register-username" placeholder="Придумайте уникальный псевдоним" oninput="checkUsernameAvailability()">
                    <div id="username-status" class="username-status"></div>
                    <button class="btn btn-info check-username-btn" onclick="checkUsernameAvailability()">🔍 Проверить доступность</button>
                </div>
                <div class="form-group">
                    <label>🔑 Код доступа (минимум 6 символов):</label>
                    <input type="password" id="register-password" placeholder="Создайте надежный пароль">
                </div>
                <div class="form-group">
                    <label>📧 Кодовое слово (email, опционально):</label>
                    <input type="email" id="register-email" placeholder="Для восстановления доступа">
                </div>
                <div class="form-buttons">
                    <button class="btn btn-success" onclick="register()">✅ Зарегистрироваться</button>
                    <button class="btn btn-primary" onclick="showLoginForm()">↩️ Назад к входу</button>
                </div>
            </div>
        </div>

        <!-- Форма смены пароля -->
        <div class="auth-form" id="change-password-form" style="display: none;">
            <h3>🔄 СМЕНА КОДА ДОСТУПА</h3>
            <div class="form-group">
                <label>🔑 Текущий код доступа:</label>
                <input type="password" id="current-password" placeholder="Введите текущий пароль">
            </div>
            <div class="form-group">
                <label>🆕 Новый код доступа:</label>
                <input type="password" id="new-password" placeholder="Придумайте новый пароль">
            </div>
            <div class="form-buttons">
                <button class="btn btn-success" onclick="changePassword()">✅ Сменить пароль</button>
                <button class="btn btn-primary" onclick="hideChangePassword()">❌ Отмена</button>
            </div>
        </div>

        <!-- Блок для забаненных пользователей -->
        <div id="banned-user-view" class="banned-user-view" style="display: none;">
            <h3>🚫 ДОСТУП ОГРАНИЧЕН</h3>
            <p>Ваш аккаунт был заблокирован администрацией системы.</p>
            <div id="ban-details"></div>
            <p>Для выяснения причин обратитесь к администратору.</p>
        </div>

        <!-- Основной контейнер чата и пользователей -->
        <div class="chat-wrapper" id="chat-wrapper" style="display: none;">
            <!-- Чат -->
            <div class="chat-container" id="chat-container">
                <div class="chat-header">
                    <h2>💬 ОПЕРАТИВНЫЙ КАНАЛ СВЯЗИ</h2>
                    <div class="connection-status">
                        <span class="status-indicator status-online"></span>
                        <span id="status-text">Канал связи активен</span>
                    </div>
                </div>

                <div class="chat-messages" id="chat-messages">
                    <div class="system-message">
                        <span class="message-content">📡 Подключение к защищенному каналу...</span>
                    </div>
                </div>

                <div class="chat-input-container">
                    <div class="image-preview-container" id="image-preview-container" style="display: none;">
                        <img id="image-preview" class="image-preview" alt="Предпросмотр">
                        <button class="remove-image-btn" onclick="removeImage()">❌ Удалить изображение</button>
                    </div>
                    <div class="input-area">
                        <textarea 
                            id="message-input" 
                            class="message-textarea" 
                            placeholder="Введите ваше сообщение..."
                            rows="1"
                        ></textarea>
                        <input type="file" id="image-upload" accept="image/*" style="display: none;">
                        <button class="btn image-upload-btn" onclick="document.getElementById('image-upload').click()">🖼️</button>
                        <button id="send-btn" class="btn btn-primary desktop-send-btn">📤 Отправить</button>
                    </div>
                    <div class="mobile-send-container">
                        <button id="mobile-send-btn" class="btn btn-primary" style="width: 100%;">📤 Отправить сообщение</button>
                    </div>
                </div>
            </div>

            <!-- Панель пользователей -->
            <div class="users-panel" id="users-panel">
                <div class="users-header">
                    <h3>👥 АГЕНТЫ В СИСТЕМЕ</h3>
                    <div class="users-count">
                        <span id="online-count">0</span>/<span id="total-count">0</span>
                    </div>
                </div>
                
                <div class="users-list" id="users-list">
                    <div class="user-item offline">
                        <div class="user-status offline"></div>
                        <div class="user-name">Загрузка...</div>
                    </div>
                </div>
                
                <div class="users-controls">
                    <div class="users-toggle">
                        <button class="toggle-btn active" onclick="setUsersFilter('all')">Все</button>
                        <button class="toggle-btn" onclick="setUsersFilter('online')">Онлайн</button>
                        <button class="toggle-btn" onclick="setUsersFilter('admins')">Админы</button>
                    </div>
                    <button class="btn btn-info btn-small" onclick="loadUsers()">🔄 Обновить</button>
                </div>
            </div>
        </div>

        <!-- Админ панель -->
        <div class="admin-panel" id="admin-panel" style="display: none;">
            <h3>⚙️ ПАНЕЛЬ УПРАВЛЕНИЯ СИСТЕМОЙ</h3>
            
            <!-- Кнопочное управление -->
            <div class="admin-controls-grid">
                <div class="admin-control-btn" id="chat-toggle-btn" onclick="toggleChatWithButton()">
                    <div class="admin-control-icon">💬</div>
                    <div class="admin-control-title" id="chat-toggle-title">Чат: ВКЛ</div>
                    <div class="admin-control-desc" id="chat-toggle-desc">Включить/выключить чат</div>
                </div>

                <div class="admin-control-btn" onclick="showUserManagement()">
                    <div class="admin-control-icon">👤</div>
                    <div class="admin-control-title">Управление пользователями</div>
                    <div class="admin-control-desc">Бан/разбан, мут/размут</div>
                </div>

                <div class="admin-control-btn" onclick="showIPManagement()">
                    <div class="admin-control-icon">🌐</div>
                    <div class="admin-control-title">Управление IP</div>
                    <div class="admin-control-desc">Блокировка IP адресов</div>
                </div>

                <div class="admin-control-btn" onclick="showBanManagement()">
                    <div class="admin-control-icon">🚫</div>
                    <div class="admin-control-title">Списки блокировок</div>
                    <div class="admin-control-desc">Просмотр и управление банами</div>
                </div>
            </div>

            <!-- Секция управления чатом -->
            <div class="admin-section" id="chat-control-section">
                <h4>🎮 Управление чатом</h4>
                <div class="chat-status">
                    <div class="status-indicator" id="chat-status-indicator"></div>
                    <span id="chat-status-text">Статус чата: проверка...</span>
                </div>
                <div class="chat-toggle-buttons">
                    <button class="btn btn-success btn-small" id="enable-chat-btn" onclick="toggleChat(true)">✅ Включить чат</button>
                    <button class="btn btn-danger btn-small" id="disable-chat-btn" onclick="toggleChat(false)">❌ Отключить чат</button>
                </div>
                <div class="chat-status-info">
                    <small id="chat-status-time">Последнее обновление: --:--:--</small>
                </div>
            </div>

            <!-- Секция управления пользователями -->
            <div class="admin-section" id="user-management-section" style="display: none;">
                <h4>👤 Управление пользователями</h4>
                
                <div class="form-group">
                    <label>Бан пользователя:</label>
                    <div class="input-group">
                        <input type="text" id="ban-username" placeholder="Псевдоним агента" class="form-input">
                        <input type="text" id="ban-reason" placeholder="Причина блокировки" class="form-input">
                        <button class="btn btn-danger" onclick="banUser()">🚫 Заблокировать</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Разбан пользователя:</label>
                    <div class="input-group">
                        <input type="text" id="unban-username" placeholder="Псевдоним агента" class="form-input">
                        <button class="btn btn-success" onclick="unbanUser()">🔓 Разблокировать</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Мут пользователя:</label>
                    <div class="input-group">
                        <input type="text" id="mute-username" placeholder="Псевдоним агента" class="form-input">
                        <input type="number" id="mute-duration" placeholder="Минуты (0=навсегда)" class="form-input" min="0">
                        <input type="text" id="mute-reason" placeholder="Причина мута" class="form-input">
                        <button class="btn btn-warning" onclick="muteUser()">🔇 Замутить</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Размут пользователя:</label>
                    <div class="input-group">
                        <input type="text" id="unmute-username" placeholder="Псевдоним агента" class="form-input">
                        <button class="btn btn-success" onclick="unmuteUser()">🔊 Размутить</button>
                    </div>
                </div>
            </div>

            <!-- Секция управления IP -->
            <div class="admin-section" id="ip-management-section" style="display: none;">
                <h4>🌐 Управление IP адресами</h4>
                
                <div class="form-group">
                    <label>Блокировка IP адреса:</label>
                    <div class="input-group">
                        <input type="text" id="ban-ip" placeholder="IP адрес" class="form-input">
                        <input type="text" id="ban-ip-reason" placeholder="Причина блокировки" class="form-input">
                        <button class="btn btn-danger" onclick="banIP()">🚫 Заблокировать IP</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Разблокировка IP адреса:</label>
                    <div class="input-group">
                        <input type="text" id="unban-ip" placeholder="IP адрес" class="form-input">
                        <button class="btn btn-success" onclick="unbanIP()">🔓 Разблокировать IP</button>
                    </div>
                </div>
            </div>

            <!-- Секция управления блокировками -->
            <div class="admin-section" id="ban-management-section" style="display: none;">
                <h4>🚫 Управление блокировками</h4>
                
                <div class="form-group">
                    <label>Заблокированные пользователи:</label>
                    <div id="banned-users-list" class="banned-users-list">
                        <div class="banned-item">
                            <div class="banned-info">
                                <div class="banned-name">Загрузка...</div>
                            </div>
                        </div>
                    </div>
                    <button class="refresh-bans-btn" onclick="loadBannedUsers()">🔄 Обновить список</button>
                </div>

                <div class="form-group">
                    <label>Заблокированные IP адреса:</label>
                    <div id="banned-ips-list" class="banned-ips-list">
                        <div class="banned-item">
                            <div class="banned-info">
                                <div class="banned-name">Загрузка...</div>
                            </div>
                        </div>
                    </div>
                    <button class="refresh-bans-btn" onclick="loadBannedIPs()">🔄 Обновить список</button>
                </div>
            </div>

            <!-- Информация о пользователе -->
            <div class="admin-section">
                <h4>🔍 Информация о пользователе</h4>
                <div class="form-group">
                    <label>Получить информацию об агенте:</label>
                    <div class="input-group">
                        <input type="text" id="user-info-username" placeholder="Псевдоним агента" class="form-input">
                        <button class="btn btn-info" onclick="getUserInfo()">🔍 Получить информацию</button>
                    </div>
                </div>
                <div id="user-info-display" class="user-info-panel" style="display: none;"></div>
            </div>
        </div>
    </div>

    <script>
        // Уведомления о новых сообщениях
        let lastMessageId = 0;
        let currentImageData = null;
        let chat = null;

        // Функция для полноэкранного просмотра изображений
        function showFullscreenImage(imgElement) {
            const overlay = document.getElementById('image-overlay');
            const fullscreenImg = imgElement.cloneNode();
            
            fullscreenImg.className = 'message-image fullscreen';
            fullscreenImg.onclick = function() {
                overlay.style.display = 'none';
                overlay.innerHTML = '';
            };
            
            overlay.innerHTML = '';
            overlay.appendChild(fullscreenImg);
            overlay.style.display = 'block';
            
            // Закрытие по клику на оверлей
            overlay.onclick = function(e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    overlay.innerHTML = '';
                }
            };
            
            // Закрытие по ESC
            document.addEventListener('keydown', function closeOnEsc(e) {
                if (e.key === 'Escape') {
                    overlay.style.display = 'none';
                    overlay.innerHTML = '';
                    document.removeEventListener('keydown', closeOnEsc);
                }
            });
        }

        // Обработка загрузки изображений
        document.getElementById('image-upload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Проверяем размер файла (максимум 5MB)
                if (file.size > 5 * 1024 * 1024) {
                    showNotification('❌ Размер изображения не должен превышать 5MB', 'error');
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    currentImageData = e.target.result;
                    const preview = document.getElementById('image-preview');
                    const container = document.getElementById('image-preview-container');
                    
                    preview.src = currentImageData;
                    preview.onclick = function() {
                        showFullscreenImage(preview);
                    };
                    container.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            }
        });

        // Функция удаления изображения
        function removeImage() {
            currentImageData = null;
            document.getElementById('image-preview-container').style.display = 'none';
            document.getElementById('image-upload').value = '';
        }

        // Автоматическое изменение высоты textarea
        function autoResizeTextarea(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        }

        // Обработка вставки из буфера обмена
        document.getElementById('message-input').addEventListener('paste', function(e) {
            const items = e.clipboardData.items;
            for (let item of items) {
                if (item.type.indexOf('image') !== -1) {
                    const file = item.getAsFile();
                    if (file && file.size > 5 * 1024 * 1024) {
                        showNotification('❌ Размер изображения не должен превышать 5MB', 'error');
                        e.preventDefault();
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        currentImageData = e.target.result;
                        const preview = document.getElementById('image-preview');
                        const container = document.getElementById('image-preview-container');
                        
                        preview.src = currentImageData;
                        preview.onclick = function() {
                            showFullscreenImage(preview);
                        };
                        container.style.display = 'flex';
                    };
                    reader.readAsDataURL(file);
                    e.preventDefault();
                    break;
                }
            }
        });

        // Улучшенная версия secureFetch
        async function secureFetch(action, data = {}) {
            data.action = action;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(data)
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ошибка ${response.status}: ${response.statusText}`);
                }
                
                const text = await response.text();
                
                // Проверяем, не содержит ли ответ HTML ошибок
                if (text.trim().startsWith('<!DOCTYPE') || text.includes('<b>') || text.includes('<br />')) {
                    console.error('Server returned HTML instead of JSON:', text.substring(0, 500));
                    return {
                        status: 'error', 
                        message: 'Сервер вернул некорректный ответ. Возможно, ошибка PHP.'
                    };
                }
                
                try {
                    return JSON.parse(text);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.error('Response text:', text);
                    return {
                        status: 'error', 
                        message: 'Неверный формат ответа от сервера: ' + text.substring(0, 100)
                    };
                }
                
            } catch (error) {
                console.error('Network error:', error);
                return { 
                    status: 'error', 
                    message: 'Ошибка сети: ' + error.message 
                };
            }
        }

        class SecurePHPChat {
            constructor() {
                this.messages = [];
                this.users = [];
                this.isScrolledToBottom = true;
                this.isAdmin = false;
                this.chatEnabled = true;
                this.isMuted = false;
                this.muteTimeLeft = 0;
                this.isBanned = false;
                this.banInfo = null;
                this.chatStatusInterval = null;
                this.usersInterval = null;
                this.usersFilter = 'all';
                this.muteCheckInterval = null;
                
                this.initializeElements();
                this.setupEventListeners();
                this.checkSavedAuth();
                this.checkAuthStatus();
            }
            
            initializeElements() {
                this.chatMessages = document.getElementById('chat-messages');
                this.messageInput = document.getElementById('message-input');
                this.sendBtn = document.getElementById('send-btn');
                this.mobileSendBtn = document.getElementById('mobile-send-btn');
                this.userPanel = document.getElementById('user-panel');
                this.authForms = document.getElementById('auth-forms');
                this.chatWrapper = document.getElementById('chat-wrapper');
                this.chatContainer = document.getElementById('chat-container');
                this.adminPanel = document.getElementById('admin-panel');
                this.bannedUserView = document.getElementById('banned-user-view');
                this.chatStatusIndicator = document.getElementById('chat-status-indicator');
                this.chatStatusText = document.getElementById('chat-status-text');
                this.enableChatBtn = document.getElementById('enable-chat-btn');
                this.disableChatBtn = document.getElementById('disable-chat-btn');
                this.chatStatusTime = document.getElementById('chat-status-time');
                this.loginForm = document.getElementById('login-form');
                this.registerForm = document.getElementById('register-form');
                this.usersList = document.getElementById('users-list');
                this.onlineCount = document.getElementById('online-count');
                this.totalCount = document.getElementById('total-count');
                this.muteInfo = document.getElementById('mute-info');
                this.banDetails = document.getElementById('ban-details');
                this.imagePreview = document.getElementById('image-preview');
                this.imageUpload = document.getElementById('image-upload');
                this.imagePreviewContainer = document.getElementById('image-preview-container');
                this.chatToggleBtn = document.getElementById('chat-toggle-btn');
                this.chatToggleTitle = document.getElementById('chat-toggle-title');
                this.chatToggleDesc = document.getElementById('chat-toggle-desc');
            }
            
            setupEventListeners() {
                this.sendBtn.addEventListener('click', () => this.sendMessage());
                this.mobileSendBtn.addEventListener('click', () => this.sendMessage());
                
                // Обработка клавиши Enter в textarea
                this.messageInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        if (e.shiftKey) {
                            // Shift+Enter - новая строка
                            return;
                        } else {
                            // Enter - отправка сообщения
                            e.preventDefault();
                            this.sendMessage();
                        }
                    }
                });
                
                // Автоматическое изменение высоты textarea
                this.messageInput.addEventListener('input', () => {
                    autoResizeTextarea(this.messageInput);
                });
                
                this.chatMessages.addEventListener('scroll', () => {
                    const { scrollTop, scrollHeight, clientHeight } = this.chatMessages;
                    this.isScrolledToBottom = Math.abs(scrollHeight - clientHeight - scrollTop) < 10;
                });
            }
            
            checkSavedAuth() {
                const savedAuth = localStorage.getItem('chat_authenticated');
                const savedUsername = localStorage.getItem('chat_username');
                
                if (savedAuth === 'true' && savedUsername) {
                    this.addSystemMessage('🔄 Восстановление сессии...');
                    this.authForms.style.display = 'none';
                }
            }
            
            async checkAuthStatus() {
                try {
                    const result = await secureFetch('check_auth');
                    
                    if (result.authenticated) {
                        this.isBanned = result.banned;
                        this.banInfo = result.ban_info;
                        
                        if (this.isBanned) {
                            this.showBannedView();
                        } else {
                            this.showChatInterface(result.username, result.role, result.muted, result.mute_time_left);
                        }
                        
                        localStorage.setItem('chat_authenticated', 'true');
                        localStorage.setItem('chat_username', result.username);
                        localStorage.setItem('chat_role', result.role);
                        
                    } else {
                        const savedAuth = localStorage.getItem('chat_authenticated');
                        const savedUsername = localStorage.getItem('chat_username');
                        
                        if (savedAuth === 'true' && savedUsername) {
                            this.addSystemMessage('🔐 Сессия истекла. Пожалуйста, войдите снова.');
                            
                            localStorage.removeItem('chat_authenticated');
                            localStorage.removeItem('chat_username');
                            localStorage.removeItem('chat_role');
                            
                            this.authForms.style.display = 'block';
                            this.userPanel.style.display = 'none';
                            this.chatWrapper.style.display = 'none';
                            this.adminPanel.style.display = 'none';
                            this.bannedUserView.style.display = 'none';
                        } else {
                            this.authForms.style.display = 'block';
                            this.userPanel.style.display = 'none';
                            this.chatWrapper.style.display = 'none';
                            this.adminPanel.style.display = 'none';
                            this.bannedUserView.style.display = 'none';
                        }
                    }
                } catch (error) {
                    console.error('Ошибка проверки авторизации:', error);
                    this.authForms.style.display = 'block';
                    this.userPanel.style.display = 'none';
                    this.chatWrapper.style.display = 'none';
                    this.adminPanel.style.display = 'none';
                    this.bannedUserView.style.display = 'none';
                    this.addSystemMessage('❌ Ошибка подключения к серверу');
                }
            }
            
            showBannedView() {
                this.authForms.style.display = 'none';
                this.userPanel.style.display = 'none';
                this.chatWrapper.style.display = 'none';
                this.adminPanel.style.display = 'none';
                this.bannedUserView.style.display = 'block';
                
                let banDetails = '';
                if (this.banInfo) {
                    if (this.banInfo.ban_reason) {
                        banDetails += `<p><strong>Причина:</strong> ${this.banInfo.ban_reason}</p>`;
                    }
                    if (this.banInfo.banned_by) {
                        banDetails += `<p><strong>Заблокировал:</strong> ${this.banInfo.banned_by}</p>`;
                    }
                    if (this.banInfo.banned_at) {
                        banDetails += `<p><strong>Время блокировки:</strong> ${this.banInfo.banned_at}</p>`;
                    }
                }
                
                this.banDetails.innerHTML = banDetails;
            }
            
            showChatInterface(username, role, muted, muteTimeLeft) {
                this.authForms.style.display = 'none';
                this.userPanel.style.display = 'flex';
                this.chatWrapper.style.display = 'grid';
                this.bannedUserView.style.display = 'none';
                
                document.getElementById('current-username').textContent = username;
                
                this.isAdmin = (role === 'admin');
                
                if (this.isAdmin) {
                    document.getElementById('user-role').style.display = 'inline';
                    this.adminPanel.style.display = 'block';
                    this.startChatStatusCheck();
                    // Загружаем списки банов при первом входе
                    this.loadBannedUsers();
                    this.loadBannedIPs();
                }
                
                this.updateMuteStatus(muted, muteTimeLeft);
                
                this.loadMessages();
                this.loadUsers();
                this.startAutoRefresh();
                this.startUsersRefresh();
                this.startActivityUpdates();
                this.startMuteCheck();
                
                this.addSystemMessage(`🎉 Добро пожаловать, ${username}!`);
            }
            
            async checkAndUpdateRole() {
                try {
                    const result = await secureFetch('check_and_update_role');
                    
                    if (result.status === 'success') {
                        const wasAdmin = this.isAdmin;
                        this.isAdmin = (result.role === 'admin');
                        
                        if (wasAdmin !== this.isAdmin) {
                            if (this.isAdmin) {
                                document.getElementById('user-role').style.display = 'inline';
                                this.adminPanel.style.display = 'block';
                                this.startChatStatusCheck();
                                this.loadBannedUsers();
                                this.loadBannedIPs();
                                this.addSystemMessage('⚡ Ваши права были обновлены. Теперь вы администратор!');
                                showNotification('🎉 Вы получили права администратора!', 'success');
                            } else {
                                document.getElementById('user-role').style.display = 'none';
                                this.adminPanel.style.display = 'none';
                                this.stopChatStatusCheck();
                                this.addSystemMessage('⚠️ Ваши права администратора были отозваны.');
                                showNotification('⚠️ Права администратора отозваны', 'warning');
                            }
                            return true;
                        }
                    }
                    return false;
                } catch (error) {
                    console.error('Ошибка проверки роли:', error);
                    return false;
                }
            }
            
            updateMuteStatus(muted, timeLeft) {
                this.isMuted = muted;
                this.muteTimeLeft = timeLeft;
                
                if (muted && timeLeft > 0) {
                    this.muteInfo.style.display = 'block';
                    this.updateMuteDisplay();
                    this.messageInput.disabled = true;
                    this.messageInput.placeholder = 'Вы в муте. Ожидайте...';
                    this.sendBtn.disabled = true;
                    this.mobileSendBtn.disabled = true;
                } else {
                    this.muteInfo.style.display = 'none';
                    this.messageInput.disabled = false;
                    this.messageInput.placeholder = 'Введите ваше сообщение...';
                    this.sendBtn.disabled = false;
                    this.mobileSendBtn.disabled = false;
                }
            }
            
            updateMuteDisplay() {
                if (this.isMuted && this.muteTimeLeft > 0) {
                    const minutes = Math.floor(this.muteTimeLeft / 60);
                    const seconds = this.muteTimeLeft % 60;
                    this.muteInfo.innerHTML = `
                        <div class="mute-warning">🔇 ВЫ В МУТЕ</div>
                        <div>Осталось: ${minutes}м ${seconds}с</div>
                        <div>Причина: проверяется...</div>
                    `;
                }
            }
            
            async checkMuteStatus() {
                try {
                    const result = await secureFetch('get_mute_info');
                    
                    if (result.muted) {
                        this.updateMuteStatus(true, result.time_left);
                        if (this.muteInfo.style.display !== 'none') {
                            this.muteInfo.innerHTML = `
                                <div class="mute-warning">🔇 ВЫ В МУТЕ</div>
                                <div>Осталось: ${Math.floor(result.time_left / 60)}м ${result.time_left % 60}с</div>
                                <div>Причина: ${result.reason}</div>
                                <div>Замутил: ${result.muted_by}</div>
                            `;
                        }
                    } else {
                        this.updateMuteStatus(false, 0);
                    }
                } catch (error) {
                    console.error('Ошибка проверки мута:', error);
                }
            }
            
            async checkChatStatus() {
                try {
                    const result = await secureFetch('get_chat_status');
                    this.updateChatStatus(result.enabled);
                    
                    const now = new Date();
                    this.chatStatusTime.textContent = `Последнее обновление: ${now.toLocaleTimeString()}`;
                    
                } catch (error) {
                    console.error('Ошибка проверки статуса чата:', error);
                    this.chatStatusText.textContent = 'Статус чата: ошибка подключения';
                    this.chatStatusIndicator.className = 'status-indicator status-offline';
                }
            }
            
            updateChatStatus(enabled) {
                this.chatEnabled = enabled;
                
                if (enabled) {
                    this.chatStatusIndicator.className = 'status-indicator status-online';
                    this.chatStatusText.textContent = 'Статус чата: АКТИВЕН';
                    this.enableChatBtn.style.display = 'none';
                    this.disableChatBtn.style.display = 'inline-block';
                    this.chatContainer.classList.remove('chat-disabled');
                    this.chatToggleBtn.classList.add('active');
                    this.chatToggleTitle.textContent = 'Чат: ВКЛ';
                    this.chatToggleDesc.textContent = 'Нажмите для отключения';
                    
                    if (!this.isMuted && !this.isBanned) {
                        this.messageInput.disabled = false;
                        this.sendBtn.disabled = false;
                        this.mobileSendBtn.disabled = false;
                    }
                    this.sendBtn.textContent = '📤 Отправить';
                    this.mobileSendBtn.textContent = '📤 Отправить сообщение';
                } else {
                    this.chatStatusIndicator.className = 'status-indicator status-offline';
                    this.chatStatusText.textContent = 'Статус чата: ОТКЛЮЧЕН';
                    this.enableChatBtn.style.display = 'inline-block';
                    this.disableChatBtn.style.display = 'none';
                    this.chatContainer.classList.add('chat-disabled');
                    this.chatToggleBtn.classList.remove('active');
                    this.chatToggleTitle.textContent = 'Чат: ВЫКЛ';
                    this.chatToggleDesc.textContent = 'Нажмите для включения';
                    
                    this.messageInput.disabled = true;
                    this.sendBtn.disabled = true;
                    this.mobileSendBtn.disabled = true;
                    this.sendBtn.textContent = '❌ Чат отключен';
                    this.mobileSendBtn.textContent = '❌ Чат отключен';
                }
            }
            
            async sendMessage() {
                if (this.isBanned) {
                    this.addSystemMessage('❌ Ваш аккаунт заблокирован. Вы не можете отправлять сообщения.');
                    return;
                }
                
                if (this.isMuted) {
                    this.addSystemMessage('❌ Вы не можете отправлять сообщения пока в муте');
                    return;
                }
                
                const messageText = this.messageInput.value.trim();
                
                // Разрешаем отправку либо текста, либо изображения, либо обоих
                if (!messageText && !currentImageData) {
                    showNotification('❌ Сообщение не может быть полностью пустым', 'error');
                    return;
                }
                
                // Временно отключаем кнопки отправки
                this.sendBtn.disabled = true;
                this.mobileSendBtn.disabled = true;
                this.sendBtn.textContent = '📡 Отправка...';
                this.mobileSendBtn.textContent = '📡 Отправка...';
                this.messageInput.disabled = true;
                
                try {
                    // Проверяем статус чата перед отправкой
                    const chatStatus = await secureFetch('get_chat_status');
                    if (chatStatus.enabled === false) {
                        this.addSystemMessage('❌ Чат временно отключен администратором');
                        this.messageInput.value = '';
                        autoResizeTextarea(this.messageInput);
                        return;
                    }
                    
                    // Отправляем сообщение
                    const result = await secureFetch('send_message', {
                        message: messageText,
                        image_data: currentImageData || ''
                    });
                    
                    if (result.status === 'success') {
                        this.messageInput.value = '';
                        autoResizeTextarea(this.messageInput); // Сбрасываем высоту
                        removeImage(); // Очищаем изображение
                        this.loadMessages();
                        showNotification('✅ Сообщение отправлено', 'success');
                    } else {
                        showNotification('❌ ' + (result.message || 'Ошибка отправки сообщения'), 'error');
                        if (result.message && result.message.includes('муте')) {
                            this.checkMuteStatus();
                        }
                        if (result.message && result.message.includes('заблокирован')) {
                            // Пользователь был забанен во время сессии
                            this.checkAuthStatus();
                        }
                    }
                } catch (error) {
                    console.error('Ошибка отправки:', error);
                    showNotification('❌ Ошибка соединения с сервером', 'error');
                } finally {
                    // Восстанавливаем состояние элементов ввода
                    if (!this.isMuted && this.chatEnabled && !this.isBanned) {
                        this.sendBtn.disabled = false;
                        this.mobileSendBtn.disabled = false;
                        this.messageInput.disabled = false;
                    }
                    this.sendBtn.textContent = '📤 Отправить';
                    this.mobileSendBtn.textContent = '📤 Отправить сообщение';
                    this.messageInput.focus();
                }
            }
            
            async loadMessages() {
                try {
                    const result = await secureFetch('get_messages');
                    if (result && !result.status) {
                        this.checkNewMessages(result);
                        this.displayMessages(result);
                    } else if (result.status === 'error') {
                        console.error('Ошибка загрузки сообщений:', result.message);
                    }
                } catch (error) {
                    console.error('Ошибка загрузки сообщений:', error);
                }
            }
            
            // Функции для работы с банами
            async loadBannedUsers() {
                if (!this.isAdmin) return;
                
                try {
                    const result = await secureFetch('get_banned_users');
                    if (result && !result.status) {
                        this.displayBannedUsers(result);
                    }
                } catch (error) {
                    console.error('Ошибка загрузки забаненных пользователей:', error);
                }
            }
            
            async loadBannedIPs() {
                if (!this.isAdmin) return;
                
                try {
                    const result = await secureFetch('get_banned_ips');
                    if (result && !result.status) {
                        this.displayBannedIPs(result);
                    }
                } catch (error) {
                    console.error('Ошибка загрузки забаненных IP:', error);
                }
            }
            
            displayBannedUsers(users) {
                const container = document.getElementById('banned-users-list');
                
                if (!users || users.length === 0) {
                    container.innerHTML = '<div class="banned-item"><div class="banned-info"><div class="banned-name">Нет заблокированных пользователей</div></div></div>';
                    return;
                }
                
                container.innerHTML = '';
                
                users.forEach(user => {
                    const banDetails = [];
                    if (user.ban_reason) banDetails.push(`Причина: ${user.ban_reason}`);
                    if (user.banned_by) banDetails.push(`Заблокировал: ${user.banned_by}`);
                    if (user.banned_at) banDetails.push(`Время: ${user.banned_at}`);
                    
                    const item = document.createElement('div');
                    item.className = 'banned-item';
                    item.innerHTML = `
                        <div class="banned-info">
                            <div class="banned-name">🕵️ ${user.username}</div>
                            <div class="banned-details">
                                ${banDetails.join(' | ')}
                            </div>
                        </div>
                        <button class="unban-btn" onclick="unbanUserByName('${user.username}')">🔓 Разблокировать</button>
                    `;
                    container.appendChild(item);
                });
            }
            
            displayBannedIPs(ips) {
                const container = document.getElementById('banned-ips-list');
                
                if (!ips || ips.length === 0) {
                    container.innerHTML = '<div class="banned-item"><div class="banned-info"><div class="banned-name">Нет заблокированных IP адресов</div></div></div>';
                    return;
                }
                
                container.innerHTML = '';
                
                ips.forEach(ip => {
                    const expires = ip.expires_at ? new Date(ip.expires_at).toLocaleString() : 'Навсегда';
                    const item = document.createElement('div');
                    item.className = 'banned-item';
                    item.innerHTML = `
                        <div class="banned-info">
                            <div class="banned-name">🌐 ${ip.ip_address}</div>
                            <div class="banned-details">
                                Причина: ${ip.reason} | 
                                Заблокировал: ${ip.banned_by} | 
                                Время: ${ip.banned_at} | 
                                Истекает: ${expires}
                            </div>
                        </div>
                        <button class="unban-btn" onclick="unbanIPByAddress('${ip.ip_address}')">🔓 Разблокировать</button>
                    `;
                    container.appendChild(item);
                });
            }
            
            // Проверка новых сообщений для уведомлений
            checkNewMessages(messages) {
                if (!messages || messages.length === 0) return;
                
                const currentUser = document.getElementById('current-username').textContent;
                const latestMessage = messages[messages.length - 1];
                
                if (lastMessageId === 0) {
                    lastMessageId = latestMessage.id;
                    return;
                }
                
                const newMessages = messages.filter(msg => 
                    msg.id > lastMessageId && 
                    msg.username !== currentUser
                );
                
                if (newMessages.length > 0) {
                    // Показываем визуальное уведомление о новых сообщениях
                    if (newMessages.length === 1) {
                        showNotification(`💬 Новое сообщение от ${newMessages[0].username}`, 'success');
                    } else {
                        showNotification(`💬 ${newMessages.length} новых сообщений`, 'success');
                    }
                }
                
                lastMessageId = latestMessage.id;
            }
            
            async loadUsers() {
                try {
                    const result = await secureFetch('get_users');
                    if (result && !result.status) {
                        this.displayUsers(result);
                    } else if (result.status === 'error') {
                        console.error('Ошибка загрузки пользователей:', result.message);
                    }
                } catch (error) {
                    console.error('Ошибка загрузки пользователей:', error);
                }
            }
            
            displayMessages(messages) {
                if (!messages || !Array.isArray(messages)) {
                    this.addSystemMessage('❌ Ошибка загрузки сообщений');
                    return;
                }

                // Если пользователь забанен, показываем только системное сообщение
                if (this.isBanned && messages.length > 0 && messages[0].role === 'system') {
                    this.chatMessages.innerHTML = '';
                    this.addMessageToChat(messages[0]);
                    return;
                }

                const wasScrolledToBottom = this.isScrolledToBottom;
                const oldScrollHeight = this.chatMessages.scrollHeight;
                const oldScrollTop = this.chatMessages.scrollTop;
                
                this.chatMessages.innerHTML = '';
                
                if (messages.length === 0) {
                    this.addSystemMessage('💬 История переговоров пуста. Будьте первым!');
                    return;
                }
                
                messages.forEach(msg => {
                    this.addMessageToChat(msg);
                });
                
                if (wasScrolledToBottom) {
                    this.scrollToBottom();
                } else {
                    const newScrollHeight = this.chatMessages.scrollHeight;
                    const heightDiff = newScrollHeight - oldScrollHeight;
                    this.chatMessages.scrollTop = oldScrollTop + heightDiff;
                }
            }
            
            displayUsers(users) {
                if (!users || !Array.isArray(users)) {
                    return;
                }

                this.users = users;
                
                const onlineUsers = users.filter(user => user.status === 'online').length;
                const totalUsers = users.length;
                
                this.onlineCount.textContent = onlineUsers;
                this.totalCount.textContent = totalUsers;
                
                let filteredUsers = users;
                if (this.usersFilter === 'online') {
                    filteredUsers = users.filter(user => user.status === 'online');
                } else if (this.usersFilter === 'admins') {
                    filteredUsers = users.filter(user => user.role === 'admin');
                }
                
                this.usersList.innerHTML = '';
                
                if (filteredUsers.length === 0) {
                    this.usersList.innerHTML = `
                        <div class="user-item offline">
                            <div class="user-status offline"></div>
                            <div class="user-name">Нет пользователей</div>
                        </div>
                    `;
                    return;
                }
                
                filteredUsers.forEach(user => {
                    const userItem = document.createElement('div');
                    userItem.className = `user-item ${user.status}`;
                    
                    userItem.innerHTML = `
                        <div class="user-status ${user.status}"></div>
                        <div class="user-name">${user.status === 'online' ? '' : ''} ${this.escapeHtml(user.username)}</div>
                        ${user.role === 'admin' ? '<div class="user-role">ADMIN</div>' : ''}
                        ${user.is_banned ? '<div class="user-role" style="background: rgba(255,0,0,0.3); color: #ff0000;">BANNED</div>' : ''}
                        <small style="color: #666; font-size: 0.65rem;">${user.status}</small>
                    `;
                    
                    this.usersList.appendChild(userItem);
                });
            }
            
            setUsersFilter(filter) {
                this.usersFilter = filter;
                
                document.querySelectorAll('.users-toggle .toggle-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                event.target.classList.add('active');
                
                this.displayUsers(this.users);
            }
            
            addMessageToChat(messageData) {
                const messageDiv = document.createElement('div');
                const isOwn = messageData.username === document.getElementById('current-username').textContent;
                
                if (messageData.role === 'system') {
                    messageDiv.className = 'message system';
                } else {
                    messageDiv.className = `message ${isOwn ? 'own' : 'other'}`;
                }
                
                let messageContent = '';
                let hasImage = false;
                let textContent = '';
                let imageContent = '';
                
                // Проверяем, содержит ли сообщение изображение
                if (messageData.message.includes('|||IMAGE|||')) {
                    // Сообщение содержит и текст и изображение
                    const parts = messageData.message.split('|||IMAGE|||');
                    textContent = parts[0];
                    imageContent = parts[1];
                    hasImage = true;
                } else if (messageData.message.startsWith('data:image')) {
                    // Сообщение содержит только изображение
                    imageContent = messageData.message;
                    hasImage = true;
                } else {
                    // Обычное текстовое сообщение
                    textContent = messageData.message;
                }
                
                // Формируем содержимое сообщения
                if (messageData.role !== 'system') {
                    messageContent = `
                        <div class="message-header">
                            <span class="username">🕵️ ${this.escapeHtml(messageData.username)}</span>
                            <span class="timestamp">[${messageData.timestamp}]</span>
                        </div>
                    `;
                }
                
                if (textContent) {
                    messageContent += `<div class="message-content">${this.escapeHtml(textContent)}</div>`;
                }
                
                if (hasImage && imageContent) {
                    messageContent += `
                        <img src="${imageContent}" class="message-image" alt="Изображение" onclick="showFullscreenImage(this)">
                    `;
                }
                
                if (this.isAdmin && messageData.user_ip && messageData.role !== 'system') {
                    messageContent += `
                        <div class="ip-info admin-ip">IP: ${messageData.user_ip}</div>
                    `;
                }
                
                if (this.isAdmin && !messageData.is_deleted && messageData.role !== 'system') {
                    messageContent += `
                        <div class="message-actions">
                            <button class="btn btn-danger btn-small" onclick="deleteMessage(${messageData.id})">🗑️ Удалить</button>
                        </div>
                    `;
                }
                
                messageDiv.innerHTML = messageContent;
                this.chatMessages.appendChild(messageDiv);
            }
            
            addSystemMessage(text) {
                const messageDiv = document.createElement('div');
                messageDiv.className = 'message system';
                messageDiv.innerHTML = `<div class="message-content">${this.escapeHtml(text)}</div>`;
                this.chatMessages.appendChild(messageDiv);
                this.scrollToBottom();
            }
            
            scrollToBottom() {
                requestAnimationFrame(() => {
                    this.chatMessages.scrollTo({
                        top: this.chatMessages.scrollHeight,
                        behavior: 'smooth'
                    });
                    this.isScrolledToBottom = true;
                });
            }
            
            startAutoRefresh() {
                let roleCheckCounter = 0;
                
                setInterval(() => {
                    this.loadMessages();
                    
                    roleCheckCounter++;
                    if (roleCheckCounter >= 3) {
                        this.checkAndUpdateRole();
                        roleCheckCounter = 0;
                    }
                }, 3000);
            }
            
            startUsersRefresh() {
                this.loadUsers();
                
                this.usersInterval = setInterval(() => {
                    this.loadUsers();
                }, 10000);
            }
            
            startActivityUpdates() {
                setInterval(() => {
                    this.updateActivity();
                }, 60000);
            }
            
            startMuteCheck() {
                this.checkMuteStatus();
                
                this.muteCheckInterval = setInterval(() => {
                    this.checkMuteStatus();
                }, 30000);
            }
            
            startChatStatusCheck() {
                this.checkChatStatus();
                
                this.chatStatusInterval = setInterval(() => {
                    this.checkChatStatus();
                }, 5000);
            }
            
            stopChatStatusCheck() {
                if (this.chatStatusInterval) {
                    clearInterval(this.chatStatusInterval);
                    this.chatStatusInterval = null;
                }
            }
            
            async updateActivity() {
                try {
                    await secureFetch('update_activity');
                } catch (error) {
                    console.error('Ошибка обновления активности:', error);
                }
            }
            
            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        }

        // Глобальные функции для админ-панели
        async function toggleChatWithButton() {
            if (!chat) return;
            
            const currentStatus = chat.chatEnabled;
            await toggleChat(!currentStatus);
        }

        function showUserManagement() {
            document.getElementById('chat-control-section').style.display = 'none';
            document.getElementById('user-management-section').style.display = 'block';
            document.getElementById('ip-management-section').style.display = 'none';
            document.getElementById('ban-management-section').style.display = 'none';
        }

        function showIPManagement() {
            document.getElementById('chat-control-section').style.display = 'none';
            document.getElementById('user-management-section').style.display = 'none';
            document.getElementById('ip-management-section').style.display = 'block';
            document.getElementById('ban-management-section').style.display = 'none';
        }

        function showBanManagement() {
            document.getElementById('chat-control-section').style.display = 'none';
            document.getElementById('user-management-section').style.display = 'none';
            document.getElementById('ip-management-section').style.display = 'none';
            document.getElementById('ban-management-section').style.display = 'block';
            
            if (chat) {
                chat.loadBannedUsers();
                chat.loadBannedIPs();
            }
        }

        // Функции для разбана
        async function unbanUserByName(username) {
            if (!confirm(`Разблокировать пользователя ${username}?`)) return;
            
            try {
                const result = await secureFetch('unban_user', { username: username });
                
                if (result.status === 'success') {
                    showNotification('✅ Пользователь разблокирован', 'success');
                    if (chat) {
                        chat.loadBannedUsers();
                        chat.loadUsers();
                    }
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка разблокировки:', error);
                showNotification('❌ Ошибка разблокировки пользователя', 'error');
            }
        }

        async function unbanIPByAddress(ipAddress) {
            if (!confirm(`Разблокировать IP адрес ${ipAddress}?`)) return;
            
            try {
                const result = await secureFetch('unban_ip', { ip_address: ipAddress });
                
                if (result.status === 'success') {
                    showNotification('✅ IP адрес разблокирован', 'success');
                    if (chat) {
                        chat.loadBannedIPs();
                    }
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка разблокировки IP:', error);
                showNotification('❌ Ошибка разблокировки IP адреса', 'error');
            }
        }

        async function unbanUser() {
            const username = document.getElementById('unban-username').value.trim();
            
            if (!username) {
                showNotification('❌ Введите имя пользователя', 'error');
                return;
            }
            
            await unbanUserByName(username);
            document.getElementById('unban-username').value = '';
        }

        async function unbanIP() {
            const ipAddress = document.getElementById('unban-ip').value.trim();
            
            if (!ipAddress) {
                showNotification('❌ Введите IP адрес', 'error');
                return;
            }
            
            await unbanIPByAddress(ipAddress);
            document.getElementById('unban-ip').value = '';
        }

        function loadBannedUsers() {
            if (chat && typeof chat.loadBannedUsers === 'function') {
                chat.loadBannedUsers();
                showNotification('🔄 Список забаненных пользователей обновлен', 'success');
            }
        }

        function loadBannedIPs() {
            if (chat && typeof chat.loadBannedIPs === 'function') {
                chat.loadBannedIPs();
                showNotification('🔄 Список забаненных IP обновлен', 'success');
            }
        }

        // Функции для работы с пользователями
        async function checkUsernameAvailability() {
            const username = document.getElementById('register-username').value.trim();
            const statusDiv = document.getElementById('username-status');
            
            if (username.length < 3) {
                statusDiv.style.display = 'none';
                return;
            }
            
            statusDiv.style.display = 'block';
            statusDiv.className = 'username-status username-checking';
            statusDiv.textContent = '🔍 Проверка доступности...';
            
            try {
                const result = await secureFetch('check_username', { username });
                
                if (result.status === 'available') {
                    statusDiv.className = 'username-status username-available';
                    statusDiv.innerHTML = '✅ ' + result.message;
                } else if (result.status === 'taken') {
                    statusDiv.className = 'username-status username-taken';
                    statusDiv.innerHTML = '❌ ' + result.message;
                } else {
                    statusDiv.className = 'username-status username-taken';
                    statusDiv.innerHTML = '❌ ' + result.message;
                }
            } catch (error) {
                statusDiv.className = 'username-status username-taken';
                statusDiv.textContent = '❌ Ошибка проверки';
            }
        }

        function showRegisterForm() {
            document.getElementById('login-form').style.display = 'none';
            document.getElementById('register-form').style.display = 'block';
        }

        function showLoginForm() {
            document.getElementById('register-form').style.display = 'none';
            document.getElementById('login-form').style.display = 'block';
        }

        async function login() {
            const username = document.getElementById('login-username').value.trim();
            const password = document.getElementById('login-password').value;
            const rememberMe = document.getElementById('remember-me').checked;
            
            if (!username || !password) {
                showNotification('❌ Заполните все поля', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('login', {
                    username: username,
                    password: password,
                    remember_me: rememberMe ? '1' : '0'
                });
                
                if (result.status === 'success') {
                    showNotification('✅ Вход выполнен успешно!', 'success');
                    
                    if (rememberMe) {
                        localStorage.setItem('chat_authenticated', 'true');
                        localStorage.setItem('chat_username', username);
                        localStorage.setItem('chat_role', result.role);
                    } else {
                        sessionStorage.setItem('chat_authenticated', 'true');
                        sessionStorage.setItem('chat_username', username);
                        sessionStorage.setItem('chat_role', result.role);
                    }
                    
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                    
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка входа:', error);
                showNotification('❌ Ошибка соединения с сервером', 'error');
            }
        }

        async function register() {
            const username = document.getElementById('register-username').value.trim();
            const password = document.getElementById('register-password').value;
            const email = document.getElementById('register-email').value.trim();
            
            if (!username || !password) {
                showNotification('❌ Заполните обязательные поля', 'error');
                return;
            }
            
            if (username.length < 3 || username.length > 20) {
                showNotification('❌ Имя пользователя должно быть от 3 до 20 символов', 'error');
                return;
            }
            
            if (password.length < 6) {
                showNotification('❌ Пароль должен быть не менее 6 символов', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('register', {
                    username: username,
                    password: password,
                    email: email
                });
                
                if (result.status === 'success') {
                    showNotification('✅ Регистрация успешна! Вы будете автоматически авторизованы.', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка регистрации:', error);
                showNotification('❌ Ошибка соединения с сервером', 'error');
            }
        }

        async function logout() {
            try {
                await secureFetch('logout');
                
                localStorage.removeItem('chat_authenticated');
                localStorage.removeItem('chat_username');
                localStorage.removeItem('chat_role');
                sessionStorage.removeItem('chat_authenticated');
                sessionStorage.removeItem('chat_username');
                sessionStorage.removeItem('chat_role');
                
                location.reload();
            } catch (error) {
                console.error('Ошибка выхода:', error);
                showNotification('❌ Ошибка выхода из системы', 'error');
            }
        }

        function showChangePassword() {
            document.getElementById('change-password-form').style.display = 'block';
        }

        function hideChangePassword() {
            document.getElementById('change-password-form').style.display = 'none';
        }

        async function changePassword() {
            const currentPassword = document.getElementById('current-password').value;
            const newPassword = document.getElementById('new-password').value;
            
            if (!currentPassword || !newPassword) {
                showNotification('❌ Заполните все поля', 'error');
                return;
            }
            
            if (newPassword.length < 6) {
                showNotification('❌ Новый пароль должен быть не менее 6 символов', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('change_password', {
                    current_password: currentPassword,
                    new_password: newPassword
                });
                
                if (result.status === 'success') {
                    showNotification('✅ Пароль успешно изменен', 'success');
                    hideChangePassword();
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка смены пароля:', error);
                showNotification('❌ Ошибка смены пароля', 'error');
            }
        }

        // Функции для работы с сообщениями
        async function deleteMessage(messageId) {
            if (!confirm('🗑️ Удалить это сообщение?')) return;
            
            try {
                await secureFetch('delete_message', { message_id: messageId });
                if (chat) chat.loadMessages();
                showNotification('✅ Сообщение удалено', 'success');
            } catch (error) {
                console.error('Ошибка удаления:', error);
                showNotification('❌ Ошибка удаления сообщения', 'error');
            }
        }

        // Функции админ-панели
        async function banUser() {
            const username = document.getElementById('ban-username').value.trim();
            const reason = document.getElementById('ban-reason').value.trim();
            
            if (!username || !reason) {
                showNotification('❌ Заполните все поля', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('ban_user', {
                    username: username,
                    reason: reason
                });
                
                if (result.status === 'success') {
                    showNotification('✅ Пользователь заблокирован', 'success');
                    document.getElementById('ban-username').value = '';
                    document.getElementById('ban-reason').value = '';
                    if (chat) {
                        chat.loadBannedUsers();
                        chat.loadUsers();
                    }
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка блокировки:', error);
                showNotification('❌ Ошибка блокировки пользователя', 'error');
            }
        }

        async function muteUser() {
            const username = document.getElementById('mute-username').value.trim();
            const duration = document.getElementById('mute-duration').value;
            const reason = document.getElementById('mute-reason').value.trim();
            
            if (!username || !reason) {
                showNotification('❌ Заполните все поля', 'error');
                return;
            }
            
            if (duration === '' || duration < 0) {
                showNotification('❌ Укажите корректную длительность мута', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('mute_user', {
                    username: username,
                    duration: duration,
                    reason: reason
                });
                
                if (result.status === 'success') {
                    showNotification('✅ Пользователь замьючен', 'success');
                    document.getElementById('mute-username').value = '';
                    document.getElementById('mute-duration').value = '';
                    document.getElementById('mute-reason').value = '';
                    if (chat) chat.loadUsers();
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка мута:', error);
                showNotification('❌ Ошибка мута пользователя', 'error');
            }
        }

        async function unmuteUser() {
            const username = document.getElementById('unmute-username').value.trim();
            
            if (!username) {
                showNotification('❌ Введите имя пользователя', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('unmute_user', { username: username });
                
                if (result.status === 'success') {
                    showNotification('✅ Пользователь размучен', 'success');
                    document.getElementById('unmute-username').value = '';
                    if (chat) chat.loadUsers();
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка размута:', error);
                showNotification('❌ Ошибка размута пользователя', 'error');
            }
        }

        async function getUserInfo() {
            const username = document.getElementById('user-info-username').value.trim();
            const displayDiv = document.getElementById('user-info-display');
            
            if (!username) {
                showNotification('❌ Введите имя пользователя', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('get_user_info', { username: username });
                
                if (result.status === 'success') {
                    const user = result.user_info;
                    displayDiv.style.display = 'block';
                    
                    let muteInfo = 'Нет';
                    if (user.mute_reason) {
                        const muteExpires = user.mute_expires ? new Date(user.mute_expires).toLocaleString() : 'Навсегда';
                        muteInfo = `Да (Причина: ${user.mute_reason}, Истекает: ${muteExpires}, Замутил: ${user.muted_by})`;
                    }
                    
                    displayDiv.innerHTML = `
                        <h4>Информация об агенте: ${user.username}</h4>
                        <div class="user-info-row">
                            <span class="user-info-label">Роль:</span>
                            <span class="user-info-value">${user.role}</span>
                        </div>
                        <div class="user-info-row">
                            <span class="user-info-label">IP регистрации:</span>
                            <span class="user-info-value">${user.registration_ip}</span>
                        </div>
                        <div class="user-info-row">
                            <span class="user-info-label">Последний IP:</span>
                            <span class="user-info-value">${user.last_ip}</span>
                        </div>
                        <div class="user-info-row">
                            <span class="user-info-label">Последняя активность:</span>
                            <span class="user-info-value">${user.last_activity}</span>
                        </div>
                        <div class="user-info-row">
                            <span class="user-info-label">Заблокирован:</span>
                            <span class="user-info-value">${user.is_banned ? 'Да' : 'Нет'}</span>
                        </div>
                        <div class="user-info-row">
                            <span class="user-info-label">В муте:</span>
                            <span class="user-info-value">${muteInfo}</span>
                        </div>
                    `;
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка получения информации:', error);
                showNotification('❌ Ошибка получения информации', 'error');
            }
        }

        async function banIP() {
            const ip = document.getElementById('ban-ip').value.trim();
            const reason = document.getElementById('ban-ip-reason').value.trim();
            
            if (!ip || !reason) {
                showNotification('❌ Заполните все поля', 'error');
                return;
            }
            
            try {
                const result = await secureFetch('ban_ip', {
                    ip_address: ip,
                    reason: reason
                });
                
                if (result.status === 'success') {
                    showNotification('✅ IP адрес заблокирован', 'success');
                    document.getElementById('ban-ip').value = '';
                    document.getElementById('ban-ip-reason').value = '';
                    if (chat) chat.loadBannedIPs();
                } else {
                    showNotification('❌ ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Ошибка блокировки IP:', error);
                showNotification('❌ Ошибка блокировки IP', 'error');
            }
        }

        async function toggleChat(enabled) {
            const button = enabled ? document.getElementById('enable-chat-btn') : document.getElementById('disable-chat-btn');
            const originalText = button.textContent;
            
            try {
                button.disabled = true;
                button.textContent = '⏳ Обработка...';
                
                const result = await secureFetch('toggle_chat', { enabled: enabled });
                
                if (result.status === 'success') {
                    if (chat) {
                        chat.updateChatStatus(result.enabled);
                    }
                    showNotification(result.message, 'success');
                    if (chat) {
                        chat.addSystemMessage(`⚡ Администратор ${enabled ? 'включил' : 'отключил'} чат`);
                    }
                } else {
                    showNotification(result.message, 'error');
                }
                
            } catch (error) {
                console.error('Ошибка переключения чата:', error);
                showNotification('❌ Ошибка соединения с сервером', 'error');
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        }

        // Функции для работы со списком пользователей
        function loadUsers() {
            if (chat && typeof chat.loadUsers === 'function') {
                chat.loadUsers();
                showNotification('👥 Список пользователей обновлен', 'success');
            }
        }

        function setUsersFilter(filter) {
            if (chat && typeof chat.setUsersFilter === 'function') {
                chat.setUsersFilter(filter);
            }
        }

        // Функция уведомлений
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `notification ${type === 'error' ? 'notification-error' : ''}`;
            notification.innerHTML = `
                <div class="notification-content">
                    <span class="notification-icon">${type === 'success' ? '✅' : '❌'}</span>
                    <span class="notification-text">${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in forwards';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Инициализация чата
        document.addEventListener('DOMContentLoaded', () => {
            chat = new SecurePHPChat();
        });
    </script>
</body>
</html>