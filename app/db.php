<?php
/**
 * Koneksi database (PDO, singleton) + helper query ringkas.
 */
declare(strict_types=1);

if (!defined('BHM_APP')) { http_response_code(403); exit('403 - Akses ditolak.'); }

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        exit(APP_DEBUG
            ? 'Koneksi database gagal: ' . $e->getMessage()
            : 'Koneksi database gagal. Hubungi administrator.');
    }
    return $pdo;
}

/** Jalankan query berparameter, kembalikan PDOStatement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Ambil 1 baris (atau null). */
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Ambil semua baris. */
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Ambil 1 kolom pertama dari baris pertama. */
function scalar(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** INSERT ringkas: insert('kamar', ['kode'=>'A-01', ...]) -> last insert id */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** UPDATE ringkas: update('kamar', ['status'=>'terisi'], 'id = ?', [3]) */
function update(string $table, array $data, string $where, array $wp = []): int
{
    $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
    $sql = "UPDATE `$table` SET $set WHERE $where";
    return q($sql, array_merge(array_values($data), $wp))->rowCount();
}

// ---------------------------------------------------------------------
// Pengaturan (key-value) dengan cache in-memory
// ---------------------------------------------------------------------
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT k, v FROM pengaturan') as $r) {
            $cache[$r['k']] = (string) $r['v'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $val): void
{
    q('INSERT INTO pengaturan (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $val]);
}
