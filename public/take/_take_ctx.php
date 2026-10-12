<?php
// /quiz/take/_take_ctx.php
declare(strict_types=1);

require_once __DIR__ . '/../_security.php';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function go(string $loc){
  if (!headers_sent()) { header('Location: '.$loc); exit; }
  echo '<script>location.href='.json_encode($loc).';</script>';
  exit;
}

function quiz_is_open(array $quiz, string $now): bool {
  if ((int)($quiz['is_active'] ?? 0) !== 1) return false;
  if (!empty($quiz['start_at']) && $now < $quiz['start_at']) return false;
  if (!empty($quiz['end_at']) && $now > $quiz['end_at']) return false;
  return true;
}

function set_cookie(string $name, string $value, int $ttl = 31536000): void {
  setcookie($name, $value, [
    'expires'  => time() + $ttl,
    'path'     => '/',
    'secure'   => qz_cookie_secure(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function generate_attempt_alias(PDO $pdo): string {
  do {
    $alias = 'a_' . bin2hex(random_bytes(6));
    $st = $pdo->prepare("SELECT 1 FROM quiz_attempts WHERE attempt_alias = :a LIMIT 1");
    $st->execute([':a'=>$alias]);
    $exists = (bool)$st->fetchColumn();
  } while ($exists);
  return $alias;
}

/* =========================================================
   Helpers: schema detect
   ========================================================= */
function qz_table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = :t
    LIMIT 1
  ");
  $st->execute([':t'=>$table]);
  return (bool)$st->fetchColumn();
}

function qz_column_exists(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = :t
      AND column_name = :c
    LIMIT 1
  ");
  $st->execute([':t'=>$table, ':c'=>$column]);
  return (bool)$st->fetchColumn();
}

/* =========================================================
   AUTO-REBUILD HELPERS (strict cap enforcement)
   - rebuild attempt_questions jika:
     a) jumlah soal attempt != questions_per_attempt
     b) ada kategori yang melebihi max_questions terbaru
   - rebuild hanya jika BELUM ADA jawaban (aman)
   ========================================================= */
function qz_get_active_category_caps(PDO $pdo, int $quiz_id): array {
  if (!qz_table_exists($pdo, 'question_categories')) return [];
  $st = $pdo->prepare("
    SELECT id, max_questions
    FROM question_categories
    WHERE quiz_id = :qid AND is_active = 1
  ");
  $st->execute([':qid'=>$quiz_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $caps = [];
  foreach ($rows as $r) {
    $cid = (int)($r['id'] ?? 0);
    if ($cid <= 0) continue;
    $raw = $r['max_questions'] ?? null;
    if ($raw === null || $raw === '') continue; // NULL => unlimited
    $caps[$cid] = max(0, (int)$raw);
  }
  return $caps;
}

/**
 * ✅ FIX PENTING:
 * - REAL PASSAGE (p.passage_text != '__CATEGORY_CONTAINER__') TIDAK BOLEH fallback ke q.category_id
 *   Kalau passage.category_id kosong => hitung sebagai 0 (UNCAT)
 * - Container/non-passage tetap boleh fallback.
 */
function qz_attempt_category_distribution(PDO $pdo, int $attempt_id): array {
  $hasPassTable = qz_table_exists($pdo, 'question_passages');
  $hasQCatCol   = qz_column_exists($pdo, 'questions', 'category_id');

  $joinP = '';
  if ($hasPassTable) {
    $joinP = "LEFT JOIN question_passages p ON p.id = q.passage_id AND p.quiz_id = q.quiz_id";
  }

  if ($hasPassTable && $hasQCatCol) {
    $expr = "CASE
      WHEN p.id IS NOT NULL AND p.passage_text <> '__CATEGORY_CONTAINER__'
        THEN COALESCE(p.category_id, 0)
      ELSE COALESCE(p.category_id, q.category_id, 0)
    END";
  } elseif ($hasPassTable) {
    $expr = "COALESCE(p.category_id, 0)";
  } elseif ($hasQCatCol) {
    $expr = "COALESCE(q.category_id, 0)";
  } else {
    $expr = "0";
  }

  $sql = "
    SELECT {$expr} AS cid, COUNT(*) AS cnt
    FROM attempt_questions aq
    JOIN questions q ON q.id = aq.question_id
    {$joinP}
    WHERE aq.attempt_id = :aid
    GROUP BY cid
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':aid'=>$attempt_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $dist = [];
  foreach ($rows as $r) {
    $cid = (int)($r['cid'] ?? 0);
    $dist[$cid] = (int)($r['cnt'] ?? 0);
  }
  return $dist;
}

function qz_attempt_needs_rebuild(PDO $pdo, int $attempt_id, int $quiz_id, int $desired_total): array {
  $desired_total = max(1, min(200, (int)$desired_total));

  $st = $pdo->prepare("SELECT COUNT(*) FROM attempt_questions WHERE attempt_id = :aid");
  $st->execute([':aid'=>$attempt_id]);
  $cnt = (int)$st->fetchColumn();

  if ($cnt === 0) return ['need'=>true, 'reason'=>'empty'];
  if ($cnt !== $desired_total) return ['need'=>true, 'reason'=>'count_mismatch'];

  $caps = qz_get_active_category_caps($pdo, $quiz_id);
  if (!$caps) return ['need'=>false, 'reason'=>'no_caps'];

  $dist = qz_attempt_category_distribution($pdo, $attempt_id);
  foreach ($caps as $cid => $cap) {
    $cur = (int)($dist[(int)$cid] ?? 0);
    if ($cur > (int)$cap) {
      return ['need'=>true, 'reason'=>"cap_violation:cat={$cid},cur={$cur},cap={$cap}"];
    }
  }

  return ['need'=>false, 'reason'=>'ok'];
}

/* =========================================================
   QUESTION POOL BUILDER (STRICT CAPS + PASSAGE GROUPS)
   Target:
   - Blok kategori berurutan sesuai question_categories.sort_order
   - Kuota per kategori = question_categories.max_questions
   - Passage tidak boleh terpencar
   - Passage shuffle_questions & max_questions dihormati
   - STRICT: kategori yang punya max_questions tidak boleh melebihi cap
   - ✅ FIX: REAL PASSAGE tanpa category dianggap UNCAT (0), tidak fallback ke q.category_id
   ========================================================= */
function qz_pick_attempt_question_ids(PDO $pdo, int $quiz_id, int $limit): array {
  $limit = max(1, min(200, (int)$limit));

  $hasCatTable  = qz_table_exists($pdo, 'question_categories');
  $hasPassTable = qz_table_exists($pdo, 'question_passages');
  $hasQCatCol   = qz_column_exists($pdo, 'questions', 'category_id');

  // fallback struktur lama
  if (!$hasCatTable && !$hasPassTable) {
    $st = $pdo->prepare("SELECT id FROM questions WHERE quiz_id=:qid AND is_active=1 ORDER BY RAND() LIMIT {$limit}");
    $st->execute([':qid'=>$quiz_id]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }

  // categories (urut UI)
  $cats = [];
  if ($hasCatTable) {
    $st = $pdo->prepare("
      SELECT id, name, min_questions, max_questions, shuffle_passages, shuffle_questions, sort_order
      FROM question_categories
      WHERE quiz_id = :qid AND is_active = 1
      ORDER BY
        CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END,
        sort_order ASC,
        id ASC
    ");
    $st->execute([':qid'=>$quiz_id]);
    $cats = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // STRICT CAP MAP (category_id => max) utk kategori aktif (yang max != NULL)
  $catMaxById = [];
  if ($cats) {
    foreach ($cats as $c) {
      $cid = (int)($c['id'] ?? 0);
      if ($cid <= 0) continue;
      $capRaw = $c['max_questions'] ?? null;
      if ($capRaw === null || $capRaw === '') continue; // NULL => unlimited
      $catMaxById[$cid] = max(0, (int)$capRaw);
    }
  }

  // passages (butuh passage_text utk deteksi container)
  $passages = [];
  if ($hasPassTable) {
    $st = $pdo->prepare("
      SELECT id, category_id, title, passage_text, min_questions, max_questions, shuffle_questions, sort_order, is_active
      FROM question_passages
      WHERE quiz_id = :qid AND is_active = 1
      ORDER BY
        CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END,
        sort_order ASC,
        id ASC
    ");
    $st->execute([':qid'=>$quiz_id]);
    $passages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // questions minimal
  $sqlQ = "SELECT id, passage_id, passage_seq, sort_order".($hasQCatCol ? ", category_id" : "")."
           FROM questions
           WHERE quiz_id = :qid AND is_active = 1";
  $stQ = $pdo->prepare($sqlQ);
  $stQ->execute([':qid'=>$quiz_id]);
  $allQ = $stQ->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // passage meta
  $passMetaById = [];        // pid => meta
  $passByCat    = [];        // cid => [pid...], hanya NON-CONTAINER
  $passUncat    = [];        // [pid...], hanya NON-CONTAINER

  foreach ($passages as $p) {
    $pid = (int)($p['id'] ?? 0);
    if ($pid <= 0) continue;

    $txt = trim((string)($p['passage_text'] ?? ''));
    $isContainer = ($txt === '__CATEGORY_CONTAINER__');
    $cid = (int)($p['category_id'] ?? 0);

    $passMetaById[$pid] = [
      'id' => $pid,
      'category_id' => $cid,
      'sort_order' => $p['sort_order'] ?? null,
      'shuffle_questions' => (int)($p['shuffle_questions'] ?? 0),
      'min_questions' => (int)($p['min_questions'] ?? 0),
      'max_questions' => $p['max_questions'] ?? null,
      'is_container' => $isContainer ? 1 : 0,
    ];

    if ($isContainer) {
      // container tidak dijadikan stimulus group
      continue;
    }

    if ($cid > 0) $passByCat[$cid][] = $pid;
    else $passUncat[] = $pid;
  }

  // ===== MAP: qid => effective category (untuk STRICT CAP) =====
  // ✅ FIX RULE:
  // - REAL PASSAGE (bukan container): kategori = passage.category_id saja; kalau kosong => 0 (UNCAT)
  // - CONTAINER passage: kategori = passage.category_id (kalau kosong fallback ke q.category_id)
  // - NON-PASSAGE: kategori = q.category_id
  $qidEffCat = [];
  foreach ($allQ as $r) {
    $qid = (int)($r['id'] ?? 0);
    if ($qid <= 0) continue;

    $pid  = (int)($r['passage_id'] ?? 0);
    $qcid = $hasQCatCol ? (int)($r['category_id'] ?? 0) : 0;

    $eff = $qcid;

    if ($pid > 0 && isset($passMetaById[$pid])) {
      $pm = $passMetaById[$pid];
      $pcid = (int)($pm['category_id'] ?? 0);
      $isCont = !empty($pm['is_container']);

      if ($isCont) {
        $eff = ($pcid > 0) ? $pcid : $qcid;
      } else {
        // REAL PASSAGE: no fallback to q.category
        $eff = ($pcid > 0) ? $pcid : 0;
      }
    }

    $qidEffCat[$qid] = ($eff > 0) ? $eff : 0;
  }

  // group questions
  $qRowsByPass   = []; // pid (NON-CONTAINER) => rows
  $qRowsNonByCat = []; // cid => rows
  $qRowsNonUncat = []; // rows

  foreach ($allQ as $r) {
    $qid = (int)($r['id'] ?? 0);
    if ($qid <= 0) continue;

    $pid = (int)($r['passage_id'] ?? 0);

    // hit passage meta
    if ($pid > 0 && isset($passMetaById[$pid])) {
      $pm = $passMetaById[$pid];
      $pcid = (int)($pm['category_id'] ?? 0);
      $qcid = $hasQCatCol ? (int)($r['category_id'] ?? 0) : 0;

      if (!empty($pm['is_container'])) {
        // container boleh fallback
        $effCid = ($pcid > 0) ? $pcid : $qcid;

        if ($effCid > 0) $qRowsNonByCat[$effCid][] = $r;
        else $qRowsNonUncat[] = $r;
      } else {
        // REAL PASSAGE (stimulus) selalu jadi group passage
        $qRowsByPass[$pid][] = $r;
      }
      continue;
    }

    // non-passage
    if ($hasQCatCol) {
      $cid = (int)($r['category_id'] ?? 0);
      if ($cid > 0) $qRowsNonByCat[$cid][] = $r;
      else $qRowsNonUncat[] = $r;
    } else {
      $qRowsNonUncat[] = $r;
    }
  }

  // sorters
  $sortPassQStable = function(array $a, array $b): int {
    $aseq = $a['passage_seq']; $bseq = $b['passage_seq'];
    $an = ($aseq === null || $aseq === ''); $bn = ($bseq === null || $bseq === '');
    if ($an !== $bn) return $an ? 1 : -1;
    if (!$an && (int)$aseq !== (int)$bseq) return ((int)$aseq < (int)$bseq) ? -1 : 1;

    $aso = $a['sort_order']; $bso = $b['sort_order'];
    $an2 = ($aso === null || $aso === ''); $bn2 = ($bso === null || $bso === '');
    if ($an2 !== $bn2) return $an2 ? 1 : -1;
    if (!$an2 && (int)$aso !== (int)$bso) return ((int)$aso < (int)$bso) ? -1 : 1;

    return ((int)$a['id'] < (int)$b['id']) ? -1 : 1;
  };

  $sortNonStable = function(array $a, array $b): int {
    $aso = $a['sort_order']; $bso = $b['sort_order'];
    $an2 = ($aso === null || $aso === ''); $bn2 = ($bso === null || $bso === '');
    if ($an2 !== $bn2) return $an2 ? 1 : -1;
    if (!$an2 && (int)$aso !== (int)$bso) return ((int)$aso < (int)$bso) ? -1 : 1;
    return ((int)$a['id'] < (int)$b['id']) ? -1 : 1;
  };

  // precompute passage qids (respect passage shuffle+max)
  $passQids = []; // pid => [qid...]
  foreach ($qRowsByPass as $pid => $rows) {
    $pm = $passMetaById[$pid] ?? null;
    if (!$pm) { $passQids[$pid] = []; continue; }

    if (!empty($pm['shuffle_questions'])) shuffle($rows);
    else usort($rows, $sortPassQStable);

    $pmax = $pm['max_questions'];
    $pmax = ($pmax === null || $pmax === '') ? PHP_INT_MAX : max(0, (int)$pmax);
    $rows = array_slice($rows, 0, $pmax);

    $ids = [];
    foreach ($rows as $rr) $ids[] = (int)$rr['id'];
    $passQids[$pid] = $ids;
  }

  // precompute non-passage qids by category
  $nonQidsByCat = []; // cid => [qid...]
  foreach ($qRowsNonByCat as $cid => $rows) {
    usort($rows, $sortNonStable);
    $ids = [];
    foreach ($rows as $rr) $ids[] = (int)$rr['id'];
    $nonQidsByCat[(int)$cid] = $ids;
  }

  // uncategorized pools
  $uncatPassIds = $passUncat;
  $uncatNonIds  = [];
  if ($qRowsNonUncat) {
    usort($qRowsNonUncat, $sortNonStable);
    foreach ($qRowsNonUncat as $rr) $uncatNonIds[] = (int)$rr['id'];
    shuffle($uncatNonIds);
  }

  $picked = [];
  $used   = [];
  $pickedCountByCat = []; // cid => count (untuk cap)

  // STRICT append (enforce category cap even in LAST RESORT)
  $append_qid = function(int $qid) use (&$picked, &$used, $limit, &$qidEffCat, &$catMaxById, &$pickedCountByCat): bool {
    if ($qid <= 0) return false;
    if (isset($used[$qid])) return false;
    if (count($picked) >= $limit) return false;

    $cid = (int)($qidEffCat[$qid] ?? 0);
    if ($cid > 0 && array_key_exists($cid, $catMaxById)) {
      $cap = (int)$catMaxById[$cid];
      $cur = (int)($pickedCountByCat[$cid] ?? 0);
      if ($cur >= $cap) return false;
    }

    $used[$qid] = 1;
    $picked[] = $qid;

    if ($cid > 0 && array_key_exists($cid, $catMaxById)) {
      $pickedCountByCat[$cid] = (int)($pickedCountByCat[$cid] ?? 0) + 1;
    }

    return true;
  };

  $append_passage_group = function(int $pid, int $need) use (&$append_qid, &$passQids, &$passMetaById, &$catMaxById, &$pickedCountByCat): int {
    if ($need <= 0) return 0;

    $ids = $passQids[$pid] ?? [];
    if (!$ids) return 0;

    $pm = $passMetaById[$pid] ?? null;
    $pmin = $pm ? max(0, (int)($pm['min_questions'] ?? 0)) : 0;

    // kalau remaining slot < min passage => skip
    if ($pmin > 0 && $need < $pmin) return 0;

    // kalau total soal di passage < min => mustahil, buang
    if ($pmin > 0 && count($ids) < $pmin) {
      $passQids[$pid] = [];
      return 0;
    }

    // ✅ FIX: REAL PASSAGE hanya pakai passage.category_id. Kalau kosong => 0 (UNCAT) => tidak ikut cap kategori.
    $cid = 0;
    if ($pm) $cid = (int)($pm['category_id'] ?? 0);

    $capRemain = PHP_INT_MAX;
    if ($cid > 0 && array_key_exists($cid, $catMaxById)) {
      $cap = (int)$catMaxById[$cid];
      $cur = (int)($pickedCountByCat[$cid] ?? 0);
      $capRemain = max(0, $cap - $cur);

      if ($capRemain <= 0) return 0;
      if ($pmin > 0 && $capRemain < $pmin) return 0;
    }

    $takeN = min($need, count($ids), $capRemain);
    if ($pmin > 0 && $takeN < $pmin) return 0;

    $taken = 0;
    for ($i=0; $i<$takeN; $i++) {
      if ($append_qid((int)$ids[$i])) $taken++;
    }

    // habiskan supaya tidak pecah
    $passQids[$pid] = [];
    return $taken;
  };

  $append_non_list = function(int $cid, int $need, bool $shuffle) use (&$append_qid, &$nonQidsByCat): int {
    if ($need <= 0) return 0;
    $ids = $nonQidsByCat[$cid] ?? [];
    if (!$ids) return 0;
    if ($shuffle) shuffle($ids);

    $taken = 0;
    $remain = [];
    foreach ($ids as $qid) {
      if ($taken < $need) {
        if ($append_qid((int)$qid)) $taken++;
        else $remain[] = (int)$qid;
      } else {
        $remain[] = (int)$qid;
      }
    }
    $nonQidsByCat[$cid] = $remain;
    return $taken;
  };

  // 1) Ambil per kategori sesuai sort_order
  foreach ($cats as $cat) {
    if (count($picked) >= $limit) break;

    $cid = (int)($cat['id'] ?? 0);
    if ($cid <= 0) continue;

    $capRaw = $cat['max_questions'] ?? null;
    $hasCap = !($capRaw === null || $capRaw === '');
    $cap    = $hasCap ? max(0, (int)$capRaw) : PHP_INT_MAX;

    $minRaw = $cat['min_questions'] ?? 0;
    $minReq = max(0, (int)$minRaw);
    if ($hasCap) $minReq = min($minReq, $cap);

    $maxNeed = min($cap, $limit - count($picked));
    if ($maxNeed <= 0) continue;

    $minNeed = min($minReq, $maxNeed);

    $shufflePass = !empty($cat['shuffle_passages']);
    $shuffleNonQ = !empty($cat['shuffle_questions']);

    $pids = $passByCat[$cid] ?? [];
    if ($shufflePass && $pids) shuffle($pids);

    // Phase 1: penuhi minimal kategori
    $before = count($picked);
    $need1 = $minNeed;

    if ($need1 > 0) {
      foreach ($pids as $pid) {
        if ($need1 <= 0) break;
        $need1 -= $append_passage_group((int)$pid, $need1);
      }
      if ($need1 > 0) {
        $need1 -= $append_non_list($cid, $need1, $shuffleNonQ);
      }
    }

    $takenMin = count($picked) - $before;

    // Phase 2: isi sampai max kategori
    $need2 = $maxNeed - $takenMin;
    if ($need2 > 0) {
      foreach ($pids as $pid) {
        if ($need2 <= 0) break;
        $need2 -= $append_passage_group((int)$pid, $need2);
      }
      if ($need2 > 0) {
        $need2 -= $append_non_list($cid, $need2, $shuffleNonQ);
      }
    }

    // kalau kategori punya cap, buang sisa pool kategori ini (biar tidak “nyelonong” ke fase lain)
    if ($hasCap) {
      $nonQidsByCat[$cid] = [];
      foreach (($passByCat[$cid] ?? []) as $pid2) {
        $passQids[(int)$pid2] = [];
      }
    }
  }

  // 2) Sisa slot => uncategorized passage (tetap group) lalu uncategorized non
  if (count($picked) < $limit && $uncatPassIds) {
    shuffle($uncatPassIds);
    foreach ($uncatPassIds as $pid) {
      if (count($picked) >= $limit) break;
      $need = $limit - count($picked);
      $append_passage_group((int)$pid, $need);
    }
  }

  if (count($picked) < $limit && $uncatNonIds) {
    foreach ($uncatNonIds as $qid) {
      if (count($picked) >= $limit) break;
      $append_qid((int)$qid);
    }
  }

  // 3) LAST RESORT: tetap tidak boleh melanggar cap kategori
  if (count($picked) < $limit) {
    $left = [];
    foreach ($allQ as $r) {
      $qid = (int)($r['id'] ?? 0);
      if ($qid > 0 && !isset($used[$qid])) $left[] = $qid;
    }
    shuffle($left);
    foreach ($left as $qid) {
      if (count($picked) >= $limit) break;
      $append_qid((int)$qid);
    }
  }

  if (count($picked) < $limit) {
    error_log("[qz_pick_attempt_question_ids] strict caps: picked ".count($picked)."/{$limit} (pool tidak cukup tanpa melanggar max kategori)");
  }

  return array_slice($picked, 0, $limit);
}

function qz_resequence_attempt_questions(PDO $pdo, int $attempt_id): void {
  // kalau tabel-tabel ini tidak ada, tidak perlu resequence
  if (!qz_table_exists($pdo, 'questions') || !qz_table_exists($pdo, 'attempt_questions')) return;

  $hasPassTable = qz_table_exists($pdo, 'question_passages');
  $hasCatTable  = qz_table_exists($pdo, 'question_categories');
  $hasQCatCol   = qz_column_exists($pdo, 'questions', 'category_id');

  // kalau gak ada passage & category sama sekali, biarkan urutan yang sudah ada
  if (!$hasPassTable && !$hasCatTable) return;

  // deteksi kolom shuffle supaya aman lintas schema
  $hasCatShufflePass = $hasCatTable ? qz_column_exists($pdo, 'question_categories', 'shuffle_passages') : false;
  $hasCatShuffleQ    = $hasCatTable ? qz_column_exists($pdo, 'question_categories', 'shuffle_questions') : false;
  $hasPassShuffleQ   = $hasPassTable ? qz_column_exists($pdo, 'question_passages', 'shuffle_questions') : false;

  // seed stabil per attempt (boleh tambah token_hash biar lebih unik)
  $tok = '';
  try {
    $stTok = $pdo->prepare("SELECT token_hash FROM quiz_attempts WHERE id = :id LIMIT 1");
    $stTok->execute([':id'=>$attempt_id]);
    $tok = (string)($stTok->fetchColumn() ?: '');
  } catch (Throwable $e) {
    $tok = '';
  }

  if (!defined('QZ_SEQ_SHUFFLE_SALT')) {
    define('QZ_SEQ_SHUFFLE_SALT', 'CHANGE_ME_TO_RANDOM_LONG_STRING_SEQ');
  }
  $seed = hash('sha256', QZ_SEQ_SHUFFLE_SALT.'|'.$attempt_id.'|'.$tok);

  // helper expr: real passage?
  $exprRealPass = "(
    p.id IS NOT NULL
    AND p.passage_text <> '__CATEGORY_CONTAINER__'
  )";

  // category sort/id: REAL PASSAGE tidak boleh fallback ke q.category (sesuai aturan kamu)
  $exprCatSort = "CASE
    WHEN {$exprRealPass} THEN COALESCE(pc.sort_order, 999999)
    ELSE COALESCE(pc.sort_order, qc.sort_order, 999999)
  END";

  $exprCatId = "CASE
    WHEN {$exprRealPass} THEN COALESCE(pc.id, 999999)
    ELSE COALESCE(pc.id, qc.id, 999999)
  END";

  // shuffle flags dari effective category (pc untuk real passage, pc/qc fallback untuk selain itu)
  $exprCatShufflePass = $hasCatShufflePass ? "CASE
    WHEN {$exprRealPass} THEN COALESCE(pc.shuffle_passages, 0)
    ELSE COALESCE(pc.shuffle_passages, qc.shuffle_passages, 0)
  END" : "0";

  $exprCatShuffleQ = $hasCatShuffleQ ? "CASE
    WHEN {$exprRealPass} THEN COALESCE(pc.shuffle_questions, 0)
    ELSE COALESCE(pc.shuffle_questions, qc.shuffle_questions, 0)
  END" : "0";

  $exprPassShuffleQ = $hasPassShuffleQ ? "COALESCE(p.shuffle_questions, 0)" : "0";

  // block key:
  // - jika real passage: urutan passage mengikuti shuffle_passages kategori (kalau ON) atau sort_order (kalau OFF)
  // - jika non-passage/container: urutan non-passage mengikuti shuffle_questions kategori (kalau ON) atau sort_order (kalau OFF)
  $exprBlockKey = "CASE
    WHEN {$exprRealPass} THEN
      CASE
        WHEN ({$exprCatShufflePass} = 1) THEN MD5(CONCAT(:seed, ':P:', p.id))
        ELSE LPAD(COALESCE(p.sort_order, 999999), 10, '0')
      END
    ELSE
      CASE
        WHEN ({$exprCatShuffleQ} = 1) THEN MD5(CONCAT(:seed, ':NQ:', q.id))
        ELSE LPAD(COALESCE(q.sort_order, 999999), 10, '0')
      END
  END";

  // q key:
  // - jika real passage: urutan soal dalam passage mengikuti passage.shuffle_questions (kalau ON) atau passage_seq/sort_order (kalau OFF)
  // - jika non-passage/container: urutan mengikuti shuffle_questions kategori (kalau ON) atau sort_order (kalau OFF)
  $exprQKey = "CASE
    WHEN {$exprRealPass} THEN
      CASE
        WHEN ({$exprPassShuffleQ} = 1) THEN MD5(CONCAT(:seed, ':PQ:', p.id, ':', q.id))
        ELSE LPAD(COALESCE(q.passage_seq, q.sort_order, 999999), 10, '0')
      END
    ELSE
      CASE
        WHEN ({$exprCatShuffleQ} = 1) THEN MD5(CONCAT(:seed, ':NQ:', q.id))
        ELSE LPAD(COALESCE(q.sort_order, 999999), 10, '0')
      END
  END";

  // pass_absent: non-passage / container setelah passage
  $exprPassAbsent = "CASE
    WHEN p.id IS NULL THEN 1
    WHEN p.passage_text = '__CATEGORY_CONTAINER__' THEN 1
    ELSE 0
  END";

  $sql = "
    SELECT
      aq.id AS aq_id,
      {$exprCatSort} AS cat_sort,
      {$exprCatId}   AS cat_id,
      {$exprPassAbsent} AS pass_absent,
      {$exprBlockKey} AS block_key,
      COALESCE(p.id, 999999) AS pass_id,
      {$exprQKey} AS q_key,
      q.id AS q_id
    FROM attempt_questions aq
    JOIN questions q ON q.id = aq.question_id
    " . ($hasPassTable ? "
    LEFT JOIN question_passages p
      ON p.id = q.passage_id AND p.quiz_id = q.quiz_id
    " : "
    LEFT JOIN question_passages p ON 1=0
    ") . "
    " . ($hasCatTable ? "
    LEFT JOIN question_categories pc
      ON pc.id = p.category_id AND pc.quiz_id = q.quiz_id
    " : "
    LEFT JOIN question_categories pc ON 1=0
    ") . "
  ";

  if ($hasCatTable && $hasQCatCol) {
    $sql .= "
    LEFT JOIN question_categories qc
      ON qc.id = q.category_id AND qc.quiz_id = q.quiz_id
    ";
  } else {
    $sql .= " LEFT JOIN question_categories qc ON 1=0 ";
  }

  $sql .= "
    WHERE aq.attempt_id = :aid
    ORDER BY
      cat_sort ASC, cat_id ASC,
      pass_absent ASC,
      block_key ASC,
      pass_id ASC,
      q_key ASC,
      q_id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([':aid'=>$attempt_id, ':seed'=>$seed]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$rows) return;

  // geser seq dulu agar tidak bentrok UNIQUE(attempt_id,seq)
  $pdo->prepare("UPDATE attempt_questions SET seq = seq + 10000 WHERE attempt_id = :aid")
      ->execute([':aid'=>$attempt_id]);

  $u2 = $pdo->prepare("UPDATE attempt_questions SET seq = :seq WHERE id = :id AND attempt_id = :aid LIMIT 1");
  $seq = 1;
  foreach ($rows as $r) {
    $u2->execute([
      ':seq' => $seq++,
      ':id'  => (int)$r['aq_id'],
      ':aid' => $attempt_id,
    ]);
  }
}

/* =========================
   0) input: a (alias) or quiz (id)
   ========================= */
$alias = trim((string)($_GET['a'] ?? ''));
$quizParam = (int)($_GET['quiz'] ?? 0);
$boundQuizId = (int)(quiz_public_route_context()['quiz_id'] ?? 0);
if ($boundQuizId > 0) {
  if ($quizParam > 0 && $quizParam !== $boundQuizId) quiz_render_core_404();
  if ($alias === '') $quizParam = $boundQuizId;
}

if ($alias === '' && $quizParam <= 0) {
  $alias = trim((string)($_COOKIE['quiz_attempt'] ?? ''));
}
if ($alias !== '' && !preg_match('/^[a-zA-Z0-9_\-]{6,80}$/', $alias)) {
  $alias = '';
}

/* =========================
   1) require student cookie
   ========================= */
$cookieName = 'quiz_cid';
$cookie_id = isset($_COOKIE[$cookieName]) ? trim((string)$_COOKIE[$cookieName]) : '';
if ($cookie_id === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $cookie_id)) {
  $next = quiz_public_url('take', $alias !== '' ? ['a' => $alias] : ($quizParam > 0 ? ['quiz' => $quizParam] : []));
  go(quiz_public_url('register', ['next' => $next]));
}

$stmt = $pdo->prepare("SELECT id, name, email, phone FROM students WHERE cookie_id = :cid LIMIT 1");
$stmt->execute([':cid'=>$cookie_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
  $next = quiz_public_url('take', $alias !== '' ? ['a' => $alias] : ($quizParam > 0 ? ['quiz' => $quizParam] : []));
  go(quiz_public_url('register', ['next' => $next]));
}
$student_id = (int)$student['id'];

/* =========================
   2) load attempt
   ========================= */
$attempt = null;

if ($alias !== '') {
  $st = $pdo->prepare("
    SELECT *
    FROM quiz_attempts
    WHERE attempt_alias = :a AND student_id = :sid
    LIMIT 1
  ");
  $st->execute([':a'=>$alias, ':sid'=>$student_id]);
  $attempt = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($quizParam > 0) {
  $st = $pdo->prepare("
    SELECT *
    FROM quiz_attempts
    WHERE quiz_id = :qid AND student_id = :sid
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([':qid'=>$quizParam, ':sid'=>$student_id]);
  $attempt = $st->fetch(PDO::FETCH_ASSOC) ?: null;

  if ($attempt) {
    $alias = (string)($attempt['attempt_alias'] ?? '');
    if ($alias === '') {
      $alias = generate_attempt_alias($pdo);
      $pdo->prepare("UPDATE quiz_attempts SET attempt_alias = :a WHERE id = :id LIMIT 1")
          ->execute([':a'=>$alias, ':id'=>(int)$attempt['id']]);
    }
    set_cookie('quiz_attempt', $alias);
    go(quiz_public_url('take', ['a' => $alias]));
  }
}

if (!$attempt) {
  if ($quizParam > 0) go(quiz_public_url('start/confirm', ['quiz' => $quizParam]));
  go(quiz_public_url('start'));
}

$attempt_id = (int)$attempt['id'];
$quiz_id = (int)$attempt['quiz_id'];
quiz_public_require_quiz($quiz_id);
$alias = (string)($attempt['attempt_alias'] ?? $alias);
if ($alias !== '') set_cookie('quiz_attempt', $alias);

/* =========================
   3) load quiz info
   ========================= */
$st = $pdo->prepare("
  SELECT id, title, description, is_active, start_at, end_at, questions_per_attempt, duration_minutes,
         require_fullscreen_override, pause_when_blur_override, block_context_menu_override, block_shortcuts_override, devtools_detect_override, show_exam_badge_override, visual_theme_override,
         shuffle_answers, timer_warning_seconds, allow_mark_for_review, result_visibility
  FROM quizzes
  WHERE id = :id
  LIMIT 1
");
$st->execute([':id'=>$quiz_id]);
$quiz = $st->fetch(PDO::FETCH_ASSOC);
if (!$quiz) {
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">Quiz tidak ditemukan.</div>';
  $ctx = null;
  return;
}

$now = date('Y-m-d H:i:s');
if (!quiz_is_open($quiz, $now)) {
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">Quiz tidak tersedia.</div>';
  $ctx = null;
  return;
}

/* =========================
   4) SECURITY: require token cookie per quiz + UA match
   ========================= */
$tokenCookieName = 'quiz_token_' . $quiz_id;
$token_plain = (string)($_COOKIE[$tokenCookieName] ?? '');
if ($token_plain === '') {
  go(quiz_public_url('start/confirm', ['quiz' => $quiz_id]));
}

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$user_agent_hash = hash('sha256', $ua);
$token_hash = hash('sha256', $token_plain);

if (
  !hash_equals((string)($attempt['token_hash'] ?? ''), $token_hash) ||
  !hash_equals((string)($attempt['user_agent_hash'] ?? ''), $user_agent_hash)
) {
$content_html = '
  <section class="st">
    <div class="st-wrap">
      <div class="st-hero">
        <div class="st-badge">SECURITY</div>
        <h1 class="st-h1">Invalid Access</h1>
        <div class="st-desc">Please use the same browser/device that was used to start the quiz.</div>
      </div>
      <div class="st-box warn">
        <a class="st-btn primary" href="'.h(quiz_public_url('start')).'">Back to Start</a>
      </div>
    </div>
  </section>';
  $ctx = null;
  return;
}

/* =========================
   5) status guards
   ========================= */
$status = (string)($attempt['status'] ?? '');
$verified_at = (string)($attempt['email_verified_at'] ?? '');

if ($status === 'submitted') {
$resultLink = quiz_safe_result_visibility($quiz['result_visibility'] ?? 'hidden') !== 'hidden'
  && quiz_attempt_result_is_ready($pdo, $attempt_id)
  ? '<a class="st-btn primary" href="' . h(quiz_public_url('result', ['a' => $alias])) . '">View Result</a>'
  : '<a class="st-btn primary" href="/">Back</a>';
$content_html = '
  <section class="st">
    <div class="st-wrap">
      <div class="st-hero">
        <div class="st-badge">QUIZ</div>
        <h1 class="st-h1">Quiz Already Submitted</h1>
        <div class="st-desc">You cannot retake this quiz.</div>
      </div>
      <div class="st-box ok">
         ' . $resultLink . '
      </div>
    </div>
  </section>';
  $ctx = null;
  return;
}

if ($verified_at === '' || $status === 'registered') {
  $next = quiz_public_url('take', ['a' => $alias]);
  go(quiz_public_url('verify', ['quiz' => $quiz_id, 'next' => $next]));
}

/* =========================
   6) prepare the immutable question package on confirmed POST only
   ========================= */
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isBeginRequest = $requestMethod === 'POST';
$packageComplete = quiz_attempt_package_is_complete($pdo, $attempt_id);
$needsPreparation = !$packageComplete || empty($attempt['started_at']);

if ($needsPreparation && !$isBeginRequest) {
  $csrf = qz_public_csrf_token();
  $content_html = '<section class="st"><div class="st-wrap"><div class="st-hero">'
    . '<div class="st-badge">QUIZ</div><h1 class="st-h1">' . h((string)$quiz['title']) . '</h1>'
    . '<div class="st-desc">Start or resume this quiz when you are ready. The timer begins after confirmation.</div></div>'
    . '<form class="st-box" method="post" action="' . h(quiz_public_url('take', ['a' => $alias])) . '">'
    . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
    . '<button class="st-btn primary" type="submit">Start / Resume Quiz</button></form></div></section>';
  $ctx = null;
  return;
}
$quiz['proctor'] = [
  'requireFullscreen' => $quiz['require_fullscreen_override'] === null ? true : (int)$quiz['require_fullscreen_override'] === 1,
  'pauseWhenBlur' => $quiz['pause_when_blur_override'] === null ? true : (int)$quiz['pause_when_blur_override'] === 1,
  'blockContextMenu' => $quiz['block_context_menu_override'] === null ? true : (int)$quiz['block_context_menu_override'] === 1,
  'blockShortcuts' => $quiz['block_shortcuts_override'] === null ? true : (int)$quiz['block_shortcuts_override'] === 1,
  'devtoolsDetect' => $quiz['devtools_detect_override'] === null ? true : (int)$quiz['devtools_detect_override'] === 1,
  'showBadge' => $quiz['show_exam_badge_override'] === null ? true : (int)$quiz['show_exam_badge_override'] === 1,
];
$quiz['visual_theme'] = quiz_safe_visual_theme_override($quiz['visual_theme_override'] ?? null)
  ?? quiz_safe_visual_theme(settings_get($pdo, 'quiz_visual_theme', 'default'));
if ($isBeginRequest && !qz_public_csrf_check(qz_public_csrf_from_request())) {
  http_response_code(403);
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">Invalid CSRF token.</div>';
  $ctx = null;
  return;
}

if ($isBeginRequest) {
try {
  $pdo->beginTransaction();

  $lock = $pdo->prepare("
    SELECT id, status, email_verified_at, started_at
    FROM quiz_attempts
    WHERE id = :id
    FOR UPDATE
  ");
  $lock->execute([':id'=>$attempt_id]);
  $A = $lock->fetch(PDO::FETCH_ASSOC);

  if (!$A) {
    $pdo->rollBack();
    go(quiz_public_url('start'));
  }

  $st2 = (string)($A['status'] ?? '');
  $ver2 = (string)($A['email_verified_at'] ?? '');

  if ($st2 === 'submitted') {
    $pdo->commit();
    go(quiz_public_url('take', ['a' => $alias]));
  }
  if ($ver2 === '' || $st2 === 'registered') {
    $pdo->commit();
    $next = quiz_public_url('take', ['a' => $alias]);
    go(quiz_public_url('verify', ['quiz' => $quiz_id, 'next' => $next]));
  }

  $limit = quiz_questions_per_attempt_limit($quiz['questions_per_attempt'] ?? null);

  $assignments = $pdo->prepare('SELECT aq.id, aq.snapshot_at, aq.available_score_snapshot,
      aq.category_key_snapshot, aq.category_id_snapshot, aq.category_name_snapshot,
      aq.options_snapshotted_at, aq.delivery_snapshot_json, aq.delivery_snapshotted_at,
      EXISTS(SELECT 1 FROM attempt_question_options aqo WHERE aqo.attempt_question_id=aq.id) AS has_snapshotted_options
    FROM attempt_questions aq WHERE aq.attempt_id=:aid ORDER BY aq.id ASC FOR UPDATE');
  $assignments->execute([':aid' => $attempt_id]);
  $assignmentRows = $assignments->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stAns = $pdo->prepare("SELECT 1 FROM attempt_answers WHERE attempt_id = :aid LIMIT 1 FOR UPDATE");
  $stAns->execute([':aid'=>$attempt_id]);
  $hasAnyAnswer = (bool)$stAns->fetchColumn();

  $hasAnySnapshot = false;
  foreach ($assignmentRows as $assignmentRow) {
    if (!empty($assignmentRow['has_snapshotted_options'])) {
      $hasAnySnapshot = true;
      break;
    }
    foreach (['snapshot_at', 'available_score_snapshot', 'category_key_snapshot', 'category_id_snapshot',
        'category_name_snapshot', 'options_snapshotted_at', 'delivery_snapshot_json', 'delivery_snapshotted_at'] as $column) {
      if ($assignmentRow[$column] !== null) {
        $hasAnySnapshot = true;
        break 2;
      }
    }
  }

  $buildPackage = $assignmentRows === [] || (!$hasAnyAnswer && !$hasAnySnapshot);
  if ($assignmentRows === [] && $hasAnyAnswer) {
    throw new RuntimeException('Attempt answers exist without an assigned question package.');
  }
  if ($buildPackage) {
    if ($assignmentRows !== []) {
      $pdo->prepare("DELETE FROM attempt_questions WHERE attempt_id = :aid")->execute([':aid'=>$attempt_id]);
    }

    $ids = qz_pick_attempt_question_ids($pdo, $quiz_id, $limit);
    if (!$ids) {
      throw new RuntimeException('There are no active questions for this quiz.');
    }

    $ins = $pdo->prepare("INSERT INTO attempt_questions (attempt_id, question_id, seq) VALUES (:aid, :qid, :seq)");
    $seqNo = 1;
    foreach ($ids as $qid) {
      $ins->execute([
        ':aid' => $attempt_id,
        ':qid' => (int)$qid,
        ':seq' => $seqNo++,
      ]);
    }

    qz_resequence_attempt_questions($pdo, $attempt_id);
  }

  if (!$hasAnySnapshot && !quiz_attempt_assignment_is_valid($pdo, $attempt_id)) {
    throw new RuntimeException('Attempt assignment structure is invalid and requires a reset.');
  }
  if (!quiz_attempt_package_is_complete($pdo, $attempt_id)) {
    quiz_snapshot_attempt_questions($pdo, $attempt_id);
  }
  if (!quiz_attempt_package_is_complete($pdo, $attempt_id)) {
    throw new RuntimeException('Attempt package preparation did not complete.');
  }

  $pdo->prepare("UPDATE quiz_attempts SET last_activity_at=NOW() WHERE id=:id LIMIT 1")
      ->execute([':id' => $attempt_id]);
  $pdo->commit();

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("[quiz/take] ensure ctx error: " . $e->getMessage());
  $content_html = '<div class="container" style="padding:40px 16px;max-width:760px;margin:0 auto">
    <h1 style="margin:0 0 8px">Terjadi kendala</h1>
    <p style="margin:0 0 14px;opacity:.8">Paket soal tidak valid dan perlu di-reset oleh admin sebelum dapat dilanjutkan.</p>
    <a class="st-btn primary" href="'.h(quiz_public_url('start')).'">Kembali ke Start</a>
  </div>';
  $ctx = null;
  return;
}
}

/* =========================
   7) build answered map
   ========================= */
$st = $pdo->prepare("
  SELECT aq.seq, aq.marked_for_review, aa.selected_answer_id, aa.answer_text
  FROM attempt_questions aq
  LEFT JOIN attempt_answers aa
    ON aa.attempt_id = aq.attempt_id AND aa.question_id = aq.question_id
  WHERE aq.attempt_id = :aid
  ORDER BY aq.seq ASC
");
$st->execute([':aid'=>$attempt_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$answeredMap = [];
$reviewMap = [];
$answeredCount = 0;

foreach ($rows as $r) {
  $seq = (int)$r['seq'];
  $answerText = trim((string)($r['answer_text'] ?? ''));
  $ok = !empty($r['selected_answer_id']) || ($answerText !== '' && $answerText !== '[]');

  $answeredMap[$seq] = $ok ? 1 : 0;
  $reviewMap[$seq] = !empty($r['marked_for_review']) ? 1 : 0;
  if ($ok) $answeredCount++;
}

/* =========================
   8) nav map + sections
   ✅ FIX: REAL PASSAGE tanpa category tampil sebagai "judul passage" (bukan nempel ke kategori soal)
   ========================= */
$hasQCatCol = qz_column_exists($pdo, 'questions', 'category_id');

$selectQCat = $hasQCatCol
  ? ", q.category_id AS question_category_id, COALESCE(qc.name,'') AS question_category_name"
  : ", NULL AS question_category_id, '' AS question_category_name";

$joinQc = $hasQCatCol
  ? "LEFT JOIN question_categories qc ON qc.id = q.category_id AND qc.quiz_id = q.quiz_id"
  : "";

$stNav = $pdo->prepare("
  SELECT
    aq.seq,
    aq.delivery_snapshot_json,
    q.passage_id,
    p.title AS passage_title,
    p.passage_text,
    p.category_id AS passage_category_id,
    COALESCE(pc.name,'') AS passage_category_name
    {$selectQCat}
  FROM attempt_questions aq
  JOIN questions q ON q.id = aq.question_id
  LEFT JOIN question_passages p
    ON p.id = q.passage_id AND p.quiz_id = q.quiz_id
  LEFT JOIN question_categories pc
    ON pc.id = p.category_id AND pc.quiz_id = q.quiz_id
  {$joinQc}
  WHERE aq.attempt_id = :aid
  ORDER BY aq.seq ASC
");
$stNav->execute([':aid'=>$attempt_id]);
$navRows = $stNav->fetchAll(PDO::FETCH_ASSOC) ?: [];

$nav_cat_map = [];
foreach ($navRows as $r) {
  $seq = (int)($r['seq'] ?? 0);
  if ($seq <= 0) continue;

  $delivery = json_decode((string)($r['delivery_snapshot_json'] ?? ''), true);
  if (is_array($delivery) && (int)($delivery['version'] ?? 0) === 1) {
    $passage = is_array($delivery['passage'] ?? null) ? $delivery['passage'] : [];
    $category = is_array($delivery['category'] ?? null) ? $delivery['category'] : [];
    $r['passage_id'] = $passage['id'] ?? null;
    $r['passage_title'] = (string)($passage['title'] ?? '');
    $r['passage_text'] = (string)($passage['text'] ?? '');
    $r['passage_category_id'] = $category['id'] ?? null;
    $r['passage_category_name'] = (string)($category['name'] ?? '');
    $r['question_category_id'] = $category['id'] ?? null;
    $r['question_category_name'] = (string)($category['name'] ?? '');
  }

  $pid  = (int)($r['passage_id'] ?? 0);
  $ptxt = trim((string)($r['passage_text'] ?? ''));
  $isRealPassage = ($pid > 0 && $ptxt !== '' && $ptxt !== '__CATEGORY_CONTAINER__');

  if ($isRealPassage) {
    // REAL PASSAGE: kategori hanya dari passage.category; kalau kosong => pakai judul passage
    $cid = (int)($r['passage_category_id'] ?? 0);
    $cname = trim((string)($r['passage_category_name'] ?? ''));

    if ($cid > 0 && $cname !== '') {
      $nav_cat_map[$seq] = ['id'=>$cid, 'name'=>$cname];
    } else {
      $title = trim((string)($r['passage_title'] ?? ''));
      if ($title === '') $title = 'Passage';
      $nav_cat_map[$seq] = ['id'=>null, 'name'=>$title];
    }
    continue;
  }

  // non-passage / container: boleh fallback ke question.category
  $cid = (int)($r['passage_category_id'] ?? 0);
  $cname = trim((string)($r['passage_category_name'] ?? ''));

  if ($cid <= 0 && $hasQCatCol) {
    $cid = (int)($r['question_category_id'] ?? 0);
    $cname = trim((string)($r['question_category_name'] ?? ''));
  }

  if ($cid <= 0) {
    $nav_cat_map[$seq] = ['id'=>null, 'name'=>'Lainnya'];
  } else {
    if ($cname === '') $cname = 'Kategori';
    $nav_cat_map[$seq] = ['id'=>$cid, 'name'=>$cname];
  }
}

$nav_sections = [];
$cur = null;
for ($i = 1; $i <= $total; $i++) {
  $cid = $nav_cat_map[$i]['id'] ?? null;
  $cname = trim((string)($nav_cat_map[$i]['name'] ?? 'Lainnya'));
  if ($cname === '') $cname = 'Lainnya';

  if ($cur === null) {
    $cur = ['id'=>$cid, 'name'=>$cname, 'start'=>$i, 'end'=>$i];
  } else {
    $same = ((string)($cur['id'] ?? '') === (string)($cid ?? '')) && ((string)$cur['name'] === (string)$cname);
    if ($same) $cur['end'] = $i;
    else {
      $nav_sections[] = $cur;
      $cur = ['id'=>$cid, 'name'=>$cname, 'start'=>$i, 'end'=>$i];
    }
  }
}
if ($cur !== null) $nav_sections[] = $cur;

/* =========================
   9) TIMER ctx (konsisten MySQL via helper)
   ========================= */
require_once __DIR__ . '/../api/_timer_helper.php';
$durMin = (int)($quiz['duration_minutes'] ?? 0);
$warningSeconds = quiz_safe_timer_warning_seconds($quiz['timer_warning_seconds'] ?? null);
$timer = qz_timer_enforce($pdo, $attempt_id, $durMin, $isBeginRequest, $isBeginRequest, $warningSeconds);

/* =========================
   10) final ctx
   ========================= */
$ctx = [
  'attempt_alias'  => $alias,
  'attempt_id'     => $attempt_id,
  'quiz'           => $quiz,
  'quiz_id'        => $quiz_id,
  'student'        => $student,

  'total'          => $total,
  'answered_count' => $answeredCount,
  'answered_map'   => $answeredMap,
  'review_map'     => $reviewMap,
  'allow_mark_for_review' => quiz_safe_bool($quiz['allow_mark_for_review'] ?? 0, 0),

  // nav
  'nav_cat_map'    => $nav_cat_map,
  'nav_sections'   => $nav_sections,

  // timer values for initial UI (API akan sync ulang juga)
  'server_now_ts'  => (int)($timer['server_now_ts'] ?? 0),
  'duration_sec'   => (int)($timer['duration_sec'] ?? 0),
  'ends_at_ts'     => (int)($timer['ends_at_ts'] ?? 0),
  'warning_seconds' => $warningSeconds,
];
