-- =====================================================================
-- Papersetter register - MySQL / MariaDB schema (XAMPP)
--
-- Used two ways:
--   1. Automatically: db.php runs this file on every start (all statements are
--      CREATE TABLE IF NOT EXISTS, so it is safe to repeat).
--   2. Manually: in phpMyAdmin create database `papersetter_db`
--      (utf8mb4_unicode_ci), select it, then Import -> this file.
--
-- Reference data (filled from Excel now, from the e-Samarth API later):
--   colleges, programmes, subjects, programme_subjects
-- Working data (filled by the web page):
--   papers, papersetters
-- =====================================================================

-- ---------- reference data -------------------------------------------

CREATE TABLE IF NOT EXISTS colleges (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,                          -- college code printed on the sheet, e.g. 503
  name         VARCHAR(255) NOT NULL,
  address      VARCHAR(255) NULL,
  external_id  VARCHAR(50)  NULL,                              -- id in e-Samarth (ou_id) when synced from the API
  source       ENUM('manual','excel','api') NOT NULL DEFAULT 'manual',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  synced_at    DATETIME     NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_college_code (code),
  UNIQUE KEY uq_college_ext  (external_id),
  KEY ix_college_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programmes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(50)  NULL,                            -- e.g. 1S00256
  name           VARCHAR(255) NOT NULL,                        -- e.g. B.Sc. (I.T.)... T.Y. B.Sc. (I.T.) Sem VI [1S00256]
  faculty        VARCHAR(100) NULL,                            -- e.g. Faculty of Science (shown locked on the form)
  level          VARCHAR(50)  NULL,                            -- API: level (BACHELOR ...)
  specialization VARCHAR(255) NULL,                            -- API: specialization
  external_id    VARCHAR(50)  NULL,                            -- id in e-Samarth (programme id)
  source         ENUM('manual','excel','api') NOT NULL DEFAULT 'manual',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  synced_at      DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_programme_ext (external_id),
  KEY ix_programme_code (code),
  KEY ix_programme_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (                          -- "subject" = a course / paper in the catalogue
  id           INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(50)  NULL,                              -- API: course code
  name         VARCHAR(255) NOT NULL,                          -- API: course title
  category     VARCHAR(50)  NULL,                              -- API: category (CORE ...)
  external_id  VARCHAR(50)  NULL,                              -- id in e-Samarth (course id)
  source       ENUM('manual','excel','api') NOT NULL DEFAULT 'manual',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  synced_at    DATETIME     NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subject_ext (external_id),
  KEY ix_subject_code (code),
  KEY ix_subject_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which subjects belong to which programme (many-to-many), with the paper code/title
-- used on the papersetter sheet. The Samarth Student Data API does not expose this
-- link, so it is filled from Excel / manually. A programme with no rows here shows
-- the whole subject list instead.
CREATE TABLE IF NOT EXISTS programme_subjects (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  programme_id  INT          NOT NULL,
  subject_id    INT          NOT NULL,
  paper_code    VARCHAR(50)  NOT NULL DEFAULT '',              -- e.g. 97527
  paper_title   VARCHAR(255) NOT NULL DEFAULT '',              -- e.g. Paper IV-501 - History of ...
  UNIQUE KEY uq_prog_subj_paper (programme_id, subject_id, paper_code),
  KEY ix_ps_subject (subject_id),
  CONSTRAINT fk_ps_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_subject   FOREIGN KEY (subject_id)   REFERENCES subjects(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exam sessions ("For 2024 Second Half"). The row with is_current = 1 is used on the form (locked).
CREATE TABLE IF NOT EXISTS exam_sessions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  is_current  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History of imports / API syncs
CREATE TABLE IF NOT EXISTS sync_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  source        VARCHAR(20)  NOT NULL,                         -- excel | api
  entity        VARCHAR(30)  NOT NULL,                         -- colleges | programmes | subjects | programme_subjects
  rows_seen     INT          NOT NULL DEFAULT 0,
  status        VARCHAR(10)  NOT NULL,                         -- ok | error
  message       VARCHAR(500) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- working data (entered through the web page) --------------

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
  course_name_dev VARCHAR(255) NULL,                           -- optional course name in Devanagari (Marathi / Hindi)
  no_students     TINYINT(1) NOT NULL DEFAULT 0,               -- 1 = no students opted for this course (no papersetters needed)
  paper_title     VARCHAR(255),
  created_by      INT NULL,                                    -- users.id of whoever entered it (admin dashboard)
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
  address       VARCHAR(500) NULL,                             -- papersetter's address
  appointed_as  VARCHAR(150),
  email         VARCHAR(150),
  mobile        VARCHAR(20),
  refused       TINYINT(1) NOT NULL DEFAULT 0,                 -- 1 = declined the appointment letter (shown struck through)
  FOREIGN KEY (paper_id) REFERENCES papers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_cache (
  cache_key   VARCHAR(190) PRIMARY KEY,
  payload     LONGTEXT,
  fetched_at  INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- login (users + email one-time passwords) -----------------

CREATE TABLE IF NOT EXISTS users (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            VARCHAR(10)  NOT NULL DEFAULT 'user',       -- admin | user
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  failed_attempts INT          NOT NULL DEFAULT 0,
  locked_until    INT          NOT NULL DEFAULT 0,            -- unix time; login blocked until then
  last_login      INT          NULL,
  college_code    VARCHAR(20)  NULL,                          -- the user's college (for the college report)
  college_name    VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_otps (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          NOT NULL,
  code_hash   VARCHAR(64)  NOT NULL,                          -- HMAC of the 6-digit code (the code itself is never stored)
  expires_at  INT          NOT NULL,                          -- unix time
  attempts    INT          NOT NULL DEFAULT 0,
  used        TINYINT(1)   NOT NULL DEFAULT 0,
  ip          VARCHAR(45)  NULL,
  created_at  INT          NOT NULL,
  KEY ix_otp_user (user_id, used),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
