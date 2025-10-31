<?php
// chat/auth.php - функции аутентификации

// Функция проверки авторизации
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Функция обновления активности
function updateUserActivity($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE agent_users SET last_activity = NOW() WHERE id = ?");
        $stmt->execute([$user_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Ошибка обновления активности: " . $e->getMessage());
        return false;
    }
}

// Обновляем активность пользователя если авторизован
if (isLoggedIn()) {
    updateUserActivity($pdo, $_SESSION['user_id']);
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

function isUsernameTaken($pdo, $username) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM agent_users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Ошибка проверки имени пользователя: " . $e->getMessage());
        return false;
    }
}

function isUserBanned($pdo, $username) {
    try {
        $stmt = $pdo->prepare("SELECT is_banned FROM agent_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        return $user && $user['is_banned'];
    } catch (PDOException $e) {
        error_log("Ошибка проверки бана пользователя: " . $e->getMessage());
        return false;
    }
}
?>