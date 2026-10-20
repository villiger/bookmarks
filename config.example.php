<?php
// Copy to config.php (gitignored) and edit.
return [
    'db_path'      => __DIR__ . '/data/bookmarks.db',   // keep outside webroot
    'lock_path'    => __DIR__ . '/data/worker.lock',
    // Optional absolute URL of the public/ dir (for the bookmarklet), e.g. https://example.com/bookmarks
    'base_url'     => '',   // REQUIRED: used in login links and bookmarklets, no trailing slash
    'mail_from'    => 'bookmarks@localhost',
    // SMTP for login mails. Leave host empty => mails are only appended to data/mail.log (chmod 600).
    'smtp'         => ['host' => '', 'port' => 587, 'secure' => 'tls' /* tls|ssl|none */, 'user' => '', 'pass' => ''],
    'mail_log'     => __DIR__ . '/data/mail.log',

    // LLM (llama.cpp)
    'llama_cli'    => '/opt/llama.cpp/llama-completion',
    'llama_model'  => '/opt/models/qwen2.5-0.5b-instruct-q4_k_m.gguf',
    'llama_threads'=> 2,
    'llama_ctx'    => 2048,
    'llama_timeout'=> 120,
    // Command prefix to cap memory. 'auto' = try systemd-run, else ulimit. '' = none.
    // Example: 'systemd-run --user --scope -q -p MemoryMax=600M'
    'llama_wrapper'=> 'auto',
    'llama_mem_mb' => 600,
    'max_attempts' => 3,
    // Starter tag vocabulary, one tag per line (edit freely). Merged with the most-used existing tags.
    'seed_tags_file' => __DIR__ . '/seed_tags.txt',
];
