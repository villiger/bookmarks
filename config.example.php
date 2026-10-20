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

    // Embeddings: multilingual-e5-small GGUF served by a short-lived llama-server during worker runs
    'llama_server' => '/opt/llama.cpp/llama-server',
    'embed_model'  => '/opt/models/multilingual-e5-small-Q8_0.gguf',
    'embed_port'   => 8099,
    'embed_threads'=> 2,
    'embed_z'      => 2.0,    // vocabulary tags with z-score >= this (vs. all vocab similarities) count as candidates
    'youtube_api_key' => '',  // optional YouTube Data API v3 key (topicCategories); empty = skip
    // Optional OpenRouter step for short/blocked pages (empty key = off). Never commit a real key.
    'openrouter_key'   => '',
    'openrouter_model' => 'google/gemini-2.5-flash-lite',
    'openrouter_cap'   => 3000,   // calls per month
    'max_attempts' => 3,
    // Starter tag vocabulary, one tag per line (edit freely). Merged with the most-used existing tags.
    'seed_tags_file' => __DIR__ . '/seed_tags.txt',
];
