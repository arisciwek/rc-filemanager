<?php
/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File    : /plugins/filemanager/lib/store.php
 * Path    : /var/www/roundcube/plugins/filemanager/lib/store.php
 *
 * Store kredensial klien di MySQL (DB yang sama dengan Roundcube),
 * diakses lewat PDO langsung dengan DSN dari config Roundcube sehingga
 * dipakai identik oleh tiga konteks:
 *   1. Gateway staff  public_html/filemanager.php   (bootstrap penuh)
 *   2. Entry klien    public_html/filemanager-client.php (tanpa bootstrap)
 *   3. CRUD UI        ?_task=filemanager&_action=clients
 *
 * Kelas ini SENGAJA tidak bergantung pada framework Roundcube maupun
 * konstanta FM_* agar aman dimuat dari mana saja.
 *
 * @author     ArisCiwek
 * @copyright  GNU AGPL v3 or later
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 */

class fm_store
{
    const TABLE = 'filemanager_clients';

    /** @var PDO|null */
    private static $pdo;

    /**
     * Ambil nilai db_dsnw dari config.inc.php Roundcube tanpa
     * mem-bootstrap framework: variabel $config didefinisikan dulu,
     * lalu file config mengisinya dalam scope ini.
     */
    private static function read_dsn()
    {
        static $dsn = null;
        if ($dsn !== null) {
            return $dsn;
        }
        $candidates = [
            dirname(dirname(dirname(__DIR__))) . '/config/config.inc.php',
            '/var/www/roundcube/config/config.inc.php',
        ];
        foreach ($candidates as $f) {
            if (!is_readable($f)) {
                continue;
            }
            $config = [];
            include $f;
            if (!empty($config['db_dsnw'])) {
                $dsn = (string) $config['db_dsnw'];
                return $dsn;
            }
        }
        $dsn = '';
        return $dsn;
    }

    /** Koneksi PDO tunggal (lazy). */
    public static function pdo()
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = self::read_dsn();
        if ($dsn === '' || stripos($dsn, 'mysql://') !== 0) {
            throw new RuntimeException('Filemanager store: db_dsnw mysql tidak ditemukan.');
        }

        // mysql://user:pass@host[:port]/db[?query]
        $u = parse_url($dsn);
        if (empty($u['host']) || empty($u['path'])) {
            throw new RuntimeException('Filemanager store: format db_dsnw tak terbaca.');
        }

        $pdo_dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $u['host'],
            isset($u['port']) ? $u['port'] : 3306,
            ltrim($u['path'], '/')
        );
        $user = isset($u['user']) ? rawurldecode($u['user']) : '';
        $pass = isset($u['pass']) ? rawurldecode($u['pass']) : '';

        self::$pdo = new PDO($pdo_dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }

    /** Buat tabel bila belum ada (aman dipanggil tiap kali). */
    public static function ensure_table()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . " (
            username   VARCHAR(64)  NOT NULL,
            hash       VARCHAR(255) NOT NULL,
            home       VARCHAR(512) NOT NULL DEFAULT '',
            paths      TEXT         NOT NULL,
            readonly   TINYINT(1)   NOT NULL DEFAULT 0,
            created_at DATETIME     NOT NULL,
            updated_at DATETIME     NOT NULL,
            created_by VARCHAR(192) NOT NULL,
            updated_by VARCHAR(192) DEFAULT NULL,
            PRIMARY KEY (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        self::pdo()->exec($sql);
    }

    /**
     * Semua baris, terurut username. Kolom paths dikembalikan tetap
     * sebagai string JSON; pemanggil yang decode.
     */
    public static function all()
    {
        return self::pdo()
            ->query('SELECT * FROM ' . self::TABLE . ' ORDER BY username')
            ->fetchAll();
    }

    /** Satu baris atau NULL. */
    public static function get($username)
    {
        $st = self::pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE username = ?'
        );
        $st->execute([(string) $username]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Insert/update satu klien. $row minimal: username, hash, home,
     * paths (string JSON), readonly; kolom created_at & updated_at
     * diisi otomatis.
     */
    public static function save(array $row, $actor)
    {
        self::ensure_table();

        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . self::TABLE
            . ' (username, hash, home, paths, readonly, created_at, updated_at, created_by)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . '  hash = VALUES(hash), home = VALUES(home), paths = VALUES(paths),'
            . '  readonly = VALUES(readonly), updated_at = VALUES(updated_at),'
            . '  updated_by = VALUES(updated_by)';

        $st = self::pdo()->prepare($sql);
        return $st->execute([
            (string) $row['username'],
            (string) $row['hash'],
            (string) $row['home'],
            (string) $row['paths'],
            !empty($row['readonly']) ? 1 : 0,
            $now,
            $now,
            (string) $actor,
        ]);
    }

    public static function delete($username)
    {
        $st = self::pdo()->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE username = ?'
        );
        return $st->execute([(string) $username]);
    }
}
