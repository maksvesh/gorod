<?php
// chat/chat_functions.php - функции чата

function isUserMuted($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM agent_muted_users WHERE user_id = ? AND (mute_expires IS NULL OR mute_expires > NOW())");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Ошибка проверки мута: " . $e->getMessage());
        return false;
    }
}

function getMuteTimeLeft($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), mute_expires) as seconds_left FROM agent_muted_users WHERE user_id = ? AND mute_expires > NOW()");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return $result ? $result['seconds_left'] : 0;
    } catch (PDOException $e) {
        error_log("Ошибка получения времени мута: " . $e->getMessage());
        return 0;
    }
}

function isChatEnabled($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM agent_chat_settings WHERE setting_key = 'chat_enabled'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result && $result['setting_value'] == '1';
    } catch (PDOException $e) {
        error_log("Ошибка проверки статуса чата: " . $e->getMessage());
        return true; // По умолчанию чат включен
    }
}

function setChatEnabled($pdo, $enabled) {
    try {
        $value = $enabled ? '1' : '0';
        $stmt = $pdo->prepare("UPDATE agent_chat_settings SET setting_value = ? WHERE setting_key = 'chat_enabled'");
        return $stmt->execute([$value]);
    } catch (PDOException $e) {
        error_log("Ошибка изменения статуса чата: " . $e->getMessage());
        return false;
    }
}

// Функция получения списка пользователей
function getUsersList($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT username, role, last_activity, is_banned, registration_ip, last_ip,
                   CASE 
                       WHEN last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'online'
                       ELSE 'offline'
                   END as status
            FROM agent_users 
            WHERE is_banned = FALSE 
            ORDER BY 
                CASE 
                    WHEN last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 
                    ELSE 2 
                END,
                username
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения списка пользователей: " . $e->getMessage());
        return [];
    }
}

// Функция проверки, должен ли пользователь видеть сообщения
function shouldUserSeeMessages($pdo, $user_id, $username) {
    // Проверка бана по никнейму
    if (isUserBanned($pdo, $username)) {
        return false;
    }
    
    // Проверка бана по IP
    $client_ip = getClientIP();
    if (isIPBanned($pdo, $client_ip)) {
        return false;
    }
    
    return true;
}

// Функция фильтрации сообщений для забаненных пользователей
function filterMessagesForUser($pdo, $messages, $user_id, $username) {
    if (shouldUserSeeMessages($pdo, $user_id, $username)) {
        return $messages;
    }
    
    // Если пользователь забанен, возвращаем только системные сообщения
    $filtered_messages = [];
    foreach ($messages as $message) {
        // Оставляем только системные сообщения или сообщения о блокировке
        if ($message['username'] === 'system' || strpos($message['message'], 'заблокирован') !== false) {
            $filtered_messages[] = $message;
        }
    }
    
    // Добавляем сообщение о блокировке
    if (empty($filtered_messages)) {
        $filtered_messages[] = [
            'id' => 0,
            'username' => 'system',
            'message' => '🔒 Ваш доступ к чату ограничен. Вы не можете видеть сообщения других пользователей.',
            'timestamp' => date('H:i:s'),
            'is_deleted' => false,
            'role' => 'system'
        ];
    }
    
    return $filtered_messages;
}
?>