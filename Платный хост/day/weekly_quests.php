<?php
// weekly_quests.php - Полная система управления заданиями с онлайн статусом

// Отключаем вывод ошибок для пользователя, но логируем их
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Функция для безопасного вывода JSON
function sendJsonResponse($data) {
    // Очищаем буфер вывода на случай, если есть лишние данные
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Данные для подключения к базе данных
$servername = "localhost";
$username = "host1882872";
$password = "6IP9PTP2TC";
$dbname = "host1882872_day";

// Создание подключения
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        sendJsonResponse(["success" => false, "error" => "Database connection failed"]);
    }
    
    // Устанавливаем кодировку
    $conn->set_charset("utf8");
    
} catch (Exception $e) {
    sendJsonResponse(["success" => false, "error" => "Database connection error"]);
}

// Функция для установки пользователя онлайн
function setUserOnline($conn, $user_id) {
    $sql = "UPDATE users SET is_online = TRUE, last_activity = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// Функция для установки пользователя оффлайн
function setUserOffline($conn, $user_id) {
    $sql = "UPDATE users SET is_online = FALSE WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// Функция для проверки статуса онлайн
function isUserOnline($conn, $user_id) {
    $sql = "SELECT is_online FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    return $user && $user['is_online'];
}

// Функция для автоматического установки оффлайн статуса при долгом бездействии
function updateOnlineStatus($conn) {
    // Устанавливаем оффлайн для пользователей, которые не активны более 30 минут
    $sql = "UPDATE users SET is_online = FALSE WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND is_online = TRUE";
    $result = $conn->query($sql);
    return $result;
}

// Функция для синхронизации уровня одного пользователя
function syncUserLevel($conn, $user_id) {
    $levelRequirements = [
        1 => 0,
        2 => 500,
        3 => 1200,
        4 => 2500,
        5 => 4500,
        6 => 6000,
        7 => 8900,
        8 => 12000,
        9 => 16000,
        10 => 20000
    ];
    
    // Получаем XP пользователя
    $user_sql = "SELECT xp, level FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result ? $user_result->fetch_assoc() : null;
    $user_stmt->close();
    
    if (!$user) {
        return false;
    }
    
    $correct_level = 1;
    $user_xp = $user['xp'];
    
    // Определяем правильный уровень на основе XP
    for ($level = 10; $level >= 1; $level--) {
        if ($user_xp >= $levelRequirements[$level]) {
            $correct_level = $level;
            break;
        }
    }
    
    // Если уровень не совпадает, обновляем
    if ($user['level'] != $correct_level) {
        $update_sql = "UPDATE users SET level = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $correct_level, $user_id);
        $update_stmt->execute();
        $update_stmt->close();
        return true;
    }
    
    return false;
}

// Функция для синхронизации уровней пользователей в лидерборде
function syncLeaderboardLevels($conn) {
    $levelRequirements = [
        1 => 0,
        2 => 500,
        3 => 1200,
        4 => 2500,
        5 => 4500,
        6 => 6000,
        7 => 8900,
        8 => 12000,
        9 => 16000,
        10 => 20000
    ];
    
    // Получаем топ-50 пользователей для синхронизации
    $users_sql = "SELECT id, xp, level FROM users ORDER BY xp DESC LIMIT 50";
    $users_result = $conn->query($users_sql);
    
    if (!$users_result) {
        return false;
    }
    
    $updated_count = 0;
    
    while ($user = $users_result->fetch_assoc()) {
        $correct_level = 1;
        $user_xp = $user['xp'];
        
        // Определяем правильный уровень на основе XP
        for ($level = 10; $level >= 1; $level--) {
            if ($user_xp >= $levelRequirements[$level]) {
                $correct_level = $level;
                break;
            }
        }
        
        // Если уровень не совпадает, обновляем
        if ($user['level'] != $correct_level) {
            $update_sql = "UPDATE users SET level = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $correct_level, $user['id']);
            $update_stmt->execute();
            $update_stmt->close();
            $updated_count++;
        }
    }
    
    return $updated_count;
}

// Автоматически создаем таблицы если их нет
function initializeDatabase($conn) {
    $tables = [
        "users" => "CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            firstname VARCHAR(100),
            lastname VARCHAR(100),
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            vk_link VARCHAR(255),
            level INT DEFAULT 1,
            xp INT DEFAULT 0,
            completed_quests INT DEFAULT 0,
            last_login TIMESTAMP NULL,
            last_activity TIMESTAMP NULL,
            is_online BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "weekly_quests" => "CREATE TABLE IF NOT EXISTS weekly_quests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            mission_type VARCHAR(100) DEFAULT 'snake_game',
            required_level INT DEFAULT 1,
            target_score INT DEFAULT 30,
            reward_xp INT NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "user_quests" => "CREATE TABLE IF NOT EXISTS user_quests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            quest_id INT NOT NULL,
            status ENUM('active', 'completed', 'failed') DEFAULT 'active',
            progress INT DEFAULT 0,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            UNIQUE KEY unique_user_quest (user_id, quest_id)
        )",
        
        "snake_game_progress" => "CREATE TABLE IF NOT EXISTS snake_game_progress (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            quest_id INT NOT NULL,
            best_score INT DEFAULT 0,
            current_score INT DEFAULT 0,
            games_played INT DEFAULT 0,
            last_played TIMESTAMP NULL,
            UNIQUE KEY unique_user_quest_game (user_id, quest_id)
        )",
        
        "tetris_game_progress" => "CREATE TABLE IF NOT EXISTS tetris_game_progress (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            quest_id INT NOT NULL,
            best_score INT DEFAULT 0,
            current_score INT DEFAULT 0,
            games_played INT DEFAULT 0,
            last_played TIMESTAMP NULL,
            UNIQUE KEY unique_user_quest_tetris (user_id, quest_id)
        )",
        
        "click_game_progress" => "CREATE TABLE IF NOT EXISTS click_game_progress (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            quest_id INT NOT NULL,
            best_score INT DEFAULT 0,
            current_score INT DEFAULT 0,
            games_played INT DEFAULT 0,
            last_played TIMESTAMP NULL,
            UNIQUE KEY unique_user_quest_click (user_id, quest_id)
        )"
    ];
    
    foreach ($tables as $sql) {
        if (!$conn->query($sql)) {
            error_log("Table creation error: " . $conn->error);
        }
    }
    
    // Добавляем новые поля в таблицу users, если их нет
    $check_columns = [
        'firstname' => "ALTER TABLE users ADD COLUMN firstname VARCHAR(100) AFTER id",
        'lastname' => "ALTER TABLE users ADD COLUMN lastname VARCHAR(100) AFTER firstname",
        'vk_link' => "ALTER TABLE users ADD COLUMN vk_link VARCHAR(255) AFTER password",
        'last_activity' => "ALTER TABLE users ADD COLUMN last_activity TIMESTAMP NULL AFTER last_login",
        'is_online' => "ALTER TABLE users ADD COLUMN is_online BOOLEAN DEFAULT FALSE AFTER last_activity"
    ];
    
    foreach ($check_columns as $column => $sql) {
        $result = $conn->query("SHOW COLUMNS FROM users LIKE '$column'");
        if ($result && $result->num_rows == 0) {
            $conn->query($sql);
        }
    }
    
    // Создаем стандартные задания (Змейка, Тетрис и Кликер-игра)
    $default_quests = [
        // Змейка задания
        ['Змейка-новичок', 'Наберите 10 очков в игре Змейка. Освойте базовые механики управления!', 'snake_game', 1, 10, 50],
        ['Аркадный чемпион', 'Наберите 30 очков в классической игре "Змейка". Собирайте зеленые яблоки для увеличения счета!', 'snake_game', 1, 30, 200],
        ['Змейка-профи', 'Наберите 50 очков в игре Змейка. Покажите свое мастерство!', 'snake_game', 2, 50, 300],
        ['Змейка-мастер', 'Наберите 100 очков в игре Змейка. Станьте настоящим чемпионом!', 'snake_game', 3, 100, 500],
        
        // Тетрис задания
        ['Тетрис-новичок', 'Наберите 1000 очков в игре Тетрис. Освойте базовые механики игры!', 'tetris', 1, 1000, 100],
        ['Тетрис-профи', 'Наберите 5000 очков в игре Тетрис. Покажите свое мастерство!', 'tetris', 2, 5000, 300],
        ['Тетрис-мастер', 'Наберите 15000 очков в игре Тетрис. Станьте настоящим чемпионом!', 'tetris', 3, 15000, 600],
        ['Тетрис-легенда', 'Наберите 30000 очков в игре Тетрис. Докажите, что вы легенда!', 'tetris', 4, 30000, 1000],
        
        // Кликер-игра задания
        ['Кликер-новичок', 'Наберите 20 очков в игре "Быстрые клики". Кликайте по появляющимся целям!', 'click_game', 1, 20, 100],
        ['Скоростной кликер', 'Наберите 50 очков в игре "Быстрые клики". Развивайте скорость и реакцию!', 'click_game', 1, 50, 200],
        ['Мастер кликов', 'Наберите 100 очков в игре "Быстрые клики". Покажите свое мастерство!', 'click_game', 2, 100, 400],
        ['Легенда кликов', 'Наберите 200 очков в игре "Быстрые клики". Станьте легендой скорости!', 'click_game', 3, 200, 600]
    ];
    
    foreach ($default_quests as $quest) {
        $check = $conn->query("SELECT id FROM weekly_quests WHERE title = '" . $conn->real_escape_string($quest[0]) . "'");
        if ($check && $check->num_rows == 0) {
            $conn->query("INSERT INTO weekly_quests (title, description, mission_type, required_level, target_score, reward_xp) 
                         VALUES (
                            '" . $conn->real_escape_string($quest[0]) . "', 
                            '" . $conn->real_escape_string($quest[1]) . "', 
                            '" . $conn->real_escape_string($quest[2]) . "',
                            {$quest[3]}, 
                            {$quest[4]}, 
                            {$quest[5]}
                         )");
        }
    }
}

// Получить таблицу лидеров
function getLeaderboard($conn, $user_id = null) {
    // Синхронизируем уровни всех пользователей в топе
    syncLeaderboardLevels($conn);
    
    // Обновляем онлайн статусы
    updateOnlineStatus($conn);
    
    $sql = "SELECT id, username, level, xp, completed_quests, is_online, last_activity 
            FROM users 
            ORDER BY level DESC, xp DESC, completed_quests DESC 
            LIMIT 20";
    
    $result = $conn->query($sql);
    $leaderboard = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $leaderboard[] = $row;
        }
    }
    
    // Получаем статистику
    $stats_sql = "SELECT 
                    COUNT(*) as total_players,
                    AVG(CAST(level AS DECIMAL(10,2))) as avg_level,
                    SUM(completed_quests) as total_completed_quests,
                    COUNT(CASE WHEN is_online = TRUE THEN 1 END) as online_players
                  FROM users";
    $stats_result = $conn->query($stats_sql);
    $stats = $stats_result ? $stats_result->fetch_assoc() : [];
    
    // Преобразуем значения в правильные типы
    if ($stats) {
        $stats['total_players'] = (int)($stats['total_players'] ?? 0);
        $stats['avg_level'] = (float)($stats['avg_level'] ?? 0);
        $stats['total_completed_quests'] = (int)($stats['total_completed_quests'] ?? 0);
        $stats['online_players'] = (int)($stats['online_players'] ?? 0);
    }
    
    return [
        "success" => true,
        "leaderboard" => $leaderboard,
        "stats" => $stats
    ];
}

// Регистрация пользователя с новыми полями
function registerUser($conn, $firstname, $lastname, $username, $password, $vk_link) {
    // Проверяем, существует ли пользователь
    $check_sql = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        return ["success" => false, "error" => "Ошибка подготовки запроса"];
    }
    
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result && $check_result->num_rows > 0) {
        $check_stmt->close();
        return ["success" => false, "error" => "Пользователь с таким логином уже существует"];
    }
    $check_stmt->close();
    
    // Создаем нового пользователя
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $sql = "INSERT INTO users (firstname, lastname, username, password, vk_link, level, xp, last_activity, is_online) VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), TRUE)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return ["success" => false, "error" => "Ошибка подготовки запроса"];
    }
    
    $stmt->bind_param("sssss", $firstname, $lastname, $username, $password_hash, $vk_link);
    
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        $stmt->close();
        return [
            "success" => true, 
            "message" => "Регистрация успешна!",
            "user_id" => $user_id,
            "username" => $username
        ];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ["success" => false, "error" => "Ошибка регистрации: " . $error];
    }
}

// Авторизация пользователя
function loginUser($conn, $username, $password) {
    $sql = "SELECT id, username, password, level, xp, completed_quests FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return ["success" => false, "error" => "Ошибка подготовки запроса"];
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        if (password_verify($password, $user['password'])) {
            // Устанавливаем пользователя онлайн
            setUserOnline($conn, $user['id']);
            
            $stmt->close();
            return [
                "success" => true, 
                "user_id" => $user['id'],
                "username" => $user['username'],
                "level" => $user['level'],
                "xp" => $user['xp'],
                "completed_quests" => $user['completed_quests'],
                "message" => "Вход выполнен успешно!"
            ];
        } else {
            $stmt->close();
            return ["success" => false, "error" => "Неверный пароль"];
        }
    } else {
        $stmt->close();
        return ["success" => false, "error" => "Пользователь не найден"];
    }
}

// Получить количество выполненных заданий пользователем
function getUserCompletedQuests($conn, $user_id) {
    $sql = "SELECT COUNT(*) as completed_count 
            FROM user_quests 
            WHERE user_id = ? AND status = 'completed'";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return 0;
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    return $data ? $data['completed_count'] : 0;
}

// Получить общее количество активных заданий
function getTotalActiveQuests($conn) {
    $sql = "SELECT COUNT(*) as total_count FROM weekly_quests WHERE status = 'active'";
    $result = $conn->query($sql);
    $data = $result ? $result->fetch_assoc() : null;
    return $data ? $data['total_count'] : 0;
}

// Получить данные пользователя
function getUserData($conn, $user_id) {
    // Сначала синхронизируем уровень пользователя
    syncUserLevel($conn, $user_id);
    
    // Обновляем активность пользователя
    setUserOnline($conn, $user_id);
    
    $sql = "SELECT id, firstname, lastname, username, vk_link, level, xp, completed_quests, last_activity, is_online FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return ["success" => false, "error" => "Database error"];
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    if (!$user_data) {
        return ["success" => false, "error" => "User not found"];
    }
    
    // Добавляем информацию о выполненных заданиях
    $user_completed_quests = getUserCompletedQuests($conn, $user_id);
    $total_active_quests = getTotalActiveQuests($conn);
    
    return [
        "success" => true, 
        "user_data" => [
            "id" => $user_data['id'],
            "firstname" => $user_data['firstname'],
            "lastname" => $user_data['lastname'],
            "username" => $user_data['username'],
            "vk_link" => $user_data['vk_link'],
            "level" => $user_data['level'],
            "xp" => $user_data['xp'],
            "completed_quests" => $user_data['completed_quests'],
            "user_completed_quests" => $user_completed_quests,
            "total_active_quests" => $total_active_quests,
            "last_activity" => $user_data['last_activity'],
            "is_online" => $user_data['is_online']
        ]
    ];
}

// Получить все активные задания для пользователя
function getUserQuests($conn, $user_id) {
    // Получаем данные пользователя
    $user_sql = "SELECT level FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user = $user_result ? $user_result->fetch_assoc() : null;
    $user_level = $user ? $user['level'] : 1;
    $user_stmt->close();

    // Получаем статистику выполнения
    $stats = getQuestStats($conn);
    $stats_map = [];
    foreach ($stats as $stat) {
        $stats_map[$stat['quest_id']] = $stat;
    }

    // Получаем все активные задания
    $sql = "SELECT wq.*, 
                   uq.status as user_status, 
                   uq.progress as user_progress,
                   CASE 
                       WHEN wq.mission_type = 'snake_game' THEN sgp.best_score
                       WHEN wq.mission_type = 'tetris' THEN tgp.best_score
                       WHEN wq.mission_type = 'click_game' THEN cgp.best_score
                       ELSE 0
                   END as best_score
            FROM weekly_quests wq
            LEFT JOIN user_quests uq ON wq.id = uq.quest_id AND uq.user_id = ?
            LEFT JOIN snake_game_progress sgp ON wq.id = sgp.quest_id AND sgp.user_id = ? AND wq.mission_type = 'snake_game'
            LEFT JOIN tetris_game_progress tgp ON wq.id = tgp.quest_id AND tgp.user_id = ? AND wq.mission_type = 'tetris'
            LEFT JOIN click_game_progress cgp ON wq.id = cgp.quest_id AND cgp.user_id = ? AND wq.mission_type = 'click_game'
            WHERE wq.status = 'active' 
            ORDER BY wq.required_level, wq.id";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ["success" => false, "error" => "Database query failed"];
    }
    
    $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $quests = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $target_score = $row['target_score'] ?? 30;
            $user_progress = $row['user_progress'] ?? 0;
            $best_score = $row['best_score'] ?? 0;
            $progress_percent = $target_score > 0 ? min(($user_progress / $target_score) * 100, 100) : 0;
            
            // Получаем статистику для этого задания
            $quest_stats = $stats_map[$row['id']] ?? [
                'completions' => 0,
                'total_users' => 0,
                'completion_rate' => 0
            ];
            
            // Определяем статус и действие
            $action = 'start';
            $status = 'available';
            
            if ($user_level < $row['required_level']) {
                $action = 'locked';
                $status = 'locked';
            } elseif ($row['user_status'] === 'completed') {
                $action = 'completed';
                $status = 'completed';
            } elseif ($row['user_status'] === 'active') {
                if ($user_progress >= $target_score) {
                    $action = 'claim';
                    $status = 'ready';
                } else {
                    $action = 'continue';
                    $status = 'active';
                }
            }
            
            $quests[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'mission_type' => $row['mission_type'],
                'target_score' => $target_score,
                'reward_xp' => $row['reward_xp'],
                'required_level' => $row['required_level'],
                'user_progress' => $user_progress,
                'progress_percent' => $progress_percent,
                'best_score' => $best_score,
                'action' => $action,
                'status' => $status,
                'completions' => $quest_stats['completions'],
                'completion_rate' => $quest_stats['completion_rate'],
                'total_users' => $quest_stats['total_users']
            ];
        }
    }
    
    $stmt->close();
    return ["success" => true, "quests" => $quests];
}

// Получить статистику выполнения заданий
function getQuestStats($conn) {
    $stats = [];
    
    // Получаем общее количество пользователей
    $total_users_sql = "SELECT COUNT(*) as total_users FROM users";
    $total_users_result = $conn->query($total_users_sql);
    $total_users = $total_users_result ? $total_users_result->fetch_assoc()['total_users'] : 0;
    
    // Получаем статистику по каждому заданию
    $quests_sql = "SELECT 
                    wq.id,
                    wq.title,
                    wq.mission_type,
                    COUNT(uq.id) as total_attempts,
                    SUM(CASE WHEN uq.status = 'completed' THEN 1 ELSE 0 END) as completions,
                    SUM(CASE WHEN uq.status = 'active' THEN 1 ELSE 0 END) as active_attempts
                   FROM weekly_quests wq
                   LEFT JOIN user_quests uq ON wq.id = uq.quest_id
                   WHERE wq.status = 'active'
                   GROUP BY wq.id, wq.title, wq.mission_type
                   ORDER BY wq.required_level, wq.id";
    
    $quests_result = $conn->query($quests_sql);
    
    if ($quests_result) {
        while ($row = $quests_result->fetch_assoc()) {
            $completion_rate = $total_users > 0 ? round(($row['completions'] / $total_users) * 100, 1) : 0;
            
            $stats[] = [
                'quest_id' => $row['id'],
                'title' => $row['title'],
                'mission_type' => $row['mission_type'],
                'total_attempts' => $row['total_attempts'],
                'completions' => $row['completions'],
                'active_attempts' => $row['active_attempts'],
                'completion_rate' => $completion_rate,
                'total_users' => $total_users
            ];
        }
    }
    
    return $stats;
}

// Начать задание
function startQuest($conn, $user_id, $quest_id) {
    // Проверяем уровень пользователя и требования задания
    $check_sql = "SELECT u.level, wq.required_level, wq.mission_type 
                  FROM users u, weekly_quests wq 
                  WHERE u.id = ? AND wq.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $user_id, $quest_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_data = $check_result ? $check_result->fetch_assoc() : null;
    $check_stmt->close();
    
    if (!$check_data) {
        return ["success" => false, "error" => "Quest or user not found"];
    }
    
    if ($check_data['level'] < $check_data['required_level']) {
        return ["success" => false, "error" => "Level requirement not met"];
    }
    
    // Создаем запись о начале задания
    $sql = "INSERT INTO user_quests (user_id, quest_id, status, progress, started_at) 
            VALUES (?, ?, 'active', 0, NOW()) 
            ON DUPLICATE KEY UPDATE status = 'active', started_at = NOW()";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ["success" => false, "error" => "Failed to prepare statement"];
    }
    
    $stmt->bind_param("ii", $user_id, $quest_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        
        // Создаем запись о прогрессе в игре
        $mission_type = $check_data['mission_type'];
        if ($mission_type === 'snake_game') {
            $progress_sql = "INSERT INTO snake_game_progress (user_id, quest_id, best_score, current_score, games_played, last_played) 
                             VALUES (?, ?, 0, 0, 0, NOW())
                             ON DUPLICATE KEY UPDATE last_played = NOW()";
        } elseif ($mission_type === 'tetris') {
            $progress_sql = "INSERT INTO tetris_game_progress (user_id, quest_id, best_score, current_score, games_played, last_played) 
                             VALUES (?, ?, 0, 0, 0, NOW())
                             ON DUPLICATE KEY UPDATE last_played = NOW()";
        } elseif ($mission_type === 'click_game') {
            $progress_sql = "INSERT INTO click_game_progress (user_id, quest_id, best_score, current_score, games_played, last_played) 
                             VALUES (?, ?, 0, 0, 0, NOW())
                             ON DUPLICATE KEY UPDATE last_played = NOW()";
        } else {
            return ["success" => true, "message" => "Quest started successfully"];
        }
        
        $progress_stmt = $conn->prepare($progress_sql);
        if ($progress_stmt) {
            $progress_stmt->bind_param("ii", $user_id, $quest_id);
            $progress_stmt->execute();
            $progress_stmt->close();
        }
        
        return ["success" => true, "message" => "Quest started successfully"];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ["success" => false, "error" => "Failed to start quest: " . $error];
    }
}

// Обновить счет в игре Змейка
function updateSnakeScore($conn, $user_id, $quest_id, $score) {
    // Получаем целевой счет задания
    $target_sql = "SELECT target_score FROM weekly_quests WHERE id = ?";
    $target_stmt = $conn->prepare($target_sql);
    $target_stmt->bind_param("i", $quest_id);
    $target_stmt->execute();
    $target_result = $target_stmt->get_result();
    $target_data = $target_result ? $target_result->fetch_assoc() : null;
    $target_stmt->close();
    
    $target_score = $target_data['target_score'] ?? 30;
    
    // Обновляем прогресс игры
    $sql = "INSERT INTO snake_game_progress (user_id, quest_id, best_score, current_score, games_played, last_played) 
            VALUES (?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE 
                best_score = GREATEST(best_score, ?),
                current_score = ?,
                games_played = games_played + 1,
                last_played = NOW()";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ["success" => false, "error" => "Failed to prepare statement"];
    }
    
    $stmt->bind_param("iiiiii", $user_id, $quest_id, $score, $score, $score, $score);
    
    if ($stmt->execute()) {
        $stmt->close();
        
        // Обновляем прогресс задания
        $progress = min($score, $target_score);
        $progress_sql = "UPDATE user_quests SET progress = ? WHERE user_id = ? AND quest_id = ?";
        $progress_stmt = $conn->prepare($progress_sql);
        $progress_stmt->bind_param("iii", $progress, $user_id, $quest_id);
        $progress_stmt->execute();
        $progress_stmt->close();
        
        $progress_percent = ($progress / $target_score) * 100;
        $quest_completed = $progress >= $target_score;
        
        return [
            "success" => true,
            "best_score" => $score,
            "progress" => $progress,
            "progress_percent" => $progress_percent,
            "target_score" => $target_score,
            "quest_completed" => $quest_completed
        ];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ["success" => false, "error" => "Failed to update score: " . $error];
    }
}

// Обновить счет в игре Тетрис
function updateTetrisScore($conn, $user_id, $quest_id, $score) {
    // Получаем целевой счет задания
    $target_sql = "SELECT target_score FROM weekly_quests WHERE id = ?";
    $target_stmt = $conn->prepare($target_sql);
    $target_stmt->bind_param("i", $quest_id);
    $target_stmt->execute();
    $target_result = $target_stmt->get_result();
    $target_data = $target_result ? $target_result->fetch_assoc() : null;
    $target_stmt->close();
    
    $target_score = $target_data['target_score'] ?? 1000;
    
    // Обновляем прогресс игры
    $sql = "INSERT INTO tetris_game_progress (user_id, quest_id, best_score, current_score, games_played, last_played) 
            VALUES (?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE 
                best_score = GREATEST(best_score, ?),
                current_score = ?,
                games_played = games_played + 1,
                last_played = NOW()";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ["success" => false, "error" => "Failed to prepare statement"];
    }
    
    $stmt->bind_param("iiiiii", $user_id, $quest_id, $score, $score, $score, $score);
    
    if ($stmt->execute()) {
        $stmt->close();
        
        // Обновляем прогресс задания
        $progress = min($score, $target_score);
        $progress_sql = "UPDATE user_quests SET progress = ? WHERE user_id = ? AND quest_id = ?";
        $progress_stmt = $conn->prepare($progress_sql);
        $progress_stmt->bind_param("iii", $progress, $user_id, $quest_id);
        $progress_stmt->execute();
        $progress_stmt->close();
        
        $progress_percent = ($progress / $target_score) * 100;
        $quest_completed = $progress >= $target_score;
        
        return [
            "success" => true,
            "best_score" => $score,
            "progress" => $progress,
            "progress_percent" => $progress_percent,
            "target_score" => $target_score,
            "quest_completed" => $quest_completed
        ];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ["success" => false, "error" => "Failed to update score: " . $error];
    }
}

// Обновить счет в кликер-игре
function updateClickScore($conn, $user_id, $quest_id, $score) {
    // Получаем целевой счет задания
    $target_sql = "SELECT target_score FROM weekly_quests WHERE id = ?";
    $target_stmt = $conn->prepare($target_sql);
    $target_stmt->bind_param("i", $quest_id);
    $target_stmt->execute();
    $target_result = $target_stmt->get_result();
    $target_data = $target_result ? $target_result->fetch_assoc() : null;
    $target_stmt->close();
    
    $target_score = $target_data['target_score'] ?? 30;
    
    // Обновляем прогресс игры
    $sql = "INSERT INTO click_game_progress (user_id, quest_id, best_score, current_score, games_played, last_played) 
            VALUES (?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE 
                best_score = GREATEST(best_score, ?),
                current_score = ?,
                games_played = games_played + 1,
                last_played = NOW()";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ["success" => false, "error" => "Failed to prepare statement"];
    }
    
    $stmt->bind_param("iiiiii", $user_id, $quest_id, $score, $score, $score, $score);
    
    if ($stmt->execute()) {
        $stmt->close();
        
        // Обновляем прогресс задания
        $progress = min($score, $target_score);
        $progress_sql = "UPDATE user_quests SET progress = ? WHERE user_id = ? AND quest_id = ?";
        $progress_stmt = $conn->prepare($progress_sql);
        $progress_stmt->bind_param("iii", $progress, $user_id, $quest_id);
        $progress_stmt->execute();
        $progress_stmt->close();
        
        $progress_percent = ($progress / $target_score) * 100;
        $quest_completed = $progress >= $target_score;
        
        return [
            "success" => true,
            "best_score" => $score,
            "progress" => $progress,
            "progress_percent" => $progress_percent,
            "target_score" => $target_score,
            "quest_completed" => $quest_completed
        ];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ["success" => false, "error" => "Failed to update score: " . $error];
    }
}

// Получить награду за задание
function claimQuestReward($conn, $user_id, $quest_id) {
    // Проверяем, выполнено ли задание
    $check_sql = "SELECT uq.progress, wq.target_score, wq.reward_xp
                  FROM user_quests uq 
                  JOIN weekly_quests wq ON uq.quest_id = wq.id 
                  WHERE uq.user_id = ? AND uq.quest_id = ? AND uq.status = 'active'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $user_id, $quest_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $quest_data = $check_result ? $check_result->fetch_assoc() : null;
    $check_stmt->close();
    
    if (!$quest_data) {
        return ["success" => false, "error" => "Active quest not found"];
    }
    
    if ($quest_data['progress'] < $quest_data['target_score']) {
        return ["success" => false, "error" => "Quest not completed"];
    }
    
    // Выдаем награду (только XP)
    $reward_xp = $quest_data['reward_xp'];
    
    $update_sql = "UPDATE users SET xp = xp + ?, completed_quests = completed_quests + 1, last_activity = NOW() WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $reward_xp, $user_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Отмечаем задание как выполненное
    $complete_sql = "UPDATE user_quests SET status = 'completed', completed_at = NOW() WHERE user_id = ? AND quest_id = ?";
    $complete_stmt = $conn->prepare($complete_sql);
    $complete_stmt->bind_param("ii", $user_id, $quest_id);
    $complete_stmt->execute();
    $complete_stmt->close();
    
    return [
        "success" => true,
        "reward_xp" => $reward_xp,
        "message" => "Reward claimed successfully"
    ];
}

// Получить список пользователей онлайн
function getOnlineUsersList($conn) {
    $sql = "SELECT id, username, level, last_activity 
            FROM users 
            WHERE is_online = TRUE
            ORDER BY last_activity DESC 
            LIMIT 50";
    
    $result = $conn->query($sql);
    $online_users = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $online_users[] = $row;
        }
    }
    
    return [
        "success" => true,
        "online_users" => $online_users,
        "total_online" => count($online_users)
    ];
}

// Обновить активность пользователя
function updateUserActivityStatus($conn, $user_id) {
    $sql = "UPDATE users SET last_activity = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        return ["success" => false, "error" => "Failed to prepare statement"];
    }
    
    $stmt->bind_param("i", $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        return ["success" => true, "message" => "Activity updated"];
    } else {
        return ["success" => false, "error" => "Failed to update activity"];
    }
}

// Основная логика
try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendJsonResponse(["success" => false, "error" => "Invalid JSON input"]);
    }
    
    $action = $input['action'] ?? '';
    $user_id = $input['user_id'] ?? 0;
    
    // Инициализируем базу
    initializeDatabase($conn);
    
    if ($action == 'register') {
        $firstname = trim($input['firstname'] ?? '');
        $lastname = trim($input['lastname'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $vk_link = trim($input['vk_link'] ?? '');
        
        if (empty($firstname) || empty($lastname) || empty($username) || empty($password)) {
            sendJsonResponse(["success" => false, "error" => "Все поля обязательны для заполнения"]);
        } elseif (strlen($password) < 6) {
            sendJsonResponse(["success" => false, "error" => "Пароль должен содержать минимум 6 символов"]);
        } else {
            $result = registerUser($conn, $firstname, $lastname, $username, $password, $vk_link);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'login') {
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            sendJsonResponse(["success" => false, "error" => "Все поля обязательны для заполнения"]);
        } else {
            $result = loginUser($conn, $username, $password);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'get_user_data') {
        if (!$user_id) {
            sendJsonResponse(["success" => false, "error" => "User ID required"]);
        } else {
            $result = getUserData($conn, $user_id);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'get_quests') {
        if (!$user_id) {
            sendJsonResponse(["success" => false, "error" => "User ID required"]);
        } else {
            $result = getUserQuests($conn, $user_id);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'start_quest') {
        $quest_id = $input['quest_id'] ?? 0;
        if (!$user_id || !$quest_id) {
            sendJsonResponse(["success" => false, "error" => "User ID and Quest ID required"]);
        } else {
            $result = startQuest($conn, $user_id, $quest_id);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'update_snake_score') {
        $quest_id = $input['quest_id'] ?? 0;
        $score = $input['score'] ?? 0;
        if (!$user_id || !$quest_id) {
            sendJsonResponse(["success" => false, "error" => "User ID and Quest ID required"]);
        } else {
            $result = updateSnakeScore($conn, $user_id, $quest_id, $score);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'update_tetris_score') {
        $quest_id = $input['quest_id'] ?? 0;
        $score = $input['score'] ?? 0;
        if (!$user_id || !$quest_id) {
            sendJsonResponse(["success" => false, "error" => "User ID and Quest ID required"]);
        } else {
            $result = updateTetrisScore($conn, $user_id, $quest_id, $score);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'update_click_score') {
        $quest_id = $input['quest_id'] ?? 0;
        $score = $input['score'] ?? 0;
        if (!$user_id || !$quest_id) {
            sendJsonResponse(["success" => false, "error" => "User ID and Quest ID required"]);
        } else {
            $result = updateClickScore($conn, $user_id, $quest_id, $score);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'claim_reward') {
        $quest_id = $input['quest_id'] ?? 0;
        if (!$user_id || !$quest_id) {
            sendJsonResponse(["success" => false, "error" => "User ID and Quest ID required"]);
        } else {
            $result = claimQuestReward($conn, $user_id, $quest_id);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'get_leaderboard') {
        $result = getLeaderboard($conn, $user_id);
        sendJsonResponse($result);
    }
    elseif ($action == 'get_online_users') {
        $result = getOnlineUsersList($conn);
        sendJsonResponse($result);
    }
    elseif ($action == 'update_activity') {
        if (!$user_id) {
            sendJsonResponse(["success" => false, "error" => "User ID required"]);
        } else {
            $result = updateUserActivityStatus($conn, $user_id);
            sendJsonResponse($result);
        }
    }
    elseif ($action == 'sync_levels') {
        // Синхронизируем уровни всех пользователей
        $levelRequirements = [
            1 => 0,
            2 => 500,
            3 => 1200,
            4 => 2500,
            5 => 4500,
            6 => 6000,
            7 => 8900,
            8 => 12000,
            9 => 16000,
            10 => 20000
        ];
        
        // Получаем всех пользователей
        $users_sql = "SELECT id, xp, level FROM users";
        $users_result = $conn->query($users_sql);
        
        if (!$users_result) {
            sendJsonResponse(["success" => false, "error" => "Failed to get users"]);
        }
        
        $updated_count = 0;
        
        while ($user = $users_result->fetch_assoc()) {
            $correct_level = 1;
            $user_xp = $user['xp'];
            
            // Определяем правильный уровень на основе XP
            for ($level = 10; $level >= 1; $level--) {
                if ($user_xp >= $levelRequirements[$level]) {
                    $correct_level = $level;
                    break;
                }
            }
            
            // Если уровень не совпадает, обновляем
            if ($user['level'] != $correct_level) {
                $update_sql = "UPDATE users SET level = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $correct_level, $user['id']);
                $update_stmt->execute();
                $update_stmt->close();
                $updated_count++;
            }
        }
        
        sendJsonResponse([
            "success" => true, 
            "message" => "Уровни синхронизированы",
            "updated_count" => $updated_count
        ]);
    }
    else {
        sendJsonResponse(["success" => false, "error" => "Неизвестное действие: " . $action]);
    }
    
} catch (Exception $e) {
    sendJsonResponse(["success" => false, "error" => "Server error: " . $e->getMessage()]);
}

$conn->close();
?>