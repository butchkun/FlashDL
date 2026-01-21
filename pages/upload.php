<?php
require_once __DIR__.'/../lib.php';
require __DIR__.'/../templates/header.php';
?>

<div class="container">
    <div class="upload-layout">
<div class="card upload-card">

<h2>Upload Flash Copy</h2>
<p class="muted">
Les fichiers sont envoyés par morceaux de 32&nbsp;Mo.
En cas de coupure, l’upload reprend automatiquement.
</p>

<!-- Sélecteurs -->
<div class="form-row">
    <label>Fichiers :</label>
    <input type="file" id="filePicker" multiple>
</div>

<div class="form-row">
    <label>Dossier :</label>
    <input type="file" id="folderPicker" webkitdirectory directory multiple>
</div>

<!-- Options -->
<div class="form-row">
    <label>
        <input type="checkbox" id="bundleMode" checked>
        Créer un seul lien (ZIP)
    </label>
</div>

<div id="bundleOptions">
    <div class="form-row">
        <label>Nom du ZIP (optionnel) :</label>
        <input type="text" id="bundleName" placeholder="ex: Dossier_Archives">
    </div>
</div>

<div class="form-row">
    <label>Mot de passe du lien (optionnel) :</label>
    <input type="text" id="linkPassword" placeholder="laisser vide si non">
</div>

<!-- Drop zone -->
<div id="dropZone" class="drop-zone">
    Glisse-dépose des fichiers ou un dossier ici
</div>

<!-- Boutons -->
<div class="form-actions">
    <button id="startBtn" class="btn">Démarrer</button>
<button id="cancelBtn" class="btn secondary">Annuler</button>
</div>

<!-- Progression -->
<div class="progress-wrap">
    <div id="progressBar" class="progress-bar"></div>
    <div id="progressText">0%</div>
</div>

<!-- Etat detaille (ex: creation du ZIP) -->
<div id="phaseText" class="phase-text">Prêt.</div>

<!-- Message e-mail a copier/coller -->
<div id="mailBox" class="mail-box" style="display:none;">
    <div class="mail-box-head">
        <b>Message e-mail</b>
        <button id="copyMailBtn" class="btn secondary" type="button" style="padding:6px 10px;">Copier</button>
    </div>
    <textarea id="mailTemplate" rows="7" readonly></textarea>
    <div class="muted" style="margin-top:6px;">Astuce : adapte la formule d’appel et la signature si besoin.</div>
</div>

<!-- Résultat -->
<div id="result" class="result"></div>

</div>

    <div class="hero-visual">
        <img src="/assets/img/robot_demat_fibre.png"
             alt="Robot de dématérialisation patrimoniale">
    </div>

</div>
</div>
<style>
.upload-card { max-width:700px; margin:auto; }
.form-row { margin:12px 0; }
.drop-zone {
    margin-top:15px;
    padding:30px;
    border:2px dashed rgba(255,255,255,0.3);
    border-radius:8px;
    text-align:center;
    color:#bbb;
}
.drop-zone.dragover {
    border-color:#4cc3ff;
    color:#4cc3ff;
    background:rgba(255,255,255,0.05);
}
.progress-wrap {
    margin-top:20px;
    background:#222;
    border-radius:6px;
    height:20px;
    position:relative;
}
.progress-bar {
    background:#4cc3ff;
    height:100%;
    width:0%;
    border-radius:6px;
    transition:width 0.2s;
}
#progressText {
    position:absolute;
    top:0; left:50%;
    transform:translateX(-50%);
    font-size:12px;
    line-height:20px;
    color:#fff;
}
.result { margin-top:20px; }

.phase-text{
    margin-top:10px;
    font-size:13px;
    color:#ddd;
    opacity:.95;
}

.mail-box{
    margin-top:16px;
    padding:12px;
    border:1px solid rgba(255,255,255,0.12);
    border-radius:10px;
    background:rgba(255,255,255,0.03);
}
.mail-box-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:8px;
}
.mail-box textarea{
    width:100%;
    box-sizing:border-box;
    background:#111;
    color:#f2f2f2;
    border:1px solid rgba(255,255,255,0.15);
    border-radius:8px;
    padding:10px;
    resize:vertical;
}

.upload-layout {
    display: flex;
    gap: 50px;
    align-items: center;
}
.upload-layout .upload-card {
    flex: 1;
}


.hero-visual img {
    max-width: 400px;
    height: auto;
    display: block;
}
</style>

<script>
const CHUNK_SIZE = 32 * 1024 * 1024; // 32 Mo (doit matcher le serveur)
const CSRF = <?= json_encode(csrf_token()) ?>;

let filesToUpload = [];
let abortFlag = false;

const dropZone = document.getElementById('dropZone');
const filePicker = document.getElementById('filePicker');
const folderPicker = document.getElementById('folderPicker');

function mergeFileLists(existing, incoming){
  const map = new Map();
  for(const f of (existing||[])){
    map.set(f.name + '|' + f.size + '|' + f.lastModified, f);
  }
  for(const f of Array.from(incoming||[])){
    map.set(f.name + '|' + f.size + '|' + f.lastModified, f);
  }
  return Array.from(map.values());
}

// Browse: files
filePicker.addEventListener('change', (e)=>{
  const picked = e.target.files;
  filesToUpload = mergeFileLists(filesToUpload, picked);
  handleFileList(filesToUpload);
  // allow picking the same file again later
  filePicker.value = '';
});

// Browse: folder
folderPicker.addEventListener('change', (e)=>{
  const picked = e.target.files;
  filesToUpload = mergeFileLists(filesToUpload, picked);
  handleFileList(filesToUpload);
  folderPicker.value = '';
});

const startBtn = document.getElementById('startBtn');
const cancelBtn = document.getElementById('cancelBtn');
const bundleModeEl = document.getElementById('bundleMode');
const bundleNameEl = document.getElementById('bundleName');
const pwdEl = document.getElementById('linkPassword');
const resultEl = document.getElementById('result');
const phaseTextEl = document.getElementById('phaseText');
const mailBoxEl = document.getElementById('mailBox');
const mailTplEl = document.getElementById('mailTemplate');
const copyMailBtn = document.getElementById('copyMailBtn');

function setPhase(text){
  if(phaseTextEl) phaseTextEl.textContent = text || '';
}

function setMailTemplate(url, password){
  if(!mailBoxEl || !mailTplEl) return;
  const pwdLine = password ? `Mot de passe : ${password}\n` : '';
  mailTplEl.value =
`Bonjour,\n\n`+
`Flash Copy vous transmet un lien de téléchargement pour récupérer vos fichiers :\n`+
`${url}\n`+
pwdLine+
`\nLe lien est disponible pour une durée limitée.\n\n`+
`Bien cordialement,\n`+
`Flash Copy`;
  mailBoxEl.style.display = 'block';
}

copyMailBtn && copyMailBtn.addEventListener('click', async ()=>{
  try{
    await navigator.clipboard.writeText(mailTplEl.value || '');
    setPhase('Message e-mail copié dans le presse-papiers.');
  }catch(e){
    // Fallback: select text
    mailTplEl.focus();
    mailTplEl.select();
    document.execCommand('copy');
    setPhase('Message e-mail copié (fallback).');
  }
});

function setResult(html){
  resultEl.innerHTML = html || '';
}

function showError(title, details){
  const d = details ? `<pre style="white-space:pre-wrap;opacity:.9">${escapeHtml(details)}</pre>` : '';
  setResult(`<div style="padding:10px;border:1px solid rgba(255,255,255,.15);border-radius:8px;background:rgba(255,0,0,.08)">
    <b>${escapeHtml(title)}</b>${d}
  </div>`);
  // cache le mail si erreur
  if(mailBoxEl) mailBoxEl.style.display = 'none';
}

function showLink(url){
  setResult(`<p>Lien de téléchargement :</p><a href="${escapeAttr(url)}" target="_blank" rel="noopener">${escapeHtml(url)}</a>`);
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function escapeAttr(s){ return escapeHtml(s); }

function updateProgress(ratio){
  ratio = Math.max(0, Math.min(1, ratio||0));
  const bar = document.getElementById('progressBar');
  const txt = document.getElementById('progressText');
  bar.style.width = (ratio*100).toFixed(1) + '%';
  txt.textContent = Math.round(ratio*100) + '%';
}

function handleFileList(fileList){
  filesToUpload = Array.from(fileList || []);
  if(filesToUpload.length === 0){
    dropZone.textContent = 'Aucun fichier détecté';
    return;
  }
  const total = filesToUpload.reduce((a,f)=>a+(f.size||0),0);
  const maybeFolder = filesToUpload.some(f=> (f.relativePath && String(f.relativePath).includes('/')) || (f.webkitRelativePath && f.webkitRelativePath.length>0));
  dropZone.innerHTML = `<b>${filesToUpload.length}</b> fichier(s) sélectionné(s) (${formatBytes(total)})` + (maybeFolder ? ` — dossier détecté` : ``);
}

function formatBytes(bytes){
  const u = ['o','Ko','Mo','Go','To'];
  let b = Number(bytes||0), i=0;
  while(b>=1024 && i<u.length-1){ b/=1024; i++; }
  return `${b.toFixed(i?2:0)} ${u[i]}`;
}

// drag & drop
// ---- Drag & drop (files + folders, recursive) ----
async function getFilesFromDataTransfer(dt){
  const items = dt.items;
  // Fallback (Firefox / older): only files
  if(!items || items.length === 0){
    return Array.from(dt.files || []);
  }

  const entries = [];
  for(const it of items){
    if(it.kind !== 'file') continue;
    // Chrome/Edge: can traverse folders via webkitGetAsEntry()
    const entry = (it.webkitGetAsEntry ? it.webkitGetAsEntry() : null);
    if(entry) entries.push(entry);
    else {
      const f = it.getAsFile();
      if(f) entries.push(f);
    }
  }

  const hasEntry = entries.some(e => e && typeof e.isDirectory === 'boolean');
  if(!hasEntry){
    return Array.from(dt.files || []);
  }

  const files = [];

  async function walkEntry(entry, parentPath){
    if(entry.isFile){
      await new Promise((resolve, reject)=>{
        entry.file(f=>{
          // Keep relative path for ZIP manifest (and UI)
          f.relativePath = parentPath + f.name;
          files.push(f);
          resolve();
        }, reject);
      });
      return;
    }

    if(entry.isDirectory){
      const reader = entry.createReader();
      while(true){
        const batch = await new Promise((resolve, reject)=>reader.readEntries(resolve, reject));
        if(!batch || batch.length === 0) break;
        for(const child of batch){
          await walkEntry(child, parentPath + entry.name + '/');
        }
      }
    }
  }

  for(const e of entries){
    if(e && typeof e.isDirectory === 'boolean'){
      await walkEntry(e, '');
    }else if(e instanceof File){
      e.relativePath = e.name;
      files.push(e);
    }
  }

  // Remove empty/broken placeholders (some browsers expose the folder itself as 0-byte "file")
  return files.filter(f => f && typeof f.name === 'string' && f.name.length > 0);
}

dropZone.addEventListener('dragover', e=>{ e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', ()=> dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', async (e)=>{
  e.preventDefault();
  dropZone.classList.remove('dragover');
  try{
    const files = await getFilesFromDataTransfer(e.dataTransfer);
    handleFileList(files);
  }catch(err){
    showError('Erreur dossier déposé', err?.message || String(err));
  }
});

// Cancel
cancelBtn.addEventListener('click', ()=>{
  abortFlag = true;
  setResult('<i>Annulation demandée…</i>');
});

// Simple JSON call (x-www-form-urlencoded)
async function apiCall(obj){
  const body = new URLSearchParams();
  body.append('csrf', CSRF);
  for (const [k,v] of Object.entries(obj)) {
    body.append(k, v === undefined || v === null ? '' : String(v));
  }

  let r, t;
  try{
    r = await fetch('/?p=api_upload', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body
    });
    t = await r.text();
  }catch(err){
    throw new Error('fetch() a échoué (réseau / CORS / TLS). ' + (err?.message || String(err)));
  }

  // If we got redirected (often means session/auth issue), the response is likely HTML
  const ct = (r.headers.get('content-type') || '').toLowerCase();
  const looksJson = ct.includes('application/json') || (t && t.trim().startsWith('{'));

  if(!r.ok || !looksJson){
    const snippet = (t || '').slice(0, 800);
    throw new Error(
      `Réponse API inattendue (HTTP ${r.status}). ` +
      (r.redirected ? 'Redirection détectée (session ?). ' : '') +
      `Content-Type=${ct || 'n/a'}\n` +
      snippet
    );
  }

  try{
    return JSON.parse(t);
  }catch(e){
    throw new Error('JSON invalide renvoyé par l’API:\n' + (t || '').slice(0, 800));
  }
}

// Upload one chunk via multipart/form-data (matches api_upload.php)
async function uploadChunk(action, fields, blob){
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF);
  for (const [k,v] of Object.entries(fields||{})){
    fd.append(k, String(v));
  }
  fd.append('chunk', blob, 'chunk.part');

  let r, t;
  try{
    r = await fetch('/?p=api_upload', { method:'POST', credentials:'same-origin', body: fd });
    t = await r.text();
  }catch(err){
    throw new Error('fetch() a échoué (réseau / CORS / TLS). ' + (err?.message || String(err)));
  }

  const ct = (r.headers.get('content-type') || '').toLowerCase();
  const looksJson = ct.includes('application/json') || (t && t.trim().startsWith('{'));

  if(!r.ok || !looksJson){
    const snippet = (t || '').slice(0, 800);
    throw new Error(
      `Réponse API inattendue (HTTP ${r.status}). ` +
      (r.redirected ? 'Redirection détectée (session ?). ' : '') +
      `Content-Type=${ct || 'n/a'}\n` +
      snippet
    );
  }

  try{
    return JSON.parse(t);
  }catch(e){
    throw new Error('JSON invalide renvoyé par l’API:\n' + (t || '').slice(0, 800));
  }
}



async function startUpload(){
  abortFlag = false;
  setResult('');
  updateProgress(0);
  setPhase('Préparation…');
  if(mailBoxEl) mailBoxEl.style.display = 'none';

  const bundle = !!bundleModeEl?.checked;
  const password = (pwdEl?.value || '').trim();

  if(!filesToUpload.length){
    showError('Aucun fichier à envoyer', 'Glisse-dépose un ou plusieurs fichiers (ou un dossier) puis clique "Démarrer".');
    return;
  }

  try{
    const totalBytes = filesToUpload.reduce((a,f)=>a+(f.size||0),0);
    let uploadedBytes = 0;

    if(!bundle){
      // mode "un lien par fichier"
      let i = 0;
      for(const file of filesToUpload){
        i++;
        if(abortFlag) return;

        setPhase(`Initialisation (${i}/${filesToUpload.length}) : ${file.name}`);

        // INIT (API: original_name / total_bytes / chunk_size)
        const init = await apiCall({
          action:'init',
          original_name: file.name,
          total_bytes: file.size,
          chunk_size: CHUNK_SIZE
        });

        if(!init.ok){
          showError('Init upload échoué', JSON.stringify(init, null, 2));
          return;
        }
        const token = init.token;

        // CHUNKS
        const totalChunks = Math.ceil(Math.max(1, file.size) / CHUNK_SIZE);
        for(let idx=0; idx<totalChunks; idx++){
          if(abortFlag) return;
          setPhase(`Envoi (${i}/${filesToUpload.length}) : ${file.name} — morceau ${idx+1}/${totalChunks}`);
          const start = idx * CHUNK_SIZE;
          const slice = file.slice(start, Math.min(file.size, start + CHUNK_SIZE));

          const rep = await uploadChunk('chunk', { token, index: idx }, slice);
          if(!rep.ok){
            showError('Envoi chunk échoué', JSON.stringify(rep, null, 2));
            return;
          }

          uploadedBytes += slice.size;
          updateProgress(uploadedBytes / totalBytes);
        }

        // FINALIZE
        setPhase(`Finalisation (${i}/${filesToUpload.length}) : ${file.name}`);
        const fin = await apiCall({ action:'finalize', token, password });
        if(!fin.ok){
          showError('Finalize échoué', JSON.stringify(fin, null, 2));
          return;
        }
        showLink(fin.url);
        setMailTemplate(fin.url, password);
        setPhase('Terminé.');
      }
      return;
    }

    // mode "un seul lien zip"
    const bundleName = (bundleNameEl?.value || '').trim();

    // Build manifest (API expects {files:[{path,total_bytes}]})
    const manifest = {
      bundle_name: bundleName || 'bundle',
      files: filesToUpload.map(f=>({
        // keep folder structure if provided by browser
        path: (f.relativePath && f.relativePath.length>0) ? f.relativePath : ((f.webkitRelativePath && f.webkitRelativePath.length>0) ? f.webkitRelativePath : f.name),
        size: f.size
      }))
    };

    const initB = await apiCall({
      action:'init_bundle',
      chunk_size: CHUNK_SIZE,
      manifest: JSON.stringify(manifest)
    });
    if(!initB.ok){
      showError('Init bundle échoué', JSON.stringify(initB, null, 2));
      return;
    }
    const token = initB.token;

    // Upload each file as chunk_bundle
    let fileIndex = 0;
    for(const file of filesToUpload){
      if(abortFlag) return;

      const totalChunks = Math.ceil(Math.max(1, file.size) / CHUNK_SIZE);
      for(let idx=0; idx<totalChunks; idx++){
        if(abortFlag) return;
        setPhase(`Envoi ZIP : ${fileIndex+1}/${filesToUpload.length} — ${file.name} — morceau ${idx+1}/${totalChunks}`);
        const start = idx * CHUNK_SIZE;
        const slice = file.slice(start, Math.min(file.size, start + CHUNK_SIZE));

        const rep = await uploadChunk('chunk_bundle', { token, file_index: fileIndex, index: idx }, slice);
        if(!rep.ok){
          showError('Envoi chunk_bundle échoué', JSON.stringify(rep, null, 2));
          return;
        }

        uploadedBytes += slice.size;
        updateProgress(uploadedBytes / totalBytes);
      }

      fileIndex++;
    }

    // A ce stade, le navigateur est a 100% mais le serveur doit assembler + zipper.
    updateProgress(1);
    setPhase('Création de l\'archive ZIP en cours… (cela peut prendre un moment)');
    const finB = await apiCall({
      action:'finalize_bundle',
      token,
      bundle_name: bundleName,
      password
    });
    if(!finB.ok){
      showError('Finalize bundle échoué', JSON.stringify(finB, null, 2));
      return;
    }
    showLink(finB.url);
    setMailTemplate(finB.url, password);
    setPhase('Terminé.');

  } catch(err){
    const msg = (err && err.message) ? err.message : String(err);
    const stack = (err && err.stack) ? err.stack : '';
    showError('Erreur côté navigateur', msg + (stack && !stack.includes(msg) ? ('\n\n' + stack) : (stack ? ('\n\n' + stack) : '')));
    setPhase('Erreur.');
  }
}

startBtn.addEventListener('click', (e)=>{ e.preventDefault(); startUpload(); });

// UI bundle toggle
document.getElementById('bundleMode').addEventListener('change', e=>{
    document.getElementById('bundleOptions').style.display = e.target.checked ? 'block':'none';
});
</script>

<?php require __DIR__.'/../templates/footer.php'; ?>
