<?php
declare(strict_types=1);

namespace RuneRide;

use PDO;

final class Database
{
    public static function connect(Config $c): PDO
    {
        return new PDO($c->get('RUNERIDE_DB_DSN'), $c->get('RUNERIDE_DB_USER'), $c->get('RUNERIDE_DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** Applique, dans l'ordre, les migrations .sql pas encore jouées. @return list<string> */
    public static function migrate(PDO $pdo, string $dir): array
    {
        // Un sous-dossier par pilote (mariadb, pgsql) : le SQL de schéma n'est pas portable tel quel.
        $dir .= '/' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'pgsql' : 'mariadb');
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $done = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = [];
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $pdo->exec((string) file_get_contents($file));
            $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }
}
