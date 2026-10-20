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
  description TEXT,
  favicon TEXT,
  image TEXT,
  body TEXT,
  category TEXT,
  status TEXT NOT NULL DEFAULT 'pending',   -- pending | done | error
  error TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  tags_edited INTEGER NOT NULL DEFAULT 0,
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
