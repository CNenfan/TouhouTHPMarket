-- 芙湘东方高校联合THP活动网站 数据库结构
-- 适用：MySQL 5.7+ / 8.0 / MariaDB 10.4+
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(32) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  admin_scope VARCHAR(16) NOT NULL DEFAULT '',
  balance INT NOT NULL DEFAULT 0,
  debt INT NOT NULL DEFAULT 0,
  banned TINYINT(1) NOT NULL DEFAULT 0,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  terms_accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  last_ip VARCHAR(45) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_username (username),
  KEY idx_role_state (role, banned, is_test)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 长期登录令牌：Cookie 保存 selector.token，数据库仅保存 token 的 SHA-256 摘要。
CREATE TABLE IF NOT EXISTS auth_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uk_selector (selector),
  KEY idx_user (user_id, id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS token_tx (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  delta INT NOT NULL,
  balance_after INT NOT NULL,
  type VARCHAR(32) NOT NULL,
  ref VARCHAR(64) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id, id),
  KEY idx_type_user (type, user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS spend_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code CHAR(6) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  amount INT NOT NULL,
  status ENUM('pending','used','expired','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  used_by_admin INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code),
  KEY idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS issue_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code CHAR(6) NOT NULL,
  amount INT NOT NULL,
  status ENUM('pending','used','revoked') NOT NULL DEFAULT 'pending',
  created_by INT UNSIGNED NOT NULL,
  used_by INT UNSIGNED NULL,
  note VARCHAR(128) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS questions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code CHAR(6) NOT NULL,
  title VARCHAR(64) NOT NULL,
  body TEXT NOT NULL,
  options TEXT NOT NULL,
  correct_index TINYINT NOT NULL DEFAULT 0,
  reward INT NOT NULL DEFAULT 20,
  image VARCHAR(255) NOT NULL DEFAULT '',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  chosen TINYINT NOT NULL,
  correct TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_q_user (question_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code CHAR(6) NOT NULL,
  title VARCHAR(64) NOT NULL,
  body TEXT NOT NULL,
  images TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_stories (
  user_id INT UNSIGNED NOT NULL,
  story_id INT UNSIGNED NOT NULL,
  unlocked_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_bindings (
  device_key CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  fingerprint CHAR(64) NOT NULL DEFAULT '',
  first_ip VARCHAR(45) NOT NULL DEFAULT '',
  last_ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (device_key),
  KEY idx_user (user_id),
  KEY idx_fingerprint (fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(32) NOT NULL,
  owner_user_id INT UNSIGNED NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  seed INT NOT NULL DEFAULT 0,
  base_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  renamed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 公司随机事件：独立于天狗快讯，只作用于尚未生成的未来价格点。
CREATE TABLE IF NOT EXISTS company_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  news_id BIGINT UNSIGNED NULL,
  template_id BIGINT UNSIGNED NULL,
  anchor_company_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  positive_company_ids VARCHAR(255) NOT NULL DEFAULT '',
  negative_company_ids VARCHAR(255) NOT NULL DEFAULT '',
  impact_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
  source ENUM('random','manual','ai') NOT NULL DEFAULT 'random',
  effective_at DATETIME NOT NULL,
  applied_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pending (applied_at, effective_at),
  KEY idx_anchor_time (anchor_company_id, effective_at),
  UNIQUE KEY uk_event_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS holdings (
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  shares INT NOT NULL DEFAULT 0,
  cost_total BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, company_id),
  KEY idx_company_shares (company_id, shares)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 持仓批次：最低持有 15 分钟，卖出时按 FIFO 只释放已解锁批次。
CREATE TABLE IF NOT EXISTS holding_lots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  shares INT NOT NULL,
  remaining_shares INT NOT NULL,
  remaining_cost BIGINT NOT NULL,
  bought_at DATETIME NOT NULL,
  unlock_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user_company_unlock (user_id, company_id, unlock_at, id),
  KEY idx_company_time (company_id, bought_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 动态行情引擎：保存当天已经实现和未来预生成的价格点。
-- source=manual/ai 的未来点优先，自动生成不会覆盖。
CREATE TABLE IF NOT EXISTS market_points (
  trade_date DATE NOT NULL,
  tick SMALLINT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  source ENUM('seed','auto','manual','ai') NOT NULL DEFAULT 'auto',
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (trade_date, tick, company_id),
  KEY idx_date_company_tick (trade_date, company_id, tick)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 每一笔实际成交都单独留痕，用于股票详情、盈亏曲线与争议核对。
CREATE TABLE IF NOT EXISTS stock_trades (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  side ENUM('buy','sell') NOT NULL,
  qty INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  amount BIGINT NOT NULL,
  tick SMALLINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user_company (user_id, company_id, id),
  KEY idx_company_time (company_id, created_at),
  KEY idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 弱网重试/双击去重：同一用户、同一请求键只会执行一次，并缓存响应。
CREATE TABLE IF NOT EXISTS idempotency_keys (
  user_id INT UNSIGNED NOT NULL,
  idem_key VARCHAR(64) NOT NULL,
  action VARCHAR(64) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  status ENUM('running','done') NOT NULL DEFAULT 'running',
  response MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, idem_key),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  amount INT NOT NULL,
  fee INT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 银行机制：允许并存多笔贷款；每 30 分钟按剩余本金增加 10% 利率，最高 100%，简单计息。
CREATE TABLE IF NOT EXISTS bank_loans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  principal INT NOT NULL,
  initial_rate TINYINT UNSIGNED NOT NULL DEFAULT 10,
  rate_step TINYINT UNSIGNED NOT NULL DEFAULT 10,
  max_rate TINYINT UNSIGNED NOT NULL DEFAULT 100,
  interest_charged INT NOT NULL DEFAULT 0,
  interest_steps TINYINT UNSIGNED NOT NULL DEFAULT 0,
  opened_at DATETIME NOT NULL,
  last_synced_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  status ENUM('active','repaid') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  KEY idx_user_status (user_id, status),
  KEY idx_status_opened (status, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 新闻库：管理员手动编辑或 AI 起草，自动播报时随机挑选 1~2 条发布。
CREATE TABLE IF NOT EXISTS news_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('market','notice') NOT NULL DEFAULT 'market',
  pool ENUM('news','event') NOT NULL DEFAULT 'news',
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  positive_company_ids VARCHAR(255) NOT NULL DEFAULT '',
  negative_company_ids VARCHAR(255) NOT NULL DEFAULT '',
  impact_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  popup_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ai_prompt VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_enabled_used (kind, enabled, last_used_at),
  KEY idx_pool_enabled_used (pool, kind, enabled, last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 已发布新闻/通知。read 记录按用户保存，保证“未读就一直弹”。
CREATE TABLE IF NOT EXISTS news_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id BIGINT UNSIGNED NULL,
  kind ENUM('market','notice') NOT NULL DEFAULT 'market',
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  positive_company_ids VARCHAR(255) NOT NULL DEFAULT '',
  negative_company_ids VARCHAR(255) NOT NULL DEFAULT '',
  impact_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
  source ENUM('manual','ai','auto','trade','notice','event') NOT NULL DEFAULT 'manual',
  popup_enabled TINYINT(1) NOT NULL DEFAULT 0,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  pinned_until DATETIME NULL,
  effect_applied_at DATETIME NULL,
  published_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_kind_published (kind, published_at),
  KEY idx_pinned (pinned, pinned_until),
  KEY idx_effect_pending (kind, effect_applied_at, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_reads (
  user_id INT UNSIGNED NOT NULL,
  news_id BIGINT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, news_id),
  KEY idx_news (news_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 每 15 分钟的净资产榜快照；top=前三，bottom=倒三亏损资产。
CREATE TABLE IF NOT EXISTS asset_rank_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_at DATETIME NOT NULL,
  bucket ENUM('top','bottom') NOT NULL,
  rank_no TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  username VARCHAR(32) NOT NULL,
  net_assets BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_round_bucket (round_at, bucket, rank_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  detail TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  username VARCHAR(32) NOT NULL DEFAULT '',
  device_key CHAR(64) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  type VARCHAR(32) NOT NULL,
  severity TINYINT UNSIGNED NOT NULL DEFAULT 2,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user_time (user_id, created_at),
  KEY idx_type_time (type, created_at),
  KEY idx_time (created_at),
  KEY idx_device (device_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL,
  v TEXT NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS throttle (
  k VARCHAR(128) NOT NULL,
  window_start INT NOT NULL,
  count INT NOT NULL DEFAULT 0,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 默认数据 =====
INSERT IGNORE INTO companies (id, name, owner_user_id, is_default, seed, base_price, created_at) VALUES
 (1, '红魔馆商会', NULL, 1, 101, 18.00, NOW()),
 (2, '博丽神社基金会', NULL, 1, 102, 22.00, NOW()),
 (3, '永远亭药业', NULL, 1, 103, 16.00, NOW()),
 (4, '守矢能源', NULL, 1, 104, 20.00, NOW()),
 (5, '天狗新闻社', NULL, 1, 105, 24.00, NOW());

INSERT IGNORE INTO settings (k, v) VALUES
 ('registration_open', '1'),
 ('banned_words', '广告,加群,代刷,外挂,赌博,色情,作弊,刷币,客服,中奖'),
 ('event_name', '芙湘东方高校联合THP活动网站'),
 ('icp_number', ''),
 ('police_beian', ''),
 ('police_code', ''),
 ('announcement', '欢迎来到芙湘东方高校联合THP活动网站！\n\n这里是本届活动的官方线上代币系统：在现场摊位扫码或输入六位代码即可获得代币，代币可用于场内消费、参与答题与故事解锁，还能在模拟股市里小试身手。\n\n祝各位在幻想乡度过愉快的一天！'),
 ('ai_endpoint', 'https://api.deepseek.com/chat/completions'),
 ('ai_model', 'deepseek-chat'),
 ('ai_key', ''),
 ('oss_base_url', ''),
 ('device_limit_enabled', '1'),
 ('company_rename_cooldown_seconds', '3600'),
 ('market_cache_seconds', '60'),
 ('trade_throttle_count', '30'),
 ('trade_throttle_window_minutes', '30'),
 ('transfer_limit_30m', '1000'),
 ('holding_lock_minutes', '15'),
 ('loan_max_per_hour', '10'),
 ('loan_base_rate', '10'),
 ('loan_rate_step', '10'),
 ('loan_max_rate', '100'),
 ('loan_interest_minutes', '30'),
 ('market_refresh_minutes', '6'),
 ('market_forecast_ticks', '20'),
 ('market_step_max_percent', '20'),
 ('market_min_ratio', '0.3333'),
 ('market_max_ratio', '3'),
 ('market_noise_weight', '0.85'),
 ('market_mean_reversion', '0.04'),
 ('market_hedge_weight', '0.35'),
 ('market_investment_weight', '0.1'),
 ('market_investment_cap_percent', '1.5'),
 ('market_big_move_chance', '1.2'),
 ('market_big_move_min_percent', '6'),
 ('market_big_move_max_percent', '18'),
 ('market_intervention_end_tick', ''),
 ('market_intervention_source', ''),
 ('market_intervention_at', ''),
 ('market_last_generated_at', ''),
 ('market_engine_day', ''),
 ('market_force_refresh', '0'),
 ('news_auto_enabled', '1'),
 ('news_auto_popup_enabled', '0'),
 ('news_auto_next_at', ''),
 ('news_auto_min', '1'),
 ('news_auto_max', '2'),
 ('asset_rank_next_at', ''),
 ('schema_version', '10'),
 ('trade_enabled', '1');

INSERT IGNORE INTO news_templates (id, kind, title, body, positive_company_ids, negative_company_ids, impact_percent, enabled, created_by, created_at, updated_at) VALUES
 (1, 'market', '红魔馆茶叶订单激增', '红魔馆商会宣布，来自外界的神秘茶叶订单在今日暴增。女仆长表示库存仍充足，但股市已经开始躁动。', '1', '', 5.5, 1, NULL, NOW(), NOW()),
 (2, 'market', '博丽神社赛钱箱维修完成', '博丽神社基金会的赛钱箱完成加固，香油钱清点效率大幅提升。巫女表示这绝对不是异变，只是普通维修。', '2', '', 4.5, 1, NULL, NOW(), NOW()),
 (3, 'market', '永远亭新药进入临床观察', '永远亭药业的一款新药进入最终观察阶段。效果似乎很好，但几位试药妖怪出现了奇怪的兔耳反应。', '3', '', 6.0, 1, NULL, NOW(), NOW()),
 (4, 'market', '守矢能源扩建水电站', '守矢能源宣布扩建妖怪之山水力设施，预计将为幻想乡提供更稳定的电力。河童工程队已连夜开工。', '4', '', 6.5, 1, NULL, NOW(), NOW()),
 (5, 'market', '天狗号外销量创下新高', '天狗新闻社今日的头号外遭到抢购，连隙间里都堆满了报纸。编辑部正在加印，并承诺明日继续追踪。', '5', '', 5.0, 1, NULL, NOW(), NOW()),
 (6, 'market', '红魔馆点心原料短缺', '因外界运输延误，红魔馆部分点心原料出现短缺。馆内下午茶照常，但供应商股价可能承压。', '', '1', 5.0, 1, NULL, NOW(), NOW()),
 (7, 'market', '永远亭被曝夸大疗效', '一份匿名传单质疑永远亭药业的宣传用词。虽然药师们迅速澄清，市场仍对短期口碑表示担忧。', '', '3', 6.5, 1, NULL, NOW(), NOW()),
 (8, 'market', '守矢神社参拜客减少', '受临时天气异变影响，守矢神社今日参拜客有所减少。能源公司称发电业务不受影响，但投资者仍在观望。', '', '4', 4.0, 1, NULL, NOW(), NOW());
