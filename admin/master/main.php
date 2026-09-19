<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
  http_response_code(403);
  exit('Forbidden');
}

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('cut')) {
  function cut($s, $n=120){
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if (mb_strlen($s,'UTF-8') <= $n) return $s;
    return mb_substr($s,0,$n-1,'UTF-8') . '…';
  }
}

$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($quiz_id <= 0) { http_response_code(400); exit('Missing or invalid quiz_id'); }
qz_require_quiz_access($pdo, $quiz_id);

$stmt = $pdo->prepare("SELECT id, title FROM quizzes WHERE id=:id LIMIT 1");
$stmt->execute([':id'=>$quiz_id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quiz) { http_response_code(404); exit('Quiz not found'); }

$csrf = csrf_token();
$returnTo = $base . '/?page=admin/quiz/master';
$returnRaw = trim((string)($_GET['return'] ?? ''));
if ($returnRaw !== '') {
  $returnParts = parse_url($returnRaw);
  if (is_array($returnParts) && !isset($returnParts['scheme']) && !isset($returnParts['host']) && ($returnParts['path'] ?? '') === $base . '/') {
    parse_str((string)($returnParts['query'] ?? ''), $returnQuery);
    if (($returnQuery['page'] ?? '') === 'admin/quiz/master') $returnTo = $returnRaw;
  }
}

$api = [
  'list'    => $base . '/?page=admin/quiz/master/api/questions_list&action=quiz_api',
  'get'     => $base . '/?page=admin/quiz/master/api/question_get&action=quiz_api',
  'qsave'   => $base . '/?page=admin/quiz/master/api/question_save&action=quiz_api',
  'qdel'    => $base . '/?page=admin/quiz/master/api/question_delete&action=quiz_api',
  'qorder'  => $base . '/?page=admin/quiz/master/api/questions_reorder&action=quiz_api',
  'asave'   => $base . '/?page=admin/quiz/master/api/answer_save&action=quiz_api',
  'adel'    => $base . '/?page=admin/quiz/master/api/answer_delete&action=quiz_api',
  'aorder'  => $base . '/?page=admin/quiz/master/api/answers_reorder&action=quiz_api',
  'bulk'    => $base . '/?page=admin/quiz/master/api/bulk_import&action=quiz_api',
  'plist'   => $base . '/?page=admin/quiz/master/api/passages_list&action=quiz_api',
  'pget'    => $base . '/?page=admin/quiz/master/api/passage_get&action=quiz_api',
  'psave'   => $base . '/?page=admin/quiz/master/api/passage_save&action=quiz_api',
  'pdel'    => $base . '/?page=admin/quiz/master/api/passage_delete&action=quiz_api',
  'porder'  => $base . '/?page=admin/quiz/master/api/passages_reorder&action=quiz_api',
  'clist'   => $base . '/?page=admin/quiz/master/api/categories_list&action=quiz_api',
  'cget'    => $base . '/?page=admin/quiz/master/api/category_get&action=quiz_api',
  'csave'   => $base . '/?page=admin/quiz/master/api/category_save&action=quiz_api',
  'cdel'    => $base . '/?page=admin/quiz/master/api/category_delete&action=quiz_api',
  'corder'  => $base . '/?page=admin/quiz/master/api/category_order&action=quiz_api',
];

$modal = [
  'media' => $base . '/admin/modal_img/list_modal.php?embedded=1',
  'file'  => $base . '/admin/modal_file/list_modal.php?embedded=1',
];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/contrib/auto-render.min.js"></script>

<section class="adam-card">
  <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$quiz['title']]]); ?>
  <div class="qz-master-head">
    <div>
      <h2 style="margin:0">Master Builder — <?= e($quiz['title']) ?></h2>
      <div class="muted" style="margin-top:6px">
        1 layar untuk kelola soal + jawaban, auto-save, drag & drop urutan, dan bulk paste.
      </div>
    </div>
    <div class="qz-master-head__actions">
      <a class="adam-button ghost"
         href="<?= e($returnTo) ?>">← Kembali</a>
      <a class="adam-button"
         href="<?= e($base) ?>/?page=admin/quiz/questions&quiz_id=<?= (int)$quiz_id ?>">View Lama</a>
    </div>
  </div>

  <div class="master-wrap" id="masterWrap" style="margin-top:14px">

    <!-- LEFT: LIST -->
    <div class="qz-question-pane" id="questionPane">
      <div class="panelq" id="leftPanel">
        <div class="panel-head">
          <h3>Daftar Soal</h3>
          <div class="toolbar">
            <input class="input" id="qSearch" placeholder="Cari soal... (ketik, auto filter)">
            <button class="btn small" id="btnAddQ">+ Soal</button>
            <button class="btn small secondary" id="btnBulk" style="display:none">Bulk</button>
          </div>
          <div class="muted" style="margin-top:8px">
            Drag & drop untuk urutkan. Klik item untuk edit.
            <span style="float:right"><span class="badge" id="qCount">0</span></span>
          </div>
        </div>
        <div class="list" id="qList"></div>
      </div>
      <button class="qz-question-pane__toggle" type="button" id="btnToggleList" aria-controls="leftPanel" aria-expanded="true" aria-label="Ciutkan daftar soal" title="Ciutkan daftar soal"><span aria-hidden="true">‹</span></button>
    </div>

    <!-- RIGHT: EDITOR -->
    <div class="panelq qz-master-editor-panel">
      <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div>
          <h3 id="editorTitle">Pilih soal</h3>
          <div class="muted" id="editorMeta">Klik salah satu soal untuk mulai edit</div>
        </div>
        <div id="questionActions" style="display:none;gap:8px;align-items:center">
          <button class="btn small secondary" id="btnYesNo" disabled>Generate Yes/No</button>
          <button class="btn small danger" id="btnDeleteQ" disabled>Hapus Soal</button>
        </div>
      </div>

      <div class="right-body">
        <div id="emptyState" class="muted">Belum ada soal dipilih.</div>

        <div id="editor" style="display:none">
          <div class="grid2">
            <div class="row qz-master-question-column">
              <div class="label">Soal</div>
              <textarea class="input textarea" id="questionText" placeholder="Tulis soal di sini..."></textarea>
              <div class="qz-live-preview qz-live-preview--question">
                <div class="qz-live-preview__head"><span>Preview soal</span><small>Tampilan peserta</small></div>
                <div class="md-preview" id="questionPreview"></div>
              </div>
              <div class="muted">Auto-save saat kamu berhenti mengetik (debounce).</div>

              <details class="attach-wrap attach-disclosure" id="questionAttachments">
                <summary><span>Lampiran (opsional)</span><small id="questionAttachmentsState">Belum ada</small></summary>
                <div class="attach-grid">
                  <div class="attach-card">
                    <div class="label">Gambar</div>
                    <div class="attach-preview" style="margin-top:8px">
                      <img id="imgPreview" alt="Preview">
                      <div id="imgPH" class="attach-ph">Tidak ada gambar</div>
                    </div>
                    <div class="attach-actions">
                      <button class="btn small" type="button" id="btnPickImage">Pilih dari Media</button>
                      <button class="btn small secondary" type="button" id="btnClearImage">Clear</button>
                    </div>
                    <div class="attach-meta">Media ID: <span id="imgIdTxt">-</span></div>
                  </div>

                  <div class="attach-card file-box">
                    <div>
                      <div class="label">File</div>
                      <div class="file-pill" style="margin-top:8px">
                        <div class="file-title" id="fileLabel">Belum ada file</div>
                        <div class="attach-meta">File ID: <span id="fileIdTxt">-</span></div>
                      </div>
                      <div class="attach-actions">
                        <button class="btn small" type="button" id="btnPickFile">Pilih File</button>
                        <button class="btn small secondary" type="button" id="btnClearFile">Clear</button>
                      </div>
                    </div>

                    <div class="row" style="margin:0">
                      <div class="label">Link YouTube</div>
                      <input class="input" id="youtubeUrl" placeholder="https://youtu.be/... atau https://www.youtube.com/watch?v=...">
                      <div class="muted">Disimpan di kolom <code>youtube_url</code>.</div>
                    </div>
                  </div>
                </div>
              </details>

              <div class="qz-master-answer-section">
                <div class="hr"></div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                  <div>
                    <div style="font-weight:800;color:var(--adam-text,#0f172a)">Jawaban</div>
                    <div class="muted">Drag & drop untuk urutkan jawaban.</div>
                  </div>
                  <div style="display:flex;gap:8px">
                    <button class="btn small" id="btnAddA">+ Jawaban</button>
                  </div>
                </div>
                <div id="answersBox" style="margin-top:6px"></div>
                <div class="muted" style="margin-top:10px">
                  Tips: Untuk pilihan benar/salah, pakai skor 100 & 0. Untuk yang bertingkat, bisa 0..100.
                </div>
              </div>
            </div>

            <div>
              <div class="row">
                <div class="label">Posisi di daftar</div>
                <input class="input" id="questionPos" type="number" min="1" placeholder="mis. 6">
                <div class="muted">
                  Ketik angka untuk memindahkan soal. Contoh: <b>6</b> = taruh di posisi #6 (soal lain otomatis bergeser).
                </div>
              </div>

              <div class="row">
                <div class="label">Tipe</div>
                <select class="select" id="questionType">
                  <option value="single_choice">single_choice</option>
                  <option value="multiple_choice">multiple_choice</option>
                  <option value="yesno">yesno</option>
                  <option value="text">text</option>
                  <option value="number">number</option>
                </select>
              </div>

              <div class="row">
                <div class="label">Status</div>
                <select class="select" id="questionActive">
                  <option value="1">Aktif</option>
                  <option value="0">Nonaktif</option>
                </select>
              </div>

              <div class="row">
                <div class="label">Shortcut</div>
                <button class="btn small secondary" id="btnDuplicate" disabled>Duplikat Soal</button>
              </div>

              <div class="row">
                <div class="label" style="display:flex;justify-content:space-between;align-items:center">
                  <span>Passage / Cerita</span>
                  <button class="btn small secondary" type="button" id="btnPassageManage">Kelola</button>
                </div>
                <select class="select" id="passageSelect">
                  <option value="">(Tanpa passage)</option>
                </select>
              </div>

              <div class="row">
                <div class="label">Urutan di dalam passage (opsional)</div>
                <input class="input" id="passageSeq" type="number" min="1" placeholder="mis. 1..10">
                <div class="muted">Isi kalau mau urutan khusus (kalau kosong = bebas).</div>
              </div>

              <div class="row">
                <div class="label" style="display:flex;justify-content:space-between;align-items:center">
                  <span>Kategori (untuk soal tanpa passage)</span>
                  <button class="btn small secondary" type="button" id="btnQCategoryManage">Kelola</button>
                </div>
                <select class="select" id="qCategorySelect">
                  <option value="">(Tanpa kategori)</option>
                </select>
                <div class="muted">
                  Disimpan ke kolom <code>questions.category_id</code>. Jika soal memakai passage, kategori mengikuti passage.
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

  <!-- BULK MODAL -->
<div class="modal" id="bulkModal" aria-hidden="true">
  <div class="modal-dialog bulk-dialog">
    <div class="modal-head">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div>
          <div style="font-weight:900;font-size:14px">Bulk Paste / Import</div>
          <div class="muted">
            1 blok = 1 soal (+ jawaban). Pisahkan blok dengan <b>1 baris kosong</b>.
            <span class="kbd">Esc</span> untuk tutup, <span class="kbd">Ctrl</span>+<span class="kbd">Enter</span> untuk import.
          </div>
        </div>
        <button class="btn small secondary" id="bulkClose" type="button">Tutup</button>
      </div>
      <div class="hr"></div>
    </div>

    <div class="modal-body">
      <div class="bulk-topbar">
        <div class="row">
          <div class="label">Mode</div>
          <select class="select" id="bulkStrict">
            <option value="1" selected>Strict (rekomendasi)</option>
            <option value="0">Lenient (abaikan mismatch)</option>
          </select>
          <div class="muted">Strict akan menolak category/passage yang bukan milik quiz ini.</div>
        </div>

        <div class="row">
          <div class="label">Default Kategori</div>
          <select class="select" id="bulkDefaultCategory">
            <option value="">(Tidak ada)</option>
          </select>
          <div class="muted">Dipakai untuk soal tanpa passage (kalau blok tidak set @cat).</div>
        </div>

        <div class="row">
          <div class="label">Default Passage</div>
          <select class="select" id="bulkDefaultPassage">
            <option value="">(Tidak ada)</option>
          </select>
          <div class="muted">Kalau dipilih, semua blok tanpa @passage akan masuk passage ini.</div>
        </div>
      </div>

      <div class="bulk-grid">
        <div>
          <div class="bulk-help">
            <div class="label">Contoh</div>
            <pre>Apa ibu kota Indonesia?
- Jakarta | 100
- Bandung | 0

@img: 12
@file: 5
@yt: https://youtu.be/xxxx
@cat: matematika
@passage: Cerita A
@seq: 1</pre>
          </div>

          <div class="bulk-tags">
            <span class="bulk-tag"><code>@img:</code> media_id</span>
            <span class="bulk-tag"><code>@file:</code> file_id</span>
            <span class="bulk-tag"><code>@yt:</code> youtube url</span>
            <span class="bulk-tag"><code>@cat:</code> id / nama</span>
            <span class="bulk-tag"><code>@passage:</code> id / judul</span>
            <span class="bulk-tag"><code>@seq:</code> urutan</span>
            <span class="bulk-tag"><code>@type:</code> single_choice/multiple_choice/yesno/text/number</span>
          </div>
        </div>

        <textarea class="input" id="bulkText" placeholder="Paste di sini..."></textarea>
        <div class="muted" style="margin-top:6px">
          Tips: Jawaban tulis pakai <b>-</b> lalu skor pakai <b>|</b>. Skor kosong = 0.
        </div>
      </div>

      <div class="bulk-right">
        <div class="bulk-stat" id="bulkStat">
          <span>Soal: <b>0</b></span>
          <span>Jawaban: <b>0</b></span>
          <span>Lampiran: <b>0</b></span>
          <span class="bulk-warn" id="bulkWarn"></span>
        </div>
        <div class="bulk-pane">
          <h4>Preview</h4>
          <div id="bulkPreview" class="muted">Belum ada data.</div>

          <div class="hr"></div>

          <h4>Catatan / Error</h4>
          <div id="bulkErrors" class="muted">—</div>
        </div>
      </div>
    </div>

    <div class="modal-foot">
      <div class="bulk-foot">
        <div class="left">
          <button class="btn small secondary" id="bulkClearBtn" type="button">Clear</button>
          <span class="muted">Preview update otomatis saat mengetik.</span>
        </div>
        <div class="right">
          <button class="btn small secondary" id="bulkPreviewBtn" type="button">Preview</button>
          <button class="btn small" id="bulkImportBtn" type="button">Import</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- PASSAGE MODAL -->
<div class="modal" id="passageModal" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-head">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div>
          <div style="font-weight:900;font-size:14px">Kelola Passage / Cerita</div>
          <div class="muted">Buat cerita, lalu tautkan ke soal via dropdown.</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn small" id="btnPassageAdd">+ Passage</button>
          <button class="btn small secondary" id="passageClose">Tutup</button>
        </div>
      </div>
      <div class="hr"></div>
    </div>

    <div class="modal-body">
      <div class="modal-grid" style="display:grid;grid-template-columns:300px 1fr;gap:12px">
        <div style="border:1px solid var(--adam-border-2,#eef2f7);border-radius:12px;overflow:auto;max-height:60vh" id="passageList"></div>

        <div class="modal-right">

          <div class="row">
            <div class="label" style="display:flex;justify-content:space-between;align-items:center">
              <span>Kategori (opsional)</span>
              <button class="btn small secondary" type="button" id="btnCategoryManage">Kelola</button>
            </div>
            <select class="select" id="categorySelect">
              <option value="">(Tanpa kategori)</option>
            </select>
            <div class="muted">Disimpan ke kolom <code>question_passages.category_id</code>.</div>
          </div>

          <div class="row">
            <div class="label">Judul (opsional)</div>
            <input class="input" id="passageTitle" placeholder="Cerita A">
          </div>

          <div class="row">
            <div class="label">Teks Passage</div>
            <textarea class="input textarea" id="passageText" placeholder="Tulis cerita/stimulus di sini..."></textarea>
            <div class="md-preview" id="passagePreview"></div>
          </div>

          <div class="attach-wrap">
            <div class="label" style="margin-bottom:8px">Lampiran Passage (opsional)</div>
            <div class="attach-grid">
              <div class="attach-card">
                <div class="label">Gambar</div>
                <div class="attach-preview" style="margin-top:8px">
                  <img id="pImgPreview" alt="Preview">
                  <div id="pImgPH" class="attach-ph">Tidak ada gambar</div>
                </div>
                <div class="attach-actions">
                  <button class="btn small" type="button" id="btnPassagePickImage">Pilih dari Media</button>
                  <button class="btn small secondary" type="button" id="btnPassageClearImage">Clear</button>
                </div>
                <div class="attach-meta">Media ID: <span id="pImgIdTxt">-</span></div>
              </div>

              <div class="attach-card file-box">
                <div>
                  <div class="label">File</div>
                  <div class="file-pill" style="margin-top:8px">
                    <div class="file-title" id="pFileLabel">Belum ada file</div>
                    <div class="attach-meta">File ID: <span id="pFileIdTxt">-</span></div>
                  </div>
                  <div class="attach-actions">
                    <button class="btn small" type="button" id="btnPassagePickFile">Pilih File</button>
                    <button class="btn small secondary" type="button" id="btnPassageClearFile">Clear</button>
                  </div>
                </div>

                <div class="row" style="margin:0">
                  <div class="label">Link YouTube</div>
                  <input class="input" id="passageYoutubeUrl" placeholder="https://youtu.be/... atau https://www.youtube.com/watch?v=...">
                  <div class="muted">Disimpan di kolom <code>youtube_url</code> pada passage.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="grid2" style="grid-template-columns:1fr 1fr">
            <div class="row">
              <div class="label">Shuffle soal di dalam passage</div>
              <select class="select" id="passageShuffle">
                <option value="1">Ya</option>
                <option value="0">Tidak</option>
              </select>
            </div>

            <div class="row">
              <div class="label">Status</div>
              <select class="select" id="passageActive">
                <option value="1">Aktif</option>
                <option value="0">Nonaktif</option>
              </select>
            </div>
          </div>

          <div class="row" style="margin-top:2px">
            <div class="label">Min soal dari passage ini (opsional)</div>
            <input class="input" id="passageMinQuestions" type="number" min="0" placeholder="mis. 3 (0/kosong = tidak wajib)">
            <div class="muted">Disimpan ke kolom <code>min_questions</code>. 0 = tidak ada minimum.</div>
          </div>

          <div class="row" style="margin-top:2px">
            <div class="label">Max soal dari passage ini (opsional)</div>
            <input class="input" id="passageMaxQuestions" type="number" min="1" placeholder="mis. 10 (kosong = semua)">
            <div class="muted">Disimpan ke kolom <code>max_questions</code>. Kosong = tidak dibatasi.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal-foot">
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn small danger" id="btnPassageDelete" disabled>Hapus</button>
        <button type="button" class="btn small" id="btnPassageSave" disabled>Simpan</button>
      </div>
    </div>
  </div>
</div>

<!-- CATEGORY MODAL -->
<div class="modal" id="categoryModal" aria-hidden="true">
  <div class="modal-dialog" style="width:min(860px,100%)">
    <div class="modal-head">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div>
          <div style="font-weight:900;font-size:14px">Kelola Kategori</div>
          <div class="muted">Kategori dipakai untuk mengelompokkan passage (dan nanti aturan random per kategori).</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn small" id="btnCategoryAdd">+ Kategori</button>
          <button class="btn small secondary" id="categoryClose">Tutup</button>
        </div>
      </div>
      <div class="hr"></div>
    </div>

    <div class="modal-body">
      <div class="modal-grid" style="display:grid;grid-template-columns:300px 1fr;gap:12px">
        <div style="border:1px solid var(--adam-border-2,#eef2f7);border-radius:12px;overflow:auto;max-height:60vh" id="categoryList"></div>

        <div class="modal-right">
          <div class="row">
            <div class="label">Nama Kategori</div>
            <input class="input" id="categoryName" placeholder="mis. Topik A (wajib)">
          </div>

          <div class="row">
            <div class="label">Deskripsi (opsional)</div>
            <textarea class="input textarea" id="categoryDesc" placeholder="Catatan singkat..."></textarea>
          </div>

          <div class="grid2" style="grid-template-columns:1fr 1fr">
            <div class="row">
              <div class="label">Shuffle passage dalam kategori</div>
              <select class="select" id="categoryShufflePassages">
                <option value="1">Ya</option>
                <option value="0">Tidak</option>
              </select>
            </div>

            <div class="row">
              <div class="label">Shuffle soal non-passage</div>
              <select class="select" id="categoryShuffleQuestions">
                <option value="1">Ya</option>
                <option value="0">Tidak</option>
              </select>
            </div>
          </div>

          <div class="row" style="margin-top:2px">
            <div class="label">Min soal dari kategori ini (opsional)</div>
            <input class="input" id="categoryMinQuestions" type="number" min="0" placeholder="mis. 5 (0/kosong = tidak wajib)">
            <div class="muted">Disimpan ke kolom <code>min_questions</code>. 0 = tidak ada minimum.</div>
          </div>

          <div class="row" style="margin-top:2px">
            <div class="label">Max soal dari kategori ini (opsional)</div>
            <input class="input" id="categoryMaxQuestions" type="number" min="1" placeholder="mis. 25 (kosong = semua)">
            <div class="muted">Disimpan ke kolom <code>max_questions</code>.</div>
          </div>

          <div class="row">
            <div class="label">Status</div>
            <select class="select" id="categoryActive">
              <option value="1">Aktif</option>
              <option value="0">Nonaktif</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="modal-foot">
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn small danger" id="btnCategoryDelete" disabled>Hapus</button>
        <button type="button" class="btn small" id="btnCategorySave" disabled>Simpan</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

  </div>
</section>

<script>
(function(){
  if (window.__QUIZ_MASTER_BUILDER_INIT__) return;
  window.__QUIZ_MASTER_BUILDER_INIT__ = true;

  const QUIZ_ID = <?= (int)$quiz_id ?>;
  const CSRF    = <?= json_encode($csrf) ?>;
  const API     = <?= json_encode($api) ?>;
  const MODAL   = <?= json_encode($modal) ?>;

  const allowedTypes = ['single_choice','multiple_choice','yesno','text','number'];

  const el = (id)=>document.getElementById(id);

  const toast = el('toast');
  let toastTimer = null;
  function showToast(msg){
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(()=>toast.classList.remove('show'), 1600);
  }

  async function fetchJSON(url, payload=null){
    const opt = payload ? {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type':'application/json',
        'Accept':'application/json',
        'X-Requested-With':'XMLHttpRequest'
      },
      body: JSON.stringify(payload)
    } : {
      method:'GET',
      credentials: 'same-origin',
      headers: {
        'Accept':'application/json',
        'X-Requested-With':'XMLHttpRequest'
      }
    };

    const res = await fetch(url, opt);
    const finalUrl = res.url || url;
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    const txt = await res.text();

    let data = null;
    try {
      data = JSON.parse(txt);
    } catch (e) {
      const snip = String(txt).replace(/\s+/g, ' ').trim().slice(0, 260);
      const hint = ct.includes('text/html')
        ? ' (CT=text/html; kemungkinan redirect ke login / error page / PHP warning)'
        : (ct ? ` (CT=${ct})` : '');

      console.error('[fetchJSON] NON-JSON', {
        url: finalUrl,
        status: res.status,
        contentType: ct,
        preview: snip
      });

      throw new Error(`[fetchJSON] Non-JSON dari ${finalUrl} (HTTP ${res.status})${hint}: ${snip}`);
    }

    if (!res.ok || !data || data.ok !== true) {
      const err = (data && data.error) ? data.error : ('HTTP ' + res.status);

      console.error('[fetchJSON] API-ERROR', {
        url: finalUrl,
        status: res.status,
        contentType: ct,
        data
      });

      const error = new Error(`[fetchJSON] ${finalUrl}: ${err}`);
      error.status = res.status;
      error.code = data && data.code ? String(data.code) : '';
      throw error;
    }

    return data;
  }

  function parseNullableInt(v){
    const s = String(v ?? '').trim();
    if (s === '') return null;
    const n = Number(s);
    if (!Number.isFinite(n)) return null;
    return Math.trunc(n);
  }
  function parseNullablePositiveInt(v){
    return parseNullableInt(v);
  }
  function parseNonNegInt(v, def=0){
    const s = String(v ?? '').trim();
    if (s === '') return def;
    const n = Number(s);
    if (!Number.isFinite(n)) return def;
    return Math.max(0, Math.trunc(n));
  }

  function renderMDWithMath(md, targetEl){
    if (!targetEl) return;

    const raw = String(md || '');

    if (!window.marked || !window.DOMPurify){
      targetEl.textContent = raw;
      return;
    }

    let html = '';
    try{
      html = marked.parse(raw, { mangle:false, headerIds:false });
    }catch(e){
      html = '<pre>Markdown error</pre>';
    }

    const clean = DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
    targetEl.innerHTML = clean;

    if (typeof window.renderMathInElement === 'function') {
      try{
        renderMathInElement(targetEl, {
          delimiters: [
            {left: "$$", right: "$$", display: true},
            {left: "$", right: "$", display: false},
            {left: "\\(", right: "\\)", display: false},
            {left: "\\[", right: "\\]", display: true},
          ],
          throwOnError: false,
          strict: "ignore",
          ignoredTags: ["script","noscript","style","textarea","pre","code"],
        });
      }catch(e){}
    }
  }

  if (!window.injectHtmlWithScriptsTo) {
    window.injectHtmlWithScriptsTo = function(container, html) {
      try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        let bodyHtml = '';
        Array.from(doc.body.childNodes).forEach(n => {
          if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
          bodyHtml += n.outerHTML || n.textContent;
        });
        container.innerHTML = bodyHtml;

        const scripts = doc.querySelectorAll('script');
        const externals = [];
        scripts.forEach(s => { if (s.src) externals.push(s.src); });
        externals.forEach(src => {
          if (!document.querySelector('script[src="' + src + '"]')) {
            const elx = document.createElement('script');
            elx.src = src;
            elx.async = false;
            document.head.appendChild(elx);
          }
        });
        scripts.forEach(s => {
          if (!s.src) {
            const elx = document.createElement('script');
            elx.text = s.textContent;
            document.body.appendChild(elx);
          }
        });
      } catch (err) {
        console.error('injectHtmlWithScriptsTo error', err);
        if (container) container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
      }
    };
  }

  if (!window.adamModalOpen) {
    window.adamModalOpen = function(url, opts){
      opts = opts || {};
      if (document.getElementById('adam-modal-backdrop')) return;
      const bg = getComputedStyle(document.documentElement).getPropertyValue('--adam-card').trim() || '#fff';
      const txt = getComputedStyle(document.documentElement).getPropertyValue('--adam-text').trim() || '#0f172a';

      const bd = document.createElement('div');
      bd.id = 'adam-modal-backdrop';
      bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px';

      const box = document.createElement('div');
      box.id = 'adam-modal-box';
      const maxWidth = (opts && opts.maxWidth) ? opts.maxWidth : '980px';
      box.style.cssText = 'background:'+bg+';color:'+txt+';border-radius:10px;max-width:' + maxWidth + ';width:100%;max-height:90vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);position:relative';

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerText = '×';
      closeBtn.style.cssText = 'position:absolute;right:10px;top:8px;border:0;background:transparent;font-size:20px;cursor:pointer';
      closeBtn.addEventListener('click', function(){ window.adamModalClose(); });
      box.appendChild(closeBtn);

      const content = document.createElement('div');
      content.id = 'adam-modal-content';
      content.style.padding = '14px';
      box.appendChild(content);

      bd.appendChild(box);
      document.body.appendChild(bd);

      fetch(url, { credentials: 'include' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => injectHtmlWithScriptsTo(content, html))
        .catch(err => {
          content.innerHTML = '<div style="color:#c00">Gagal memuat modal.</div>';
          console.error(err);
        });

      bd.addEventListener('click', function(ev){ if (ev.target === bd) window.adamModalClose(); });
      function onKey(e){ if (e.key === 'Escape') window.adamModalClose(); }
      document.addEventListener('keydown', onKey);
      bd.__esc = onKey;
    };

    window.adamModalClose = function(){
      const bd = document.getElementById('adam-modal-backdrop');
      if (!bd) return;
      if (bd.__esc) document.removeEventListener('keydown', bd.__esc);
      bd.remove();
    };
  }

  function openSelector({ url, eventName, messageType, maxWidth='980px' }){
    return new Promise((resolve)=>{
      let done = false;

      function cleanup(){
        document.removeEventListener(eventName, onEvent);
        window.removeEventListener('message', onMsg);
      }
      function finish(payload){
        if (done) return;
        done = true;
        cleanup();
        try { window.adamModalClose(); } catch(e){}
        resolve(payload || null);
      }
      function onEvent(e){ finish(e && e.detail ? e.detail : null); }
      function onMsg(ev){
        try {
          if (ev && ev.data && (ev.data.type === messageType || ev.data.type === eventName)) {
            finish(ev.data.detail || null);
          }
        } catch(e){}
      }

      document.addEventListener(eventName, onEvent);
      window.addEventListener('message', onMsg);

      window.adamModalOpen(url, { maxWidth });

      const iv = setInterval(()=>{
        const bd = document.getElementById('adam-modal-backdrop');
        if (!bd) {
          clearInterval(iv);
          if (!done) finish(null);
        }
      }, 250);
    });
  }

  function normalizeMedia(detail){
    if (!detail) return null;
    const m = (detail.media && typeof detail.media === 'object') ? detail.media : detail;
    return {
      id: (m.id != null) ? (parseInt(m.id,10) || null) : null,
      url: (m.url != null) ? String(m.url || '') : ''
    };
  }

  function normalizeFile(detail){
    if (!detail) return null;
    const f = (detail.file && typeof detail.file === 'object') ? detail.file : detail;
    const id = (f.id != null) ? (parseInt(f.id,10) || null) : null;
    const label = (f.filename || f.title || f.name || f.original_name || (id ? ('File #' + id) : '') || '' );
    return { id, label: String(label) };
  }

  function escapeHTML(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  }
  function cutText(s, n){
    s = String(s).replace(/\s+/g,' ').trim();
    return s.length <= n ? s : (s.slice(0,n-1) + '…');
  }
  function sortQuestionsByOrder(arr){
    return (arr || []).slice().sort((a,b)=>{
      const ao = (a?.sort_order != null && Number(a.sort_order) > 0) ? Number(a.sort_order) : Number.MAX_SAFE_INTEGER;
      const bo = (b?.sort_order != null && Number(b.sort_order) > 0) ? Number(b.sort_order) : Number.MAX_SAFE_INTEGER;
      if (ao !== bo) return ao - bo;
      return (Number(a?.id||0) || 0) - (Number(b?.id||0) || 0);
    });
  }

  function getGlobalIds(){
    return (questions || []).map(q => Number(q.id));
  }

  function canReorderQuestions(){
    return !searchTerm && questions.length > 1 && questions.every(q=>q.can_mutate === true);
  }

  async function persistOrder(url, payload, reload, successMessage){
    if (orderSaving) return false;
    orderSaving = true;
    try {
      await fetchJSON(url, payload);
      orderSaving = false;
      showToast(successMessage);
      await reload();
      return true;
    } catch (error) {
      if (error && (error.status === 409 || error.code === 'order_conflict')) {
        orderSaving = false;
        showToast('Urutan berubah. Data dimuat ulang.');
        await reload();
        return false;
      }
      throw error;
    } finally {
      orderSaving = false;
    }
  }

  function getGlobalPosById(qid){
    const ids = getGlobalIds();
    const idx = ids.indexOf(Number(qid));
    return (idx >= 0) ? (idx + 1) : 1;
  }

  function syncEditorPositionUI(){
    if (!activeQ || !questionPos) return;
    const pos = getGlobalPosById(activeQ.id);
    const total = (questions || []).length || 1;
    questionPos.value = String(pos);
    if (editorMeta) {
      editorMeta.textContent = `Auto-save aktif. Urutan jawaban bisa drag & drop. · Posisi #${pos} dari ${total}`;
    }
  }

  async function moveQuestionToPosition(qid, pos){
    if (!qid || !activeQ || activeQ.can_mutate !== true || !canReorderQuestions()) return;

    if (searchTerm){
      alert('Matikan filter/cari dulu sebelum pindah posisi agar urutan global aman.');
      syncEditorPositionUI();
      return;
    }

    const ids = getGlobalIds();
    const from = ids.indexOf(Number(qid));
    if (from < 0) return;

    const total = ids.length || 1;
    let toPos = Math.trunc(Number(pos));
    if (!Number.isFinite(toPos)) return;

    toPos = Math.max(1, Math.min(total, toPos));
    const to = toPos - 1;

    if (from === to) {
      syncEditorPositionUI();
      return;
    }

    ids.splice(to, 0, ids.splice(from, 1)[0]);

    clearTimeout(saveTimer);
    try {
      await saveQuestionNow();
    } catch (e) {
      showToast('Gagal simpan sebelum pindah posisi');
      syncEditorPositionUI();
      return;
    }

    if (questionPos) questionPos.disabled = true;
    try{
      await persistOrder(API.qorder, {
        csrf_token: CSRF,
        quiz_id: QUIZ_ID,
        ordered_ids: ids,
        order_revision: questionOrderRevision
      }, loadQuestions, 'Posisi soal dipindahkan');
      syncEditorPositionUI();
      renderList();
    } finally {
      if (questionPos) questionPos.disabled = !activeQ || activeQ.can_mutate !== true || !canReorderQuestions();
    }
  }

  // ===== STATE
  let questions = [];
  let questionOrderRevision = '';
  let activeQ = null;
  let searchTerm = '';
  let saveTimer = null;

  let passages = [];
  let passageOrderRevision = '';
  let activeP = null;
  let passageSaving = false;

  let attImageId = null;
  let attImageUrl = '';
  let attFileId = null;
  let attFileLabel = '';

  let pAttImageId = null;
  let pAttImageUrl = '';
  let pAttFileId = null;
  let pAttFileLabel = '';

  let categories = [];
  let categoryOrderRevision = '';
  let activeC = null;
  let categorySaving = false;
  let orderSaving = false;

  // ===== ELEMENTS
  const masterWrap = el('masterWrap');
  const questionPane = el('questionPane');
  const leftPanel = el('leftPanel');
  const btnToggleList = el('btnToggleList');
  const qList = el('qList');
  const qCount = el('qCount');
  const qSearch = el('qSearch');
  const btnAddQ = el('btnAddQ');
  const btnDeleteQ = el('btnDeleteQ');
  const btnYesNo = el('btnYesNo');
  const questionActions = el('questionActions');
  const btnAddA = el('btnAddA');
  const btnDuplicate = el('btnDuplicate');

  const editorTitle = el('editorTitle');
  const editorMeta = el('editorMeta');
  const emptyState = el('emptyState');
  const editor = el('editor');

  const questionText = el('questionText');
  const questionPreview = el('questionPreview');
  const questionType = el('questionType');
  const questionActive = el('questionActive');
  const questionPos = el('questionPos');
  const answersBox = el('answersBox');

  const imgPreview = el('imgPreview');
  const imgPH = el('imgPH');
  const imgIdTxt = el('imgIdTxt');
  const btnPickImage = el('btnPickImage');
  const btnClearImage = el('btnClearImage');

  const fileLabelEl = el('fileLabel');
  const fileIdTxt = el('fileIdTxt');
  const btnPickFile = el('btnPickFile');
  const btnClearFile = el('btnClearFile');

  const youtubeUrl = el('youtubeUrl');
  const questionAttachments = el('questionAttachments');
  const questionAttachmentsState = el('questionAttachmentsState');

  const btnPassageManage = el('btnPassageManage');
  const passageSelect = el('passageSelect');
  const passageSeq = el('passageSeq');

  const passageModal = el('passageModal');
  const passageClose = el('passageClose');
  const passageList = el('passageList');
  const btnPassageAdd = el('btnPassageAdd');

  const passageTitle = el('passageTitle');
  const passageText = el('passageText');
  const passagePreview = el('passagePreview');

  const passageShuffle = el('passageShuffle');
  const passageActive = el('passageActive');
  const passageMinQuestions = el('passageMinQuestions');
  const passageMaxQuestions = el('passageMaxQuestions');

  const btnPassageSave = el('btnPassageSave');
  const btnPassageDelete = el('btnPassageDelete');

  const pImgPreview = el('pImgPreview');
  const pImgPH = el('pImgPH');
  const pImgIdTxt = el('pImgIdTxt');
  const btnPassagePickImage = el('btnPassagePickImage');
  const btnPassageClearImage = el('btnPassageClearImage');

  const pFileLabelEl = el('pFileLabel');
  const pFileIdTxt = el('pFileIdTxt');
  const btnPassagePickFile = el('btnPassagePickFile');
  const btnPassageClearFile = el('btnPassageClearFile');

  const passageYoutubeUrl = el('passageYoutubeUrl');

  const btnCategoryManage = el('btnCategoryManage');
  const categorySelect = el('categorySelect');

  const categoryModal = el('categoryModal');
  const categoryClose = el('categoryClose');
  const btnCategoryAdd = el('btnCategoryAdd');
  const categoryList = el('categoryList');

  const categoryName = el('categoryName');
  const categoryDesc = el('categoryDesc');
  const categoryShufflePassages = el('categoryShufflePassages');
  const categoryShuffleQuestions = el('categoryShuffleQuestions');
  const categoryMaxQuestions = el('categoryMaxQuestions');
  const categoryMinQuestions = el('categoryMinQuestions');
  const categoryActive = el('categoryActive');

  const btnCategorySave = el('btnCategorySave');
  const btnCategoryDelete = el('btnCategoryDelete');

  const masterListStorageKey = 'quiz-master-list-collapsed-v2';
  function setQuestionListCollapsed(collapsed, persist=false){
    if (!masterWrap || !questionPane || !leftPanel || !btnToggleList) return;
    masterWrap.classList.toggle('is-list-collapsed', collapsed);
    questionPane.classList.toggle('is-collapsed', collapsed);
    leftPanel.hidden = collapsed;
    btnToggleList.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    const label = collapsed ? 'Tampilkan daftar soal' : 'Ciutkan daftar soal';
    btnToggleList.setAttribute('aria-label', label);
    btnToggleList.title = label;
    btnToggleList.firstElementChild.textContent = collapsed ? '›' : '‹';
    if (persist) {
      try { localStorage.setItem(masterListStorageKey, collapsed ? '1' : '0'); } catch (error) {}
    }
  }
  let initialListCollapsed = false;
  try {
    const storedListState = localStorage.getItem(masterListStorageKey);
    if (storedListState === '1' || storedListState === '0') initialListCollapsed = storedListState === '1';
  } catch (error) {}
  setQuestionListCollapsed(initialListCollapsed);
  if (btnToggleList) btnToggleList.addEventListener('click', ()=>{
    setQuestionListCollapsed(!masterWrap.classList.contains('is-list-collapsed'), true);
  });

  // ===== CATEGORIES helpers
  function categoryTitleText(c){
    const t = (c && (c.name || c.title)) ? String(c.name || c.title).trim() : '';
    if (t) return t;
    return c && c.id ? ('Kategori #' + c.id) : 'Kategori';
  }

  function getCategoryNameById(id){
    const cid = Number(id||0);
    if (!cid) return '';
    const found = categories.find(x=>Number(x.id)===cid);
    if (found) return categoryTitleText(found);
    return 'Kategori #' + cid;
  }

  // ============================================================
  // Question Category UI
  // ============================================================
  let qCategoryRow = null;
  let qCategorySelect = null;
  let btnQCategoryManage = null;

  function bindQuestionCategoryUI(){
    qCategorySelect    = el('qCategorySelect');
    btnQCategoryManage = el('btnQCategoryManage');
    if (qCategorySelect && qCategorySelect.closest) {
      qCategoryRow = qCategorySelect.closest('.row');
    } else {
      qCategoryRow = el('qCategoryRow');
    }
  }

  function getQuestionCategoryId(q){
    const v = (q && (q.category_id != null ? q.category_id : q.question_category_id));
    const n = Number(v || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function setQuestionCategoryRowVisible(show){
    bindQuestionCategoryUI();
    if (!qCategoryRow) return;
    qCategoryRow.style.display = show ? '' : 'none';
  }

  function renderQuestionCategoryOptions(){
    bindQuestionCategoryUI();
    if (!qCategorySelect) return;

    const desired =
      (activeQ && getQuestionCategoryId(activeQ) > 0) ? String(getQuestionCategoryId(activeQ)) :
      (qCategorySelect.value || '');

    qCategorySelect.innerHTML = '<option value="">(Tanpa kategori)</option>';

    categories.forEach(c=>{
      const opt = document.createElement('option');
      opt.value = String(c.id);
      opt.textContent = categoryTitleText(c);
      qCategorySelect.appendChild(opt);
    });

    qCategorySelect.value = desired || '';
  }

  function syncQuestionCategoryUIFromPassage(silent=false){
    bindQuestionCategoryUI();

    const hasPassage = !!(passageSelect && String(passageSelect.value || '').trim());
    setQuestionCategoryRowVisible(!hasPassage);

    if (hasPassage) {
      const had = qCategorySelect ? String(qCategorySelect.value || '').trim() : '';
      if (qCategorySelect) qCategorySelect.value = '';
      if (activeQ) {
        activeQ.category_id = null;
        activeQ.question_category_id = null;
      }
      if (!silent && had) scheduleSaveQuestion();
    }
  }

  bindQuestionCategoryUI();

  // ===== Attachments UI setters (question)
  function syncQuestionAttachments(openWhenPopulated=false){
    const count = (attImageId ? 1 : 0) + (attFileId ? 1 : 0) + ((youtubeUrl && youtubeUrl.value.trim()) ? 1 : 0);
    if (questionAttachmentsState) questionAttachmentsState.textContent = count > 0 ? count + ' terisi' : 'Belum ada';
    if (openWhenPopulated && count > 0 && questionAttachments) questionAttachments.open = true;
  }

  function setImage(mid, url, silent=false){
    attImageId = (mid && Number(mid) > 0) ? Number(mid) : null;
    attImageUrl = url ? String(url) : '';

    if (imgIdTxt) imgIdTxt.textContent = attImageId ? String(attImageId) : '-';

    if (imgPreview && imgPH){
      if (attImageUrl) {
        imgPreview.src = attImageUrl;
        imgPreview.style.display = 'block';
        imgPH.style.display = 'none';
      } else {
        imgPreview.removeAttribute('src');
        imgPreview.style.display = 'none';
        imgPH.style.display = 'block';
      }
    }

    if (activeQ) {
      activeQ.image_media_id = attImageId;
      activeQ.image_url = attImageUrl;
    }

    syncQuestionAttachments();
    if (!silent) scheduleSaveQuestion();
  }

  function setFile(fid, label, silent=false){
    attFileId = (fid && Number(fid) > 0) ? Number(fid) : null;
    attFileLabel = label ? String(label) : '';

    if (fileIdTxt) fileIdTxt.textContent = attFileId ? String(attFileId) : '-';
    if (fileLabelEl) fileLabelEl.textContent = attFileId ? (attFileLabel || ('File #' + attFileId)) : 'Belum ada file';

    if (activeQ) {
      activeQ.file_id = attFileId;
      activeQ.file_label = attFileLabel;
    }

    syncQuestionAttachments();
    if (!silent) scheduleSaveQuestion();
  }

  // ===== Attachments UI setters (passage)
  function setPassageImage(mid, url){
    pAttImageId = (mid && Number(mid) > 0) ? Number(mid) : null;
    pAttImageUrl = url ? String(url) : '';

    if (pImgIdTxt) pImgIdTxt.textContent = pAttImageId ? String(pAttImageId) : '-';

    if (pImgPreview && pImgPH){
      if (pAttImageUrl) {
        pImgPreview.src = pAttImageUrl;
        pImgPreview.style.display = 'block';
        pImgPH.style.display = 'none';
      } else {
        pImgPreview.removeAttribute('src');
        pImgPreview.style.display = 'none';
        pImgPH.style.display = 'block';
      }
    }

    if (activeP) {
      activeP.image_media_id = pAttImageId;
      activeP.image_url = pAttImageUrl;
    }
    updatePassageButtons();
  }

  function setPassageFile(fid, label){
    pAttFileId = (fid && Number(fid) > 0) ? Number(fid) : null;
    pAttFileLabel = label ? String(label) : '';

    if (pFileIdTxt) pFileIdTxt.textContent = pAttFileId ? String(pAttFileId) : '-';
    if (pFileLabelEl) pFileLabelEl.textContent = pAttFileId ? (pAttFileLabel || ('File #' + pAttFileId)) : 'Belum ada file';

    if (activeP) {
      activeP.file_id = pAttFileId;
      activeP.file_label = pAttFileLabel;
    }
    updatePassageButtons();
  }

  function resetPassageAttachments(){
    pAttImageId = null; pAttImageUrl = '';
    pAttFileId = null; pAttFileLabel = '';
    if (passageYoutubeUrl) passageYoutubeUrl.value = '';
    setPassageImage(null, '');
    setPassageFile(null, '');
  }

  // ===== PASSAGE: enable/disable buttons
  function updatePassageButtons(){
    if (!btnPassageSave || !btnPassageDelete) return;

    const hasText = !!(passageText && String(passageText.value || '').trim());
    const isEdit = !!(activeP && Number(activeP.id) > 0);
    const canMutate = !!(activeP && (!isEdit || activeP.can_mutate === true));

    btnPassageSave.disabled = passageSaving || !canMutate || (!isEdit && !hasText);
    btnPassageDelete.disabled = passageSaving || !isEdit || !canMutate;
    [passageTitle, passageText, passageShuffle, passageActive, passageMinQuestions, passageMaxQuestions,
      categorySelect, btnPassagePickImage, btnPassageClearImage, btnPassagePickFile,
      btnPassageClearFile, passageYoutubeUrl].forEach(control=>{ if (control) control.disabled = !canMutate; });
  }

  function updateCategoryButtons(){
    if (!btnCategorySave || !btnCategoryDelete) return;

    const nameOk = !!(categoryName && String(categoryName.value || '').trim());
    const isEdit = !!(activeC && Number(activeC.id) > 0);
    const canMutate = !!(activeC && (!isEdit || activeC.can_mutate === true));

    btnCategorySave.disabled = categorySaving || !canMutate || !nameOk;
    btnCategoryDelete.disabled = categorySaving || !isEdit || !canMutate;
    [categoryName, categoryDesc, categoryShufflePassages, categoryShuffleQuestions,
      categoryMaxQuestions, categoryMinQuestions, categoryActive].forEach(control=>{ if (control) control.disabled = !canMutate; });
  }

  async function loadCategories(){
    const data = await fetchJSON(API.clist + '&quiz_id=' + encodeURIComponent(QUIZ_ID));
    categories = data.categories || [];
    categoryOrderRevision = String(data.order_revision || '');

    renderCategoryOptions();
    renderQuestionCategoryOptions();
    renderCategoryList();
  }

  function renderCategoryOptions(){
    if (!categorySelect) return;

    const desired =
      (activeP && Number(activeP.category_id||0) > 0) ? String(activeP.category_id) :
      (categorySelect.value || '');

    categorySelect.innerHTML = '<option value="">(Tanpa kategori)</option>';
    categories.forEach(c=>{
      const opt = document.createElement('option');
      opt.value = String(c.id);
      opt.textContent = categoryTitleText(c);
      categorySelect.appendChild(opt);
    });

    categorySelect.value = desired || '';
  }

  function renderCategoryList(){
    if (!categoryList) return;

    if (!categories.length){
      categoryList.innerHTML = '<div class="muted" style="padding:12px">Belum ada kategori.</div>';
      return;
    }

    const canReorder = categories.length > 1 && !orderSaving && categories.every(c=>c.can_mutate === true);
    categoryList.innerHTML = categories.map((c, index)=>{
      const st = (Number(c.is_active)===1) ? '<span class="chip">aktif</span>' : '<span class="chip off">nonaktif</span>';
      const sp = (Number(c.shuffle_passages)===1) ? '<span class="chip" title="Passage dalam kategori diacak">shuffle-pass</span>' : '<span class="chip off" title="Passage tidak diacak">no-pass</span>';
      const sq = (Number(c.shuffle_questions)===1) ? '<span class="chip" title="Soal (non-passage) diacak">shuffle-q</span>' : '<span class="chip off" title="Soal (non-passage) tidak diacak">no-q</span>';

      const mn = (c.min_questions != null && Number(c.min_questions) > 0)
        ? `<span class="chip" title="Minimal soal dari kategori">min ${Number(c.min_questions)}</span>`
        : '';
      const mq = (c.max_questions != null && Number(c.max_questions) > 0)
        ? `<span class="chip" title="Batas soal dari kategori">max ${Number(c.max_questions)}</span>`
        : '';

      const snip = c.description ? `<div class="muted" style="margin-top:6px">${escapeHTML(cutText(c.description, 90))}</div>` : '';

      return `
        <div class="item" data-cid="${c.id}">
          <div class="handle" title="Drag untuk urutkan">≡</div>
          <div style="min-width:0;flex:1">
            <div class="item-title">${escapeHTML(categoryTitleText(c))}</div>
            <div class="item-sub">${st}${sp}${sq}${mn}${mq}</div>
            ${snip}
          </div>
          <div class="order-buttons" ${canReorder?'':'hidden'}>
            <button type="button" data-category-step="-1" title="Naikkan kategori" aria-label="Naikkan kategori" ${index===0?'disabled':''}>↑</button>
            <button type="button" data-category-step="1" title="Turunkan kategori" aria-label="Turunkan kategori" ${index===categories.length-1?'disabled':''}>↓</button>
          </div>
        </div>
      `;
    }).join('');

    categoryList.querySelectorAll('[data-cid]').forEach(n=>{
      n.draggable = canReorder;
      n.onclick = ()=> selectCategory(Number(n.dataset.cid));
      if (canReorder) attachDnDCategory(n);
      n.querySelectorAll('[data-category-step]').forEach(button=>{
        button.addEventListener('click', event=>{
          event.stopPropagation();
          moveCategoryByStep(Number(n.dataset.cid), Number(button.dataset.categoryStep));
        });
      });
    });
  }

  // ===== CATEGORY MODAL open/close
  let lastFocusCat = null;

  function openCategoryModal(){
    if (!categoryModal) return;

    lastFocusCat = document.activeElement;

    categoryModal.classList.add('active');
    categoryModal.classList.add('is-open');
    categoryModal.setAttribute('aria-hidden','false');

    clearCategoryEditor(true);

    loadCategories().catch(err=>{
      console.error(err);
      showToast('Gagal load kategori');
    });

    setTimeout(()=>{ try{ if (categoryName) categoryName.focus(); }catch(e){} }, 0);
  }

  function closeCategoryModal(){
    if (!categoryModal) return;

    categoryModal.classList.remove('active');
    categoryModal.classList.remove('is-open');
    categoryModal.setAttribute('aria-hidden','true');

    try{
      if (lastFocusCat && typeof lastFocusCat.focus === 'function') lastFocusCat.focus();
    }catch(e){}
    lastFocusCat = null;
  }

  function clearCategoryEditor(newMode=false){
    activeC = newMode ? { id: 0, can_mutate: true } : null;

    if (categoryName) categoryName.value = '';
    if (categoryDesc) categoryDesc.value = '';
    if (categoryShufflePassages) categoryShufflePassages.value = '1';
    if (categoryShuffleQuestions) categoryShuffleQuestions.value = '1';
    if (categoryActive) categoryActive.value = '1';
    if (categoryMaxQuestions) categoryMaxQuestions.value = '';
    if (categoryMinQuestions) categoryMinQuestions.value = '0';

    categorySaving = false;
    updateCategoryButtons();
  }

  async function selectCategory(cid){
    const data = await fetchJSON(API.cget + '&quiz_id=' + encodeURIComponent(QUIZ_ID) + '&category_id=' + encodeURIComponent(cid));
    activeC = data.category;
    if (!activeC) return;

    if (categoryName) categoryName.value = activeC.name || activeC.title || '';
    if (categoryDesc) categoryDesc.value = activeC.description || '';
    if (categoryShufflePassages) categoryShufflePassages.value = String(activeC.shuffle_passages ?? 1);
    if (categoryShuffleQuestions) categoryShuffleQuestions.value = String(activeC.shuffle_questions ?? 1);
    if (categoryActive) categoryActive.value = String(activeC.is_active ?? 1);

    if (categoryMaxQuestions){
      const mq = (activeC.max_questions != null && Number(activeC.max_questions) > 0) ? String(activeC.max_questions) : '';
      categoryMaxQuestions.value = mq;
    }
    if (categoryMinQuestions){
      const mn = (activeC.min_questions != null && Number(activeC.min_questions) >= 0) ? String(activeC.min_questions) : '0';
      categoryMinQuestions.value = mn;
    }

    categorySaving = false;
    updateCategoryButtons();
  }

  async function saveCategory(){
    if (activeC && activeC.id && activeC.can_mutate !== true) return;
    const name = (categoryName && categoryName.value) ? String(categoryName.value).trim() : '';
    if (!name) { alert('Nama kategori wajib diisi'); return; }

    let mq = null;
    if (categoryMaxQuestions) {
      mq = parseNullablePositiveInt(categoryMaxQuestions.value);
      if (mq !== null) {
        if (!Number.isFinite(mq) || mq < 1) {
          alert('Max soal harus >= 1 atau kosong');
          return;
        }
      }
    }

    let mn = 0;
    if (categoryMinQuestions) {
      mn = parseNonNegInt(categoryMinQuestions.value, 0);
      if (!Number.isFinite(mn) || mn < 0) {
        alert('Min soal harus >= 0');
        return;
      }
    }

    if (mq !== null && mn > mq) {
      alert('Min soal tidak boleh lebih besar dari Max soal');
      return;
    }

    const payload = {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      id: (activeC && activeC.id != null) ? (Number(activeC.id)||0) : 0,
      name: name,
      description: (categoryDesc && categoryDesc.value) ? String(categoryDesc.value) : '',
      shuffle_passages: categoryShufflePassages ? Number(categoryShufflePassages.value) : 1,
      shuffle_questions: categoryShuffleQuestions ? Number(categoryShuffleQuestions.value) : 1,
      is_active: categoryActive ? Number(categoryActive.value) : 1,
      min_questions: mn,
      max_questions: mq
    };

    categorySaving = true;
    updateCategoryButtons();
    showToast('Menyimpan kategori...');

    const res = await fetchJSON(API.csave, payload);

    showToast('Kategori tersimpan');

    await loadCategories();

    const newId = (res && res.id) ? Number(res.id) : payload.id;
    if (newId > 0) await selectCategory(newId);

    await loadPassages().catch(()=>{});
    await loadQuestions().catch(()=>{});
    renderCategoryOptions();
    renderQuestionCategoryOptions();
    renderList();

    categorySaving = false;
    updateCategoryButtons();
  }

  async function deleteCategory(){
    if (!activeC || !activeC.id || activeC.can_mutate !== true) return;
    if (!(await NewNotifConfirm.danger({message:'Hapus kategori ini? Passage yang terkait akan menjadi tanpa kategori.'}))) return;

    categorySaving = true;
    updateCategoryButtons();

    await fetchJSON(API.cdel, { csrf_token: CSRF, quiz_id: QUIZ_ID, id: Number(activeC.id) });
    showToast('Kategori dihapus');

    clearCategoryEditor(true);
    await loadCategories();

    await loadPassages().catch(()=>{});
    await loadQuestions().catch(()=>{});
    renderCategoryOptions();
    renderQuestionCategoryOptions();
    renderList();

    categorySaving = false;
    updateCategoryButtons();
  }

  // ===== DnD reorder categories
  let dragCId = null;

  async function moveCategoryByStep(categoryId, delta){
    if (orderSaving || categories.some(c=>c.can_mutate !== true)) return;
    const ids = categories.map(c=>Number(c.id));
    const from = ids.indexOf(categoryId);
    const to = from + delta;
    if (from < 0 || to < 0 || to >= ids.length) return;
    ids.splice(to, 0, ids.splice(from, 1)[0]);
    await persistOrder(API.corder, {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      ordered_ids: ids,
      order_revision: categoryOrderRevision
    }, loadCategories, 'Urutan kategori tersimpan');
  }

  function attachDnDCategory(node){
    node.addEventListener('dragstart', (e)=>{
      dragCId = node.dataset.cid;
      e.dataTransfer.effectAllowed = 'move';
    });
    node.addEventListener('dragover', (e)=>{
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    node.addEventListener('drop', async (e)=>{
      e.preventDefault();
      const targetId = node.dataset.cid;
      if (!dragCId || dragCId === targetId) return;

      const ids = categories.map(x=>String(x.id));
      const from = ids.indexOf(String(dragCId));
      const to = ids.indexOf(String(targetId));
      if (from < 0 || to < 0) return;

      ids.splice(to, 0, ids.splice(from, 1)[0]);

      await persistOrder(API.corder, {
        csrf_token: CSRF,
        quiz_id: QUIZ_ID,
        ordered_ids: ids.map(Number),
        order_revision: categoryOrderRevision
      }, async ()=>{
        await loadCategories();
        renderCategoryOptions();
        renderQuestionCategoryOptions();
        renderList();
      }, 'Urutan kategori tersimpan');
    });
  }

  // ===== LOAD PASSAGES + OPTIONS
  async function loadPassages(){
    const data = await fetchJSON(API.plist + '&quiz_id=' + encodeURIComponent(QUIZ_ID));
    passages = data.passages || [];
    passageOrderRevision = String(data.order_revision || '');
    renderPassageOptions();
    renderPassageList();
  }

  function passageTitleText(p){
    const t = (p && p.title) ? String(p.title).trim() : '';
    if (t) return t;
    return p && p.id ? ('Passage #' + p.id) : 'Passage';
  }

  function renderPassageOptions(){
    if (!passageSelect) return;

    const desired = (activeQ && activeQ.passage_id) ? String(activeQ.passage_id) : (passageSelect.value || '');
    passageSelect.innerHTML = '<option value="">(Tanpa passage)</option>';

    passages.forEach(p=>{
      const opt = document.createElement('option');
      opt.value = String(p.id);
      opt.textContent = passageTitleText(p);
      passageSelect.appendChild(opt);
    });

    passageSelect.value = desired || '';
    syncQuestionCategoryUIFromPassage(true);
  }

  function renderPassageList(){
    if (!passageList) return;

    if (!passages.length){
      passageList.innerHTML = '<div class="muted" style="padding:12px">Belum ada passage.</div>';
      return;
    }

    const canReorder = passages.length > 1 && !orderSaving && passages.every(p=>p.can_mutate === true);
    passageList.innerHTML = passages.map((p, index)=>{
      const title = passageTitleText(p);
      const snip = cutText(p.passage_text || '', 80);

      const st = (Number(p.is_active)===1) ? '<span class="chip">aktif</span>' : '<span class="chip off">nonaktif</span>';
      const sh = (Number(p.shuffle_questions)===1) ? '<span class="chip" title="Soal dalam passage diacak">shuffle</span>' : '<span class="chip off" title="Soal dalam passage tidak diacak">no-shuffle</span>';

      const mn = (p.min_questions != null && Number(p.min_questions) > 0)
        ? `<span class="chip" title="Minimal soal dari passage ini">min ${Number(p.min_questions)}</span>`
        : '';
      const mq = (p.max_questions != null && Number(p.max_questions) > 0)
        ? `<span class="chip" title="Maks soal dari passage ini">max ${Number(p.max_questions)}</span>`
        : '';

      const catChip = (Number(p.category_id||0) > 0)
        ? `<span class="chip" title="Kategori">${escapeHTML(p.category_name || p.category_title || getCategoryNameById(p.category_id))}</span>`
        : '';

      return `
        <div class="item" data-pid="${p.id}">
          <div class="handle" title="Drag untuk urutkan">≡</div>
          <div style="min-width:0;flex:1">
            <div class="item-title">${escapeHTML(title)}</div>
            <div class="item-sub">
              ${st}
              ${sh}
              ${mn}
              ${mq}
              ${catChip}
            </div>
            <div class="muted" style="margin-top:6px">${escapeHTML(snip)}</div>
          </div>
          <div class="order-buttons" ${canReorder?'':'hidden'}>
            <button type="button" data-passage-step="-1" title="Naikkan passage" aria-label="Naikkan passage" ${index===0?'disabled':''}>↑</button>
            <button type="button" data-passage-step="1" title="Turunkan passage" aria-label="Turunkan passage" ${index===passages.length-1?'disabled':''}>↓</button>
          </div>
        </div>
      `;
    }).join('');

    passageList.querySelectorAll('[data-pid]').forEach(n=>{
      n.draggable = canReorder;
      n.onclick = ()=> selectPassage(Number(n.dataset.pid));
      if (canReorder) attachDnDPassage(n);
      n.querySelectorAll('[data-passage-step]').forEach(button=>{
        button.addEventListener('click', event=>{
          event.stopPropagation();
          movePassageByStep(Number(n.dataset.pid), Number(button.dataset.passageStep));
        });
      });
    });
  }

  let dragPId = null;
  async function movePassageByStep(passageId, delta){
    if (orderSaving || passages.some(p=>p.can_mutate !== true)) return;
    const ids = passages.map(p=>Number(p.id));
    const from = ids.indexOf(passageId);
    const to = from + delta;
    if (from < 0 || to < 0 || to >= ids.length) return;
    ids.splice(to, 0, ids.splice(from, 1)[0]);
    await persistOrder(API.porder, {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      ordered_ids: ids,
      order_revision: passageOrderRevision
    }, loadPassages, 'Urutan passage tersimpan');
  }
  function attachDnDPassage(node){
    node.addEventListener('dragstart', (event)=>{
      dragPId = node.dataset.pid;
      event.dataTransfer.effectAllowed = 'move';
    });
    node.addEventListener('dragover', (event)=>{
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
    });
    node.addEventListener('drop', async (event)=>{
      event.preventDefault();
      const targetId = node.dataset.pid;
      if (!dragPId || dragPId === targetId) return;
      const ids = passages.map(item=>String(item.id));
      const from = ids.indexOf(String(dragPId));
      const to = ids.indexOf(String(targetId));
      if (from < 0 || to < 0) return;
      ids.splice(to, 0, ids.splice(from, 1)[0]);
      await persistOrder(API.porder, {
        csrf_token: CSRF,
        quiz_id: QUIZ_ID,
        ordered_ids: ids.map(Number),
        order_revision: passageOrderRevision
      }, loadPassages, 'Urutan passage tersimpan');
    });
  }

  // ===== PASSAGE MODAL open/close + editor
  let lastFocusEl = null;

  function openPassageModal(){
    if (!passageModal) return;

    lastFocusEl = document.activeElement;

    passageModal.classList.add('active');
    passageModal.classList.add('is-open');
    passageModal.setAttribute('aria-hidden','false');

    clearPassageEditor(true);

    Promise.all([loadCategories(), loadPassages()]).catch(err=>{
      console.error(err);
      showToast('Gagal load data passage/kategori');
    });

    setTimeout(()=>{ try{ if (passageTitle) passageTitle.focus(); }catch(e){} }, 0);
  }

  function closePassageModal(){
    if (!passageModal) return;

    passageModal.classList.remove('active');
    passageModal.classList.remove('is-open');
    passageModal.setAttribute('aria-hidden','true');

    try{
      if (lastFocusEl && typeof lastFocusEl.focus === 'function') lastFocusEl.focus();
    }catch(e){}
    lastFocusEl = null;
  }

  function clearPassageEditor(newMode=false){
    activeP = newMode ? { id: 0, can_mutate: true } : null;

    if (passageTitle) passageTitle.value = '';
    if (passageText) passageText.value = '';
    if (passageShuffle) passageShuffle.value = '1';
    if (passageActive) passageActive.value = '1';
    if (passageMinQuestions) passageMinQuestions.value = '0';
    if (passageMaxQuestions) passageMaxQuestions.value = '';
    if (categorySelect) categorySelect.value = '';

    renderMDWithMath('', passagePreview);
    resetPassageAttachments();

    passageSaving = false;
    updatePassageButtons();
  }

  async function selectPassage(pid){
    const data = await fetchJSON(API.pget + '&quiz_id=' + encodeURIComponent(QUIZ_ID) + '&passage_id=' + encodeURIComponent(pid));
    activeP = data.passage;
    if (!activeP) return;

    if (passageTitle) passageTitle.value = activeP.title || '';
    if (passageText) passageText.value = activeP.passage_text || '';
    if (passageShuffle) passageShuffle.value = String(activeP.shuffle_questions ?? 1);
    if (passageActive) passageActive.value = String(activeP.is_active ?? 1);

    if (passageMinQuestions){
      const mn = (activeP.min_questions != null && Number(activeP.min_questions) >= 0)
        ? String(activeP.min_questions)
        : '0';
      passageMinQuestions.value = mn;
    }

    if (passageMaxQuestions) {
      const mq = (activeP.max_questions != null && Number(activeP.max_questions) > 0)
        ? String(activeP.max_questions)
        : '';
      passageMaxQuestions.value = mq;
    }

    renderCategoryOptions();
    if (categorySelect) {
      const cid = (activeP.category_id != null && Number(activeP.category_id) > 0) ? String(activeP.category_id) : '';
      categorySelect.value = cid;
    }

    renderMDWithMath((activeP.passage_text||''), passagePreview);

    setPassageImage(activeP.image_media_id || null, activeP.image_url || '');
    setPassageFile(activeP.file_id || null, activeP.file_label || (activeP.file_id ? ('File #' + activeP.file_id) : ''));
    if (passageYoutubeUrl) passageYoutubeUrl.value = activeP.youtube_url || '';

    passageSaving = false;
    updatePassageButtons();
  }

  async function savePassage(){
    if (activeP && activeP.id && activeP.can_mutate !== true) return;
    const txt = (passageText && passageText.value) ? String(passageText.value).trim() : '';
    if (!txt) { alert('Teks passage wajib diisi'); return; }

    let mn = 0;
    if (passageMinQuestions){
      mn = parseNonNegInt(passageMinQuestions.value, 0);
      if (!Number.isFinite(mn) || mn < 0) {
        alert('Min soal passage harus >= 0');
        return;
      }
    }

    let mq = null;
    if (passageMaxQuestions) {
      mq = parseNullablePositiveInt(passageMaxQuestions.value);
      if (mq !== null) {
        if (!Number.isFinite(mq) || mq < 1) {
          alert('Max soal passage harus >= 1 atau kosong');
          return;
        }
      }
    }

    if (mq !== null && mn > mq) {
      alert('Min soal passage tidak boleh lebih besar dari Max soal passage');
      return;
    }

    let cid = null;
    if (categorySelect) {
      const v = String(categorySelect.value || '').trim();
      cid = v ? Number(v) : null;
      if (cid && isNaN(cid)) cid = null;
    }

    const payload = {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      id: (activeP && activeP.id != null) ? (Number(activeP.id)||0) : 0,
      title: (passageTitle && passageTitle.value) ? String(passageTitle.value) : '',
      passage_text: passageText ? String(passageText.value) : '',
      shuffle_questions: passageShuffle ? Number(passageShuffle.value) : 1,
      is_active: passageActive ? Number(passageActive.value) : 1,
      min_questions: mn,
      max_questions: mq,
      category_id: cid,
      image_media_id: pAttImageId ? Number(pAttImageId) : null,
      file_id: pAttFileId ? Number(pAttFileId) : null,
      youtube_url: passageYoutubeUrl ? String(passageYoutubeUrl.value||'').trim() : ''
    };

    passageSaving = true;
    updatePassageButtons();
    showToast('Menyimpan passage...');

    const res = await fetchJSON(API.psave, payload);

    showToast('Passage tersimpan');

    await loadPassages();

    const newId = (res && res.id) ? Number(res.id) : payload.id;
    if (newId > 0) await selectPassage(newId);

    renderPassageOptions();
    await loadQuestions();
    if (activeQ && activeQ.id) {
      try { await selectQuestion(activeQ.id); } catch(e){}
    }

    passageSaving = false;
    updatePassageButtons();
  }

  async function deletePassage(){
    if (!activeP || !activeP.id || activeP.can_mutate !== true) return;
    if (!(await NewNotifConfirm.danger({message:'Hapus passage ini? Soal yang terkait akan otomatis jadi tanpa passage.'}))) return;

    passageSaving = true;
    updatePassageButtons();

    await fetchJSON(API.pdel, { csrf_token: CSRF, quiz_id: QUIZ_ID, id: Number(activeP.id) });
    showToast('Passage dihapus');

    clearPassageEditor(true);
    await loadPassages();

    await loadQuestions();
    if (activeQ && activeQ.id) {
      try { await selectQuestion(activeQ.id); } catch(e){}
    }

    passageSaving = false;
    updatePassageButtons();
  }

  // ===== LOAD QUESTIONS LIST
  async function loadQuestions(){
    const data = await fetchJSON(API.list + '&quiz_id=' + encodeURIComponent(QUIZ_ID));
    questions = sortQuestionsByOrder(data.questions || []);
    questionOrderRevision = String(data.order_revision || '');
    renderList();
  }

  function filteredQuestions(){
    if (!searchTerm) return questions;
    const s = searchTerm.toLowerCase();
    return questions.filter(q => {
      const qtxt = (q.question_text||'').toLowerCase();
      const cid = getQuestionCategoryId(q);
      const cname = cid ? getCategoryNameById(cid).toLowerCase() : '';
      return qtxt.includes(s) || String(q.id).includes(s) || (cname && cname.includes(s));
    });
  }

  function renderList(){
    const list = filteredQuestions();
    if (qCount) qCount.textContent = list.length;
    if (!qList) return;
    qList.innerHTML = '';

    if (!list.length){
      qList.innerHTML = '<div style="padding:12px" class="muted">Tidak ada soal.</div>';
      return;
    }

    const canReorder = canReorderQuestions() && !orderSaving;
    list.forEach((q, idx)=>{
      const ord = (q.sort_order != null && Number(q.sort_order) > 0) ? Number(q.sort_order) : (idx + 1);
      const item = document.createElement('div');
      item.className = 'item' + (activeQ && activeQ.id == q.id ? ' active' : '');
      item.dataset.id = q.id;
      item.draggable = canReorder;

      const statusChip = (Number(q.is_active)===1) ? '<span class="chip">aktif</span>' : '<span class="chip off">nonaktif</span>';

      const attChips = [
        (Number(q.image_media_id||0) > 0) ? '<span class="chip" title="Ada gambar">🖼</span>' : '',
        (Number(q.file_id||0) > 0) ? '<span class="chip" title="Ada file">📎</span>' : '',
        (q.youtube_url && String(q.youtube_url).trim() !== '') ? '<span class="chip" title="Ada video">▶</span>' : ''
      ].join('');

      const pChip = (Number(q.passage_id||0) > 0)
        ? `<span class="chip passage" title="Passage">${escapeHTML(q.passage_title || '📜')}</span>`
        : '';

      const cid = getQuestionCategoryId(q);
      const cChip = (cid > 0 && Number(q.passage_id||0) <= 0)
        ? `<span class="chip" title="Kategori">🏷 ${escapeHTML(getCategoryNameById(cid) || ('Kategori #' + cid))}</span>`
        : '';

      item.innerHTML = `
        <div class="handle" title="Drag untuk urutkan">≡</div>
        <div style="min-width:0;flex:1">
          <div class="item-title">#${ord} · ${escapeHTML(cutText(q.question_text || '', 92))}</div>
          <div class="item-sub">
            <span class="chip">${escapeHTML(q.question_type || '')}</span>
            ${statusChip}
            ${pChip}
            ${cChip}
            ${attChips}
            <span class="badge" title="Jumlah jawaban aktif">${Number(q.answer_count||0)}</span>
          </div>
        </div>
      `;

      item.onclick = ()=> selectQuestion(q.id);

      if (canReorder) attachDnDQuestion(item);
      qList.appendChild(item);
    });
  }

  // ===== SELECT / LOAD QUESTION DETAIL
  async function selectQuestion(id){
    await flushPendingAnswerSaves();
    const data = await fetchJSON(API.get + '&quiz_id=' + encodeURIComponent(QUIZ_ID) + '&question_id=' + encodeURIComponent(id));
    activeQ = data.question;
    if (!activeQ) return;

    if (emptyState) emptyState.style.display = 'none';
    if (editor) editor.style.display = 'block';
    if (questionActions) questionActions.style.display = 'flex';

    if (editorTitle) editorTitle.textContent = 'Edit Soal #' + activeQ.id;
    if (editorMeta) editorMeta.textContent = 'Auto-save aktif. Urutan jawaban bisa drag & drop.';

    const canMutate = activeQ.can_mutate === true;
    if (btnDeleteQ) btnDeleteQ.disabled = !canMutate;
    if (btnAddA) btnAddA.disabled = !canMutate;
    if (btnYesNo) btnYesNo.disabled = !canMutate;
    if (btnDuplicate) btnDuplicate.disabled = !canMutate;
    [questionText, questionType, questionActive, btnPickImage, btnClearImage,
      btnPickFile, btnClearFile, youtubeUrl, passageSelect, passageSeq].forEach(control=>{
      if (control) control.disabled = !canMutate;
    });
    if (questionPos) questionPos.disabled = !canMutate || !canReorderQuestions();

    if (questionText) questionText.value = activeQ.question_text || '';
    if (questionType) questionType.value = activeQ.question_type || 'single_choice';
    if (questionActive) questionActive.value = String(activeQ.is_active ?? 1);

    renderMDWithMath(questionText ? questionText.value : '', questionPreview);

    const mid = (activeQ.image_media_id != null) ? Number(activeQ.image_media_id) : null;
    const murl = activeQ.image_url ? String(activeQ.image_url) : '';
    setImage(mid, murl, true);

    const fid = (activeQ.file_id != null) ? Number(activeQ.file_id) : null;
    const flabel = activeQ.file_label ? String(activeQ.file_label) : '';
    setFile(fid, flabel, true);

    if (youtubeUrl) youtubeUrl.value = activeQ.youtube_url ? String(activeQ.youtube_url) : '';
    syncQuestionAttachments(true);

    if (passageSelect) {
      const pid = (activeQ.passage_id != null && Number(activeQ.passage_id) > 0) ? String(activeQ.passage_id) : '';
      passageSelect.value = pid;
    }
    if (passageSeq) {
      const seq = (activeQ.passage_seq != null && Number(activeQ.passage_seq) > 0) ? String(activeQ.passage_seq) : '';
      passageSeq.value = seq;
    }
    renderPassageOptions();

    bindQuestionCategoryUI();
    if (qCategorySelect) qCategorySelect.disabled = !canMutate;
    renderQuestionCategoryOptions();
    if (qCategorySelect) {
      const qc = getQuestionCategoryId(activeQ);
      qCategorySelect.value = (qc > 0) ? String(qc) : '';
    }
    syncQuestionCategoryUIFromPassage(true);

    renderList();
    renderAnswers();
    syncEditorPositionUI();
  }

  // ===== AUTOSAVE (debounced)
  function scheduleSaveQuestion(){
    if (!activeQ || activeQ.can_mutate !== true) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(()=> {
      saveQuestionNow().catch(err=>{
        console.error(err);
        showToast('Gagal simpan: ' + (err.message || 'error'));
      });
    }, 520);
  }

  async function saveQuestionNow(){
    if (!activeQ || activeQ.can_mutate !== true) return;

    const qt = (questionText && questionText.value ? questionText.value : '').trim();
    if (qt === '') {
      showToast('Soal kosong (tidak disimpan)');
      return;
    }

    let pid = null;
    let pseq = null;
    if (passageSelect) {
      const v = String(passageSelect.value || '').trim();
      pid = v ? Number(v) : null;
      if (pid && isNaN(pid)) pid = null;
    }
    if (passageSeq) {
      const v = String(passageSeq.value || '').trim();
      pseq = v ? Number(v) : null;
      if (pseq && isNaN(pseq)) pseq = null;
    }
    if (!pid) pseq = null;

    let qcid = null;
    bindQuestionCategoryUI();
    if (!pid && qCategorySelect) {
      const v = String(qCategorySelect.value || '').trim();
      qcid = v ? Number(v) : null;
      if (qcid && isNaN(qcid)) qcid = null;
    } else {
      qcid = null;
    }

    const payload = {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      id: activeQ.id,
      question_text: questionText ? questionText.value : '',
      question_type: questionType ? questionType.value : 'single_choice',
      is_active: Number(questionActive ? questionActive.value : 1),
      image_media_id: attImageId ? Number(attImageId) : null,
      file_id: attFileId ? Number(attFileId) : null,
      youtube_url: (youtubeUrl && youtubeUrl.value ? youtubeUrl.value : '').trim(),
      passage_id: pid,
      passage_seq: pseq,
      category_id: qcid,
      question_category_id: qcid
    };

    const data = await fetchJSON(API.qsave, payload);

    activeQ.id = data.id;
    activeQ.question_text = payload.question_text;
    activeQ.question_type = payload.question_type;
    activeQ.is_active = payload.is_active;
    activeQ.image_media_id = payload.image_media_id;
    activeQ.file_id = payload.file_id;
    activeQ.youtube_url = payload.youtube_url;
    activeQ.passage_id = payload.passage_id;
    activeQ.passage_seq = payload.passage_seq;
    activeQ.category_id = payload.category_id;
    activeQ.question_category_id = payload.category_id;

    const idx = questions.findIndex(x=>x.id == activeQ.id);
    if (idx >= 0){
      questions[idx].question_text = payload.question_text;
      questions[idx].question_type = payload.question_type;
      questions[idx].is_active = payload.is_active;
      questions[idx].image_media_id = payload.image_media_id;
      questions[idx].file_id = payload.file_id;
      questions[idx].youtube_url = payload.youtube_url;
      questions[idx].passage_id = payload.passage_id;
      questions[idx].category_id = payload.category_id;
      questions[idx].question_category_id = payload.category_id;
    }

    renderList();
    showToast('Tersimpan');
  }

  // ===== ANSWERS
  function renderAnswers(){
    const ans = (activeQ && activeQ.answers) ? activeQ.answers : [];
    if (!answersBox) return;
    answersBox.innerHTML = '';

    if (!ans.length){
      answersBox.innerHTML = '<div class="muted" style="padding:10px 0">Belum ada jawaban. Klik "+ Jawaban".</div>';
      return;
    }

    const canMutate = !!(activeQ && activeQ.can_mutate === true);
    const canReorder = canMutate && ans.length > 1;
    ans.forEach((a, index)=>{
      const row = document.createElement('div');
      row.className = 'answer-row';
      row.dataset.id = a.id;
      row.draggable = canMutate;

      row.innerHTML = `
        <div class="handle" title="Drag untuk urutkan">≡</div>

        <div class="answer-md">
          <textarea class="input" data-k="answer_text" rows="2" placeholder="Teks jawaban...">${escapeHTML(a.answer_text || '')}</textarea>
          <div class="qz-live-preview qz-live-preview--answer">
            <div class="qz-live-preview__head"><span>Preview jawaban ${index + 1}</span><small>Tampilan peserta</small></div>
            <div class="md-preview answer-preview" data-preview></div>
          </div>
          <div class="order-buttons answer-order-buttons" ${canReorder?'':'hidden'}>
            <button type="button" data-answer-step="-1" title="Naikkan jawaban" aria-label="Naikkan jawaban" ${index===0?'disabled':''}>↑</button>
            <button type="button" data-answer-step="1" title="Turunkan jawaban" aria-label="Turunkan jawaban" ${index===ans.length-1?'disabled':''}>↓</button>
          </div>
        </div>

        <input class="input" data-k="score" type="number" min="0" max="100" value="${Number(a.score||0)}" title="Skor 0..100">

        <select class="select" data-k="is_active" title="Status">
          <option value="1" ${Number(a.is_active)===1?'selected':''}>Aktif</option>
          <option value="0" ${Number(a.is_active)===0?'selected':''}>Nonaktif</option>
        </select>

        <button class="iconbtn danger" type="button" title="Hapus jawaban">🗑</button>
      `;

      if (canMutate) attachDnDAnswer(row);

      const pv = row.querySelector('[data-preview]');
      renderMDWithMath(a.answer_text || '', pv);

      const ta = row.querySelector('textarea[data-k="answer_text"]');
      const scoreInp = row.querySelector('input[data-k="score"]');
      const actSel = row.querySelector('select[data-k="is_active"]');
      [ta, scoreInp, actSel].forEach(control=>{ control.disabled = !canMutate; });
      const deleteButton = row.querySelector('.iconbtn.danger');
      if (deleteButton) deleteButton.hidden = !canMutate;

      ta.addEventListener('input', ()=>{
        renderMDWithMath(ta.value, pv);
        scheduleSaveAnswer(a.id, row);
      });
      ta.addEventListener('change', ()=>{
        renderMDWithMath(ta.value, pv);
        scheduleSaveAnswer(a.id, row);
      });

      scoreInp.addEventListener('input', ()=> scheduleSaveAnswer(a.id, row));
      scoreInp.addEventListener('change', ()=> scheduleSaveAnswer(a.id, row));
      actSel.addEventListener('change', ()=> scheduleSaveAnswer(a.id, row));

      if (deleteButton) deleteButton.onclick = ()=> deleteAnswer(a.id);
      row.querySelectorAll('[data-answer-step]').forEach(button=>{
        button.addEventListener('click', ()=>moveAnswerByStep(Number(a.id), Number(button.dataset.answerStep)));
      });

      answersBox.appendChild(row);
    });
  }

  const answerSaveTimers = new Map();
  function runPendingAnswerSave(key, pending){
    if (pending.promise) return pending.promise;
    if (pending.timer) clearTimeout(pending.timer);
    pending.timer = null;
    pending.promise = Promise.resolve(pending.waitFor).catch(()=>{})
      .then(()=>saveAnswerNow(pending.questionId, pending.answerId, pending.row))
      .catch(err=>{
        console.error(err);
        showToast('Gagal simpan jawaban');
        throw err;
      })
      .finally(()=>{
        if (answerSaveTimers.get(key) === pending) answerSaveTimers.delete(key);
      });
    return pending.promise;
  }

  function scheduleSaveAnswer(answerId, row){
    if (!activeQ || activeQ.can_mutate !== true) return;
    const questionId = Number(activeQ.id);
    const key = questionId + ':' + Number(answerId);
    const previous = answerSaveTimers.get(key);
    if (previous && previous.timer) clearTimeout(previous.timer);
    const pending = { questionId, answerId: Number(answerId), row, timer: null, promise: null, waitFor: previous?.promise || null };
    pending.timer = setTimeout(()=>{ runPendingAnswerSave(key, pending).catch(()=>{}); }, 420);
    answerSaveTimers.set(key, pending);
  }

  async function flushPendingAnswerSaves(){
    const pending = Array.from(answerSaveTimers.entries());
    if (!pending.length) return;
    await Promise.all(pending.map(([key, entry])=>runPendingAnswerSave(key, entry)));
  }

  async function saveAnswerNow(questionId, answerId, row){
    if (!questionId || !answerId || !row) return;

    const txt = row.querySelector('[data-k="answer_text"]').value;
    const score = row.querySelector('[data-k="score"]').value;
    const act = row.querySelector('[data-k="is_active"]').value;

    const payload = {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      question_id: questionId,
      id: answerId,
      answer_text: txt,
      score: Number(score),
      is_active: Number(act),
    };

    const data = await fetchJSON(API.asave, payload);

    if (activeQ && Number(activeQ.id) === questionId) {
      const idx = activeQ.answers.findIndex(x=>x.id == answerId);
      if (idx >= 0){
        activeQ.answers[idx].answer_text = payload.answer_text;
        activeQ.answers[idx].score = payload.score;
        activeQ.answers[idx].is_active = payload.is_active;
        activeQ.answers[idx].id = data.id;
      }
    }

    showToast('Jawaban tersimpan');
    await refreshCounts();
  }

  async function refreshCounts(){
    const listData = await fetchJSON(API.list + '&quiz_id=' + encodeURIComponent(QUIZ_ID));
    questions = sortQuestionsByOrder(listData.questions || questions);
    questionOrderRevision = String(listData.order_revision || questionOrderRevision);
    renderList();
  }

  async function addAnswer(){
    if (!activeQ || activeQ.can_mutate !== true) return;
    const payload = {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      question_id: activeQ.id,
      id: 0,
      answer_text: 'Jawaban baru',
      score: 0,
      is_active: 1
    };
    const data = await fetchJSON(API.asave, payload);
    await selectQuestion(activeQ.id);
    showToast('Jawaban ditambah');
    await refreshCounts();
  }

  async function deleteAnswer(id){
    if (!activeQ || activeQ.can_mutate !== true) return;
    if (!(await NewNotifConfirm.danger({message:'Hapus jawaban ini?'}))) return;
    await flushPendingAnswerSaves();
    await fetchJSON(API.adel, { csrf_token: CSRF, quiz_id: QUIZ_ID, question_id: activeQ.id, id: id });
    await selectQuestion(activeQ.id);
    showToast('Jawaban dihapus');
    await refreshCounts();
  }

  // ===== ADD / DELETE / DUPLICATE QUESTION
  async function addQuestion(){
    const payload = {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      id: 0,
      question_text: 'Soal baru (edit saya)',
      question_type: 'single_choice',
      is_active: 0,
      image_media_id: null,
      file_id: null,
      youtube_url: '',
      passage_id: null,
      passage_seq: null,
      category_id: null,
      question_category_id: null
    };
    const data = await fetchJSON(API.qsave, payload);
    await loadQuestions();
    showToast('Soal dibuat');
    await selectQuestion(data.id);
  }

  async function deleteQuestion(){
    if (!activeQ || activeQ.can_mutate !== true) return;
    if (!(await NewNotifConfirm.danger({message:'Hapus soal ini? Semua jawaban akan ikut terhapus.'}))) return;
    await fetchJSON(API.qdel, { csrf_token: CSRF, quiz_id: QUIZ_ID, id: activeQ.id });
    activeQ = null;

    if (editor) editor.style.display = 'none';
    if (emptyState) emptyState.style.display = 'block';
    if (questionActions) questionActions.style.display = 'none';
    if (editorTitle) editorTitle.textContent = 'Pilih soal';
    if (editorMeta) editorMeta.textContent = 'Klik salah satu soal untuk mulai edit';
    if (btnDeleteQ) btnDeleteQ.disabled = true;
    if (btnAddA) btnAddA.disabled = true;
    if (btnYesNo) btnYesNo.disabled = true;
    if (btnDuplicate) btnDuplicate.disabled = true;

    await loadQuestions();
    showToast('Soal dihapus');
  }

  async function duplicateQuestion(){
    if (!activeQ || activeQ.can_mutate !== true) return;

    let pid = null;
    let pseq = null;
    if (passageSelect) {
      const v = String(passageSelect.value || '').trim();
      pid = v ? Number(v) : null;
      if (pid && isNaN(pid)) pid = null;
    }
    if (passageSeq) {
      const v = String(passageSeq.value || '').trim();
      pseq = v ? Number(v) : null;
      if (pseq && isNaN(pseq)) pseq = null;
    }
    if (!pid) pseq = null;

    let qcid = null;
    if (!pid) {
      const cur = getQuestionCategoryId(activeQ);
      qcid = cur > 0 ? cur : null;
    }

    const payload = {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      id: 0,
      question_text: (activeQ.question_text || '') + ' (copy)',
      question_type: activeQ.question_type || 'single_choice',
      is_active: 0,
      image_media_id: (activeQ.image_media_id != null) ? Number(activeQ.image_media_id) : null,
      file_id: (activeQ.file_id != null) ? Number(activeQ.file_id) : null,
      youtube_url: (activeQ.youtube_url || ''),
      clone_from_question_id: activeQ.id,
      passage_id: pid,
      passage_seq: pseq,
      category_id: qcid,
      question_category_id: qcid
    };

    const data = await fetchJSON(API.qsave, payload);
    showToast('Duplikat dibuat');
    await loadQuestions();
    await selectQuestion(data.id);
  }

  // ===== YES/NO GENERATOR
  async function generateYesNo(){
    if (!activeQ || activeQ.can_mutate !== true) return;
    if (questionType) questionType.value = 'yesno';
    scheduleSaveQuestion();

    if (!(await NewNotifConfirm.warning({message:'Generate jawaban Yes/No akan mengisi "Ya (100)" & "Tidak (0)". Lanjutkan?'}))) return;
    await flushPendingAnswerSaves();

    const existing = (activeQ.answers||[]).slice();
    for (const a of existing){
      await fetchJSON(API.adel, { csrf_token: CSRF, quiz_id: QUIZ_ID, question_id: activeQ.id, id: a.id });
    }
    await fetchJSON(API.asave, { csrf_token: CSRF, quiz_id: QUIZ_ID, question_id: activeQ.id, id: 0, answer_text:'Ya', score:100, is_active:1 });
    await fetchJSON(API.asave, { csrf_token: CSRF, quiz_id: QUIZ_ID, question_id: activeQ.id, id: 0, answer_text:'Tidak', score:0, is_active:1 });

    await selectQuestion(activeQ.id);
    showToast('Yes/No dibuat');
    await refreshCounts();
  }

  // ===== DRAG & DROP REORDER (QUESTIONS)
  let dragQId = null;

  function attachDnDQuestion(node){
    node.addEventListener('dragstart', (e)=>{
      dragQId = node.dataset.id;
      e.dataTransfer.effectAllowed = 'move';
    });
    node.addEventListener('dragover', (e)=>{
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    node.addEventListener('drop', async (e)=>{
      e.preventDefault();
      const targetId = node.dataset.id;
      if (!dragQId || dragQId === targetId) return;

      const list = questions.map(x=>String(x.id));
      const from = list.indexOf(String(dragQId));
      const to = list.indexOf(String(targetId));
      if (from < 0 || to < 0) return;

      list.splice(to, 0, list.splice(from, 1)[0]);

      if (searchTerm){
        alert('Matikan filter/cari dulu sebelum reorder agar urutan global aman.');
        return;
      }

      await persistOrder(API.qorder, {
        csrf_token: CSRF,
        quiz_id: QUIZ_ID,
        ordered_ids: list.map(Number),
        order_revision: questionOrderRevision
      }, loadQuestions, 'Urutan soal tersimpan');
    });
  }

  // ===== DRAG & DROP REORDER (ANSWERS)
  let dragAId = null;

  async function moveAnswerByStep(answerId, delta){
    if (orderSaving || !activeQ || activeQ.can_mutate !== true) return;
    const ids = (activeQ.answers || []).map(answer=>Number(answer.id));
    const from = ids.indexOf(answerId);
    const to = from + delta;
    if (from < 0 || to < 0 || to >= ids.length) return;
    ids.splice(to, 0, ids.splice(from, 1)[0]);
    const questionId = Number(activeQ.id);
    await flushPendingAnswerSaves();
    await persistOrder(API.aorder, {
      csrf_token: CSRF,
      quiz_id: QUIZ_ID,
      question_id: questionId,
      ordered_ids: ids,
      order_revision: String(activeQ.answer_order_revision || '')
    }, ()=>selectQuestion(questionId), 'Urutan jawaban tersimpan');
    await refreshCounts();
  }

  function attachDnDAnswer(node){
    node.addEventListener('dragstart', (e)=>{
      dragAId = node.dataset.id;
      e.dataTransfer.effectAllowed = 'move';
    });
    node.addEventListener('dragover', (e)=>{
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    node.addEventListener('drop', async (e)=>{
      e.preventDefault();
      if (!activeQ) return;
      const targetId = node.dataset.id;
      if (!dragAId || dragAId === targetId) return;

      const ids = (activeQ.answers||[]).map(x=>String(x.id));
      const from = ids.indexOf(String(dragAId));
      const to = ids.indexOf(String(targetId));
      if (from < 0 || to < 0) return;

      ids.splice(to, 0, ids.splice(from, 1)[0]);

      const questionId = activeQ.id;
      await flushPendingAnswerSaves();
      await persistOrder(API.aorder, {
        csrf_token: CSRF,
        quiz_id: QUIZ_ID,
        question_id: questionId,
        ordered_ids: ids.map(Number),
        order_revision: String(activeQ.answer_order_revision || '')
      }, ()=>selectQuestion(questionId), 'Urutan jawaban tersimpan');
      await refreshCounts();
    });
  }

  // ===== SEARCH
  let searchTimer = null;
  if (qSearch){
    qSearch.addEventListener('input', ()=>{
      clearTimeout(searchTimer);
      searchTimer = setTimeout(()=>{
        searchTerm = qSearch.value.trim();
        renderList();
      }, 150);
    });
  }

  // ===== BULK MODAL
  const bulkModal = el('bulkModal');
  const bulkClose = el('bulkClose');
  const bulkText = el('bulkText');
  const bulkPreview = el('bulkPreview');
  const bulkPreviewBtn = el('bulkPreviewBtn');
  const bulkImportBtn = el('bulkImportBtn');
  const btnBulk = el('btnBulk');

  const bulkStrict = el('bulkStrict');
  const bulkDefaultCategory = el('bulkDefaultCategory');
  const bulkDefaultPassage  = el('bulkDefaultPassage');
  const bulkClearBtn = el('bulkClearBtn');

  const bulkStat = el('bulkStat');
  const bulkWarn = el('bulkWarn');
  const bulkErrors = el('bulkErrors');

  let bulkLastFocus = null;
  let bulkTypingTimer = null;

  function renderBulkDefaults(){
    if (bulkDefaultCategory){
      const cur = String(bulkDefaultCategory.value || '');
      bulkDefaultCategory.innerHTML = '<option value="">(Tidak ada)</option>';
      (categories || []).forEach(c=>{
        const opt = document.createElement('option');
        opt.value = String(c.id);
        opt.textContent = categoryTitleText(c);
        bulkDefaultCategory.appendChild(opt);
      });
      bulkDefaultCategory.value = cur;
    }

    if (bulkDefaultPassage){
      const cur = String(bulkDefaultPassage.value || '');
      bulkDefaultPassage.innerHTML = '<option value="">(Tidak ada)</option>';
      (passages || []).forEach(p=>{
        const opt = document.createElement('option');
        opt.value = String(p.id);
        opt.textContent = passageTitleText(p);
        bulkDefaultPassage.appendChild(opt);
      });
      bulkDefaultPassage.value = cur;
    }
  }

  function parseBulkDetailed(text){
    const raw = String(text || '');
    const blocks = raw.split(/\n\s*\n+/).map(b=>b.trim()).filter(Boolean);

    const items = [];
    const warns = [];
    const errs = [];

    function parseDirective(line){
      const m = line.match(/^@?\s*([a-z_]+)\s*[:=]\s*(.+)\s*$/i);
      if (!m) return null;
      return { key: String(m[1]||'').toLowerCase(), val: String(m[2]||'').trim() };
    }

    function asIntOrNull(v){
      const n = parseInt(String(v||'').trim(), 10);
      return Number.isFinite(n) && n > 0 ? n : null;
    }

    for (let bi=0; bi<blocks.length; bi++){
      const b = blocks[bi];
      const lines = b.split('\n').map(x=>String(x).trim()).filter(Boolean);
      if (!lines.length) continue;

      const qText = lines[0];
      if (!qText) { errs.push(`Blok #${bi+1}: soal kosong`); continue; }

      const answers = [];
      const q = {
        question_text: qText,
        question_type: 'single_choice',
        is_active: 1,
        answers: [],
        image_media_id: null,
        file_id: null,
        youtube_url: null,
        category_id: null,
        category_name: null,
        passage_id: null,
        passage_title: null,
        passage_seq: null
      };

      for (let i=1;i<lines.length;i++){
        const rawLine = lines[i];

        const d = parseDirective(rawLine);
        if (d){
          const k = d.key;
          const v = d.val;

          if (k === 'img' || k === 'image'){
            const id = asIntOrNull(v);
            if (!id) warns.push(`Blok #${bi+1}: @img invalid (${v})`);
            q.image_media_id = id;
            continue;
          }
          if (k === 'file'){
            const id = asIntOrNull(v);
            if (!id) warns.push(`Blok #${bi+1}: @file invalid (${v})`);
            q.file_id = id;
            continue;
          }
          if (k === 'yt' || k === 'youtube'){
            q.youtube_url = v || null;
            continue;
          }
          if (k === 'cat' || k === 'category'){
            const id = asIntOrNull(v);
            if (id) q.category_id = id;
            else q.category_name = v || null;
            continue;
          }
          if (k === 'passage' || k === 'p'){
            const id = asIntOrNull(v);
            if (id) q.passage_id = id;
            else q.passage_title = v || null;
            continue;
          }
          if (k === 'seq' || k === 'passage_seq'){
            const n = asIntOrNull(v);
            q.passage_seq = n;
            continue;
          }
          if (k === 'type'){
            const t = String(v||'').trim();
            if (allowedTypes.includes(t)) q.question_type = t;
            else warns.push(`Blok #${bi+1}: @type tidak dikenal (${v})`);
            continue;
          }

          continue;
        }

        const line = rawLine.replace(/^[\-\•]\s*/, '').trim();
        if (!line) continue;

        const parts = line.split('|').map(x=>x.trim());
        const aText = parts[0] || '';
        if (!aText) continue;

        let score = 0;
        if (parts.length > 1 && parts[1] !== '' && !isNaN(Number(parts[1]))) score = Number(parts[1]);
        score = Math.max(0, Math.min(100, score));

        answers.push({answer_text:aText, score, is_active:1});
      }

      q.answers = answers;

      if (!q.question_type || q.question_type === 'single_choice'){
        const lower = answers.map(a => String(a.answer_text||'').toLowerCase());
        const isYesNo =
          answers.length === 2 &&
          lower.includes('ya') && lower.includes('tidak');

        if (isYesNo) q.question_type = 'yesno';
        else if (answers.length === 0) q.question_type = 'text';
        else q.question_type = 'single_choice';
      }

      if (answers.length === 0 && (q.question_type === 'single_choice' || q.question_type === 'multiple_choice' || q.question_type === 'yesno')){
        warns.push(`Blok #${bi+1}: tidak ada jawaban, tipe ${q.question_type} (server tetap bisa simpan, tapi mungkin bukan yang kamu mau)`);
      }

      items.push(q);
    }

    return { items, warns, errs };
  }

  function applyBulkDefaults(items){
    const defCat = bulkDefaultCategory ? String(bulkDefaultCategory.value||'').trim() : '';
    const defPass = bulkDefaultPassage ? String(bulkDefaultPassage.value||'').trim() : '';

    return (items || []).map(q=>{
      const qq = Object.assign({}, q);

      if (!qq.passage_id && !qq.passage_title && defPass){
        qq.passage_id = parseInt(defPass, 10) || null;
      }

      const hasPass = !!(qq.passage_id || (qq.passage_title && String(qq.passage_title).trim() !== ''));
      if (!hasPass && !qq.category_id && !qq.category_name && defCat){
        qq.category_id = parseInt(defCat, 10) || null;
      }

      if (qq.category_id) qq.question_category_id = qq.category_id;

      return qq;
    });
  }

  function updateBulkUI(){
    if (!bulkText) return;

    const parsed = parseBulkDetailed(bulkText.value);
    const finalItems = applyBulkDefaults(parsed.items);

    const totalQ = finalItems.length;
    const totalA = finalItems.reduce((s,q)=>s + ((q.answers||[]).length||0), 0);
    const totalAtt = finalItems.reduce((s,q)=>{
      let c = 0;
      if (q.image_media_id) c++;
      if (q.file_id) c++;
      if (q.youtube_url) c++;
      return s + c;
    }, 0);

    if (bulkStat){
      const spans = bulkStat.querySelectorAll('span b');
      if (spans[0]) spans[0].textContent = String(totalQ);
      if (spans[1]) spans[1].textContent = String(totalA);
      if (spans[2]) spans[2].textContent = String(totalAtt);
    }

    if (bulkWarn){
      const w = [];
      if (parsed.warns.length) w.push(`${parsed.warns.length} warning`);
      if (parsed.errs.length) w.push(`${parsed.errs.length} error`);
      bulkWarn.textContent = w.length ? ('· ' + w.join(' · ')) : '';
    }

    if (bulkPreview){
      if (!totalQ){
        bulkPreview.innerHTML = '<div class="muted">Belum ada data.</div>';
      } else {
        const lis = finalItems.slice(0, 10).map((q,i)=>{
          const chips = [];
          chips.push(`<span class="chip">${escapeHTML(q.question_type||'')}</span>`);
          chips.push(`<span class="chip" title="Jawaban">${Number((q.answers||[]).length||0)} ans</span>`);

          if (q.passage_id || q.passage_title){
            chips.push(`<span class="chip passage" title="Passage">📜</span>`);
          } else if (q.category_id || q.category_name){
            chips.push(`<span class="chip" title="Kategori">🏷</span>`);
          }

          if (q.image_media_id) chips.push(`<span class="chip" title="Gambar">🖼</span>`);
          if (q.file_id) chips.push(`<span class="chip" title="File">📎</span>`);
          if (q.youtube_url) chips.push(`<span class="chip" title="YouTube">▶</span>`);

          return `<li>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <span>${escapeHTML(cutText(q.question_text, 90))}</span>
              ${chips.join('')}
            </div>
          </li>`;
        }).join('');

        bulkPreview.innerHTML = `
          <div class="muted" style="margin-bottom:8px">Menampilkan ${Math.min(10,totalQ)} dari ${totalQ} soal.</div>
          <ol class="bulk-preview-list">${lis}</ol>
          ${totalQ>10 ? '<div class="muted" style="margin-top:8px">… dan lainnya</div>' : ''}
        `;
      }
    }

    if (bulkErrors){
      const parts = [];
      if (parsed.errs.length){
        parts.push(`<div class="bulk-err"><b>Error:</b><ul>${parsed.errs.slice(0,8).map(x=>`<li>${escapeHTML(x)}</li>`).join('')}</ul></div>`);
        if (parsed.errs.length>8) parts.push(`<div class="muted">… ${parsed.errs.length-8} error lainnya</div>`);
      }
      if (parsed.warns.length){
        parts.push(`<div class="bulk-warn"><b>Warning:</b><ul>${parsed.warns.slice(0,8).map(x=>`<li>${escapeHTML(x)}</li>`).join('')}</ul></div>`);
        if (parsed.warns.length>8) parts.push(`<div class="muted">… ${parsed.warns.length-8} warning lainnya</div>`);
      }
      bulkErrors.innerHTML = parts.length ? parts.join('') : '<div class="muted">—</div>';
    }

    if (bulkImportBtn){
      bulkImportBtn.disabled = (totalQ === 0) || (parsed.errs.length > 0);
    }

    return { finalItems, parsed };
  }

  function openBulk(){
    if (!bulkModal) return;
    bulkLastFocus = document.activeElement;

    renderBulkDefaults();

    bulkModal.classList.add('active');
    bulkModal.classList.add('is-open');
    bulkModal.setAttribute('aria-hidden','false');

    if (bulkText) bulkText.value = '';
    if (bulkPreview) bulkPreview.innerHTML = '<div class="muted">Belum ada data.</div>';
    if (bulkErrors) bulkErrors.innerHTML = '<div class="muted">—</div>';
    if (bulkWarn) bulkWarn.textContent = '';
    if (bulkImportBtn) bulkImportBtn.disabled = true;

    setTimeout(()=>{ try{ bulkText && bulkText.focus(); }catch(e){} }, 0);
  }

  function closeBulk(){
    if (!bulkModal) return;

    bulkModal.classList.remove('active');
    bulkModal.classList.remove('is-open');
    bulkModal.setAttribute('aria-hidden','true');

    try{
      if (bulkLastFocus && typeof bulkLastFocus.focus === 'function') bulkLastFocus.focus();
    }catch(e){}
    bulkLastFocus = null;
  }

  function onBulkKeydown(e){
    if (!bulkModal || !bulkModal.classList.contains('active')) return;

    if (e.key === 'Escape'){
      e.preventDefault();
      closeBulk();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter'){
      e.preventDefault();
      if (bulkImportBtn && !bulkImportBtn.disabled) bulkImportBtn.click();
    }
  }

  document.addEventListener('keydown', onBulkKeydown);

  if (btnBulk) btnBulk.onclick = openBulk;
  if (bulkClose) bulkClose.onclick = closeBulk;
  if (bulkModal) bulkModal.addEventListener('click', (e)=>{ if (e.target === bulkModal) closeBulk(); });

  if (bulkClearBtn) bulkClearBtn.onclick = ()=>{
    if (bulkText) bulkText.value = '';
    updateBulkUI();
    try{ bulkText && bulkText.focus(); }catch(e){}
  };

  if (bulkStrict) bulkStrict.addEventListener('change', updateBulkUI);
  if (bulkDefaultCategory) bulkDefaultCategory.addEventListener('change', updateBulkUI);
  if (bulkDefaultPassage) bulkDefaultPassage.addEventListener('change', updateBulkUI);

  if (bulkText){
    bulkText.addEventListener('input', ()=>{
      clearTimeout(bulkTypingTimer);
      bulkTypingTimer = setTimeout(updateBulkUI, 220);
    });
  }

  if (bulkPreviewBtn) bulkPreviewBtn.onclick = ()=>{ updateBulkUI(); };

  if (bulkImportBtn) bulkImportBtn.onclick = async ()=>{
    const res = updateBulkUI();
    const items = (res && res.finalItems) ? res.finalItems : [];

    if (!items.length) { alert('Tidak ada data untuk diimport'); return; }
    if (!(await NewNotifConfirm.warning({message:`Import ${items.length} soal?`}))) return;

    const strictVal = bulkStrict ? Number(bulkStrict.value||1) : 1;

    bulkImportBtn.disabled = true;
    if (bulkPreviewBtn) bulkPreviewBtn.disabled = true;
    showToast('Import berjalan...');

    try{
      await fetchJSON(API.bulk, {
        csrf_token: CSRF,
        quiz_id: QUIZ_ID,
        strict: strictVal,
        questions: items
      });

      showToast('Import selesai');
      closeBulk();
      await loadQuestions();
    } finally {
      if (bulkPreviewBtn) bulkPreviewBtn.disabled = false;
      bulkImportBtn.disabled = false;
    }
  };

  // ===== Attachments events (question)
  if (btnPickImage) btnPickImage.onclick = async ()=>{
    if (!activeQ) return;
    const d = await openSelector({ url: MODAL.media, eventName:'media:insert', messageType:'media:insert', maxWidth:'980px' });
    const m = normalizeMedia(d);
    if (!m || !m.id) return;
    setImage(m.id, m.url || '', false);
  };
  if (btnClearImage) btnClearImage.onclick = ()=>{ if (!activeQ) return; setImage(null, '', false); };

  if (btnPickFile) btnPickFile.onclick = async ()=>{
    if (!activeQ) return;
    const d = await openSelector({ url: MODAL.file, eventName:'file:insert', messageType:'file:insert', maxWidth:'980px' });
    const f = normalizeFile(d);
    if (!f || !f.id) return;
    setFile(f.id, f.label || ('File #' + f.id), false);
  };
  if (btnClearFile) btnClearFile.onclick = ()=>{ if (!activeQ) return; setFile(null, '', false); };

  if (youtubeUrl){
    youtubeUrl.addEventListener('input', ()=>{ syncQuestionAttachments(); scheduleSaveQuestion(); });
    youtubeUrl.addEventListener('change', ()=>{ syncQuestionAttachments(); scheduleSaveQuestion(); });
  }

  // ===== Passage events (question editor)
  if (btnPassageManage) btnPassageManage.onclick = (e)=>{ e.preventDefault(); openPassageModal(); };

  if (passageSelect) {
    passageSelect.addEventListener('change', ()=>{
      if (!activeQ) return;

      const v = String(passageSelect.value || '').trim();
      if (!v) {
        if (passageSeq) passageSeq.value = '';
      }

      syncQuestionCategoryUIFromPassage(false);

      scheduleSaveQuestion();
      renderList();
    });
  }
  if (passageSeq) {
    passageSeq.addEventListener('input', ()=>{
      if (!activeQ) return;
      scheduleSaveQuestion();
    });
  }

  // ===== event kategori non-passage
  bindQuestionCategoryUI();
  if (btnQCategoryManage) btnQCategoryManage.onclick = (e)=>{ e.preventDefault(); openCategoryModal(); };
  if (qCategorySelect) {
    qCategorySelect.addEventListener('change', ()=>{
      if (!activeQ) return;
      const hasPassage = !!(passageSelect && String(passageSelect.value || '').trim());
      if (hasPassage) {
        qCategorySelect.value = '';
        return;
      }
      scheduleSaveQuestion();
      renderList();
    });
  }

  // ===== Passage modal events
  if (passageClose) passageClose.onclick = (e)=>{ e.preventDefault(); closePassageModal(); };
  if (passageModal) passageModal.addEventListener('click', (e)=>{ if (e.target === passageModal) closePassageModal(); });

  if (btnPassageAdd) btnPassageAdd.onclick = (e)=>{
    e.preventDefault();
    clearPassageEditor(true);
    setTimeout(()=>{ try{ if (passageTitle) passageTitle.focus(); }catch(_){ } }, 0);
  };

  if (categorySelect) {
    categorySelect.addEventListener('change', ()=>{
      if (!activeP) activeP = { id: 0, can_mutate: true };
      activeP.category_id = categorySelect.value ? Number(categorySelect.value) : null;
      updatePassageButtons();
    });
  }
  if (btnCategoryManage) btnCategoryManage.onclick = (e)=>{ e.preventDefault(); openCategoryModal(); };

  if (passageTitle) passageTitle.addEventListener('input', updatePassageButtons);
  if (passageShuffle) passageShuffle.addEventListener('change', updatePassageButtons);
  if (passageActive) passageActive.addEventListener('change', updatePassageButtons);

  if (passageMinQuestions) passageMinQuestions.addEventListener('input', ()=>{ if (!activeP) activeP={id:0, can_mutate:true}; updatePassageButtons(); });
  if (passageMaxQuestions) passageMaxQuestions.addEventListener('input', ()=>{ if (!activeP) activeP={id:0, can_mutate:true}; updatePassageButtons(); });

  if (passageText) {
    passageText.addEventListener('input', ()=>{
      renderMDWithMath(passageText.value, passagePreview);
      if (!activeP) activeP = { id: 0, can_mutate: true };
      updatePassageButtons();
    });
    passageText.addEventListener('change', ()=>{
      if (!activeP) activeP = { id: 0, can_mutate: true };
      updatePassageButtons();
    });
  }

  if (passageYoutubeUrl) {
    passageYoutubeUrl.addEventListener('input', ()=>{
      if (!activeP) activeP = { id: 0, can_mutate: true };
      updatePassageButtons();
    });
  }

  if (btnPassageSave) btnPassageSave.onclick = (e)=> {
    e.preventDefault();
    e.stopPropagation();
    if (btnPassageSave.disabled) return;
    savePassage().catch(err=>{
      console.error(err);
      passageSaving = false;
      updatePassageButtons();
      alert('Gagal simpan passage: ' + (err.message || 'error'));
    });
  };

  if (btnPassageDelete) btnPassageDelete.onclick = (e)=> {
    e.preventDefault();
    e.stopPropagation();
    if (btnPassageDelete.disabled) return;
    deletePassage().catch(err=>{
      console.error(err);
      passageSaving = false;
      updatePassageButtons();
      alert('Gagal hapus passage: ' + (err.message || 'error'));
    });
  };

  if (btnPassagePickImage) btnPassagePickImage.onclick = async (e)=>{
    e.preventDefault();
    if (!activeP) activeP = { id: 0, can_mutate: true };
    const d = await openSelector({ url: MODAL.media, eventName:'media:insert', messageType:'media:insert', maxWidth:'980px' });
    const m = normalizeMedia(d);
    if (!m || !m.id) return;
    setPassageImage(m.id, m.url || '');
  };
  if (btnPassageClearImage) btnPassageClearImage.onclick = (e)=>{ e.preventDefault(); if (!activeP) activeP = { id: 0, can_mutate: true }; setPassageImage(null, ''); };

  if (btnPassagePickFile) btnPassagePickFile.onclick = async (e)=>{
    e.preventDefault();
    if (!activeP) activeP = { id: 0, can_mutate: true };
    const d = await openSelector({ url: MODAL.file, eventName:'file:insert', messageType:'file:insert', maxWidth:'980px' });
    const f = normalizeFile(d);
    if (!f || !f.id) return;
    setPassageFile(f.id, f.label || ('File #' + f.id));
  };
  if (btnPassageClearFile) btnPassageClearFile.onclick = (e)=>{ e.preventDefault(); if (!activeP) activeP = { id: 0, can_mutate: true }; setPassageFile(null, ''); };

  // ===== Category modal events
  if (categoryClose) categoryClose.onclick = (e)=>{ e.preventDefault(); closeCategoryModal(); };
  if (categoryModal) categoryModal.addEventListener('click', (e)=>{ if (e.target === categoryModal) closeCategoryModal(); });

  if (btnCategoryAdd) btnCategoryAdd.onclick = (e)=>{
    e.preventDefault();
    clearCategoryEditor(true);
    setTimeout(()=>{ try{ if (categoryName) categoryName.focus(); }catch(_){ } }, 0);
  };

  if (categoryName) categoryName.addEventListener('input', ()=>{ if (!activeC) activeC={id:0, can_mutate:true}; updateCategoryButtons(); });
  if (categoryDesc) categoryDesc.addEventListener('input', ()=>{ if (!activeC) activeC={id:0, can_mutate:true}; updateCategoryButtons(); });
  if (categoryShufflePassages) categoryShufflePassages.addEventListener('change', ()=>{ if (!activeC) activeC={id:0, can_mutate:true}; updateCategoryButtons(); });
  if (categoryShuffleQuestions) categoryShuffleQuestions.addEventListener('change', ()=>{ if (!activeC) activeC={id:0, can_mutate:true}; updateCategoryButtons(); });
  if (categoryActive) categoryActive.addEventListener('change', ()=>{ if (!activeC) activeC={id:0, can_mutate:true}; updateCategoryButtons(); });
  if (categoryMaxQuestions) categoryMaxQuestions.addEventListener('input', ()=>{ if (!activeC) activeC={id:0, can_mutate:true}; updateCategoryButtons(); });
  if (categoryMinQuestions) categoryMinQuestions.addEventListener('input', ()=>{ if (!activeC) activeC={id:0, can_mutate:true}; updateCategoryButtons(); });

  if (btnCategorySave) btnCategorySave.onclick = (e)=>{
    e.preventDefault();
    e.stopPropagation();
    if (btnCategorySave.disabled) return;
    saveCategory().catch(err=>{
      console.error(err);
      categorySaving = false;
      updateCategoryButtons();
      alert('Gagal simpan kategori: ' + (err.message || 'error'));
    });
  };

  if (btnCategoryDelete) btnCategoryDelete.onclick = (e)=>{
    e.preventDefault();
    e.stopPropagation();
    if (btnCategoryDelete.disabled) return;
    deleteCategory().catch(err=>{
      console.error(err);
      categorySaving = false;
      updateCategoryButtons();
      alert('Gagal hapus kategori: ' + (err.message || 'error'));
    });
  };

  // ===== EVENTS (question)
  if (btnAddQ) btnAddQ.onclick = addQuestion;
  if (btnDeleteQ) btnDeleteQ.onclick = deleteQuestion;
  if (btnYesNo) btnYesNo.onclick = generateYesNo;
  if (btnAddA) btnAddA.onclick = addAnswer;
  if (btnDuplicate) btnDuplicate.onclick = duplicateQuestion;

  if (questionText){
    questionText.addEventListener('input', ()=>{
      renderMDWithMath(questionText.value, questionPreview);
      scheduleSaveQuestion();
    });
  }
  if (questionType) questionType.addEventListener('change', scheduleSaveQuestion);
  if (questionActive) questionActive.addEventListener('change', scheduleSaveQuestion);

  // ===== Position input (move question to global position)
  let posCommitTimer = null;
  if (questionPos){
    const commitPos = ()=>{
      if (!activeQ) return;

      const raw = String(questionPos.value || '').trim();
      if (!raw) { syncEditorPositionUI(); return; }

      clearTimeout(posCommitTimer);
      posCommitTimer = setTimeout(()=>{
        moveQuestionToPosition(activeQ.id, raw).catch(err=>{
          console.error(err);
          showToast('Gagal pindah posisi');
          syncEditorPositionUI();
        });
      }, 0);
    };

    questionPos.addEventListener('change', commitPos);
    questionPos.addEventListener('blur', commitPos);

    questionPos.addEventListener('keydown', (e)=>{
      if (e.key === 'Enter'){
        e.preventDefault();
        commitPos();
      }
      if (e.key === 'Escape'){
        e.preventDefault();
        syncEditorPositionUI();
        questionPos.blur();
      }
    });
  }

  // ===== init
  Promise.all([loadCategories(), loadPassages(), loadQuestions()]).catch(err=>{
    console.error(err);
    alert('Gagal init: ' + err.message);
  });

})();
</script>
