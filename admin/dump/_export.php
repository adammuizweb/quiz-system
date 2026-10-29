<?php
declare(strict_types=1);

defined('DASHBOARD_CONTEXT') || exit;

function qz_question_export_no_store(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function qz_question_export_real_passage_sql(string $alias = 'p'): string {
    return "{$alias}.id IS NOT NULL"
        . " AND TRIM(COALESCE({$alias}.passage_text, '')) <> ''"
        . " AND {$alias}.passage_text <> '__CATEGORY_CONTAINER__'";
}

function qz_question_export_category_sql(string $questionAlias = 'q', string $passageAlias = 'p'): string {
    $realPassage = qz_question_export_real_passage_sql($passageAlias);
    return "CASE WHEN {$realPassage} THEN {$passageAlias}.category_id"
        . " ELSE COALESCE({$passageAlias}.category_id, {$questionAlias}.category_id) END";
}

function qz_question_export_order_sql(string $questionAlias = 'q', string $passageAlias = 'p', string $categoryAlias = 'c'): string {
    $realPassage = qz_question_export_real_passage_sql($passageAlias);
    return "({$categoryAlias}.id IS NULL) ASC, ({$categoryAlias}.sort_order IS NULL) ASC,"
        . " {$categoryAlias}.sort_order ASC, {$categoryAlias}.id ASC,"
        . " (CASE WHEN {$realPassage} THEN 0 ELSE 1 END) ASC,"
        . " ({$passageAlias}.sort_order IS NULL) ASC, {$passageAlias}.sort_order ASC, {$passageAlias}.id ASC,"
        . " ({$questionAlias}.passage_seq IS NULL) ASC, {$questionAlias}.passage_seq ASC,"
        . " ({$questionAlias}.sort_order IS NULL) ASC, {$questionAlias}.sort_order ASC, {$questionAlias}.id ASC";
}

function qz_question_export_questions_sql(bool $filterCategory): string {
    $category = qz_question_export_category_sql();
    $categoryFilter = $filterCategory ? " AND {$category} = :category_id" : '';
    return "SELECT
        q.id AS question_id, q.question_text, q.question_type,
        q.sort_order AS question_sort_order, q.is_active AS question_is_active,
        q.passage_id, q.passage_seq,
        q.image_media_id AS question_image_media_id, qm.url AS question_image_url,
        q.file_id AS question_file_id, COALESCE(qf.filename, qf.title, '') AS question_file_label,
        qf.url AS question_file_url, q.youtube_url AS question_youtube_url,
        p.title AS passage_title, p.passage_text, p.sort_order AS passage_sort_order,
        p.is_active AS passage_is_active,
        p.image_media_id AS passage_image_media_id, pm.url AS passage_image_url,
        p.file_id AS passage_file_id, COALESCE(pf.filename, pf.title, '') AS passage_file_label,
        pf.url AS passage_file_url, p.youtube_url AS passage_youtube_url,
        c.id AS category_id, c.name AS category_name, c.sort_order AS category_sort_order,
        c.is_active AS category_is_active,
        CASE
          WHEN p.id IS NOT NULL AND p.passage_text = '__CATEGORY_CONTAINER__' THEN 'category_container'
          WHEN " . qz_question_export_real_passage_sql() . " THEN 'passage'
          ELSE 'none'
        END AS passage_kind
      FROM questions q
      LEFT JOIN question_passages p ON p.id=q.passage_id AND p.quiz_id=q.quiz_id
      LEFT JOIN question_categories c ON c.id={$category} AND c.quiz_id=q.quiz_id
      LEFT JOIN media qm ON qm.id=q.image_media_id AND qm.visibility='public' AND qm.storage_disk='public' AND qm.access_scope='public'
      LEFT JOIN media pm ON pm.id=p.image_media_id AND pm.visibility='public' AND pm.storage_disk='public' AND pm.access_scope='public'
      LEFT JOIN `file` qf ON qf.id=q.file_id AND qf.visibility='public' AND qf.storage_disk='public' AND qf.access_scope='public' AND qf.is_downloadable=1
      LEFT JOIN `file` pf ON pf.id=p.file_id AND pf.visibility='public' AND pf.storage_disk='public' AND pf.access_scope='public' AND pf.is_downloadable=1
      WHERE q.quiz_id=:quiz_id{$categoryFilter}
      ORDER BY " . qz_question_export_order_sql();
}

function qz_question_export_answers_sql(bool $filterCategory): string {
    $category = qz_question_export_category_sql();
    $categoryFilter = $filterCategory ? " AND {$category} = :category_id" : '';
    return "SELECT
        qa.id AS answer_id, qa.question_id, qa.answer_text, qa.score,
        qa.sort_order AS answer_sort_order, qa.is_active AS answer_is_active,
        q.question_type, p.title AS passage_title,
        c.id AS category_id, c.name AS category_name
      FROM question_answers qa
      INNER JOIN questions q ON q.id=qa.question_id AND q.quiz_id=:quiz_id
      LEFT JOIN question_passages p ON p.id=q.passage_id AND p.quiz_id=q.quiz_id
      LEFT JOIN question_categories c ON c.id={$category} AND c.quiz_id=q.quiz_id
      WHERE 1=1{$categoryFilter}
      ORDER BY " . qz_question_export_order_sql() . ",
        (qa.sort_order <= 0) ASC, qa.sort_order ASC, qa.id ASC";
}

function qz_question_export_alpha_label(int $index): string {
    $number = $index + 1;
    $label = '';
    while ($number > 0) {
        $label = chr(65 + (($number - 1) % 26)) . $label;
        $number = intdiv($number - 1, 26);
    }
    return $label;
}

function qz_question_export_normalized_text(mixed $value): string {
    $text = (string)$value;
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $text = str_replace("\0", '', $text);
    $text = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $text) ?? $text;
    $text = preg_replace('~</\s*(?:p|div|li|h[1-6]|tr)\s*>~i', "\n", $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n?|\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

function qz_question_export_plain_text(mixed $value): string {
    $text = qz_question_export_normalized_text($value);
    $suffix = "\n[Content truncated for the Excel cell limit]";
    if (mb_strlen($text, 'UTF-8') > 32767) {
        $text = mb_substr($text, 0, 32767 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
    }
    return $text;
}

function qz_question_export_type_label(string $type): string {
    return match ($type) {
        'single_choice' => 'Single Choice',
        'multiple_choice' => 'Multiple Choice',
        'yesno' => 'Yes / No',
        'text' => 'Text',
        'number' => 'Number',
        default => $type,
    };
}

function qz_question_export_columns(): array {
    return [
        ['key'=>'number', 'label'=>'No.', 'type'=>'number', 'width'=>8],
        ['key'=>'category_id', 'label'=>'Category ID', 'type'=>'number', 'width'=>12],
        ['key'=>'category', 'label'=>'Category', 'type'=>'text', 'width'=>28],
        ['key'=>'category_status', 'label'=>'Category Status', 'type'=>'text', 'width'=>15],
        ['key'=>'passage_id', 'label'=>'Passage ID', 'type'=>'number', 'width'=>11],
        ['key'=>'passage', 'label'=>'Passage', 'type'=>'text', 'width'=>30],
        ['key'=>'passage_status', 'label'=>'Passage Status', 'type'=>'text', 'width'=>15],
        ['key'=>'question_id', 'label'=>'Question ID', 'type'=>'number', 'width'=>12],
        ['key'=>'question_order', 'label'=>'Question Order', 'type'=>'number', 'width'=>14],
        ['key'=>'passage_sequence', 'label'=>'Passage Sequence', 'type'=>'number', 'width'=>16],
        ['key'=>'question_type', 'label'=>'Question Type', 'type'=>'text', 'width'=>18],
        ['key'=>'question_status', 'label'=>'Question Status', 'type'=>'text', 'width'=>15],
        ['key'=>'question', 'label'=>'Question', 'type'=>'text', 'width'=>60],
        ['key'=>'answer_count', 'label'=>'Answer Count', 'type'=>'number', 'width'=>13],
        ['key'=>'answer_options', 'label'=>'Answer Options', 'type'=>'text', 'width'=>60],
        ['key'=>'answer_key', 'label'=>'Answer Key / Score', 'type'=>'text', 'width'=>22],
        ['key'=>'question_image_id', 'label'=>'Question Image ID', 'type'=>'number', 'width'=>16],
        ['key'=>'question_image', 'label'=>'Question Image URL', 'type'=>'text', 'width'=>38],
        ['key'=>'question_file_id', 'label'=>'Question File ID', 'type'=>'number', 'width'=>15],
        ['key'=>'question_file_label', 'label'=>'Question File', 'type'=>'text', 'width'=>28],
        ['key'=>'question_file', 'label'=>'Question File URL', 'type'=>'text', 'width'=>38],
        ['key'=>'question_youtube', 'label'=>'Question YouTube URL', 'type'=>'text', 'width'=>38],
        ['key'=>'passage_text', 'label'=>'Passage Text', 'type'=>'text', 'width'=>60],
        ['key'=>'passage_image_id', 'label'=>'Passage Image ID', 'type'=>'number', 'width'=>15],
        ['key'=>'passage_image', 'label'=>'Passage Image URL', 'type'=>'text', 'width'=>38],
        ['key'=>'passage_file_id', 'label'=>'Passage File ID', 'type'=>'number', 'width'=>14],
        ['key'=>'passage_file_label', 'label'=>'Passage File', 'type'=>'text', 'width'=>28],
        ['key'=>'passage_file', 'label'=>'Passage File URL', 'type'=>'text', 'width'=>38],
        ['key'=>'passage_youtube', 'label'=>'Passage YouTube URL', 'type'=>'text', 'width'=>38],
    ];
}

function qz_answer_export_columns(): array {
    return [
        ['key'=>'question_number', 'label'=>'Question No.', 'type'=>'number', 'width'=>12],
        ['key'=>'category_id', 'label'=>'Category ID', 'type'=>'number', 'width'=>12],
        ['key'=>'category', 'label'=>'Category', 'type'=>'text', 'width'=>28],
        ['key'=>'passage', 'label'=>'Passage', 'type'=>'text', 'width'=>30],
        ['key'=>'question_id', 'label'=>'Question ID', 'type'=>'number', 'width'=>12],
        ['key'=>'question_type', 'label'=>'Question Type', 'type'=>'text', 'width'=>18],
        ['key'=>'answer_label', 'label'=>'Answer Label', 'type'=>'text', 'width'=>12],
        ['key'=>'answer_id', 'label'=>'Answer ID', 'type'=>'number', 'width'=>12],
        ['key'=>'answer_order', 'label'=>'Answer Order', 'type'=>'number', 'width'=>13],
        ['key'=>'answer_status', 'label'=>'Answer Status', 'type'=>'text', 'width'=>15],
        ['key'=>'answer', 'label'=>'Answer', 'type'=>'text', 'width'=>60],
        ['key'=>'score', 'label'=>'Score', 'type'=>'number', 'width'=>11],
        ['key'=>'scoring_option', 'label'=>'Active Scoring Option', 'type'=>'text', 'width'=>21],
    ];
}

function qz_question_export_write_sheet(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    array $columns,
    array $records
): void {
    foreach ($columns as $index => $definition) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValueExplicit($column . '1', $definition['label'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    $rowNumber = 2;
    foreach ($records as $record) {
        foreach ($columns as $index => $definition) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $value = $record[$definition['key']] ?? '';
            if ($definition['type'] === 'number' && $value !== '' && $value !== null) {
                $sheet->setCellValue($column . $rowNumber, (int)$value);
            } else {
                $sheet->setCellValueExplicit($column . $rowNumber, (string)($value ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
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
    $sheet->getSheetView()->setZoomScale(85);

    foreach ($columns as $index => $definition) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->getColumnDimension($column)->setWidth((float)$definition['width']);
        if ($lastRow >= 2 && $definition['type'] === 'text') {
            $sheet->getStyle($column . '2:' . $column . $lastRow)->getAlignment()->setWrapText(true);
        }
    }
    if ($lastRow >= 2) {
        $sheet->getStyle('A2:' . $lastColumn . $lastRow)->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    }
}

function qz_question_export_workbook(array $questions, array $answers, array $quiz, ?array $category = null): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $answersByQuestion = [];
    foreach ($answers as $answer) {
        $questionId = (int)($answer['question_id'] ?? 0);
        if ($questionId > 0) $answersByQuestion[$questionId][] = $answer;
    }

    $questionRecords = [];
    $answerRecords = [];
    foreach ($questions as $index => $question) {
        $number = $index + 1;
        $questionId = (int)($question['question_id'] ?? 0);
        $questionAnswers = $answersByQuestion[$questionId] ?? [];
        $optionLines = [];
        $keyLines = [];
        $questionType = (string)($question['question_type'] ?? '');
        $choiceType = in_array($questionType, ['single_choice', 'multiple_choice', 'yesno'], true);

        foreach ($questionAnswers as $answerIndex => $answer) {
            $label = qz_question_export_alpha_label($answerIndex);
            $active = (int)($answer['answer_is_active'] ?? 0) === 1;
            $score = (int)($answer['score'] ?? 0);
            $optionLines[] = $label . '. ' . qz_question_export_plain_text($answer['answer_text'] ?? '')
                . ' [' . ($active ? 'Active' : 'Inactive') . '; Score ' . $score . ']';
            if ($choiceType && $active && $score > 0) $keyLines[] = $label . ' (' . $score . ')';

            $answerRecords[] = [
                'question_number' => $number,
                'category_id' => (int)($question['category_id'] ?? 0) ?: '',
                'category' => trim((string)($question['category_name'] ?? '')) ?: 'Uncategorized',
                'passage' => ($question['passage_kind'] ?? '') === 'passage'
                    ? (trim((string)($question['passage_title'] ?? '')) ?: 'Passage #' . (int)($question['passage_id'] ?? 0))
                    : '',
                'question_id' => $questionId,
                'question_type' => qz_question_export_type_label($questionType),
                'answer_label' => $label,
                'answer_id' => (int)($answer['answer_id'] ?? 0),
                'answer_order' => (int)($answer['answer_sort_order'] ?? 0),
                'answer_status' => $active ? 'Active' : 'Inactive',
                'answer' => qz_question_export_plain_text($answer['answer_text'] ?? ''),
                'score' => $score,
                'scoring_option' => ($choiceType && $active && $score > 0) ? 'Yes' : 'No',
            ];
        }

        $passageKind = (string)($question['passage_kind'] ?? 'none');
        $questionRecords[] = [
            'number' => $number,
            'category_id' => (int)($question['category_id'] ?? 0) ?: '',
            'category' => trim((string)($question['category_name'] ?? '')) ?: 'Uncategorized',
            'category_status' => !empty($question['category_id'])
                ? ((int)($question['category_is_active'] ?? 0) === 1 ? 'Active' : 'Inactive')
                : '',
            'passage_id' => $passageKind === 'passage' ? ((int)($question['passage_id'] ?? 0) ?: '') : '',
            'passage' => $passageKind === 'passage'
                ? (trim((string)($question['passage_title'] ?? '')) ?: 'Passage #' . (int)($question['passage_id'] ?? 0))
                : '',
            'passage_status' => $passageKind === 'passage'
                ? ((int)($question['passage_is_active'] ?? 0) === 1 ? 'Active' : 'Inactive')
                : '',
            'question_id' => $questionId,
            'question_order' => !isset($question['question_sort_order']) ? '' : (int)$question['question_sort_order'],
            'passage_sequence' => !isset($question['passage_seq']) ? '' : (int)$question['passage_seq'],
            'question_type' => qz_question_export_type_label($questionType),
            'question_status' => (int)($question['question_is_active'] ?? 0) === 1 ? 'Active' : 'Inactive',
            'question' => qz_question_export_plain_text($question['question_text'] ?? ''),
            'answer_count' => count($questionAnswers),
            'answer_options' => qz_question_export_plain_text(implode("\n\n", $optionLines)),
            'answer_key' => implode(', ', $keyLines),
            'question_image_id' => (int)($question['question_image_media_id'] ?? 0) ?: '',
            'question_image' => (string)($question['question_image_url'] ?? ''),
            'question_file_id' => (int)($question['question_file_id'] ?? 0) ?: '',
            'question_file_label' => (string)($question['question_file_label'] ?? ''),
            'question_file' => (string)($question['question_file_url'] ?? ''),
            'question_youtube' => (string)($question['question_youtube_url'] ?? ''),
            'passage_text' => $passageKind === 'passage' ? qz_question_export_plain_text($question['passage_text'] ?? '') : '',
            'passage_image_id' => $passageKind === 'passage' ? ((int)($question['passage_image_media_id'] ?? 0) ?: '') : '',
            'passage_image' => $passageKind === 'passage' ? (string)($question['passage_image_url'] ?? '') : '',
            'passage_file_id' => $passageKind === 'passage' ? ((int)($question['passage_file_id'] ?? 0) ?: '') : '',
            'passage_file_label' => $passageKind === 'passage' ? (string)($question['passage_file_label'] ?? '') : '',
            'passage_file' => $passageKind === 'passage' ? (string)($question['passage_file_url'] ?? '') : '',
            'passage_youtube' => $passageKind === 'passage' ? (string)($question['passage_youtube_url'] ?? '') : '',
        ];
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $scope = $category ? 'Category: ' . (string)($category['name'] ?? '') : 'All categories';
    $spreadsheet->getProperties()
        ->setCreator('Jyavani Quiz')
        ->setTitle('Quiz Question Bank')
        ->setSubject('Questions and answers for ' . (string)($quiz['title'] ?? 'Quiz') . ' - ' . $scope);

    $questionSheet = $spreadsheet->getActiveSheet();
    $questionSheet->setTitle('Questions');
    qz_question_export_write_sheet($questionSheet, qz_question_export_columns(), $questionRecords);

    $answerSheet = $spreadsheet->createSheet();
    $answerSheet->setTitle('Answers');
    qz_question_export_write_sheet($answerSheet, qz_answer_export_columns(), $answerRecords);
    $spreadsheet->setActiveSheetIndex(0);
    return $spreadsheet;
}

function qz_question_export_docx_support(): array {
    $missing = [];
    if (PHP_INT_SIZE !== 8) $missing[] = '64-bit PHP';
    foreach (['mbstring', 'zlib'] as $extension) {
        if (!extension_loaded($extension)) $missing[] = 'ext-' . $extension;
    }
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload) || !is_readable($autoload)) $missing[] = 'bundled Composer autoloader';

    return [
        'available' => $missing === [],
        'autoload' => $autoload,
        'message' => $missing === [] ? '' : 'Missing ' . implode(', ', $missing) . '.',
    ];
}

function qz_question_export_docx_xml(string $value): string {
    if (!mb_check_encoding($value, 'UTF-8')) $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    $value = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function qz_question_export_docx_runs(string $text): string {
    $lines = explode("\n", $text);
    $runs = '';
    foreach ($lines as $index => $line) {
        if ($index > 0) $runs .= '<w:r><w:br/></w:r>';
        $runs .= '<w:r><w:t xml:space="preserve">' . qz_question_export_docx_xml($line) . '</w:t></w:r>';
    }
    return $runs;
}

function qz_question_export_docx_paragraph(string $text, string $style = ''): string {
    $properties = $style !== '' ? '<w:pPr><w:pStyle w:val="' . qz_question_export_docx_xml($style) . '"/></w:pPr>' : '';
    return '<w:p>' . $properties . qz_question_export_docx_runs($text) . '</w:p>';
}

function qz_question_export_docx_cell(string $text, int $width, string $style = '', int $span = 1, string $shade = ''): string {
    $properties = '<w:tcW w:w="' . $width . '" w:type="dxa"/>';
    if ($span > 1) $properties .= '<w:gridSpan w:val="' . $span . '"/>';
    if ($shade !== '') $properties .= '<w:shd w:val="clear" w:color="auto" w:fill="' . qz_question_export_docx_xml($shade) . '"/>';
    $properties .= '<w:vAlign w:val="center"/>';
    return '<w:tc><w:tcPr>' . $properties . '</w:tcPr>' . qz_question_export_docx_paragraph($text, $style) . '</w:tc>';
}

function qz_question_export_docx_score(mixed $score): string {
    if (!is_numeric($score)) return trim((string)$score);
    $number = (float)$score;
    if (is_finite($number) && floor($number) === $number) return (string)(int)$number;
    return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
}

function qz_question_export_docx_document(array $questions, array $answers, array $quiz, ?array $category = null): string {
    $answersByQuestion = [];
    foreach ($answers as $answer) {
        $questionId = (int)($answer['question_id'] ?? 0);
        if ($questionId > 0) $answersByQuestion[$questionId][] = $answer;
    }

    $title = trim((string)($quiz['title'] ?? '')) ?: 'Quiz';
    $scope = $category !== null
        ? 'Category: ' . (trim((string)($category['name'] ?? '')) ?: '#' . (int)($category['id'] ?? 0))
        : 'All categories';
    $body = qz_question_export_docx_paragraph($title, 'Title');
    $body .= qz_question_export_docx_paragraph('Question Bank · ' . $scope, 'Subtitle');

    $currentCategory = null;
    $currentPassage = null;
    foreach ($questions as $index => $question) {
        $categoryId = (int)($question['category_id'] ?? 0);
        $categoryName = trim((string)($question['category_name'] ?? '')) ?: 'Uncategorized';
        $categoryKey = $categoryId > 0 ? 'id:' . $categoryId : 'name:' . $categoryName;
        if ($categoryKey !== $currentCategory) {
            $currentCategory = $categoryKey;
            $currentPassage = null;
            $body .= qz_question_export_docx_paragraph($categoryName, 'Heading1');
        }

        $passageKind = (string)($question['passage_kind'] ?? 'none');
        if ($passageKind === 'passage') {
            $passageId = (int)($question['passage_id'] ?? 0);
            $passageTitle = trim((string)($question['passage_title'] ?? '')) ?: 'Passage';
            $passageText = qz_question_export_normalized_text($question['passage_text'] ?? '');
            $passageKey = $passageId > 0 ? 'id:' . $passageId : hash('sha256', $passageTitle . "\0" . $passageText);
            if ($passageKey !== $currentPassage) {
                $currentPassage = $passageKey;
                $body .= qz_question_export_docx_paragraph($passageTitle, 'Heading2');
                if ($passageText !== '') $body .= qz_question_export_docx_paragraph($passageText, 'Passage');
            }
        } else {
            $currentPassage = null;
        }

        $questionId = (int)($question['question_id'] ?? 0);
        $questionText = qz_question_export_normalized_text($question['question_text'] ?? '');
        if ((int)($question['question_is_active'] ?? 0) !== 1) $questionText .= ' [Inactive]';
        $body .= '<w:tbl><w:tblPr>'
            . '<w:tblW w:w="9000" w:type="dxa"/>'
            . '<w:tblBorders><w:top w:val="single" w:sz="6" w:color="CBD5E1"/>'
            . '<w:left w:val="single" w:sz="6" w:color="CBD5E1"/><w:bottom w:val="single" w:sz="6" w:color="CBD5E1"/>'
            . '<w:right w:val="single" w:sz="6" w:color="CBD5E1"/><w:insideH w:val="single" w:sz="4" w:color="E2E8F0"/>'
            . '<w:insideV w:val="single" w:sz="4" w:color="E2E8F0"/></w:tblBorders>'
            . '<w:tblLayout w:type="fixed"/>'
            . '<w:tblCellMar><w:top w:w="100" w:type="dxa"/><w:left w:w="120" w:type="dxa"/>'
            . '<w:bottom w:w="100" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr><w:tblGrid><w:gridCol w:w="700"/><w:gridCol w:w="7500"/><w:gridCol w:w="800"/></w:tblGrid>';
        $body .= '<w:tr>'
            . qz_question_export_docx_cell((string)($index + 1), 700, 'QuestionNumber', 1, 'DBEAFE')
            . qz_question_export_docx_cell($questionText, 8300, 'Question', 2, 'EFF6FF')
            . '</w:tr>';

        $questionAnswers = $answersByQuestion[$questionId] ?? [];
        if ($questionAnswers === []) {
            $body .= '<w:tr>'
                . qz_question_export_docx_cell('—', 700, 'AnswerLabel')
                . qz_question_export_docx_cell('No predefined answers', 7500, 'Muted')
                . qz_question_export_docx_cell('—', 800, 'Score')
                . '</w:tr>';
        } else {
            foreach ($questionAnswers as $answerIndex => $answer) {
                $answerText = qz_question_export_normalized_text($answer['answer_text'] ?? '');
                if ((int)($answer['answer_is_active'] ?? 0) !== 1) $answerText .= ' [Inactive]';
                $body .= '<w:tr>'
                    . qz_question_export_docx_cell(qz_question_export_alpha_label($answerIndex), 700, 'AnswerLabel')
                    . qz_question_export_docx_cell($answerText, 7500, 'Answer')
                    . qz_question_export_docx_cell(qz_question_export_docx_score($answer['score'] ?? 0), 800, 'Score')
                    . '</w:tr>';
            }
        }
        $body .= '</w:tbl><w:p><w:pPr><w:spacing w:after="100"/></w:pPr></w:p>';
    }

    if ($questions === []) $body .= qz_question_export_docx_paragraph('No questions were found for this scope.', 'Muted');

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';
}

function qz_question_export_docx($stream, array $questions, array $answers, array $quiz, ?array $category = null): void {
    if (!is_resource($stream)) throw new InvalidArgumentException('Word export stream is invalid.');
    if (!class_exists(\ZipStream\ZipStream::class)) throw new RuntimeException('ZipStream is unavailable.');

    $title = trim((string)($quiz['title'] ?? '')) ?: 'Quiz';
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'word/document.xml' => qz_question_export_docx_document($questions, $answers, $quiz, $category),
        'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Aptos" w:hAnsi="Aptos"/><w:sz w:val="21"/><w:szCs w:val="21"/><w:lang w:val="en-US"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="80" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="80"/></w:pPr><w:rPr><w:b/><w:color w:val="0F172A"/><w:sz w:val="36"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="260"/></w:pPr><w:rPr><w:color w:val="64748B"/><w:sz w:val="20"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="Heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="220" w:after="100"/></w:pPr><w:rPr><w:b/><w:color w:val="1D4ED8"/><w:sz w:val="28"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="Heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="140" w:after="50"/></w:pPr><w:rPr><w:b/><w:color w:val="334155"/><w:sz w:val="23"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Passage"><w:name w:val="Passage"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="120"/><w:ind w:left="180"/></w:pPr><w:rPr><w:i/><w:color w:val="475569"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="QuestionNumber"><w:name w:val="Question Number"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:b/><w:color w:val="1D4ED8"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Question"><w:name w:val="Question"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:color w:val="0F172A"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="AnswerLabel"><w:name w:val="Answer Label"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:b/><w:color w:val="475569"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Answer"><w:name w:val="Answer"/><w:basedOn w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Score"><w:name w:val="Score"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:b/><w:color w:val="166534"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Muted"><w:name w:val="Muted"/><w:basedOn w:val="Normal"/><w:rPr><w:i/><w:color w:val="64748B"/></w:rPr></w:style>'
            . '</w:styles>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . qz_question_export_docx_xml($title . ' Question Bank') . '</dc:title><dc:creator>Jyavani Quiz</dc:creator>'
            . '<cp:lastModifiedBy>Jyavani Quiz</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Jyavani Quiz</Application><AppVersion>1.0</AppVersion></Properties>',
    ];

    $zip = new \ZipStream\ZipStream(outputStream: $stream, enableZip64: false, sendHttpHeaders: false);
    foreach ($files as $name => $contents) $zip->addFile(fileName: $name, data: $contents);
    $zip->finish();
    rewind($stream);
}
