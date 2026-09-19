<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
define('QUIZ_REQUIRED_PERMISSION', 'plugin.quiz.attempts.export');
require __DIR__ . '/../../_init.php';
require_once __DIR__ . '/_monitoring.php';
require_once __DIR__ . '/_export.php';
qz_attempt_no_store();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}
quiz_csrf_check();

$format = strtolower(trim((string)($_POST['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'csv'], true)) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Format export tidak valid.');
}

if ($format === 'xlsx') {
    $xlsxSupport = qz_attempt_xlsx_support();
    if (!$xlsxSupport['available']) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Export Excel tidak tersedia. ' . $xlsxSupport['message'] . ' Gunakan Export CSV.');
    }
    require_once $xlsxSupport['autoload'];
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Export Excel tidak tersedia karena PhpSpreadsheet gagal dimuat. Gunakan Export CSV.');
    }
}

$filterQuery = qz_attempt_filters($_POST);
$stmt = $pdo->prepare(qz_attempt_monitoring_sql(
    $filterQuery['where_sql'],
    $filterQuery['order_sql']
));
$stmt->execute($filterQuery['params']);

while (ob_get_level() > 0) @ob_end_clean();
$columns = qz_attempt_export_columns($_POST['columns'] ?? null);
$headers = array_column($columns, 'label');

if ($format === 'csv') {
    $filename = 'quiz-attempts-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    qz_attempt_no_store();

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to create export');
    }
    $safe = static function (mixed $value): string {
        $cell = $value === null ? '' : (string)$value;
        $cell = str_replace(["\0", "\r", "\n"], ['', ' ', ' '], $cell);
        if (preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]/u', $cell) === 1) $cell = "'" . $cell;
        return $cell;
    };
    $write = static function (array $cells) use ($output, $safe): void {
        fputcsv($output, array_map($safe, $cells), ';', '"', '\\');
    };

    fwrite($output, "\xEF\xBB\xBF");
    $write($headers);
    $rank = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $record = qz_attempt_export_record($row);
        $record['rank'] = ++$rank;
        $write(array_map(static fn(array $column): mixed => $record[$column['key']] ?? '', $columns));
    }
    fclose($output);
    exit;
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Jyavani Quiz')
    ->setTitle('Hasil Quiz')
    ->setSubject('Hasil attempt sesuai filter Attempt Manager');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Hasil Quiz');

foreach ($headers as $index => $header) {
    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
    $sheet->setCellValueExplicit($column . '1', $header, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
}

$rowNumber = 2;
$rank = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $record = qz_attempt_export_record($row);
    $record['rank'] = ++$rank;
    foreach ($columns as $index => $definition) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $value = $record[$definition['key']] ?? '';
        if ($definition['type'] === 'datetime' && $record['activity_timestamp'] !== false) {
            $sheet->setCellValue($column . $rowNumber, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel((int)$record['activity_timestamp']));
        } elseif ($definition['type'] === 'number') {
            $sheet->setCellValue($column . $rowNumber, (float)$value);
        } else {
            $sheet->setCellValueExplicit($column . $rowNumber, (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }
    $rowNumber++;
}

$lastRow = max(1, $rowNumber - 1);
$lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
$sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FF1F4E78'],
    ],
    'alignment' => [
        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
    ],
]);
$sheet->getRowDimension(1)->setRowHeight(24);
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
$sheet->getSheetView()->setZoomScale(90);

foreach ($columns as $index => $definition) {
    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
    $sheet->getColumnDimension($column)->setWidth((float)$definition['width']);
}
if ($lastRow >= 2) {
    $dataRange = 'A2:' . $lastColumn . $lastRow;
    $sheet->getStyle($dataRange)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    foreach ($columns as $index => $definition) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $range = $column . '2:' . $column . $lastRow;
        if ($definition['type'] === 'text') $sheet->getStyle($range)->getAlignment()->setWrapText(true);
        if (in_array($definition['key'], ['legacy_score', 'score100'], true)) $sheet->getStyle($range)->getNumberFormat()->setFormatCode('0.00');
        if ($definition['type'] === 'datetime') $sheet->getStyle($range)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        if ($definition['type'] !== 'text') $sheet->getStyle($range)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }
    $sheet->getStyle($dataRange)->getBorders()->getBottom()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR)
        ->getColor()->setARGB('FFD9E2F3');
}

$filename = 'quiz-attempts-' . date('Ymd-His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
qz_attempt_no_store();

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
