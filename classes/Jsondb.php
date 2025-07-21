<?php

namespace Bot;

use Exception;

/**
 * کلاس JsonDB برای مدیریت داده‌ها در فایل‌های JSON به عنوان یک پایگاه داده ساده.
 * این کلاس عملیات CRUD (ایجاد، خواندن، به‌روزرسانی، حذف) را پیاده‌سازی می‌کند.
 * برای جلوگیری از تداخل در نوشتن همزمان، از قفل انحصاری فایل (file locking) استفاده می‌کند.
 */
class JsonDB
{
    /**
     * مسیر پوشه‌ای که فایل‌های JSON در آن ذخیره می‌شوند.
     * @var string
     */
    private string $dataDir;

    /**
     * نام "جدول" که در واقع نام فایل JSON است (بدون پسوند).
     * @var string
     */
    private string $table;

    /**
     * مسیر کامل فایل JSON.
     * @var string
     */
    private string $filePath;

    /**
     * سازنده کلاس که نام جدول (فایل) را دریافت می‌کند.
     *
     * @param string $tableName نام جدولی که می‌خواهید با آن کار کنید.
     */
    public function __construct(string $tableName)
    {
        if (empty($tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new Exception("نام جدول نامعتبر است.");
        }
        $this->table = $tableName;
        $this->dataDir = __DIR__ . '/../data/'; // داده‌ها در پوشه‌ای به نام data ذخیره می‌شوند

        // اگر پوشه data وجود نداشت، آن را ایجاد کن
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0775, true);
        }

        $this->filePath = $this->dataDir . $this->table . '.json';
    }

    /**
     * یک رکورد جدید به جدول اضافه می‌کند.
     *
     * @param array $data داده‌ای که باید ذخیره شود.
     * @return string|int شناسه (ID) رکورد جدید.
     */
    public function insert(array $data)
    {
        $allData = $this->getAllData();
        
        // ایجاد یک شناسه منحصر به فرد
        $id = $data['id'] ?? uniqid(time() . '_');
        $data['id'] = $id;

        $allData[$id] = $data;
        $this->saveAllData($allData);

        return $id;
    }

    /**
     * یک رکورد را بر اساس شناسه (ID) آن پیدا می‌کند.
     *
     * @param string|int $id شناسه رکورد.
     * @return array|null داده‌های رکورد یا null اگر پیدا نشود.
     */
    public function findById($id): ?array
    {
        $allData = $this->getAllData();
        return $allData[$id] ?? null;
    }

    /**
     * رکوردها را بر اساس یک یا چند شرط جستجو می‌کند.
     *
     * @param array $criteria شروط جستجو به صورت ['key' => 'value'].
     * @return array آرایه‌ای از رکوردهای مطابق با شرط.
     */
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

    /**
     * یک رکورد موجود را به‌روزرسانی می‌کند.
     *
     * @param string|int $id شناسه رکوردی که باید آپدیت شود.
     * @param array $newData داده‌های جدید.
     * @return bool true در صورت موفقیت و false در صورت عدم وجود رکورد.
     */
    public function update($id, array $newData): bool
    {
        $allData = $this->getAllData();
        if (!isset($allData[$id])) {
            return false; // رکورد وجود ندارد
        }

        // داده‌های جدید با داده‌های قبلی ادغام می‌شوند
        $allData[$id] = array_merge($allData[$id], $newData);
        $this->saveAllData($allData);

        return true;
    }

    /**
     * یک رکورد را بر اساس شناسه آن حذف می‌کند.
     *
     * @param string|int $id شناسه رکوردی که باید حذف شود.
     * @return bool true در صورت موفقیت و false در صورت عدم وجود رکورد.
     */
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
    
    /**
     * تمام رکوردهای جدول را برمی‌گرداند.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->getAllData();
    }

    /**
     * تمام داده‌ها را از فایل JSON می‌خواند.
     * @return array
     */
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

    /**
     * تمام داده‌ها را در فایل JSON ذخیره می‌کند (با استفاده از قفل انحصاری).
     * @param array $data
     */
    private function saveAllData(array $data): void
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // استفاده از c+ برای ایجاد فایل در صورت عدم وجود
        $file = fopen($this->filePath, 'c+');
        if ($file === false) {
            error_log("امکان باز کردن فایل برای نوشتن وجود ندارد: " . $this->filePath);
            return;
        }

        // قفل کردن فایل برای جلوگیری از نوشتن همزمان
        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0); // خالی کردن محتوای فایل قبل از نوشتن
            fwrite($file, $jsonData);
            fflush($file); // اطمینان از نوشته شدن داده‌ها روی دیسک
            flock($file, LOCK_UN); // آزاد کردن قفل
        }
        
        fclose($file);
    }
}