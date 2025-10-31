<?php
// chat/admin_handlers.php - обработчики админских действий

// Проверка прав администратора
function checkAdminRights() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']);
        exit;
    }
}

// Удаление сообщения (только для админов)
if ($_POST['action'] === 'delete_message') {
    checkAdminRights();
    
    $message_id = intval($_POST['message_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE agent_chat SET is_deleted = TRUE WHERE id = ?");
    $stmt->execute([$message_id]);
    echo json_encode(['status' => 'success']);
    exit;
}

// Бан пользователя (только для админов)
if ($_POST['action'] === 'ban_user') {
    checkAdminRights();
    
    $username = trim($_POST['username'] ?? '');
    $reason = htmlspecialchars(trim($_POST['reason'] ?? ''));
    
    $stmt = $pdo->prepare("UPDATE agent_users SET is_banned = TRUE WHERE username = ?");
    $stmt->execute([$username]);
    
    // Добавляем системное сообщение о блокировке
    addBlockSystemMessage($pdo, $username, $reason);
    
    echo json_encode(['status' => 'success', 'message' => 'Пользователь заблокирован']);
    exit;
}

// Разбан пользователя (только для админов)
if ($_POST['action'] === 'unban_user') {
    checkAdminRights();
    
    $username = trim($_POST['username'] ?? '');
    
    $stmt = $pdo->prepare("UPDATE agent_users SET is_banned = FALSE WHERE username = ?");
    $stmt->execute([$username]);
    
    // Добавляем системное сообщение о разблокировке
    addUnblockSystemMessage($pdo, $username);
    
    echo json_encode(['status' => 'success', 'message' => 'Пользователь разблокирован']);
    exit;
}

// Мут пользователя (только для админов)
if ($_POST['action'] === 'mute_user') {
    checkAdminRights();
    
    $username = trim($_POST['username'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);
    $reason = htmlspecialchars(trim($_POST['reason'] ?? ''));
    
    // Получаем ID пользователя
    $stmt = $pdo->prepare("SELECT id FROM agent_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Пользователь не найден']);
        exit;
    }
    
    $mute_expires = $duration > 0 ? date('Y-m-d H:i:s', strtotime("+$duration minutes")) : null;
    
    // Удаляем старый мут если есть
    $stmt = $pdo->prepare("DELETE FROM agent_muted_users WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    
    // Добавляем новый мут
    $stmt = $pdo->prepare("INSERT INTO agent_muted_users (user_id, muted_by, mute_reason, mute_expires) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user['id'], $_SESSION['username'], $reason, $mute_expires]);
    
    echo json_encode(['status' => 'success', 'message' => 'Пользователь замьючен']);
    exit;
}

// Размут пользователя (только для админов)
if ($_POST['action'] === 'unmute_user') {
    checkAdminRights();
    
    $username = trim($_POST['username'] ?? '');
    
    // Получаем ID пользователя
    $stmt = $pdo->prepare("SELECT id FROM agent_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Пользователь не найден']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM agent_muted_users WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    
    echo json_encode(['status' => 'success', 'message' => 'Пользователь размучен']);
    exit;
}

// Получение информации о муте
if ($_POST['action'] === 'get_mute_info' && isset($_SESSION['user_id'])) {
    if ($mute_info = isUserMuted($pdo, $_SESSION['user_id'])) {
        $time_left = getMuteTimeLeft($pdo, $_SESSION['user_id']);
        echo json_encode([
            'muted' => true,
            'reason' => $mute_info['mute_reason'],
            'time_left' => $time_left,
            'muted_by' => $mute_info['muted_by'],
            'muted_at' => $mute_info['muted_at']
        ]);
    } else {
        echo json_encode(['muted' => false]);
    }
    exit;
}

// Бан по IP (только для админов)
if ($_POST['action'] === 'ban_ip') {
    checkAdminRights();
    
    $ip_address = trim($_POST['ip_address'] ?? '');
    $reason = htmlspecialchars(trim($_POST['reason'] ?? ''));
    
    $stmt = $pdo->prepare("INSERT INTO agent_banned_ips (ip_address, reason, banned_by) VALUES (?, ?, ?)");
    $stmt->execute([$ip_address, $reason, $_SESSION['username']]);
    
    echo json_encode(['status' => 'success', 'message' => 'IP адрес заблокирован']);
    exit;
}

// Разбан по IP (только для админов)
if ($_POST['action'] === 'unban_ip') {
    checkAdminRights();
    
    $ip_address = trim($_POST['ip_address'] ?? '');
    
    $stmt = $pdo->prepare("DELETE FROM agent_banned_ips WHERE ip_address = ?");
    $stmt->execute([$ip_address]);
    
    echo json_encode(['status' => 'success', 'message' => 'IP адрес разблокирован']);
    exit;
}

// Получение информации о пользователе для админов
if ($_POST['action'] === 'get_user_info') {
    checkAdminRights();
    
    $username = trim($_POST['username'] ?? '');
    
    $stmt = $pdo->prepare("
        SELECT u.username, u.role, u.registration_ip, u.last_ip, u.last_activity, 
               u.is_banned, m.mute_reason, m.muted_by, m.mute_expires
        FROM agent_users u 
        LEFT JOIN agent_muted_users m ON u.id = m.user_id AND (m.mute_expires IS NULL OR m.mute_expires > NOW())
        WHERE u.username = ?
    ");
    $stmt->execute([$username]);
    $user_info = $stmt->fetch();
    
    if ($user_info) {
        echo json_encode(['status' => 'success', 'user_info' => $user_info]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Пользователь не найден']);
    }
    exit;
}

// Включение/отключение чата (только для админов)
if ($_POST['action'] === 'toggle_chat') {
    checkAdminRights();
    
    $enabled = $_POST['enabled'] === 'true' || $_POST['enabled'] === true;
    
    if (setChatEnabled($pdo, $enabled)) {
        $status = $enabled ? 'включен' : 'отключен';
        echo json_encode([
            'status' => 'success', 
            'message' => 'Чат успешно ' . $status,
            'enabled' => $enabled
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка изменения статуса чата']);
    }
    exit;
}

// Получение списка забаненных пользователей
if ($_POST['action'] === 'get_banned_users') {
    checkAdminRights();
    
    $users = getBannedUsers($pdo);
    echo json_encode($users);
    exit;
}

// Получение списка забаненных IP
if ($_POST['action'] === 'get_banned_ips') {
    checkAdminRights();
    
    $ips = getBannedIPs($pdo);
    echo json_encode($ips);
    exit;
}

// Проверка и обновление роли пользователя
if ($_POST['action'] === 'check_and_update_role') {
    if (isset($_SESSION['user_id'])) {
        if (updateUserRoleInSession($pdo, $_SESSION['user_id'])) {
            echo json_encode([
                'status' => 'success', 
                'role' => $_SESSION['role'],
                'username' => $_SESSION['username']
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка обновления роли']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Пользователь не авторизован']);
    }
    exit;
}
?>