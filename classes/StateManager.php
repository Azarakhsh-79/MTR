<?php

namespace Bot;

class StateManager
{
    private $stateFilePath;
    private $lockFilePointer;

    public function __construct()
    {
        $this->stateFilePath = __DIR__ . '/../data/state/'; 
        if (!is_dir($this->stateFilePath)) {
            mkdir($this->stateFilePath, 0777, true);
        }
    }

    public function lockState(string $chatId): bool
    {
        $lockFile = $this->stateFilePath . $chatId . '.lock';
        $this->lockFilePointer = fopen($lockFile, 'w');
        if ($this->lockFilePointer === false) {
            Logger::log('error', 'Locking Failed', "Could not open lock file for chat ID {$chatId}.");
            return false;
        }
        return flock($this->lockFilePointer, LOCK_EX | LOCK_NB);
    }

    public function unlockState(string $chatId): void
    {
        if ($this->lockFilePointer) {
            flock($this->lockFilePointer, LOCK_UN);
            fclose($this->lockFilePointer);
            $this->lockFilePointer = null;
            @unlink($this->stateFilePath . $chatId . '.lock');
        }
    }

    public function getState(string $chatId): ?array
    {
        $file = $this->stateFilePath . $chatId . '.json';
        if (!file_exists($file)) return null;
        $stateData = file_get_contents($file);
        return $stateData ? json_decode($stateData, true) : null;
    }

    public function saveState(string $chatId, array $state): void
    {
        $file = $this->stateFilePath . $chatId . '.json';
        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
    }

    public function clearState(string $chatId): void
    {
        $stateFile = $this->stateFilePath . $chatId . '.json';
        if (file_exists($stateFile)) @unlink($stateFile);
        $this->clearVerificationCode($chatId);
    }

    private function getVerificationCodePath(string $chatId): string
    {
        return $this->stateFilePath . $chatId . '.vcode';
    }

    public function saveVerificationCode(string $chatId, string $code): void
    {
        $path = $this->getVerificationCodePath($chatId);
        $data = json_encode(['code' => $code, 'expires_at' => time() + 300]);
        $fp = fopen($path, 'w');
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $data);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public function getVerificationCode(string $chatId): ?string
    {
        $path = $this->getVerificationCodePath($chatId);
        if (!file_exists($path)) {
            return null;
        }
        $fp = fopen($path, 'r');
        if (!$fp) return null;
        $content = '';
        if (flock($fp, LOCK_SH)) {
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        if (empty($content)) return null;
        $data = json_decode($content, true);
        if (empty($data['code']) || empty($data['expires_at']) || time() > $data['expires_at']) {
            @unlink($path);
            return null;
        }
        return $data['code'];
    }

    public function clearVerificationCode(string $chatId): void
    {
        $path = $this->getVerificationCodePath($chatId);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    public function saveStepPage(string $chatId, string $stepKey, int $page): void
    {
        $state = $this->getState($chatId) ?? [];
        $state['pages'][$stepKey] = $page;
        $this->saveState($chatId, $state);
    }

    public function getStepPage(string $chatId, string $stepKey): int
    {
        $state = $this->getState($chatId);
        return $state['pages'][$stepKey] ?? 1;
    }
}