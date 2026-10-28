<?php
/**
 * CLI / Web Script to import CSV data into MySQL database tables (programmes table).
 * Usage: php import_csv.php
 */
require_once __DIR__ . '/db.php';

$csvFile = __DIR__ . '/DataExportProgrammeCourseStructure.csv';
if (!file_exists($csvFile)) {
    exit("Error: DataExportProgrammeCourseStructure.csv not found in " . __DIR__ . "
");
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    exit("Error opening CSV file.
");
}

$header = fgetcsv($handle);
$pdo = db();

// Clear existing programmes if refreshing
$pdo->exec("DELETE FROM programme_subjects");
$pdo->exec("DELETE FROM programmes");

$stmt = $pdo->prepare("INSERT INTO programmes (id, code, name, faculty, specialization, is_active) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE name=VALUES(name), faculty=VALUES(faculty), specialization=VALUES(specialization)");

$count = 0;
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 16) continue;
    $id = trim($row[0]);
    $code = trim($row[1]);
    $faculty = trim($row[7]);
    $spec = trim($row[13]);
    $name = trim($row[15]);

    if ($id !== '' && $name !== '') {
        $stmt->execute([$id, $code !== '' ? $code : $id, $name, $faculty, $spec]);
        $count++;
    }
}
fclose($handle);

echo "Successfully imported $count programmes into the MySQL database!
";
