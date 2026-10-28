<?php
return [
    'source' => 'excel', // Configured to load directly from SQL Database as requested

    'db' => [
    'host' => 'sql12.freesqldatabase.com', // Update if changed
    'name' => 'sql12839543',
    'user' => 'sql12839543',
    'pass' => 'FMr4PLAibt',
    'port' => 3306,
],
    'auth' => [
        'otp'          => false, // Disabled for easy default testing
        'otp_ttl'      => 600,
        'otp_tries'    => 5,
        'resend_after' => 60,
        'max_failed'   => 5,
        'lock_minutes' => 15,
        'cookie_secure' => null,
        'session_idle' => 3600,
        'secret'       => 'change-this-to-a-long-random-string',
    ],

    'mail' => [
        'mode'      => 'log',
        'host'      => 'smtp.example.edu',
        'port'      => 587,
        'secure'    => 'tls',
        'username'  => 'noreply@example.edu',
        'password'  => 'PUT_SMTP_PASSWORD_HERE',
        'from'      => 'noreply@example.edu',
        'from_name' => 'BoS Papersetter Register',
    ],

    'report' => [
        'faculty_codes' => [
            'Faculty of Science'    => 'SCI_TECH',
            'Faculty of Humanities' => 'HUM',
        ],
        'next_session' => '',
    ],

    'excel' => [
        'catalogue'  => 'data/catalogue/*.xlsx',
        'colleges'   => 'data/All_Organizational_Unit.xlsx',
        'sessions'   => 'data/exam_sessions.xlsx',
    ],
];
