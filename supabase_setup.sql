-- ═══════════════════════════════════════════════════════════════
-- SUPABASE SQL SETUP — Run this in Supabase SQL Editor
-- Dashboard → SQL Editor → New query → Paste → Run
-- ═══════════════════════════════════════════════════════════════

-- AI Memories table (stores preferences, corrections, patterns, facts)
CREATE TABLE IF NOT EXISTS ai_memories (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT,
    device_id VARCHAR(255),
    type VARCHAR(50) NOT NULL,
    key VARCHAR(255) NOT NULL,
    value JSONB NOT NULL DEFAULT '{}',
    hits INTEGER NOT NULL DEFAULT 1,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ai_memories_user_type_key ON ai_memories(user_id, type, key);
CREATE INDEX IF NOT EXISTS idx_ai_memories_device_type_key ON ai_memories(device_id, type, key);
CREATE INDEX IF NOT EXISTS idx_ai_memories_type ON ai_memories(type);

-- AI Conversations table (stores conversation summaries)
CREATE TABLE IF NOT EXISTS ai_conversations (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT,
    device_id VARCHAR(255),
    summary TEXT NOT NULL,
    topics JSONB DEFAULT '[]',
    mood VARCHAR(50),
    message_count INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ai_conversations_user ON ai_conversations(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ai_conversations_device ON ai_conversations(device_id, created_at DESC);

-- Enable Row Level Security
ALTER TABLE ai_memories ENABLE ROW LEVEL SECURITY;
ALTER TABLE ai_conversations ENABLE ROW LEVEL SECURITY;

-- Allow anon key full access (Laravel backend is the trusted client)
CREATE POLICY "Allow all for anon" ON ai_memories FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Allow all for anon" ON ai_conversations FOR ALL USING (true) WITH CHECK (true);

-- Productivity Snapshots table (stores historical performance metrics)
CREATE TABLE IF NOT EXISTS productivity_snapshots (
    id SERIAL PRIMARY KEY,
    user_id BIGINT,
    device_id VARCHAR(255),
    total_tasks INTEGER NOT NULL DEFAULT 0,
    completed_tasks INTEGER NOT NULL DEFAULT 0,
    overdue_tasks INTEGER NOT NULL DEFAULT 0,
    mental_load_score INTEGER NOT NULL DEFAULT 0,
    efficiency_rating DECIMAL(5, 2) NOT NULL DEFAULT 0,
    expert_metadata JSONB DEFAULT '{}',
    measured_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_productivity_snapshots_user ON productivity_snapshots(user_id, measured_at DESC);
CREATE INDEX IF NOT EXISTS idx_productivity_snapshots_device ON productivity_snapshots(device_id, measured_at DESC);

-- Enable RLS & Policy
ALTER TABLE productivity_snapshots ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow all for anon" ON productivity_snapshots FOR ALL USING (true) WITH CHECK (true);

-- ═══════════════════════════════════════════════════════════════
-- ORGANIZATIONAL TABLES (Mirroring Laravel Industrial Migrations)
-- ═══════════════════════════════════════════════════════════════

-- Workspaces
CREATE TABLE IF NOT EXISTS workspaces (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    settings JSONB DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Projects
CREATE TABLE IF NOT EXISTS projects (
    id BIGSERIAL PRIMARY KEY,
    workspace_id BIGINT REFERENCES workspaces(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    color VARCHAR(7), -- Hex code
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Todo States (Industrial Workflow)
CREATE TABLE IF NOT EXISTS todo_states (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7),
    is_terminal BOOLEAN DEFAULT FALSE, -- Completed/Cancelled
    sort_order INTEGER DEFAULT 0
);

-- Seed Todo States (Matches AiChatController assumptions)
INSERT INTO todo_states (id, name, color, is_terminal, sort_order) VALUES
(1, 'Backlog', '#94A3B8', false, 10),
(2, 'Todo', '#3B82F6', false, 20),
(3, 'In Progress', '#F59E0B', false, 30),
(4, 'Review', '#8B5CF6', false, 40),
(5, 'Completed', '#10B981', true, 50),
(6, 'Cancelled', '#EF4444', true, 60)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, color = EXCLUDED.color, is_terminal = EXCLUDED.is_terminal;

-- Enable RLS for all
ALTER TABLE workspaces ENABLE ROW LEVEL SECURITY;
ALTER TABLE projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE todo_states ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Allow all for anon" ON workspaces FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Allow all for anon" ON projects FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Allow all for anon" ON todo_states FOR ALL USING (true) WITH CHECK (true);
