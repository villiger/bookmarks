CREATE TABLE IF NOT EXISTS bookmarks (
  id INTEGER PRIMARY KEY,
  url TEXT NOT NULL UNIQUE,
  title TEXT,
  description TEXT,
  favicon TEXT,
  image TEXT,
  body TEXT,
  category TEXT,
  status TEXT NOT NULL DEFAULT 'pending',   -- pending | done | error
  error TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);
CREATE INDEX IF NOT EXISTS idx_bookmarks_status ON bookmarks(status, attempts);
CREATE INDEX IF NOT EXISTS idx_bookmarks_created ON bookmarks(created_at DESC);

CREATE TABLE IF NOT EXISTS tags (
  bookmark_id INTEGER NOT NULL REFERENCES bookmarks(id) ON DELETE CASCADE,
  tag TEXT NOT NULL,
  PRIMARY KEY (bookmark_id, tag)
);
CREATE INDEX IF NOT EXISTS idx_tags_tag ON tags(tag);

-- Plain FTS5 table; rowid = bookmarks.id. Rewritten by db.php fts_reindex().
CREATE VIRTUAL TABLE IF NOT EXISTS bookmarks_fts USING fts5(
  title, description, body, tags, url,
  tokenize = 'unicode61 remove_diacritics 2'
);

CREATE TABLE IF NOT EXISTS login_failures (ip TEXT NOT NULL, ts INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_lf ON login_failures(ip, ts);
