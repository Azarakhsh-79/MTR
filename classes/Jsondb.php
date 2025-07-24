<?php

declare(strict_types=1);

namespace Bot;

use InvalidArgumentException;
use RuntimeException;


class Jsondb
{
   
    private  $filePath;

    
    private ?array $dataCache = null;

    
    private string $table;
    private string $dataDir;

    public function __construct(
        string $table,
        string $dataDir = __DIR__ . '/../data/'
    ) {
        $this->table = $table;
        $this->dataDir = $dataDir;

        if (empty($this->table) || !preg_match('/^[a-zA-Z0-9_]+$/', $this->table)) {
            throw new InvalidArgumentException("نام جدول نامعتبر است.");
        }

        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0775, true);
        }

        $this->filePath = $this->dataDir . $this->table . '.json';
        date_default_timezone_set('Asia/Tehran');
    }

    public function insert(array $data): string
    {
        $allData = $this->getAllData();

        $id = $data['id'] ?? uniqid(time() . '_');
        $data['id'] = $id;

        $allData[$id] = $data;
        $this->saveAllData($allData);

        return $id;
    }

    public function findById(string|int $id): ?array
    {
        return $this->getAllData()[$id] ?? null;
    }

    public function find(array $criteria): array
    {
        $allData = $this->getAllData();

        $results = array_filter($allData, function ($record) use ($criteria) {
            foreach ($criteria as $key => $value) {
                if (!isset($record[$key]) || $record[$key] !== $value) {
                    return false; 
                }
            }
            return true; 
        });

        return array_values($results);
    }

    public function unsetKey(string|int $id, string $key): bool
    {
        $allData = $this->getAllData();
        if (!isset($allData[$id])) {
            return false;
        }

        if (array_key_exists($key, $allData[$id])) {
            unset($allData[$id][$key]);
            $this->saveAllData($allData);
        }

        return true;
    }

 
    public function update(string|int $id, array $newData): bool
    {
        $allData = $this->getAllData();
        if (!isset($allData[$id])) {
            return false;
        }

        $allData[$id] = array_merge($allData[$id], $newData);
        $this->saveAllData($allData);

        return true;
    }

  
    public function delete(string|int $id): bool
    {
        $allData = $this->getAllData();
        if (!isset($allData[$id])) {
            return false;
        }

        unset($allData[$id]);
        $this->saveAllData($allData);

        return true;
    }

   
    public function set(string $key, mixed $value): void
    {
        $allData = $this->getAllData();
        $allData[$key] = $value;
        $this->saveAllData($allData);
    }

  
    public function all(): array
    {
        return $this->getAllData();
    }

    private function getAllData(): array
    {
        if ($this->dataCache !== null) {
            return $this->dataCache;
        }

        if (!file_exists($this->filePath)) {
            return $this->dataCache = []; 
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            throw new RuntimeException("امکان خواندن فایل وجود ندارد: " . $this->filePath);
        }

        if (trim($content) === '') {
            return $this->dataCache = [];
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "خطا در تجزیه JSON: " . json_last_error_msg() . " در فایل: " . $this->filePath
            );
        }

        return $this->dataCache = $data ?? [];
    }

    private function saveAllData(array $data): void
    {
        $this->dataCache = $data;

        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            throw new RuntimeException('خطا در تبدیل داده به JSON: ' . json_last_error_msg());
        }

        $result = file_put_contents($this->filePath, $jsonData, LOCK_EX);

        if ($result === false) {
            throw new RuntimeException("امکان نوشتن در فایل وجود ندارد: " . $this->filePath);
        }
    }
}