<?php

use PHPUnit\Framework\TestCase;
use Bot\Jsondb;

class JsondbTest extends TestCase
{
    private string $tableName = 'test_table';

    // protected function tearDown(): void
    // {
    //     $path = __DIR__ . '/../data/' . $this->tableName . '.json';
    //     if (file_exists($path)) {
    //         unlink($path);
    //     }
    // }

    public function testInsertAndFindById()
    {
        $db = new Jsondb($this->tableName);
        $id = $db->insert(['name' => 'Ali']);
        $record = $db->findById($id);

        $this->assertNotNull($record);
        $this->assertEquals('Ali', $record['name']);
    }

    public function testUpdate()
    {
        $db = new Jsondb($this->tableName);
        $id = $db->insert(['name' => 'Old']);
        $db->update($id, ['name' => 'New']);
        $this->assertEquals('New', $db->findById($id)['name']);
    }

    public function testDelete()
    {
        $db = new Jsondb($this->tableName);
        $id = $db->insert(['delete_me' => true]);
        $this->assertTrue($db->delete($id));
        $this->assertNull($db->findById($id));
    }
    public function testInsertMultipleUsers()
    {
        $db = new Jsondb($this->tableName);

        $userCount = 100;
        $ids = [];

        for ($i = 0; $i < $userCount; $i++) {
            $user = [
                'username' => "user_{$i}",
                'age' => rand(18, 50),
                'email' => "user{$i}@example.com"
            ];
            $ids[] = $db->insert($user);
        }

        // بررسی تعداد رکوردها
        $all = $db->all();
        $this->assertCount($userCount, $all, "باید دقیقاً $userCount کاربر ذخیره شده باشد");

        // بررسی صحت هر رکورد
        foreach ($ids as $i => $id) {
            $record = $db->findById($id);
            $this->assertNotNull($record, "رکورد شماره {$i} یافت نشد");
            $this->assertEquals("user_{$i}", $record['username']);
            $this->assertEquals("user{$i}@example.com", $record['email']);
        }
    }
}
