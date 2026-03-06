<?php
/**
 * DB.php — singleton-обёртка над SQLite (PDO)
 * Создаёт clicks.db и таблицы при первом запуске.
 */
class DB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $settings = json_decode(
                file_get_contents(__DIR__ . '/../config/settings.json'),
                true
            );

            $path = $settings['db_path'] ?? __DIR__ . '/../data/clicks.db';

            try {
                $pdo = new PDO('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA journal_mode = WAL;');
                $pdo->exec('PRAGMA foreign_keys = ON;');
                self::$instance = $pdo;
                self::createTables($pdo);
            } catch (PDOException $e) {
                error_log('[PreLend][DB] ' . $e->getMessage());
                http_response_code(500);
                exit;
            }
        }

        return self::$instance;
    }

    private static function createTables(PDO $pdo): void
    {
        $sql = file_get_contents(__DIR__ . '/../data/init_db.sql');
        $pdo->exec($sql);
    }
}
