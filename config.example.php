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

    'youtube_api_key' => '',  // optional YouTube Data API v3 key (topicCategories); empty = skip
    // OpenRouter does the tagging and summaries (empty key = rules only). Never commit a real key.
    'openrouter_key'   => '',
    // First = primary, the rest are tried automatically on errors/429 (OpenRouter `models` fallback). Avoid :free variants.
    'openrouter_models' => ['google/gemini-2.5-flash-lite', 'mistralai/mistral-small-3.2-24b-instruct', 'meta-llama/llama-3.1-8b-instruct'],
    'openrouter_cap'   => 3000,   // calls per month
    'max_attempts' => 3,
    // Starter tag vocabulary, one tag per line (edit freely). Merged with the most-used existing tags.
    'seed_tags_file' => __DIR__ . '/seed_tags.txt',
];
