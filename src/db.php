<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/url.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = cfg('db_path');
    @mkdir(dirname($path), 0770, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=30000; PRAGMA foreign_keys=ON; PRAGMA synchronous=NORMAL;');
    migrate($pdo);
    @chmod($path, 0660);
    return $pdo;
}

const SCHEMA_VERSION = 4;   // version of schema.sql

/** Bring the schema up to date: a fresh database is created from schema.sql, an older one gets the pending migrations once each. */
function migrate(PDO $pdo): void {
    $version = fn() => (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $migrations = require __DIR__ . '/migrations.php';
    if ($version() >= max([SCHEMA_VERSION, ...array_keys($migrations)])) return;
    $pdo->exec('BEGIN IMMEDIATE');   // concurrent requests wait here; the loser sees the new version below and does nothing
    try {
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE name='users'")->fetchColumn()) {
            $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
            $pdo->exec('PRAGMA user_version=' . SCHEMA_VERSION);
        }
        $from = $version();
        foreach ($migrations as $n => $run) {
            if ($n <= $from) continue;
            $run($pdo);
            $pdo->exec("PRAGMA user_version=$n");
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Prepare + execute in one go: q('SELECT … WHERE id=?', [$id])->fetch(). */
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
