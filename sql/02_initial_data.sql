-- =====================================================
-- Initial Data for Telegram Bot Database
-- Version: 1.0
-- Description: Default settings and initial data
-- =====================================================

USE telegram_bot_db;

-- =====================================================
-- DEFAULT SETTINGS
-- =====================================================
INSERT INTO `settings` (`key`, `value`, `description`, `type`) VALUES
('bot_welcome_message', 'سلام! به ربات خوش آمدید. 👋', 'پیام خوش‌آمدگویی ربات', 'string'),
('admin_chat_id', '7285637709', 'شناسه چت ادمین اصلی', 'string'),
('maintenance_mode', 'false', 'حالت تعمیر و نگهداری', 'boolean'),
('broadcast_delay', '1000', 'تاخیر بین ارسال پیام‌های همگانی (میلی‌ثانیه)', 'integer'),
('max_broadcast_batch', '20', 'حداکثر تعداد پیام در هر دسته همگانی', 'integer'),
('ai_chat_enabled', 'true', 'فعال بودن چت هوش مصنوعی', 'boolean'),
('ai_model', 'gpt-4', 'مدل هوش مصنوعی پیش‌فرض', 'string'),
('ai_temperature', '0.7', 'دمای مدل هوش مصنوعی', 'string'),
('max_chat_history', '50', 'حداکثر تعداد پیام در تاریخچه چت', 'integer'),
('file_upload_limit', '10485760', 'حداکثر اندازه فایل (بایت)', 'integer'),
('allowed_file_types', '["jpg","jpeg","png","gif","pdf","doc","docx","txt"]', 'انواع فایل مجاز', 'json'),
('timezone', 'Asia/Tehran', 'منطقه زمانی پیش‌فرض', 'string'),
('date_format', 'Y-m-d H:i:s', 'فرمت تاریخ پیش‌فرض', 'string'),
('language', 'fa', 'زبان پیش‌فرض', 'string')
ON DUPLICATE KEY UPDATE 
    `value` = VALUES(`value`),
    `description` = VALUES(`description`),
    `type` = VALUES(`type`),
    `updated_at` = CURRENT_TIMESTAMP;

-- =====================================================
-- SAMPLE ADMIN USER (Replace with actual admin chat_id)
-- =====================================================
INSERT INTO `users` (`chat_id`, `username`, `first_name`, `last_name`, `language`, `is_admin`, `status`, `join_date`) VALUES
(7285637709, 'admin', 'مدیر', 'سیستم', 'fa', 1, 'active', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE 
    `is_admin` = 1,
    `status` = 'active',
    `updated_at` = CURRENT_TIMESTAMP;

-- =====================================================
-- INDEXES FOR BETTER PERFORMANCE
-- =====================================================
-- These indexes are already included in the schema, but here are some additional ones for optimization

-- Composite index for user search
CREATE INDEX IF NOT EXISTS `idx_users_search` ON `users` (`status`, `is_admin`, `join_date`);

-- Composite index for chat history
CREATE INDEX IF NOT EXISTS `idx_chat_history_conversation` ON `chat_history` (`chat_id`, `role`, `created_at`);

-- Composite index for broadcasts
CREATE INDEX IF NOT EXISTS `idx_broadcasts_status` ON `broadcasts` (`status`, `created_at`);

-- Composite index for broadcast recipients
CREATE INDEX IF NOT EXISTS `idx_broadcast_recipients_status` ON `broadcast_recipients` (`broadcast_id`, `status`);

-- =====================================================
-- VIEWS FOR COMMON QUERIES
-- =====================================================

-- View for user statistics
CREATE OR REPLACE VIEW `v_user_stats` AS
SELECT 
    u.chat_id,
    u.username,
    u.first_name,
    u.last_name,
    u.join_date,
    u.last_activity,
    COUNT(DISTINCT ch.id) as total_messages,
    COUNT(DISTINCT CASE WHEN ch.role = 'user' THEN ch.id END) as user_messages,
    COUNT(DISTINCT CASE WHEN ch.role = 'assistant' THEN ch.id END) as ai_responses
FROM `users` u
LEFT JOIN `chat_history` ch ON u.chat_id = ch.chat_id
GROUP BY u.chat_id, u.username, u.first_name, u.last_name, u.join_date, u.last_activity;

-- View for broadcast statistics
CREATE OR REPLACE VIEW `v_broadcast_stats` AS
SELECT 
    b.id,
    b.title,
    b.status,
    b.created_at,
    COUNT(br.id) as total_recipients,
    COUNT(CASE WHEN br.status = 'sent' THEN br.id END) as sent_count,
    COUNT(CASE WHEN br.status = 'failed' THEN br.id END) as failed_count,
    COUNT(CASE WHEN br.status = 'pending' THEN br.id END) as pending_count
FROM `broadcasts` b
LEFT JOIN `broadcast_recipients` br ON b.id = br.broadcast_id
GROUP BY b.id, b.title, b.status, b.created_at;

-- =====================================================
-- STORED PROCEDURES FOR COMMON OPERATIONS
-- =====================================================

DELIMITER //

-- Procedure to get user with all related data
CREATE PROCEDURE `GetUserWithDetails`(IN p_chat_id BIGINT)
BEGIN
    SELECT 
        u.*,
        COUNT(DISTINCT ch.id) as total_messages,
        COUNT(DISTINCT CASE WHEN ch.role = 'user' THEN ch.id END) as user_messages,
        COUNT(DISTINCT CASE WHEN ch.role = 'assistant' THEN ch.id END) as ai_responses
    FROM `users` u
    LEFT JOIN `chat_history` ch ON u.chat_id = ch.chat_id
    WHERE u.chat_id = p_chat_id
    GROUP BY u.id;
END //

-- Procedure to get chat history for a user
CREATE PROCEDURE `GetChatHistory`(
    IN p_chat_id BIGINT,
    IN p_limit INT DEFAULT 50,
    IN p_offset INT DEFAULT 0
)
BEGIN
    SELECT 
        ch.*,
        u.username,
        u.first_name,
        u.last_name
    FROM `chat_history` ch
    JOIN `users` u ON ch.chat_id = u.chat_id
    WHERE ch.chat_id = p_chat_id
    ORDER BY ch.created_at DESC
    LIMIT p_limit OFFSET p_offset;
END //

-- Procedure to create a new broadcast
CREATE PROCEDURE `CreateBroadcast`(
    IN p_title VARCHAR(255),
    IN p_message TEXT,
    IN p_button_text VARCHAR(100),
    IN p_button_link VARCHAR(500),
    IN p_created_by BIGINT,
    OUT p_broadcast_id INT
)
BEGIN
    INSERT INTO `broadcasts` (
        title, message, button_text, button_link, created_by
    ) VALUES (
        p_title, p_message, p_button_text, p_button_link, p_created_by
    );
    
    SET p_broadcast_id = LAST_INSERT_ID();
    
    -- Log the broadcast creation
    INSERT INTO `logs` (level, title, message, chat_id, context)
    VALUES ('info', 'Broadcast Created', 
            CONCAT('Broadcast "', p_title, '" created by user ', p_created_by),
            p_created_by, JSON_OBJECT('broadcast_id', p_broadcast_id));
END //

-- Procedure to add recipients to a broadcast
CREATE PROCEDURE `AddBroadcastRecipients`(
    IN p_broadcast_id INT,
    IN p_user_ids JSON
)
BEGIN
    DECLARE i INT DEFAULT 0;
    DECLARE user_id BIGINT;
    DECLARE user_count INT;
    
    SET user_count = JSON_LENGTH(p_user_ids);
    
    WHILE i < user_count DO
        SET user_id = JSON_EXTRACT(p_user_ids, CONCAT('$[', i, ']'));
        
        INSERT IGNORE INTO `broadcast_recipients` (broadcast_id, user_id)
        VALUES (p_broadcast_id, user_id);
        
        SET i = i + 1;
    END WHILE;
END //

DELIMITER ;

-- =====================================================
-- TRIGGERS FOR AUTOMATIC UPDATES
-- =====================================================

DELIMITER //

-- Trigger to update user last_activity
CREATE TRIGGER `tr_users_last_activity` 
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
    SET NEW.last_activity = CURRENT_TIMESTAMP;
END //

-- Trigger to log broadcast status changes
CREATE TRIGGER `tr_broadcasts_status_log` 
AFTER UPDATE ON `broadcasts`
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO `logs` (level, title, message, chat_id, context)
        VALUES (
            CASE 
                WHEN NEW.status = 'completed' THEN 'success'
                WHEN NEW.status = 'cancelled' THEN 'warning'
                ELSE 'info'
            END,
            'Broadcast Status Changed',
            CONCAT('Broadcast "', NEW.title, '" status changed from ', OLD.status, ' to ', NEW.status),
            NEW.created_by,
            JSON_OBJECT('broadcast_id', NEW.id, 'old_status', OLD.status, 'new_status', NEW.status)
        );
    END IF;
END //

-- Trigger to log chat history for AI interactions
CREATE TRIGGER `tr_chat_history_log` 
AFTER INSERT ON `chat_history`
FOR EACH ROW
BEGIN
    IF NEW.role = 'assistant' THEN
        INSERT INTO `logs` (level, title, message, chat_id, context)
        VALUES ('info', 'AI Response Generated', 
                CONCAT('AI response generated for user ', NEW.chat_id),
                NEW.chat_id, JSON_OBJECT('message_id', NEW.id, 'content_length', LENGTH(NEW.content)));
    END IF;
END //

DELIMITER ; 