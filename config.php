<?php
/**
 * Configuration -- edit this file only.
 * Default XAMPP MySQL: user "root", empty password.
 */
return [
    // Where the right-hand Programme/Subject panel gets its data:
    //   'excel' -> data/catalogue/*.xlsx (e.g. BSC_IT.xlsx), read directly and cached (works with no setup)
    //   'db'    -> MySQL tables colleges/programmes/subjects/programme_subjects (schema.sql).
    //              Fill them with import_excel.php now, and with sync_api.php once API access arrives.
    //   'api'   -> e-Samarth Student Data API called live (fill in the 'api' section below)
    'source' => 'excel',

        // Where the right-hand Programme/Subject panel gets its data:
    'source' => 'db',

    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'papersetter_db',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASSWORD') ?: '',
        'port' => getenv('DB_PORT') ?: '3306',
    ],


    /* ---- login ---- */
    'auth' => [
        'otp'          => true,     // true = password + 6-digit code sent by email; false = password only
        'otp_ttl'      => 600,      // seconds a code stays valid (10 minutes)
        'otp_tries'    => 5,        // wrong codes allowed per code
        'resend_after' => 60,       // seconds before "send a new code" is allowed
        'max_failed'   => 5,        // wrong passwords before the account is locked
        'lock_minutes' => 15,
        'cookie_secure' => null,    // null = automatic (secure cookie only when the page is https); true/false to force
        'session_idle' => 3600,     // log out after this many seconds of inactivity
        // Random secret used to hash one-time codes. CHANGE THIS to any long random text on your server.
        'secret'       => 'change-this-to-a-long-random-string',
    ],

    /* ---- email (for one-time codes) ----
       'log'  -> do not send; write the email to data/mail.log (testing on your own PC only)
       'smtp' -> send through your mail server (college SMTP, Gmail app password, Office 365 ...)
       'mail' -> PHP mail() (needs a working sendmail/postfix on the server)            */
    'mail' => [
        'mode'      => 'log',
        'host'      => 'smtp.example.edu',
        'port'      => 587,
        'secure'    => 'tls',          // 'tls' (STARTTLS, port 587) | 'ssl' (port 465) | '' (none)
        'username'  => 'noreply@example.edu',
        'password'  => 'PUT_SMTP_PASSWORD_HERE',
        'from'      => 'noreply@example.edu',
        'from_name' => 'BoS Papersetter Register',
    ],

    /* ---- "Recommendation Report" PDF (report.php) ---- */
    'report' => [
        // Faculty name in the register -> short code printed at the top-left of every page (unlisted faculties print as they are)
        'faculty_codes' => [
            'Faculty of Science'    => 'SCI_TECH',
            'Faculty of Humanities' => 'HUM',
        ],
        // "Suggest Names for ..." is worked out from the exam session (FIRST HALF (SUMMER) 2026 -> SECOND HALF (WINTER) 2026).
        // Put a text here only if your session names follow another pattern; it is then printed on every page.
        'next_session' => '',
    ],

    // Excel files (relative to this folder, or a full path like C:/data/BSC_IT.xlsx)
    'excel' => [
        // University format, one row per college x paper. Several files are fine (use a pattern such as
        // 'data/catalogue/*.xlsx'). Columns: Faculty | Course Full Name | CoursePattern | CourseTerm |
        // College/Study Center Code | College/Study Center Name | Paper Code | Paper Name
        'catalogue'  => 'data/catalogue/*.xlsx',
        'colleges'   => 'data/All_Organizational_Unit.xlsx',   // e-Samarth OU export -> college list ('' = take colleges from the catalogue)
        'sessions'   => 'data/exam_sessions.xlsx',         // Exam Session | Current (Yes for the one in use)
    ],

    /* ---- e-Samarth Student Data API (Basic auth) -- only used when 'source' => 'api' ---- */
    'api' => [
        'base_url'  => 'https://demo-student.samarth.ac.in/index.php/apiv2',  // your {base-url}, no trailing slash
        'username'  => 'PUT_API_USERNAME_HERE',
        'password'  => 'PUT_API_PASSWORD_HERE',

        'ep_programmes' => '/programme/index',
        'ep_courses'    => '/course/index',      // used as the "subjects" list
        'ep_ou'         => '/ou/index',          // organizational units, used by sync_api.php to fill colleges ('' to skip)
        'ou_code_field' => 'code',               // which /ou/index field holds the college code printed on papersetter sheets
                                                 // (check with test.php / sync - if it is not 'code', change it here)

        // our field => API field
        'map_programme' => ['id' => 'id', 'name' => 'title_english', 'code' => 'code', 'tag' => 'specialization'],
        'map_course'    => ['id' => 'id', 'name' => 'title', 'code' => 'code', 'tag' => 'category'],

        'max_pages'  => 200,     // safety cap when walking X-Pagination-Page-Count
        'cache_ttl'  => 3600,    // seconds
        'verify_ssl' => true,    // set false ONLY for local testing if XAMPP has no CA bundle
    ],

    // Shown in API mode when the API cannot be reached and nothing is cached yet
    'demo_programmes' => [
        ['id' => '1004', 'name' => 'BACHELOR OF ARTS (SAMPLE)', 'code' => 'KU-KU01A', 'tag' => 'History'],
        ['id' => '1005', 'name' => 'BACHELOR OF SCIENCE (SAMPLE)', 'code' => 'KU-KU02A', 'tag' => 'Mathematics'],
    ],
    'demo_subjects' => [
        ['id' => '1656', 'name' => 'ENGLISH I', 'code' => '5142310001', 'tag' => 'CORE'],
        ['id' => '97527', 'name' => 'Marathi : Paper IV-501 - History of Medieval Marathi Literature', 'code' => '97527', 'tag' => ''],
    ],
];
