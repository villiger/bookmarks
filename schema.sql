-- Schema of a fresh database (SCHEMA_VERSION in src/db.php). Later changes: add an entry to src/migrations.php, bump SCHEMA_VERSION and edit this file to match.
CREATE TABLE users (
  id INTEGER PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  is_admin INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'pending',   -- pending | active | disabled
  bookmarklet_key TEXT NOT NULL UNIQUE,
  created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);
CREATE TABLE login_tokens (hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, expires INTEGER NOT NULL);
CREATE TABLE sessions (hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at INTEGER NOT NULL, expires INTEGER NOT NULL);
CREATE TABLE rate_events (kind TEXT NOT NULL, key TEXT NOT NULL, ts INTEGER NOT NULL);
CREATE INDEX idx_rate ON rate_events(kind, key, ts);

CREATE TABLE bookmarks (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  url TEXT NOT NULL,
  url_key TEXT NOT NULL DEFAULT '',                -- normalized URL: duplicates are detected on it
  scheme_guessed INTEGER NOT NULL DEFAULT 0,
  title TEXT,
  title_orig TEXT,                                 -- page title as crawled; title is the cleaned one
  description TEXT,
  summary TEXT,                                    -- short neutral summary shown on the card (LLM), else description is cut cleanly
  favicon TEXT,
  image TEXT,
  body TEXT,
  category TEXT,
  tags_edited INTEGER NOT NULL DEFAULT 0,          -- 1 = the user corrected the tags: reprocessing leaves them alone
  text_edited INTEGER NOT NULL DEFAULT 0,          -- 1 = title/summary written by the user: rescans keep them
  crawl_source TEXT,                               -- direct | jina | client
  created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  deleted_at INTEGER,                              -- set = in the trash (kept TRASH_DAYS days)
  UNIQUE(user_id, url)
);
CREATE UNIQUE INDEX idx_bookmarks_urlkey ON bookmarks(user_id, url_key);
CREATE INDEX idx_bookmarks_created ON bookmarks(user_id, created_at DESC);
CREATE INDEX idx_bookmarks_deleted ON bookmarks(deleted_at) WHERE deleted_at IS NOT NULL;

-- Processing state of a bookmark; the row is created by a trigger.
CREATE TABLE jobs (
  bookmark_id INTEGER PRIMARY KEY REFERENCES bookmarks(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'pending',          -- pending | done | error
  error TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  refresh INTEGER NOT NULL DEFAULT 0,              -- 1 = full refresh of an edited bookmark: keep its applied tags, add new ones
  llm_pending INTEGER NOT NULL DEFAULT 0, llm_attempts INTEGER NOT NULL DEFAULT 0, llm_next_at INTEGER NOT NULL DEFAULT 0   -- deferred OpenRouter step
);
CREATE INDEX idx_jobs_status ON jobs(status, attempts);
CREATE TRIGGER bookmarks_job AFTER INSERT ON bookmarks BEGIN INSERT INTO jobs(bookmark_id) VALUES (new.id); END;

-- Page data sent by the bookmarklet (used when the page cannot be fetched).
CREATE TABLE capture (
  bookmark_id INTEGER PRIMARY KEY REFERENCES bookmarks(id) ON DELETE CASCADE,
  client_title TEXT, client_desc TEXT, client_image TEXT, client_favicon TEXT, client_canonical TEXT, client_keywords TEXT, client_text TEXT
);

CREATE TABLE tags (
  bookmark_id INTEGER NOT NULL REFERENCES bookmarks(id) ON DELETE CASCADE,
  tag TEXT NOT NULL,
  suggested INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (bookmark_id, tag)
);
CREATE INDEX idx_tags_tag ON tags(tag);

-- Plain FTS5 table; rowid = bookmarks.id. Rewritten by fts_reindex().
CREATE VIRTUAL TABLE bookmarks_fts USING fts5(
  title, description, body, tags, url,
  tokenize = 'unicode61 remove_diacritics 2'
);

-- OpenRouter calls per month: model '' = every call made (the cap in config), other rows = the model that answered.
CREATE TABLE api_usage (month TEXT NOT NULL, model TEXT NOT NULL DEFAULT '', calls INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (month, model));
