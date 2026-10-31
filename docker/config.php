<?php
// Config for the Docker setup (BM_CONFIG points here). Values come from environment variables set in .env, see .env.example.
$env = fn(string $k, string $default = '') => ($v = getenv($k)) === false || $v === '' ? $default : $v;
$root = dirname(__DIR__);
return [
    'db_path'      => "$root/data/bookmarks.db",   // named volume, see compose.yaml
    'lock_path'    => "$root/data/worker.lock",
    'base_url'     => rtrim($env('BM_BASE_URL'), '/'),
    'mail_from'    => $env('BM_MAIL_FROM', 'bookmarks@localhost'),
    'smtp'         => ['host' => $env('BM_SMTP_HOST'), 'port' => (int)$env('BM_SMTP_PORT', '587'), 'secure' => $env('BM_SMTP_SECURE', 'tls'),
                       'user' => $env('BM_SMTP_USER'), 'pass' => $env('BM_SMTP_PASS')],
    'mail_log'     => "$root/data/mail.log",

    'youtube_api_key'   => $env('BM_YOUTUBE_API_KEY'),
    'openrouter_key'    => $env('BM_OPENROUTER_KEY'),
    'openrouter_models' => array_map('trim', explode(',', $env('BM_OPENROUTER_MODELS', 'google/gemini-2.5-flash-lite,mistralai/mistral-small-3.2-24b-instruct,meta-llama/llama-3.1-8b-instruct'))),
    'openrouter_cap'    => (int)$env('BM_OPENROUTER_CAP', '3000'),
    'max_attempts'      => 3,
    'seed_tags_file'    => "$root/seed_tags.txt",
];
