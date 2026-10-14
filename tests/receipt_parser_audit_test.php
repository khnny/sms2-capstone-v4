<?php
declare(strict_types=1);
// Pure parsing checks: no database, uploads, OCR process, or external services.
require_once __DIR__ . '/../modules/crad/includes/research-clearance-payment.php';
$cases = [
    ['CRAD-OCR-001', 'OR No: 00012345', '00012345'],
    ['CRAD-OCR-002', 'OR No: OR-000123', 'OR-000123'],
    ['CRAD-OCR-003', 'Amount PHP 1500.00 Date 2026-10-14', ''],
    ['CRAD-OCR-004', 'Student ID: ABC123456', ''],
    ['CRAD-OCR-005', 'Invoice: INV123456 OR No: OR-000123', 'OR-000123'],
];
$failed = 0;
foreach ($cases as [$id, $input, $expected]) {
    $actual = rcpParseReferenceNumber($input);
    $pass = $actual === $expected;
    $failed += $pass ? 0 : 1;
    echo json_encode(compact('id', 'input', 'expected', 'actual') + ['status' => $pass ? 'PASS' : 'FAIL']), PHP_EOL;
}
exit($failed ? 1 : 0);
