<?php
// Schema changes after schema.sql, applied once each in order to databases older than the entry (PRAGMA user_version = last applied).
// To change the schema: add an entry here, bump SCHEMA_VERSION in db.php and edit schema.sql so a fresh database matches. Never edit an applied migration.

return [
    // 4: the two OpenRouter counters become one table
    4 => function (PDO $pdo) {
        $pdo->exec("CREATE TABLE api_usage_new (month TEXT NOT NULL, model TEXT NOT NULL DEFAULT '', calls INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (month, model))");
        $pdo->exec("INSERT INTO api_usage_new SELECT month, '', calls FROM api_usage");
        $pdo->exec('INSERT INTO api_usage_new SELECT month, model, calls FROM api_model_usage');
        $pdo->exec('DROP TABLE api_usage');
        $pdo->exec('DROP TABLE api_model_usage');
        $pdo->exec('ALTER TABLE api_usage_new RENAME TO api_usage');
    },
];
