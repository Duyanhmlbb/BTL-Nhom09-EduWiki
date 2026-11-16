-- Migration: Ratings, Reports, and Reading History
CREATE TABLE IF NOT EXISTS page_ratings (
    id SERIAL PRIMARY KEY,
    page_id INTEGER NOT NULL REFERENCES saved_pages(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    stars INTEGER NOT NULL CHECK (stars >= 1 AND stars <= 5),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT unique_page_user_rating UNIQUE (page_id, user_id)
);

CREATE TABLE IF NOT EXISTS page_reports (
    id SERIAL PRIMARY KEY,
    page_id INTEGER NOT NULL REFERENCES saved_pages(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reason TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT unique_page_user_report UNIQUE (page_id, user_id)
);

CREATE TABLE IF NOT EXISTS reading_history (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    page_id INTEGER NOT NULL REFERENCES saved_pages(id) ON DELETE CASCADE,
    last_read_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    read_count INTEGER NOT NULL DEFAULT 1,
    CONSTRAINT unique_user_page_history UNIQUE (user_id, page_id)
);
