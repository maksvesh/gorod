<?php
// chat/auth_handlers.php - обработчики аутентификации

// Включаем файлы конфигурации
require_once 'config.php';
require_once 'auth.php';

// Если это POST запрос с действием, отключаем вывод ошибок на экран
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ini_set('display_errors', 0);
}

// Проверка свободного логина
if ($_POST['action'] === 'check_username') {
    $username = trim($_POST['username'] ?? '');
    
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
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
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
    
    try {
        $stmt = $pdo->prepare("INSERT INTO agent_users (username, password, email, registration_ip, last_ip, last_activity, role) VALUES (?, ?, ?, ?, ?, NOW(), 'user')");
        $stmt->execute([$username, $hashed_password, $email, $registration_ip, $registration_ip]);
        
        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'user';
        $_SESSION['login_time'] = time();
        
        sendJsonResponse(['status' => 'success', 'message' => 'Регистрация успешна']);
    } catch (PDOException $e) {
        error_log("Ошибка регистрации: " . $e->getMessage());
        sendJsonResponse(['status' => 'error', 'message' => 'Ошибка регистрации']);
    }
}

// Авторизация
if ($_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        sendJsonResponse(['status' => 'error', 'message' => 'Заполните все поля']);
    }
    
    if (strlen($username) < 3 || strlen($username) > 20) {
        sendJsonResponse(['status' => 'error', 'message' => 'Имя пользователя должно быть от 3 до 20 символов']);
    }
    
    // Проверка бана пользователя
    if (isUserBanned($pdo, $username)) {
        sendJsonResponse(['status' => 'error', 'message' => 'Ваш аккаунт заблокирован']);
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM agent_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Обновляем последний IP и активность
            $current_ip = getClientIP();
            $stmt = $pdo->prepare("UPDATE agent_users SET last_ip = ?, last_activity = NOW() WHERE id = ?");
            $stmt->execute([$current_ip, $user['id']]);
            
            // Устанавливаем сессию
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
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
    } catch (PDOException $e) {
        error_log("Ошибка авторизации: " . $e->getMessage());
        sendJsonResponse(['status' => 'error', 'message' => 'Ошибка сервера при авторизации']);
    }
}

// Проверка статуса авторизации
if ($_POST['action'] === 'check_auth') {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT username, role, is_muted, mute_expires FROM agent_users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Проверяем мут
                $isMuted = false;
                $muteTimeLeft = 0;
                
                if ($user['is_muted'] && $user['mute_expires']) {
                    $now = time();
                    $muteExpires = strtotime($user['mute_expires']);
                    if ($muteExpires > $now) {
                        $isMuted = true;
                        $muteTimeLeft = $muteExpires - $now;
                    } else {
                        // Снимаем мут если время истекло
                        $stmt = $pdo->prepare("UPDATE agent_users SET is_muted = 0, mute_expires = NULL WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                    }
                }
                
                sendJsonResponse([
                    'status' => 'success',
                    'authenticated' => true,
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'muted' => $isMuted,
                    'mute_time_left' => $muteTimeLeft
                ]);
            } else {
                // Пользователь не найден в базе
                session_destroy();
                sendJsonResponse([
                    'status' => 'success',
                    'authenticated' => false
                ]);
            }
        } catch (PDOException $e) {
            error_log("Ошибка проверки авторизации: " . $e->getMessage());
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Ошибка проверки авторизации'
            ]);
        }
    } else {
        sendJsonResponse([
            'status' => 'success', 
            'authenticated' => false
        ]);
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
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (strlen($new_password) < 6) {
        sendJsonResponse(['status' => 'error', 'message' => 'Новый пароль должен быть не менее 6 символов']);
    }
    
    try {
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
    } catch (PDOException $e) {
        error_log("Ошибка смены пароля: " . $e->getMessage());
        sendJsonResponse(['status' => 'error', 'message' => 'Ошибка смены пароля']);
    }
}

// Проверка и обновление роли пользователя
if ($_POST['action'] === 'check_and_update_role') {
    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse(['status' => 'error', 'message' => 'Не авторизован']);
    }
    
    try {
        $stmt = $pdo->prepare("SELECT role FROM agent_users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if ($user && $user['role'] !== $_SESSION['role']) {
            // Роль изменилась - обновляем сессию
            $_SESSION['role'] = $user['role'];
            
            sendJsonResponse([
                'status' => 'success',
                'role' => $user['role'],
                'message' => 'Роль обновлена'
            ]);
        } else {
            sendJsonResponse([
                'status' => 'success',
                'role' => $_SESSION['role'],
                'message' => 'Роль не изменилась'
            ]);
        }
    } catch (PDOException $e) {
        error_log("Ошибка проверки роли: " . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Ошибка проверки роли'
        ]);
    }
}
?>