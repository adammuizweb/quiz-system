<?php
declare(strict_types=1);
defined('DASHBOARD_CONTEXT') || exit;
require __DIR__ . '/../_init.php';

$base = ADMIN_BASE_PATH;
$id = (int)($_GET['id'] ?? 0);
$quiz_id = (int)($_GET['quiz_id'] ?? 0);
if ($id <= 0 || $quiz_id <= 0) { http_response_code(400); exit('Missing id/quiz_id'); }
$quiz = qz_require_quiz_access($pdo, $quiz_id);
qz_require_passage_access($pdo, $quiz_id, $id);

function is_valid_youtube_url(string $url): bool {
  if ($url === '') return true;
  if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  return $host !== '' && in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','youtu.be'], true);
}
function fetch_media_url(\PDO $pdo, int $id): string {
  $st = $pdo->prepare("SELECT url FROM media WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' LIMIT 1");
  $st->execute([':id'=>$id]);
  $u = $st->fetchColumn();
  return $u ? (string)$u : '';
}
function fetch_file_label(\PDO $pdo, int $id): string {
  $st = $pdo->prepare("SELECT filename, title FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1");
  $st->execute([':id'=>$id]);
  $r = $st->fetch(\PDO::FETCH_ASSOC);
  if (!$r) return '';
  return (string)($r['filename'] ?? $r['name'] ?? $r['title'] ?? ('File #'.$id));
}

$stmt = $pdo->prepare("SELECT p.*, q.title AS quiz_title FROM question_passages p JOIN quizzes q ON q.id=p.quiz_id WHERE p.id=:id AND p.quiz_id=:quiz_id LIMIT 1");
$stmt->execute([':id'=>$id,':quiz_id'=>$quiz_id]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Passage not found / no access'); }
$user = qz_admin_user($pdo);
if (!qz_can_mutate_resource($pdo, (int)$user['id'], $quiz, $row)) { http_response_code(403); exit('Passage is read-only.'); }

$catStmt = $pdo->prepare("SELECT id, name FROM question_categories WHERE quiz_id=:qid ORDER BY CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END, sort_order ASC, id ASC");
$catStmt->execute([':qid'=>$quiz_id]);
$categories = $catStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$errors = [];
$title = (string)($row['title'] ?? '');
$passage_text = (string)($row['passage_text'] ?? '');
$category_id = isset($row['category_id']) && (int)$row['category_id'] > 0 ? (int)$row['category_id'] : null;
$shuffle_questions = (int)($row['shuffle_questions'] ?? 1) ? 1 : 0;
$min_questions = isset($row['min_questions']) ? (string)(int)$row['min_questions'] : '';
if ($min_questions === '0') $min_questions = '';
$max_questions = ($row['max_questions'] !== null) ? (string)$row['max_questions'] : '';
$is_active = (int)($row['is_active'] ?? 1) ? 1 : 0;
$image_media_id = isset($row['image_media_id']) && (int)$row['image_media_id'] > 0 ? (int)$row['image_media_id'] : null;
$image_preview_url = $image_media_id ? fetch_media_url($pdo, $image_media_id) : '';
$file_id = isset($row['file_id']) && (int)$row['file_id'] > 0 ? (int)$row['file_id'] : null;
$file_label = $file_id ? fetch_file_label($pdo, $file_id) : '';
$youtube_url = trim((string)($row['youtube_url'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) $errors[] = 'CSRF token tidak valid.';
  $title = trim((string)($_POST['title'] ?? ''));
  $passage_text = (string)($_POST['passage_text'] ?? '');
  $shuffle_questions = (int)($_POST['shuffle_questions'] ?? 1) ? 1 : 0;
  $is_active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;
  $min_questions = trim((string)($_POST['min_questions'] ?? ''));
  $minVal = 0;
  if ($min_questions !== '') {
    if (!ctype_digit($min_questions)) $errors[] = 'Min questions harus angka (>= 0).';
    else { $minVal = (int)$min_questions; if ($minVal < 0) $errors[] = 'Min tidak boleh negatif.'; if ($minVal > 5000) $errors[] = 'Min terlalu besar.'; }
  }
  $max_questions = trim((string)($_POST['max_questions'] ?? ''));
  if ($max_questions !== '' && !ctype_digit($max_questions)) $errors[] = 'Max questions harus angka.';
  if ($max_questions !== '' && (int)$max_questions < 1) $errors[] = 'Max questions minimal 1.';
  $maxVal = null; if ($max_questions !== '') $maxVal = (int)$max_questions;
  if ($maxVal !== null && $minVal > $maxVal) $errors[] = 'Min tidak boleh > Max.';
  $raw_cat = trim((string)($_POST['category_id'] ?? ''));
  if ($raw_cat === '') $category_id = null;
  elseif (ctype_digit($raw_cat)) $category_id = (int)$raw_cat;
  else $errors[] = 'Kategori tidak valid.';
  $raw_mid = trim((string)($_POST['image_media_id'] ?? ''));
  if ($raw_mid === '') { $image_media_id = null; $image_preview_url = ''; }
  elseif (ctype_digit($raw_mid)) $image_media_id = (int)$raw_mid;
  else $errors[] = 'Gambar tidak valid.';
  $raw_fid = trim((string)($_POST['file_id'] ?? ''));
  if ($raw_fid === '') { $file_id = null; $file_label = ''; }
  elseif (ctype_digit($raw_fid)) $file_id = (int)$raw_fid;
  else $errors[] = 'File tidak valid.';
  $youtube_url = trim((string)($_POST['youtube_url'] ?? ''));
  if (!is_valid_youtube_url($youtube_url)) $errors[] = 'Link YouTube tidak valid.';
  if ($passage_text === '') $errors[] = 'Passage text wajib diisi.';
  if ($category_id !== null && $category_id > 0) {
    $st = $pdo->prepare("SELECT id FROM question_categories WHERE id=:id AND quiz_id=:qid LIMIT 1");
    $st->execute([':id'=>$category_id,':qid'=>$quiz_id]);
    if (!$st->fetchColumn()) { $errors[] = 'Kategori tidak ditemukan.'; $category_id = null; }
  }
  if ($image_media_id !== null && $image_media_id > 0) {
    $st = $pdo->prepare("SELECT id, url FROM media WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' LIMIT 1");
    $st->execute([':id'=>$image_media_id]);
    $mrow = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$mrow) { $errors[] = 'Media tidak ditemukan.'; $image_media_id = null; $image_preview_url = ''; }
    else $image_preview_url = (string)($mrow['url'] ?? '');
  }
  if ($file_id !== null && $file_id > 0) {
    $st = $pdo->prepare("SELECT id, filename, title FROM `file` WHERE id=:id AND visibility='public' AND storage_disk='public' AND access_scope='public' AND is_downloadable=1 LIMIT 1");
    $st->execute([':id'=>$file_id]);
    $frow = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$frow) { $errors[] = 'File tidak ditemukan.'; $file_id = null; $file_label = ''; }
    else $file_label = (string)($frow['filename'] ?? $frow['name'] ?? $frow['title'] ?? ('File #'.$file_id));
  }
  if (!$errors) {
    try {
      $pdo->beginTransaction();
      qz_lock_passage_for_mutation($pdo, $quiz_id, $id, (int)$user['id']);
      $stmt = $pdo->prepare("UPDATE question_passages SET category_id=:catid,title=:title,passage_text=:pt,image_media_id=:mid,file_id=:fid,youtube_url=:yt,shuffle_questions=:shuf,min_questions=:minq,max_questions=:maxq,is_active=:act WHERE id=:id AND quiz_id=:quiz_id LIMIT 1");
      $stmt->execute([':catid'=>$category_id,':title'=>$title,':pt'=>$passage_text,':mid'=>$image_media_id,':fid'=>$file_id,':yt'=>($youtube_url===''?null:$youtube_url),':shuf'=>$shuffle_questions,':minq'=>$minVal,':maxq'=>($max_questions===''?null:(int)$max_questions),':act'=>$is_active,':id'=>$id,':quiz_id'=>$quiz_id]);
      $pdo->commit();
      qz_redirect("$base/?page=admin/quiz/passages/index&quiz_id=$quiz_id&msg=".urlencode("Passage berhasil diperbarui."));
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Passage gagal disimpan atau akses sudah tidak berlaku.';
    }
  }
}

$catManageUrl = "$base/?page=admin/quiz/categories/index&quiz_id=$quiz_id";
$backUrl = "$base/?page=admin/quiz/passages/index&quiz_id=$quiz_id";
$masterUrl = "$base/?page=admin/quiz/master&quiz_id=$quiz_id";
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/contrib/auto-render.min.js"></script>

<section class="adam-card qz-card qz-editor-page">
  <?php qz_admin_breadcrumb([['label'=>'Master Builder','url'=>$base.'/?page=admin/quiz/master'],['label'=>(string)$row['quiz_title'],'url'=>$masterUrl],['label'=>'Passage','url'=>$backUrl],['label'=>'Edit']]); ?>
  <div class="qz-head"><div><div class="qz-kicker">Bank soal</div><h2 style="margin:0">Edit Passage</h2><p class="qz-context"><?=h((string)$row['quiz_title'])?> · Passage #<?=$id?></p></div><div class="qz-toolbar"><a class="adam-button ghost" href="<?=h($backUrl)?>">Daftar Passage</a><a class="adam-button ghost" href="<?=h($masterUrl)?>">Master Builder</a></div></div>
  <?php if ($errors):?><div class="adam-alert error"><?php foreach($errors as $e):?><div><?=h($e)?></div><?php endforeach;?></div><?php endif;?>
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="image_media_id" id="inp_image_media_id" value="<?=h((string)($image_media_id ?? ''))?>">
    <input type="hidden" name="file_id" id="inp_file_id" value="<?=h((string)($file_id ?? ''))?>">
    <div class="group-box" style="margin-bottom:1rem">
      <div style="display:grid;gap:.5rem"><label><strong>Judul (opsional)</strong></label><input class="adam-input" name="title" value="<?=h($title)?>" placeholder="mis. Bacaan 1 / Stimulus A"></div>
      <div style="display:grid;gap:.5rem"><label><strong>Kategori (opsional)</strong></label><select class="adam-input" name="category_id"><option value="">(Tanpa kategori)</option><?php foreach($categories as $c):$cid=(int)$c['id'];$cn=trim((string)($c['name']??''));$sel=($category_id!==null&&$category_id===$cid)?'selected':'';?><option value="<?=$cid?>"<?=$sel?>><?=h($cn!==''?$cn:'Kategori #'.$cid)?></option><?php endforeach;?></select><div class="small-hint"><a href="<?=h($catManageUrl)?>" style="font-weight:900;text-decoration:none">Kelola kategori</a></div></div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
        <div style="display:grid;gap:.5rem"><label><strong>Shuffle questions</strong></label><select class="adam-input" name="shuffle_questions"><option value="1" <?=$shuffle_questions?'selected':''?>>Ya</option><option value="0">Tidak</option></select></div>
        <div style="display:grid;gap:.5rem"><label><strong>Min questions</strong> (opsional)</label><input class="adam-input" name="min_questions" value="<?=h($min_questions)?>" placeholder="0/kosong"><div class="small-hint">Min 0, tidak boleh > Max.</div></div>
        <div style="display:grid;gap:.5rem"><label><strong>Max questions</strong> (opsional)</label><input class="adam-input" name="max_questions" value="<?=h($max_questions)?>" placeholder="kosong = tak terbatas"><div class="small-hint">Jika diisi, minimal 1.</div></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div style="display:grid;gap:.5rem"><label><strong>Urutan saat ini</strong></label><div class="qz-order-note">#<?= (int)($row['sort_order'] ?? 0) ?> · Ubah melalui Master Builder agar tidak menimpa urutan editor lain.</div></div>
        <div style="display:grid;gap:.5rem"><label><strong>Status</strong></label><select class="adam-input" name="is_active"><option value="1" <?=$is_active?'selected':''?>>Aktif</option><option value="0">Nonaktif</option></select></div>
      </div>
    </div>
    <div style="display:grid;gap:.5rem;margin-bottom:1rem"><label><strong>Passage Text (Markdown + Math)</strong></label><textarea class="adam-input" id="passageText" name="passage_text" rows="10" required><?=h($passage_text)?></textarea><div class="small-hint">Math: <code>$x^2$</code> / <code>$$\frac{a}{b}$$</code> · Markdown: <code>**bold**</code>, <code>- list</code>, dll.</div><div class="md-preview" id="passagePreview"></div></div>
    <div style="margin:0 0 1rem 0"><label style="display:block;margin-bottom:.4rem"><strong>Gambar (opsional)</strong></label><div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><div class="attach-card" style="width:160px;height:100px;display:flex;align-items:center;justify-content:center"><img id="img_preview" src="<?=h($image_preview_url?:'')?>" alt="Preview" style="width:100%;height:100%;object-fit:cover;display:<?=$image_preview_url?'block':'none'?>"><div id="img_placeholder" class="muted" style="font-size:.9rem;display:<?=$image_preview_url?'none':'block'?>">Tidak ada gambar</div></div><div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap"><button type="button" class="adam-button" id="btn_pick_image">Pilih dari Media</button><button type="button" class="adam-button ghost" id="btn_clear_image">Clear</button><span class="muted">Media ID: <span id="txt_media_id"><?=$image_media_id?(int)$image_media_id:'-'?></span></span></div></div></div>
    <div style="margin:0 0 1rem 0"><label style="display:block;margin-bottom:.4rem"><strong>File (opsional)</strong></label><div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><div class="file-pill" style="min-width:260px;max-width:520px"><div style="font-weight:700" id="file_label"><?=h($file_label?:'Belum ada file')?></div><div class="muted">File ID: <span id="txt_file_id"><?=$file_id?(int)$file_id:'-'?></span></div></div><div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap"><button type="button" class="adam-button" id="btn_pick_file">Pilih File</button><button type="button" class="adam-button ghost" id="btn_clear_file">Clear</button></div></div></div>
    <div style="display:grid;gap:.5rem;margin-bottom:1rem"><label><strong>Link YouTube (opsional)</strong></label><input class="adam-input" name="youtube_url" value="<?=h($youtube_url)?>" placeholder="https://youtu.be/xxxx atau https://www.youtube.com/watch?v=xxxx"><div class="small-hint">Domain: youtube.com / youtu.be</div></div>
    <div style="margin-top:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap"><button class="adam-button" type="submit">Simpan Perubahan</button><a class="adam-button ghost" href="<?=h($backUrl)?>">Batal</a></div>
  </form>
</section>
<script>
(function(){
  const BASE = <?=json_encode($base)?>;
  function renderMDWithMath(md,targetEl){
    if(!targetEl)return;
    const raw=String(md||'');
    if(!window.marked||!window.DOMPurify){targetEl.textContent=raw;return;}
    let html='';
    try{html=marked.parse(raw,{mangle:false,headerIds:false});}catch(e){html='<pre>Markdown error</pre>';}
    const clean=DOMPurify.sanitize(html,{USE_PROFILES:{html:true}});
    targetEl.innerHTML=clean;
    if(typeof window.renderMathInElement==='function'){
      try{renderMathInElement(targetEl,{delimiters:[{left:"$$",right:"$$",display:true},{left:"$",right:"$",display:false},{left:"\\(",right:"\\)",display:false},{left:"\\[",right:"\\]",display:true}],throwOnError:false,strict:"ignore",ignoredTags:["script","noscript","style","textarea","pre","code"]});}catch(e){}
    }
  }
  const ta=document.getElementById('passageText'),pv=document.getElementById('passagePreview');
  if(ta&&pv){renderMDWithMath(ta.value,pv);ta.addEventListener('input',()=>renderMDWithMath(ta.value,pv));ta.addEventListener('change',()=>renderMDWithMath(ta.value,pv));}
  if(!window.injectHtmlWithScriptsTo){
    window.injectHtmlWithScriptsTo=function(container,html){
      try{
        const parser=new DOMParser(),doc=parser.parseFromString(html,'text/html');
        let bodyHtml='';
        Array.from(doc.body.childNodes).forEach(n=>{if(n.nodeName&&n.nodeName.toLowerCase()==='script')return;bodyHtml+=n.outerHTML||n.textContent;});
        container.innerHTML=bodyHtml;
        const scripts=doc.querySelectorAll('script'),externals=[];
        scripts.forEach(s=>{if(s.src)externals.push(s.src);});
        externals.forEach(src=>{if(!document.querySelector('script[src="'+src+'"]')){const el=document.createElement('script');el.src=src;el.async=false;document.head.appendChild(el);}});
        scripts.forEach(s=>{if(!s.src){const el=document.createElement('script');el.text=s.textContent;document.body.appendChild(el);}});
      }catch(err){console.error(err);if(container)container.innerHTML='<div style="color:#c00">Gagal memuat konten.</div>';}
    };
  }
  if(!window.adamModalOpen){
    window.adamModalOpen=function(url,opts){
      opts=opts||{};
      if(document.getElementById('adam-modal-backdrop'))return;
      const bg=getComputedStyle(document.documentElement).getPropertyValue('--adam-card').trim()||'#fff';const txt=getComputedStyle(document.documentElement).getPropertyValue('--adam-text').trim()||'#0f172a';
      const bd=document.createElement('div');bd.id='adam-modal-backdrop';bd.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px';
      const box=document.createElement('div');box.id='adam-modal-box';const mw=(opts&&opts.maxWidth)?opts.maxWidth:'980px';box.style.cssText='background:'+bg+';color:'+txt+';border-radius:10px;max-width:'+mw+';width:100%;max-height:90vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);position:relative';
      const close=document.createElement('button');close.type='button';close.innerText='×';close.style.cssText='position:absolute;right:10px;top:8px;border:0;background:transparent;font-size:20px;cursor:pointer';close.addEventListener('click',function(){window.adamModalClose();});box.appendChild(close);
      const content=document.createElement('div');content.id='adam-modal-content';content.style.padding='14px';box.appendChild(content);bd.appendChild(box);document.body.appendChild(bd);
      fetch(url,{credentials:'include'}).then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.text();}).then(html=>injectHtmlWithScriptsTo(content,html)).catch(err=>{content.innerHTML='<div style="color:#c00">Gagal memuat modal.</div>';console.error(err);});
      bd.addEventListener('click',function(ev){if(ev.target===bd)window.adamModalClose();});
      function onKey(e){if(e.key==='Escape')window.adamModalClose();}
      document.addEventListener('keydown',onKey);bd.__esc=onKey;
    };
    window.adamModalClose=function(){const bd=document.getElementById('adam-modal-backdrop');if(!bd)return;if(bd.__esc)document.removeEventListener('keydown',bd.__esc);bd.remove();};
  }
  function openSelector({url,eventName,messageType,maxWidth='980px'}){
    return new Promise((resolve)=>{
      let done=false;
      function cleanup(){document.removeEventListener(eventName,onEvent);window.removeEventListener('message',onMsg);}
      function finish(payload){if(done)return;done=true;cleanup();try{window.adamModalClose();}catch(e){}resolve(payload||null);}
      function onEvent(e){finish(e&&e.detail?e.detail:null);}
      function onMsg(ev){try{if(ev&&ev.data&&(ev.data.type===messageType||ev.data.type===eventName))finish(ev.data.detail||null);}catch(e){}}
      document.addEventListener(eventName,onEvent);window.addEventListener('message',onMsg);window.adamModalOpen(url,{maxWidth});
      const iv=setInterval(()=>{const bd=document.getElementById('adam-modal-backdrop');if(!bd){clearInterval(iv);if(!done)finish(null);}},250);
    });
  }
  function normalizeMedia(detail){if(!detail)return null;const m=(detail.media&&typeof detail.media==='object')?detail.media:detail;return{id:(m.id!=null)?(parseInt(m.id,10)||null):null,url:(m.url!=null)?String(m.url||''):''};}
  function normalizeFile(detail){if(!detail)return null;const f=(detail.file&&typeof detail.file==='object')?detail.file:detail;const id=(f.id!=null)?(parseInt(f.id,10)||null):null;const label=(f.filename||f.title||f.name||f.original_name||(id?'File #'+id:'')||'');return{id,label:String(label)};}
  function setImage(mid,url){document.getElementById('inp_image_media_id').value=mid?String(mid):'';document.getElementById('txt_media_id').textContent=mid?String(mid):'-';const img=document.getElementById('img_preview'),ph=document.getElementById('img_placeholder');if(url){img.src=url;img.style.display='block';if(ph)ph.style.display='none';}else{img.removeAttribute('src');img.style.display='none';if(ph)ph.style.display='block';}}
  function setFile(fid,label){document.getElementById('inp_file_id').value=fid?String(fid):'';document.getElementById('txt_file_id').textContent=fid?String(fid):'-';document.getElementById('file_label').textContent=(fid?(label||'File #'+fid):'Belum ada file');}
  document.getElementById('btn_pick_image')?.addEventListener('click',async function(){const d=await openSelector({url:BASE+'/admin/modal_img/list_modal.php?embedded=1',eventName:'media:insert',messageType:'media:insert',maxWidth:'980px'});const m=normalizeMedia(d);if(!m||!m.id)return;setImage(m.id,m.url||'');});
  document.getElementById('btn_clear_image')?.addEventListener('click',function(){setImage(null,'');});
  document.getElementById('btn_pick_file')?.addEventListener('click',async function(){const d=await openSelector({url:BASE+'/admin/modal_file/list_modal.php?embedded=1',eventName:'file:insert',messageType:'file:insert',maxWidth:'980px'});const f=normalizeFile(d);if(!f||!f.id)return;setFile(f.id,f.label||'File #'+f.id);});
  document.getElementById('btn_clear_file')?.addEventListener('click',function(){setFile(null,'');});
})();
</script>
<?php
