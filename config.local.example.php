<?php
/**
 * Copy this file to config.local.php on the server (it is NOT committed to GitHub) and fill in the password.
 * Anything set here overrides config.php.
 */
return [
    'source' => 'db',                                  // read programmes / subjects / colleges from the MySQL tables
    'db' => [
        'host' => 'sql12.freesqldatabase.com',
        'port' => 3306,
        'name' => 'sql12839543',
        'user' => 'sql12839543',
        'pass' => 'PUT_YOUR_DATABASE_PASSWORD_HERE',
    ],
    'auth' => [
        'secret' => 'put-a-long-random-text-here',
        'otp'    => false,                             // true only when the mail settings below work
    ],
];
