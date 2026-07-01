-- ============================================================
--  StockBoard Dealer — MySQL Schema  (v6.2 — Add is_pending)
--  Contains: 1. users, 2. categories, 3. products, 4. sales,
--            5. sale_items, 6. stock_movements, 7. audit_log
-- ============================================================

CREATE DATABASE IF NOT EXISTS stockboard_dealer
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE stockboard_dealer;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- 1. users
-- --------------------------------------------------------
CREATE TABLE users (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(80)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  full_name  VARCHAR(150) NOT NULL,
  role       ENUM(
               'Administrator','Manager','InventoryOfficer',
               'SalesCashier','WarehouseStaff','Accountant',
               'Auditor','ITSupport'
             ) NOT NULL DEFAULT 'SalesCashier',
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  is_pending TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = self-registered, awaiting admin approval
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 2. categories
-- --------------------------------------------------------
CREATE TABLE categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 3. products
-- --------------------------------------------------------
CREATE TABLE products (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_type          VARCHAR(200) NOT NULL,
  category_id         INT UNSIGNED NOT NULL,
  color_design        VARCHAR(100) DEFAULT '',
  unit                VARCHAR(20)  NOT NULL DEFAULT 'pcs',
  cost_price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  selling_price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  agent_price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  current_stock       INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_cat FOREIGN KEY (category_id)
    REFERENCES categories(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 4. sales
-- --------------------------------------------------------
CREATE TABLE sales (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  invoice_no   VARCHAR(50)  NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  notes        VARCHAR(255),
  sale_date    DATE NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sale_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 5. sale_items
-- --------------------------------------------------------
CREATE TABLE sale_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id             INT UNSIGNED NOT NULL,
  product_id          INT UNSIGNED NOT NULL,
  quantity            INT NOT NULL,
  price_per_unit      DECIMAL(10,2) NOT NULL,
  total               DECIMAL(10,2) NOT NULL,
  commission_cleared  TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_si_sale    FOREIGN KEY (sale_id)    REFERENCES sales(id)    ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 6. stock_movements
-- --------------------------------------------------------
CREATE TABLE stock_movements (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  type        ENUM('IN','OUT','ADJUSTMENT','SALE') NOT NULL,
  quantity    INT NOT NULL,
  notes       VARCHAR(255) DEFAULT '',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sm_product FOREIGN KEY (product_id)
    REFERENCES products(id) ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT fk_sm_user    FOREIGN KEY (user_id)
    REFERENCES users(id)    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 7. audit_log
-- --------------------------------------------------------
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  action      VARCHAR(100) NOT NULL,
  target_type VARCHAR(50)  DEFAULT '',
  target_id   INT UNSIGNED DEFAULT NULL,
  detail      TEXT,
  ip_address  VARCHAR(45)  DEFAULT '',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_al_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 8. agent_commission_payouts
-- --------------------------------------------------------
CREATE TABLE agent_commission_payouts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id   INT UNSIGNED NOT NULL,
  cleared_by INT UNSIGNED NOT NULL,
  amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  note       VARCHAR(255),
  cleared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_acp_agent   FOREIGN KEY (agent_id)   REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_acp_cleared FOREIGN KEY (cleared_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ── SEED DATA ─────────────────────────────────────────────

INSERT INTO users (username, password, full_name, role) VALUES
  ('admin',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Manager',      'Manager'),
  ('cashier',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sales Cashier',       'SalesCashier'),
  ('inventory',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Inventory Officer',   'InventoryOfficer'),
  ('agent',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Online Agent',        'OnlineAgent');

INSERT INTO categories (name, description) VALUES
  ('11PLY SOLID MARINE',  'Solid marine plywood — standard 18mm 4×8'),
  ('COMPACT MARINE',      'Compact marine grade board — 18mm 4×8'),
  ('LAMINATED PLYBOARD',  'Standard laminated plyboard — 18mm 4×8'),
  ('PETG HIGH GLOSS',     'PETG glossy/matte finish boards — 18mm 4×8'),
  ('UV GLOSS',            'UV Gloss coated board — 18mm 4×8'),
  ('UV MARBLE',           'UV Marble finish board — 18mm 4×8'),
  ('6MM BACKING',         '6mm backing sheet — 4×8'),
  ('EDGEBAND',            'Standard PVC edgeband rolls — per meter'),
  ('EDGEBAND GLOSS',      'High-gloss PETG/UV edgeband rolls — per meter');
