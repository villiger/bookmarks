CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  is_admin INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'pending',   -- pending | active | disabled
  bookmarklet_key TEXT NOT NULL UNIQUE,
  created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);
CREATE TABLE IF NOT EXISTS login_tokens (hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, expires INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS sessions (hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at INTEGER NOT NULL, expires INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS rate_events (kind TEXT NOT NULL, key TEXT NOT NULL, ts INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_rate ON rate_events(kind, key, ts);

CREATE TABLE IF NOT EXISTS bookmarks (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  url TEXT NOT NULL,
  url_key TEXT NOT NULL DEFAULT '',
  scheme_guessed INTEGER NOT NULL DEFAULT 0,
  title TEXT,
  title_orig TEXT,                               -- page title as crawled; title is the cleaned one
  description TEXT,
  summary TEXT,                                  -- short neutral summary shown on the card (LLM), else description is cut cleanly
  favicon TEXT,
  image TEXT,
  body TEXT,
  category TEXT,
  status TEXT NOT NULL DEFAULT 'pending',   -- pending | done | error
  error TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  tags_edited INTEGER NOT NULL DEFAULT 0,
  client_title TEXT, client_desc TEXT, client_image TEXT, client_favicon TEXT, client_canonical TEXT, client_keywords TEXT, client_text TEXT,  -- sent by the bookmarklet
  llm_pending INTEGER NOT NULL DEFAULT 0, llm_attempts INTEGER NOT NULL DEFAULT 0, llm_next_at INTEGER NOT NULL DEFAULT 0,   -- deferred OpenRouter step
  crawl_source TEXT,                             -- direct | jina | client
  embedding BLOB,                                -- float32 e5 vector of the page text
  created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  UNIQUE(user_id, url)
);
CREATE INDEX IF NOT EXISTS idx_bookmarks_status ON bookmarks(status, attempts);
CREATE INDEX IF NOT EXISTS idx_bookmarks_created ON bookmarks(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS tags (
  bookmark_id INTEGER NOT NULL REFERENCES bookmarks(id) ON DELETE CASCADE,
  tag TEXT NOT NULL,
  suggested INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (bookmark_id, tag)
);
CREATE INDEX IF NOT EXISTS idx_tags_tag ON tags(tag);

-- Plain FTS5 table; rowid = bookmarks.id. Rewritten by db.php fts_reindex().
CREATE VIRTUAL TABLE IF NOT EXISTS bookmarks_fts USING fts5(
  title, description, body, tags, url,
  tokenize = 'unicode61 remove_diacritics 2'
);


-- cached e5 embeddings of the vocabulary descriptions (h = md5 of the description)
CREATE TABLE IF NOT EXISTS tag_vec (tag TEXT PRIMARY KEY, h TEXT NOT NULL, vec BLOB NOT NULL);

-- OpenRouter calls per month (cap in config)
CREATE TABLE IF NOT EXISTS api_usage (month TEXT PRIMARY KEY, calls INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS api_model_usage (month TEXT NOT NULL, model TEXT NOT NULL, calls INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (month, model));
