<?php
declare(strict_types=1);

namespace RuneRide;

use Doctrine\DBAL\{Connection, DriverManager};
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use PDO;

final class Database
{
    /** Pilote DBAL selon le préfixe du DSN PDO (la configuration `.env` reste en DSN PDO). */
    private const PILOTES = ['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql'];

    /** Connexion DBAL paresseuse : rien n'est ouvert avant la première requête. */
    public static function connect(Config $c): Connection
    {
        return DriverManager::getConnection(self::parametres($c->get('RUNERIDE_DB_DSN'), $c->get('RUNERIDE_DB_USER'), $c->get('RUNERIDE_DB_PASSWORD', '')));
    }

    /**
     * Traduit un DSN PDO (« mysql:host=…;port=…;dbname=…;charset=… ») en paramètres DBAL.
     * @return array<string,mixed>
     */
    public static function parametres(string $dsn, string $user, string $password): array
    {
        [$prefixe, $reste] = array_pad(explode(':', $dsn, 2), 2, '');
        if (!isset(self::PILOTES[$prefixe])) {
            throw new InvalidArgumentException('DSN non pris en charge : pilote ' . $prefixe);
        }
        $kv = [];
        foreach (array_filter(explode(';', $reste), 'strlen') as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $kv[strtolower(trim($k))] = trim($v);
        }
        $p = ['driver' => self::PILOTES[$prefixe], 'user' => $user, 'password' => $password,
            'driverOptions' => [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]];
        foreach (['host', 'dbname', 'charset', 'unix_socket'] as $cle) {
            if (isset($kv[$cle])) {
                $p[$cle] = $kv[$cle];
            }
        }
        if (isset($kv['port'])) {
            $p['port'] = (int) $kv['port'];
        }
        return $p;
    }

    /** Applique, dans l'ordre, les migrations .sql pas encore jouées. @return list<string> */
    public static function migrate(Connection $db, string $dir): array
    {
        // Un sous-dossier par pilote (mariadb, pgsql) : le SQL de schéma n'est pas portable tel quel.
        $dir .= '/' . ($db->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'pgsql' : 'mariadb');
        $db->executeStatement('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $done = $db->fetchFirstColumn('SELECT name FROM migrations');
        $applied = [];
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $db->executeStatement((string) file_get_contents($file)); // fichier de notre dépôt, une instruction par fichier
            $db->insert('migrations', ['name' => $name]);
            $applied[] = $name;
        }
        return $applied;
    }
}
