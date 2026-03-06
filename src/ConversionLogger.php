<?php
/**
 * ConversionLogger.php
 *
 * Записывает конверсии в таблицу conversions.
 * Работает в двух режимах:
 *
 *   API (постбэк от рекламодателя):
 *     - Принимает click_id (наш SubID) из параметра запроса
 *     - Проверяет существование click_id в таблице clicks
 *     - Обновляет статус клика на 'converted'
 *     - source = 'api'
 *
 *   Manual (ручной ввод через COMMANDER):
 *     - click_id не обязателен
 *     - source = 'manual'
 *
 * Fingerprint для дедупликации:
 *   SHA256(click_id + advertiser_id + date)
 *   — предотвращает двойной зачёт одной конверсии
 */
class ConversionLogger
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Публичный API ──────────────────────────────────────────────────────────

    /**
     * Записывает конверсию от рекламодателя (postback).
     *
     * @param string $clickId      Наш click_id (SubID, переданный рекламодателю)
     * @param string $advertiserId ID рекламодателя
     * @param string $convDate     Дата конверсии YYYY-MM-DD (у рекламодателя)
     * @param int    $count        Количество конверсий (дефолт 1)
     * @param string $notes        Произвольные заметки
     * @return array               ['ok' => bool, 'conv_id' => string, 'error' => string]
     */
    public function logApi(
        string $clickId,
        string $advertiserId,
        string $convDate,
        int    $count  = 1,
        string $notes  = ''
    ): array {
        // Валидация click_id
        if (!$this->clickExists($clickId)) {
            return ['ok' => false, 'error' => 'click_id not found'];
        }

        // Дедупликация
        if ($this->isDuplicate($clickId, $advertiserId, $convDate)) {
            return ['ok' => false, 'error' => 'duplicate'];
        }

        $convId = $this->generateId();

        try {
            $this->db->prepare("
                INSERT INTO conversions (conv_id, date, advertiser_id, count, source, notes, created_at)
                VALUES (?, ?, ?, ?, 'api', ?, ?)
            ")->execute([$convId, $convDate, $advertiserId, $count, $notes, time()]);

            // Обновляем статус клика
            $this->db->prepare("
                UPDATE clicks SET status = 'converted' WHERE click_id = ?
            ")->execute([$clickId]);

        } catch (PDOException $e) {
            error_log('[PreLend][ConversionLogger] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db_error'];
        }

        return ['ok' => true, 'conv_id' => $convId];
    }

    /**
     * Записывает конверсию вручную (из Python COMMANDER или CLI).
     *
     * @param string      $advertiserId
     * @param string      $convDate       YYYY-MM-DD
     * @param int         $count
     * @param string      $notes
     * @param string|null $convId         Если передан — использует его (для TEST_*)
     * @return array
     */
    public function logManual(
        string  $advertiserId,
        string  $convDate,
        int     $count   = 1,
        string  $notes   = '',
        ?string $convId  = null
    ): array {
        $convId = $convId ?? $this->generateId();

        try {
            $this->db->prepare("
                INSERT INTO conversions (conv_id, date, advertiser_id, count, source, notes, created_at)
                VALUES (?, ?, ?, ?, 'manual', ?, ?)
            ")->execute([$convId, $convDate, $advertiserId, $count, $notes, time()]);
        } catch (PDOException $e) {
            error_log('[PreLend][ConversionLogger] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db_error'];
        }

        return ['ok' => true, 'conv_id' => $convId];
    }

    // ── Приватные методы ───────────────────────────────────────────────────────

    private function clickExists(string $clickId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM clicks WHERE click_id = ? LIMIT 1"
        );
        $stmt->execute([$clickId]);
        return (bool) $stmt->fetchColumn();
    }

    private function isDuplicate(string $clickId, string $advId, string $date): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM conversions
             WHERE conv_id = ? OR (date = ? AND advertiser_id = ?
             AND notes LIKE ? AND source = 'api')
             LIMIT 1"
        );
        // Проверяем как по conv_id так и по fingerprint
        $fp = hash('sha256', $clickId . $advId . $date);
        $stmt->execute([$fp, $date, $advId, "%{$clickId}%"]);
        return (bool) $stmt->fetchColumn();
    }

    private function generateId(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
