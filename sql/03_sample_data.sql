-- =====================================================
-- Sample Data for Telegram Bot Database
-- Version: 1.0
-- Description: Sample data for development and testing
-- =====================================================

USE telegram_bot_db;

-- =====================================================
-- SAMPLE USERS
-- =====================================================
INSERT INTO `users` (`chat_id`, `username`, `first_name`, `last_name`, `language`, `is_admin`, `status`, `join_date`) VALUES
-- Admin users
(123456789, 'admin1', 'مدیر', 'اول', 'fa', 1, 'active', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(987654321, 'admin2', 'مدیر', 'دوم', 'fa', 1, 'active', DATE_SUB(NOW(), INTERVAL 25 DAY)),

-- Regular users
(111111111, 'user1', 'علی', 'احمدی', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(222222222, 'user2', 'فاطمه', 'محمدی', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 18 DAY)),
(333333333, 'user3', 'محمد', 'رضایی', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 15 DAY)),
(444444444, 'user4', 'زهرا', 'حسینی', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(555555555, 'user5', 'احمد', 'کریمی', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(666666666, 'user6', 'مریم', 'نوری', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(777777777, 'user7', 'حسن', 'مهدوی', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(888888888, 'user8', 'سارا', 'جعفری', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(999999999, 'user9', 'رضا', 'طاهری', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(101010101, 'user10', 'نرگس', 'صادقی', 'fa', 0, 'active', NOW()),

-- English speaking users
(202020202, 'john_doe', 'John', 'Doe', 'en', 0, 'active', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(303030303, 'jane_smith', 'Jane', 'Smith', 'en', 0, 'active', DATE_SUB(NOW(), INTERVAL 4 DAY)),

-- Blocked users
(404040404, 'blocked_user', 'کاربر', 'مسدود', 'fa', 0, 'blocked', DATE_SUB(NOW(), INTERVAL 2 DAY)),

-- Pending users
(505050505, 'pending_user', 'کاربر', 'در انتظار', 'fa', 0, 'pending', NOW())
ON DUPLICATE KEY UPDATE 
    `username` = VALUES(`username`),
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `status` = VALUES(`status`),
    `updated_at` = CURRENT_TIMESTAMP;

-- =====================================================
-- SAMPLE CHAT HISTORY
-- =====================================================
INSERT INTO `chat_history` (`chat_id`, `role`, `content_type`, `content`, `created_at`) VALUES
-- User 1 chat history
(111111111, 'user', 'text', 'سلام، چطوری؟', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(111111111, 'assistant', 'text', 'سلام! ممنون، خوبم. چطور می‌تونم کمکتون کنم؟', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(111111111, 'user', 'text', 'می‌خوام درباره برنامه‌نویسی سوال کنم', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(111111111, 'assistant', 'text', 'بله، حتماً! در چه زمینه‌ای از برنامه‌نویسی سوال دارید؟', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(111111111, 'user', 'text', 'PHP چطوره برای شروع؟', DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
(111111111, 'assistant', 'text', 'PHP زبان خوبیه برای شروع برنامه‌نویسی وب. سینتکس ساده‌ای داره و منابع آموزشی زیادی موجوده.', DATE_SUB(NOW(), INTERVAL 30 MINUTE)),

-- User 2 chat history
(222222222, 'user', 'text', 'Hi there!', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(222222222, 'assistant', 'text', 'Hello! How can I help you today?', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(222222222, 'user', 'text', 'I need help with Python', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(222222222, 'assistant', 'text', 'Sure! Python is a great programming language. What specific help do you need?', DATE_SUB(NOW(), INTERVAL 2 HOUR)),

-- User 3 chat history
(333333333, 'user', 'text', 'سلام، وقت بخیر', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(333333333, 'assistant', 'text', 'سلام، وقت شما هم بخیر! چطور می‌تونم کمکتون کنم؟', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(333333333, 'user', 'text', 'می‌خوام درباره هوش مصنوعی بدونم', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(333333333, 'assistant', 'text', 'هوش مصنوعی حوزه‌ای بسیار گسترده و جذابه. از کدوم جنبه‌ش می‌خواید شروع کنیم؟', DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- User 4 chat history (with file)
(444444444, 'user', 'text', 'سلام، این فایل رو ببین', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(444444444, 'user', 'document', 'document.pdf', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(444444444, 'assistant', 'text', 'فایل شما رو دریافت کردم. چطور می‌تونم کمکتون کنم؟', DATE_SUB(NOW(), INTERVAL 4 HOUR)),

-- User 5 chat history (with image)
(555555555, 'user', 'text', 'این عکس رو ببین', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(555555555, 'user', 'image', 'screenshot.jpg', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(555555555, 'assistant', 'text', 'عکس شما رو دیدم. چه کمکی از دستم برمیاد؟', DATE_SUB(NOW(), INTERVAL 6 HOUR)),

-- English user chat history
(202020202, 'user', 'text', 'Hello, I need help with JavaScript', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(202020202, 'assistant', 'text', 'Hello! JavaScript is a powerful language for web development. What specific help do you need?', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(202020202, 'user', 'text', 'How do I create a function?', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(202020202, 'assistant', 'text', 'In JavaScript, you can create a function using the function keyword or arrow functions. Here are examples...', DATE_SUB(NOW(), INTERVAL 4 HOUR))

ON DUPLICATE KEY UPDATE 
    `content` = VALUES(`content`),
    `created_at` = VALUES(`created_at`);

-- =====================================================
-- SAMPLE BROADCASTS
-- =====================================================
INSERT INTO `broadcasts` (`title`, `message`, `button_text`, `button_link`, `status`, `created_by`, `created_at`) VALUES
('خوش‌آمدگویی', 'سلام به همه کاربران عزیز! 👋\n\nبه ربات ما خوش آمدید. امیدواریم تجربه خوبی داشته باشید.', 'شروع کنید', 'https://t.me/your_bot', 'completed', 123456789, DATE_SUB(NOW(), INTERVAL 5 DAY)),
('به‌روزرسانی سیستم', 'سیستم ما به‌روزرسانی شده و ویژگی‌های جدیدی اضافه شده است. 🚀', 'مشاهده تغییرات', 'https://example.com/changelog', 'completed', 987654321, DATE_SUB(NOW(), INTERVAL 3 DAY)),
('مسابقه برنامه‌نویسی', 'مسابقه برنامه‌نویسی هفتگی شروع شده! 🏆\n\nجوایز ارزشمندی در انتظار برندگان است.', 'شرکت در مسابقه', 'https://example.com/contest', 'draft', 123456789, DATE_SUB(NOW(), INTERVAL 1 DAY)),
('نکات امنیتی', 'لطفاً نکات امنیتی مهم را رعایت کنید تا حساب شما در امان باشد. 🔒', 'راهنمای امنیت', 'https://example.com/security', 'sending', 987654321, NOW())

ON DUPLICATE KEY UPDATE 
    `message` = VALUES(`message`),
    `status` = VALUES(`status`),
    `created_at` = VALUES(`created_at`);

-- =====================================================
-- SAMPLE BROADCAST RECIPIENTS
-- =====================================================
-- For the first broadcast (completed)
INSERT INTO `broadcast_recipients` (`broadcast_id`, `user_id`, `status`, `message_id`, `sent_at`) VALUES
(1, 111111111, 'sent', 1001, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 222222222, 'sent', 1002, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 333333333, 'sent', 1003, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 444444444, 'sent', 1004, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 555555555, 'sent', 1005, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 666666666, 'failed', NULL, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 777777777, 'sent', 1007, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 888888888, 'sent', 1008, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 999999999, 'sent', 1009, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 101010101, 'sent', 1010, DATE_SUB(NOW(), INTERVAL 5 DAY)),

-- For the second broadcast (completed)
(2, 111111111, 'sent', 2001, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 222222222, 'sent', 2002, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 333333333, 'sent', 2003, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 444444444, 'sent', 2004, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 555555555, 'sent', 2005, DATE_SUB(NOW(), INTERVAL 3 DAY)),

-- For the third broadcast (draft - no recipients yet)
-- No recipients for draft broadcasts

-- For the fourth broadcast (sending - some recipients)
(4, 111111111, 'sent', 4001, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(4, 222222222, 'sent', 4002, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
(4, 333333333, 'pending', NULL, NULL),
(4, 444444444, 'pending', NULL, NULL),
(4, 555555555, 'pending', NULL, NULL)

ON DUPLICATE KEY UPDATE 
    `status` = VALUES(`status`),
    `message_id` = VALUES(`message_id`),
    `sent_at` = VALUES(`sent_at`);

-- =====================================================
-- SAMPLE LOGS
-- =====================================================
INSERT INTO `logs` (`level`, `title`, `message`, `chat_id`, `context`, `created_at`) VALUES
('info', 'User Registration', 'New user registered', 101010101, '{"username": "user10", "first_name": "نرگس"}', NOW()),
('success', 'Broadcast Completed', 'Broadcast "خوش‌آمدگویی" completed successfully', 123456789, '{"broadcast_id": 1, "recipients": 10, "sent": 9, "failed": 1}', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('warning', 'Message Failed', 'Failed to send message to user', 666666666, '{"error_code": 403, "error_description": "Forbidden"}', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('error', 'Database Connection', 'Database connection failed', NULL, '{"error": "Connection timeout"}', DATE_SUB(NOW(), INTERVAL 1 DAY)),
('info', 'AI Response', 'AI response generated successfully', 111111111, '{"response_length": 150, "model": "gpt-4"}', DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
('success', 'User Activity', 'User completed tutorial', 222222222, '{"tutorial_id": 1, "completion_time": 300}', DATE_SUB(NOW(), INTERVAL 2 HOUR))

ON DUPLICATE KEY UPDATE 
    `message` = VALUES(`message`),
    `context` = VALUES(`context`),
    `created_at` = VALUES(`created_at`);

-- =====================================================
-- ADDITIONAL SAMPLE DATA FOR TESTING
-- =====================================================

-- Add more chat history for testing pagination
INSERT INTO `chat_history` (`chat_id`, `role`, `content_type`, `content`, `created_at`) VALUES
(111111111, 'user', 'text', 'ممنون از راهنمایی‌تون', DATE_SUB(NOW(), INTERVAL 15 MINUTE)),
(111111111, 'assistant', 'text', 'خواهش می‌کنم! اگر سوال دیگه‌ای داشتید، در خدمت هستم.', DATE_SUB(NOW(), INTERVAL 15 MINUTE)),
(222222222, 'user', 'text', 'Thanks for the help!', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(222222222, 'assistant', 'text', 'You\'re welcome! Feel free to ask if you need more help.', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(333333333, 'user', 'text', 'بسیار مفید بود', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(333333333, 'assistant', 'text', 'خوشحالم که مفید بود! 😊', DATE_SUB(NOW(), INTERVAL 2 HOUR))

ON DUPLICATE KEY UPDATE 
    `content` = VALUES(`content`),
    `created_at` = VALUES(`created_at`);

-- Add more users for testing broadcast functionality
INSERT INTO `users` (`chat_id`, `username`, `first_name`, `last_name`, `language`, `is_admin`, `status`, `join_date`) VALUES
(606060606, 'test_user1', 'کاربر', 'تست ۱', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(707070707, 'test_user2', 'کاربر', 'تست ۲', 'fa', 0, 'active', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(808080808, 'test_user3', 'کاربر', 'تست ۳', 'fa', 0, 'active', NOW())

ON DUPLICATE KEY UPDATE 
    `username` = VALUES(`username`),
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `status` = VALUES(`status`),
    `updated_at` = CURRENT_TIMESTAMP; 