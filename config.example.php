<?php
// Copy to config.php (gitignored) and edit.
return [
    // Secret used for add.php?k=... AND as the UI password. Generate: php -r 'echo bin2hex(random_bytes(24));'
    'token'        => 'CHANGE_ME',
    'db_path'      => __DIR__ . '/data/bookmarks.db',   // keep outside webroot
    'lock_path'    => __DIR__ . '/data/worker.lock',
    // Optional absolute URL of the public/ dir (for the bookmarklet), e.g. https://example.com/bookmarks
    'base_url'     => '',

    // LLM (llama.cpp)
    'llama_cli'    => '/opt/llama.cpp/llama-cli',
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
