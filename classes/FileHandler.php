<?php

namespace Bot;

class FileHandler
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../parent_ids.json';
    }

    public function saveState($chatId, $state)
    {
        $data = $this->getAllData();
        $data[$chatId]['state'] = $state;
        $this->saveAllData($data);
    }

    public function getState($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['state'] ?? NULL;
    }

    private function getAllData()
    {
        $content = file_get_contents($this->filePath);
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            return [];
        }
        return $data ?? [];
    }

    private function saveAllData($data)
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $file = fopen($this->filePath, 'c+');
        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0);
            fwrite($file, $jsonData);
            fflush($file);
            flock($file, LOCK_UN);
        }
        fclose($file);
    }

    public function addMessageId($chatId, $messageId)
    {
        $data = $this->getAllData();
        if (!isset($data[$chatId]['message_ids'])) {
            $data[$chatId]['message_ids'] = [];
        }
        $data[$chatId]['message_ids'][] = $messageId;
        $this->saveAllData($data);
    }

    public function getMessageIds($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['message_ids'] ?? [];
    }

    public function clearMessageIds($chatId)
    {
        $data = $this->getAllData();
        if (isset($data[$chatId]['message_ids'])) {
            unset($data[$chatId]['message_ids']);
        }
        $this->saveAllData($data);
    }

    public function saveMessageId($chatId, $messageId)
    {
        $data = $this->getAllData();
        $data[$chatId]['message_id'] = $messageId;
        $this->saveAllData($data);
    }

    public function getMessageId($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['message_id'] ?? null;
    }
}

