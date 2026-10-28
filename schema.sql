-- =====================================================================
-- Papersetter register - Legacy MySQL Compatible Schema
-- =====================================================================

-- ---------- reference data -------------------------------------------

CREATE TABLE IF NOT EXISTS colleges (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(255) NOT NULL,
  address      VARCHAR(255) NULL,
  external_id  VARCHAR(50)  NULL,
  source       ENUM('manual','excel','api') NOT NULL DEFAULT 'manual',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  synced_at    DATETIME     NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT '1970-01-01 00:00:01',
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_college_code (code),
  UNIQUE KEY uq_college_ext  (external_id),
  KEY ix_college_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programmes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(50)  NULL,
  name           VARCHAR(255) NOT NULL,
  faculty        VARCHAR(100) NULL,
  level          VARCHAR(50)  NULL,
  specialization VARCHAR(255) NULL,
  external_id    VARCHAR(50)  NULL,
  source         ENUM('manual','excel','api') NOT NULL DEFAULT 'manual',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  synced_at      DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT '1970-01-01 00:00:01',
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_programme_ext (external_id),
  KEY ix_programme_code (code),
  KEY ix_programme_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(50)  NULL,
  name         VARCHAR(255) NOT NULL,
  category     VARCHAR(50)  NULL,
  external_id  VARCHAR(50)  NULL,
  source       ENUM('manual','excel','api') NOT NULL DEFAULT 'manual',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  synced_at      DATETIME     NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT '1970-01-01 00:00:01',
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subject_ext (external_id),
  KEY ix_subject_code (code),
  KEY ix_subject_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programme_subjects (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  programme_id  INT          NOT NULL,
  subject_id    INT          NOT NULL,
  paper_code    VARCHAR(50)  NOT NULL DEFAULT '',
  paper_title   VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY uq_prog_subj_paper (programme_id, subject_id, paper_code),
  KEY ix_ps_subject (subject_id),
  CONSTRAINT fk_ps_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_subject   FOREIGN KEY (subject_id)   REFERENCES subjects(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_sessions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  is_current  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  source        VARCHAR(20)  NOT NULL,
  entity        VARCHAR(30)  NOT NULL,
  rows_seen     INT          NOT NULL DEFAULT 0,
  status        VARCHAR(10)  NOT NULL,
  message       VARCHAR(500) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- working data ---------------------------------------------

CREATE TABLE IF NOT EXISTS papers (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  faculty         VARCHAR(100),
  exam_session    VARCHAR(100),
  paper_code      VARCHAR(50),
  programme_code  VARCHAR(50),
  programme_name  VARCHAR(255),
  programme_id    VARCHAR(100),
  subject_name    VARCHAR(255),
  subject_id      VARCHAR(100),
  course_name_dev VARCHAR(255) NULL,
  no_students     TINYINT(1) NOT NULL DEFAULT 0,
  paper_title     VARCHAR(255),
  created_by      INT NULL,
  remark          VARCHAR(255),
  generated_on    VARCHAR(50),
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (programme_id),
  INDEX (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS papersetters (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  paper_id      INT NOT NULL,
  sr_no         INT,
  name          VARCHAR(150),
  college_code  VARCHAR(20),
  college_name  VARCHAR(255),
  address       VARCHAR(500) NULL,
  appointed_as  VARCHAR(150),
  email         VARCHAR(150),
  mobile        VARCHAR(20),
  refused       TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (paper_id) REFERENCES papers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_cache (
  cache_key   VARCHAR(190) PRIMARY KEY,
  payload     LONGTEXT,
  fetched_at  INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- login (users + auth logs) --------------------------------

CREATE TABLE IF NOT EXISTS users (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            VARCHAR(10)  NOT NULL DEFAULT 'user',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  failed_attempts INT          NOT NULL DEFAULT 0,
  locked_until    INT          NOT NULL DEFAULT 0,
  college_code    VARCHAR(20)  NULL,
  college_name    VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_otps (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  otp_code      VARCHAR(10) NOT NULL,
  expires_at    INT NOT NULL,
  tries_left    INT NOT NULL DEFAULT 5,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
