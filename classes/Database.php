<?php

namespace Bot;

use Exception;
use mysqli;
use Config\AppConfig;

class Database
{
    private $mysqli;
    private $botLink;

    public function __construct()
    {
        $config = AppConfig::getConfig();
        $this->botLink = $config['bot']['bot_link'];
        $dbConfig = $config['database'];
        $this->mysqli = new mysqli(
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database']
        );
        if ($this->mysqli->connect_errno) {
            error_log("❌ Database Connection Failed: " . $this->mysqli->connect_error);
            exit();
        }
        $this->mysqli->set_charset("utf8mb4");
    }
    public function saveUser($user, $entryToken = null)
    {
        $excludedUsers = [193551966];
        if (in_array($user['id'], $excludedUsers)) {
            return;
        }


        $stmt = $this->mysqli->prepare("SELECT username, first_name, last_name, language FROM users WHERE chat_id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();

            $username = $user['username'] ?? '';
            $firstName = $user['first_name'] ?? '';
            $lastName = $user['last_name'] ?? '';
            $language = $user['language_code'] ?? 'en';


            $stmt = $this->mysqli->prepare("
            INSERT INTO users (chat_id, username, first_name, last_name, language, last_activity, entry_token) 
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
            $stmt->bind_param(
                "isssss",
                $user['id'],
                $username,
                $firstName,
                $lastName,
                $language,
                $entryToken
            );
            $stmt->execute();
        } else {
            $stmt->close();

            $username = $user['username'] ?? '';
            $firstName = $user['first_name'] ?? '';
            $lastName = $user['last_name'] ?? '';
            $language = $user['language_code'] ?? 'en';

            $stmt = $this->mysqli->prepare("
            UPDATE users 
            SET username = ?, first_name = ?, last_name = ?, language = ?, last_activity = NOW()
            WHERE chat_id = ?
        ");
            $stmt->bind_param(
                "ssssi",
                $username,
                $firstName,
                $lastName,
                $language,
                $user['id']
            );
            $stmt->execute();
        }
    }
    public function getAllUsers()
    {
        $query = "SELECT * FROM users";
        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            error_log("❌ Failed to prepare statement in getAllUsers: " . $this->mysqli->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log("❌ Failed to execute statement in getAllUsers: " . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $users;
    }

    public function getAdmins()
    {
        $stmt = $this->mysqli->prepare("SELECT id, chat_id, username FROM users WHERE is_admin = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $admins = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $admins;
    }

    public function getUsernameByChatId($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `username` FROM `users` WHERE `chat_id` = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['username'] ?? 'Unknown';
    }

    public function setUserLanguage($chatId, $language)
    {
        $stmt = $this->mysqli->prepare("UPDATE `users` SET `language` = ? WHERE `chat_id` = ?");
        $stmt->bind_param("si", $language, $chatId);
        return $stmt->execute();
    }

    public function getUserByUsername($username)
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getUserLanguage($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `language` FROM `users` WHERE `chat_id` = ? LIMIT 1");
        $stmt->bind_param('s', $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['language'] ?? 'fa';
    }

    public function getUserInfo($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `username`, `first_name`, `last_name` FROM `users` WHERE `chat_id` = ?");
        if (!$stmt) {
            error_log("Failed to prepare statement: " . $this->mysqli->error);
            return null;
        }
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        if (!$user) {
            error_log("User not found for chat_id: {$chatId}");
            return null;
        }
        return $user;
    }



    public function getUserByChatIdOrUsername($identifier)
    {
        if (is_numeric($identifier)) {
            $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE chat_id = ?");
            $stmt->bind_param("i", $identifier);
        } else {
            $username = ltrim($identifier, '@');
            $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user;
    }

    public function getUserFullName($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `first_name`, `last_name` FROM `users` WHERE `chat_id` = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''));
    }

    public function getUsersBatch($limit = 20, $offset = 0)
    {
        $query = "SELECT id, chat_id, username, first_name, last_name, join_date, last_activity, status, language, is_admin, entry_token 
              FROM users 
              ORDER BY id ASC 
              LIMIT ? OFFSET ?";
        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            error_log("❌ Prepare failed: " . $this->mysqli->error);
            return [];
        }
        $stmt->bind_param("ii", $limit, $offset);
        if (!$stmt->execute()) {
            error_log("❌ Execute failed: " . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function updateUserStatus($chatId, $status)
    {
        $query = "UPDATE users SET status = ? WHERE chat_id = ?";
        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            error_log("Database Error: " . $this->mysqli->error);
            return false;
        }
        $stmt->bind_param("si", $status, $chatId);
        if (!$stmt->execute()) {
            error_log("Error updating status for User ID: $chatId");
            $stmt->close();
            return false;
        }
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows > 0;
    }

    public function isAdmin($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT is_admin FROM users WHERE chat_id = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user && $user['is_admin'] == 1;
    }

    public function getUserByUserId($userId)
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE chat_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
?>
