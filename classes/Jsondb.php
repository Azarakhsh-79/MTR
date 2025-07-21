<?php

namespace Bot;

use Exception;

class JsonDB
{
    
    private string $dataDir;
    private string $table;
    private string $filePath;
    public function __construct(string $tableName)
    {
        if (empty($tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new Exception("نام جدول نامعتبر است.");
        }
        $this->table = $tableName;
        $this->dataDir = __DIR__ . '/../data/'; 

        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0775, true);
        }

        $this->filePath = $this->dataDir . $this->table . '.json';
    }

    public function insert(array $data)
    {
        $allData = $this->getAllData();
        
        $id = $data['id'] ?? uniqid(time() . '_');
        $data['id'] = $id;

        $allData[$id] = $data;
        $this->saveAllData($allData);

        return $id;
    }

    public function findById($id): ?array
    {
        $allData = $this->getAllData();
        return $allData[$id] ?? null;
    }

    public function find(array $criteria): array
    {
        $allData = $this->getAllData();
        $results = [];

        foreach ($allData as $record) {
            $match = true;
            foreach ($criteria as $key => $value) {
                if (!isset($record[$key]) || $record[$key] !== $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $results[] = $record;
            }
        }

        return $results;
    }

    public function update($id, array $newData): bool
    {
        $allData = $this->getAllData();
        if (!isset($allData[$id])) {
            return false; 
        }

        $allData[$id] = array_merge($allData[$id], $newData);
        $this->saveAllData($allData);

        return true;
    }

    public function delete($id): bool
    {
        $allData = $this->getAllData();
        if (!isset($allData[$id])) {
            return false;
        }

        unset($allData[$id]);
        $this->saveAllData($allData);

        return true;
    }
    
    public function all(): array
    {
        return $this->getAllData();
    }

    private function getAllData(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("خطا در خواندن فایل JSON: " . json_last_error_msg() . " در فایل: " . $this->filePath);
            return [];
        }

        return $data ?? [];
    }

    private function saveAllData(array $data): void
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $file = fopen($this->filePath, 'c+');
        if ($file === false) {
            error_log("امکان باز کردن فایل برای نوشتن وجود ندارد: " . $this->filePath);
            return;
        }

        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0); 
            fwrite($file, $jsonData);
            fflush($file);
            flock($file, LOCK_UN); 
        }
        
        fclose($file);
    }
}