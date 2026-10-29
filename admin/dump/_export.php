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

function qz_question_export_plain_text(mixed $value): string {
    $text = (string)$value;
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $text = str_replace("\0", '', $text);
    $text = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $text) ?? $text;
    $text = preg_replace('~</\s*(?:p|div|li|h[1-6]|tr)\s*>~i', "\n", $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n?|\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    $text = trim($text);

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
