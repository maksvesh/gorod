<?php
// chat/admin_functions.php - админские функции

function isIPBanned($pdo, $ip) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM agent_banned_ips WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$ip]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Ошибка проверки бана IP: " . $e->getMessage());
        return false;
    }
}

// Функция получения списка забаненных пользователей
function getBannedUsers($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT username, role, registration_ip, last_ip, last_activity 
            FROM agent_users 
            WHERE is_banned = TRUE 
            ORDER BY username
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения списка забаненных пользователей: " . $e->getMessage());
        return [];
    }
}

// Функция получения списка забаненных IP
function getBannedIPs($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT ip_address, reason, banned_by, banned_at, expires_at 
            FROM agent_banned_ips 
            WHERE expires_at IS NULL OR expires_at > NOW()
            ORDER BY banned_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения списка забаненных IP: " . $e->getMessage());
        return [];
    }
}

// Функция обновления роли в сессии
function updateUserRoleInSession($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT role FROM agent_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user && isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
            $_SESSION['role'] = $user['role'];
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log("Ошибка обновления роли в сессии: " . $e->getMessage());
        return false;
    }
}

// Функция добавления системного сообщения о блокировке
function addBlockSystemMessage($pdo, $username, $reason) {
    try {
        $message = "🚫 Пользователь {$username} был заблокирован. Причина: {$reason}";
        $stmt = $pdo->prepare("INSERT INTO agent_chat (username, message, user_ip, is_system) VALUES (?, ?, ?, 1)");
        return $stmt->execute(['system', $message, 'system']);
    } catch (PDOException $e) {
        error_log("Ошибка добавления системного сообщения о блокировке: " . $e->getMessage());
        return false;
    }
}

// Функция добавления системного сообщения о разблокировке
function addUnblockSystemMessage($pdo, $username) {
    try {
        $message = "✅ Пользователь {$username} был разблокирован.";
        $stmt = $pdo->prepare("INSERT INTO agent_chat (username, message, user_ip, is_system) VALUES (?, ?, ?, 1)");
        return $stmt->execute(['system', $message, 'system']);
    } catch (PDOException $e) {
        error_log("Ошибка добавления системного сообщения о разблокировке: " . $e->getMessage());
        return false;
    }
}
?>