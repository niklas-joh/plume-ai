-- plan column supports all four tiers: 'free', 'trial', 'pro_managed', 'pro'
-- Add new tiers here; TIER_CONFIG in src/tier-config.ts is the capability definition.
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER  PRIMARY KEY AUTOINCREMENT,
  email         TEXT     UNIQUE NOT NULL COLLATE NOCASE,
  password_hash TEXT     NOT NULL,
  plan          TEXT     NOT NULL DEFAULT 'trial' CHECK(plan IN ('free','trial','pro_managed','pro')),
  plan_expires  DATETIME,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
