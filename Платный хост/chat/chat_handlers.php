<?php
// chat/chat_handlers.php - обработчики чата

// Отправка сообщения
if ($_POST['action'] === 'send_message' && isset($_SESSION['user_id'])) {
    // Проверяем, не забанен ли пользователь
    if (!shouldUserSeeMessages($pdo, $_SESSION['user_id'], $_SESSION['username'])) {
        echo json_encode(['status' => 'error', 'message' => 'Ваш доступ к чату ограничен']);
        exit;
    }
    
    if (!isChatEnabled($pdo)) {
        echo json_encode(['status' => 'error', 'message' => 'Чат временно отключен администратором']);
        exit;
    }
    
    if (isUserBanned($pdo, $_SESSION['username'])) {
        echo json_encode(['status' => 'error', 'message' => 'Ваш аккаунт заблокирован']);
        exit;
    }
    
    // Проверка мута
    if ($mute_info = isUserMuted($pdo, $_SESSION['user_id'])) {
        $time_left = getMuteTimeLeft($pdo, $_SESSION['user_id']);
        if ($time_left > 0) {
            $minutes = floor($time_left / 60);
            $seconds = $time_left % 60;
            echo json_encode(['status' => 'error', 'message' => 'Вы в муте. Осталось: ' . $minutes . 'м ' . $seconds . 'с']);
            exit;
        }
    }
    
    $message = trim($_POST['message'] ?? '');
    $image_data = $_POST['image_data'] ?? '';
    
    // Разрешаем отправку либо текста, либо изображения, либо обоих
    if (empty($message) && empty($image_data)) {
        echo json_encode(['status' => 'error', 'message' => 'Сообщение не может быть полностью пустым']);
        exit;
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
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($_POST['action'] === 'update_notification_settings') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Необходима авторизация']);
        exit;
    }
    
    $notifications_enabled = $_POST['notifications_enabled'] === 'true' || $_POST['notifications_enabled'] === true;
    
    // Сохраняем настройки в сессии
    $_SESSION['notifications_enabled'] = $notifications_enabled;
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Настройки уведомлений обновлены',
        'notifications_enabled' => $notifications_enabled
    ]);
    exit;
}

// Получение сообщений
if ($_POST['action'] === 'get_messages') {
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
    
    // Фильтрация сообщений для забаненных пользователей
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        $messages = filterMessagesForUser($pdo, $messages, $_SESSION['user_id'], $_SESSION['username']);
    }
    
    echo json_encode($messages);
    exit;
}

// Получение списка пользователей
if ($_POST['action'] === 'get_users') {
    $users = getUsersList($pdo);
    echo json_encode($users);
    exit;
}

// Получение статуса чата
if ($_POST['action'] === 'get_chat_status') {
    echo json_encode(['enabled' => isChatEnabled($pdo)]);
    exit;
}

// Проверка авторизации
if ($_POST['action'] === 'check_auth') {
    if (isset($_SESSION['user_id'])) {
        // Проверяем мут
        $mute_info = isUserMuted($pdo, $_SESSION['user_id']);
        $mute_time_left = $mute_info ? getMuteTimeLeft($pdo, $_SESSION['user_id']) : 0;
        
        echo json_encode([
            'authenticated' => true,
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'muted' => $mute_info && $mute_time_left > 0,
            'mute_time_left' => $mute_time_left
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

// Обновление активности
if ($_POST['action'] === 'update_activity' && isset($_SESSION['user_id'])) {
    updateUserActivity($pdo, $_SESSION['user_id']);
    echo json_encode(['status' => 'success']);
    exit;
}
?>