// chat/chat.js - JavaScript для чата (исправленная версия)
let chat;
let lastMessageId = 0;
let currentImageData = null;

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

// Исправленная версия secureFetch
async function secureFetch(action, data = {}) {
    data.action = action;
    
    try {
        // Преобразуем все значения в строки
        const formData = new URLSearchParams();
        for (const [key, value] of Object.entries(data)) {
            if (value !== null && value !== undefined) {
                formData.append(key, value.toString());
            }
        }
        
        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ошибка ${response.status}: ${response.statusText}`);
        }
        
        const text = await response.text();
        
        // Очистка текста от возможных PHP предупреждений
        const cleanText = text.trim();
        
        // Проверяем, начинается ли ответ с HTML тегов (ошибка PHP)
        if (cleanText.startsWith('<') || cleanText.includes('<b>Warning</b>') || cleanText.includes('<b>Notice</b>')) {
            // Пытаемся найти JSON в тексте
            const jsonMatch = cleanText.match(/\{.*\}/s);
            if (jsonMatch) {
                return JSON.parse(jsonMatch[0]);
            } else {
                throw new Error('Сервер вернул HTML вместо JSON. Возможно, ошибка PHP.');
            }
        }
        
        try {
            return JSON.parse(cleanText);
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            console.error('Response text:', cleanText);
            return {
                status: 'error', 
                message: 'Неверный формат ответа от сервера'
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
        this.chatStatusInterval = null;
        this.usersInterval = null;
        this.usersFilter = 'all';
        this.muteCheckInterval = null;
        
        this.initializeElements();
        this.setupEventListeners();
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
        this.imagePreview = document.getElementById('image-preview');
        this.imageUpload = document.getElementById('image-upload');
        this.imagePreviewContainer = document.getElementById('image-preview-container');
        this.bannedUsersTable = document.getElementById('banned-users-table');
        this.bannedIPsTable = document.getElementById('banned-ips-table');
    }
    
    setupEventListeners() {
        this.sendBtn.addEventListener('click', () => this.sendMessage());
        this.mobileSendBtn.addEventListener('click', () => this.sendMessage());
        
        // Обработка клавиши Enter в textarea
        this.messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                // Проверяем, является ли устройство мобильным
                const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                
                if (isMobile) {
                    // На мобильных устройствах - перенос строки
                    if (!e.shiftKey) {
                        // Обычный Enter - отправка сообщения
                        e.preventDefault();
                        this.sendMessage();
                    }
                    // Shift+Enter - обычный перенос строки (оставляем как есть)
                } else {
                    // На десктопе - стандартное поведение
                    if (e.shiftKey) {
                        // Shift+Enter - новая строка
                        return;
                    } else {
                        // Enter - отправка сообщения
                        e.preventDefault();
                        this.sendMessage();
                    }
                }
            }
        });
        
        // Автоматическое изменение высоты textarea
        this.messageInput.addEventListener('input', () => {
            this.autoResizeTextarea(this.messageInput);
        });
        
        this.chatMessages.addEventListener('scroll', () => {
            const { scrollTop, scrollHeight, clientHeight } = this.chatMessages;
            this.isScrolledToBottom = Math.abs(scrollHeight - clientHeight - scrollTop) < 10;
        });
    }
    
    // Функция автоматического изменения высоты textarea
    autoResizeTextarea(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
    }
    
    async checkAuthStatus() {
        try {
            const result = await secureFetch('check_auth');
            console.log('Auth check result:', result);
            
            if (result.status === 'success' && result.authenticated) {
                this.showChatInterface(result.username, result.role, result.muted, result.mute_time_left);
                
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
                    
                    this.showAuthForms();
                } else {
                    this.showAuthForms();
                }
            }
        } catch (error) {
            console.error('Ошибка проверки авторизации:', error);
            this.showAuthForms();
            this.addSystemMessage('❌ Ошибка подключения к серверу');
        }
    }
    
    showAuthForms() {
        this.authForms.style.display = 'block';
        this.userPanel.style.display = 'none';
        this.chatWrapper.style.display = 'none';
        this.adminPanel.style.display = 'none';
    }
    
    showChatInterface(username, role, muted, muteTimeLeft) {
        this.authForms.style.display = 'none';
        this.userPanel.style.display = 'flex';
        this.chatWrapper.style.display = 'grid';
        
        document.getElementById('current-username').textContent = username;
        
        this.isAdmin = (role === 'admin');
        
        if (this.isAdmin) {
            document.getElementById('user-role').style.display = 'inline';
            this.adminPanel.style.display = 'block';
            this.startChatStatusCheck();
            this.loadBannedLists();
        } else {
            document.getElementById('user-role').style.display = 'none';
            this.adminPanel.style.display = 'none';
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
                        this.loadBannedLists();
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
            if (!this.isMuted) {
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
            this.messageInput.disabled = true;
            this.sendBtn.disabled = true;
            this.mobileSendBtn.disabled = true;
            this.sendBtn.textContent = '❌ Чат отключен';
            this.mobileSendBtn.textContent = '❌ Чат отключен';
        }
    }
    
    async sendMessage() {
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
                this.autoResizeTextarea(this.messageInput);
                return;
            }
            
            // Отправляем сообщение
            const result = await secureFetch('send_message', {
                message: messageText,
                image_data: currentImageData || ''
            });
            
            if (result.status === 'success') {
                this.messageInput.value = '';
                this.autoResizeTextarea(this.messageInput); // Сбрасываем высоту
                removeImage(); // Очищаем изображение
                this.loadMessages();
                showNotification('✅ Сообщение отправлено', 'success');
            } else {
                showNotification('❌ ' + (result.message || 'Ошибка отправки сообщения'), 'error');
                if (result.message && result.message.includes('муте')) {
                    this.checkMuteStatus();
                }
            }
        } catch (error) {
            console.error('Ошибка отправки:', error);
            showNotification('❌ Ошибка соединения с сервером', 'error');
        } finally {
            // Восстанавливаем состояние элементов ввода
            if (!this.isMuted && this.chatEnabled) {
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
    
    async loadBannedLists() {
        if (!this.isAdmin) return;
        
        try {
            // Загружаем забаненных пользователей
            const bannedUsers = await secureFetch('get_banned_users');
            if (bannedUsers && !bannedUsers.status) {
                this.displayBannedUsers(bannedUsers);
            }
            
            // Загружаем забаненные IP
            const bannedIPs = await secureFetch('get_banned_ips');
            if (bannedIPs && !bannedIPs.status) {
                this.displayBannedIPs(bannedIPs);
            }
        } catch (error) {
            console.error('Ошибка загрузки списков банов:', error);
        }
    }
    
    displayMessages(messages) {
        if (!messages || !Array.isArray(messages)) {
            this.addSystemMessage('❌ Ошибка загрузки сообщений');
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
                <small style="color: #666; font-size: 0.65rem;">${user.status}</small>
            `;
            
            this.usersList.appendChild(userItem);
        });
    }
    
    displayBannedUsers(users) {
        if (!users || !Array.isArray(users)) {
            this.bannedUsersTable.innerHTML = '<div class="empty-banned">Ошибка загрузки</div>';
            return;
        }
        
        if (users.length === 0) {
            this.bannedUsersTable.innerHTML = '<div class="empty-banned">Нет забаненных пользователей</div>';
            return;
        }
        
        let html = `
            <table>
                <thead>
                    <tr>
                        <th>Пользователь</th>
                        <th>Роль</th>
                        <th>IP регистрации</th>
                        <th>Последний IP</th>
                        <th>Последняя активность</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        users.forEach(user => {
            html += `
                <tr>
                    <td>${this.escapeHtml(user.username)}</td>
                    <td>${this.escapeHtml(user.role)}</td>
                    <td>${this.escapeHtml(user.registration_ip)}</td>
                    <td>${this.escapeHtml(user.last_ip)}</td>
                    <td>${this.escapeHtml(user.last_activity)}</td>
                </tr>
            `;
        });
        
        html += '</tbody></table>';
        this.bannedUsersTable.innerHTML = html;
    }
    
    displayBannedIPs(ips) {
        if (!ips || !Array.isArray(ips)) {
            this.bannedIPsTable.innerHTML = '<div class="empty-banned">Ошибка загрузки</div>';
            return;
        }
        
        if (ips.length === 0) {
            this.bannedIPsTable.innerHTML = '<div class="empty-banned">Нет забаненных IP адресов</div>';
            return;
        }
        
        let html = `
            <table>
                <thead>
                    <tr>
                        <th>IP адрес</th>
                        <th>Причина</th>
                        <th>Заблокировал</th>
                        <th>Дата блокировки</th>
                        <th>Истекает</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        ips.forEach(ip => {
            const expiresAt = ip.expires_at ? new Date(ip.expires_at).toLocaleString() : 'Навсегда';
            html += `
                <tr>
                    <td>${this.escapeHtml(ip.ip_address)}</td>
                    <td>${this.escapeHtml(ip.reason)}</td>
                    <td>${this.escapeHtml(ip.banned_by)}</td>
                    <td>${this.escapeHtml(ip.banned_at)}</td>
                    <td>${expiresAt}</td>
                </tr>
            `;
        });
        
        html += '</tbody></table>';
        this.bannedIPsTable.innerHTML = html;
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
        messageDiv.className = `message ${isOwn ? 'own' : 'other'}`;
        
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
        messageContent = `
            <div class="message-header">
                <span class="username">🕵️ ${this.escapeHtml(messageData.username)}</span>
                <span class="timestamp">[${messageData.timestamp}]</span>
            </div>
        `;
        
        if (textContent) {
            messageContent += `<div class="message-content">${this.escapeHtml(textContent)}</div>`;
        }
        
        if (hasImage && imageContent) {
            messageContent += `
                <img src="${imageContent}" class="message-image" alt="Изображение" onclick="showFullscreenImage(this)">
            `;
        }
        
        if (this.isAdmin && messageData.user_ip) {
            messageContent += `
                <div class="ip-info admin-ip">IP: ${messageData.user_ip}</div>
            `;
        }
        
        if (this.isAdmin && !messageData.is_deleted) {
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

// Глобальные функции
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
    
    if (!username || !password) {
        showNotification('❌ Заполните все поля', 'error');
        return;
    }
    
    // Показываем индикатор загрузки
    const loginBtn = document.querySelector('#login-form .btn-primary');
    const originalText = loginBtn.textContent;
    loginBtn.disabled = true;
    loginBtn.textContent = '⏳ Вход...';
    
    try {
        const result = await secureFetch('login', {
            username: username,
            password: password
        });
        
        console.log('Login result:', result);
        
        if (result.status === 'success') {
            showNotification('✅ Вход выполнен успешно!', 'success');
            
            // Обновляем интерфейс после успешного входа
            if (chat) {
                await chat.checkAuthStatus();
            }
            
        } else {
            showNotification('❌ ' + (result.message || 'Ошибка авторизации'), 'error');
        }
    } catch (error) {
        console.error('Ошибка входа:', error);
        showNotification('❌ Ошибка соединения с сервером', 'error');
    } finally {
        // Восстанавливаем кнопку
        loginBtn.disabled = false;
        loginBtn.textContent = originalText;
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
            
            // Обновляем интерфейс после регистрации
            if (chat) {
                await chat.checkAuthStatus();
            }
            
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
        
        // Обновляем интерфейс после выхода
        if (chat) {
            chat.showAuthForms();
        }
        
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
                chat.loadUsers();
                chat.loadBannedLists();
            }
        } else {
            showNotification('❌ ' + result.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка блокировки:', error);
        showNotification('❌ Ошибка блокировки пользователя', 'error');
    }
}

async function unbanUser() {
    const username = document.getElementById('unban-username').value.trim();
    
    if (!username) {
        showNotification('❌ Введите имя пользователя', 'error');
        return;
    }
    
    try {
        const result = await secureFetch('unban_user', { username: username });
        
        if (result.status === 'success') {
            showNotification('✅ Пользователь разблокирован', 'success');
            document.getElementById('unban-username').value = '';
            if (chat) {
                chat.loadUsers();
                chat.loadBannedLists();
            }
        } else {
            showNotification('❌ ' + result.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка разблокировки:', error);
        showNotification('❌ Ошибка разблокировки пользователя', 'error');
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
            if (chat) chat.loadBannedLists();
        } else {
            showNotification('❌ ' + result.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка блокировки IP:', error);
        showNotification('❌ Ошибка блокировки IP', 'error');
    }
}

async function unbanIP() {
    const ip = document.getElementById('unban-ip').value.trim();
    
    if (!ip) {
        showNotification('❌ Введите IP адрес', 'error');
        return;
    }
    
    try {
        const result = await secureFetch('unban_ip', { ip_address: ip });
        
        if (result.status === 'success') {
            showNotification('✅ IP адрес разблокирован', 'success');
            document.getElementById('unban-ip').value = '';
            if (chat) chat.loadBannedLists();
        } else {
            showNotification('❌ ' + result.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка разблокировки IP:', error);
        showNotification('❌ Ошибка разблокировки IP', 'error');
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

function loadUsers() {
    if (chat && typeof chat.loadUsers === 'function') {
        chat.loadUsers();
        showNotification('👥 Список пользователей обновлен', 'success');
    }
}

function loadBannedLists() {
    if (chat && typeof chat.loadBannedLists === 'function') {
        chat.loadBannedLists();
        showNotification('🚫 Списки банов обновлены', 'success');
    }
}

function setUsersFilter(filter) {
    if (chat && typeof chat.setUsersFilter === 'function') {
        chat.setUsersFilter(filter);
    }
}

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