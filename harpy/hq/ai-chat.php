<?php
// ══════════════════════════════════════════════════════
// hq/ai-chat.php — Chat Tanya Data dengan AI
//
// User ketik pertanyaan natural language → AI generate SQL aman →
// execute → AI explain hasilnya.
// ══════════════════════════════════════════════════════

$activePage = 'hq-ai-chat';
$pageTitle  = 'AI Chat — Tanya Data';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/AIChatData.php';
require_once ROOT . '/core/CoinLedger.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';

// ── API: ask question ────────────────────────────────
if ($action === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!CoinLedger::canAfford('ai_chat_data')) {
        echo json_encode(['error' => 'Coin tidak cukup. Butuh 50 coin per pertanyaan.']);
        exit;
    }

    $question = trim($_POST['question'] ?? '');
    if (!$question) {
        echo json_encode(['error' => 'Pertanyaan kosong.']);
        exit;
    }

    try {
        $result = AIChatData::ask($question, $tid);

        try { CoinLedger::deduct('ai_chat_data'); } catch (Throwable) {}
        try { logAudit('ai_chat', 'data', "Tanya: " . substr($question, 0, 100)); } catch (Throwable) {}

        echo json_encode([
            'ok'          => true,
            'answer'      => $result['answer'],
            'sql'         => $result['sql'],
            'rows'        => $result['rows'],
            'row_count'   => $result['row_count'],
            'tokens_used' => $result['tokens_used'],
            'generated_at'=> $result['generated_at'],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

require __DIR__ . '/_layout_open.php';
?>
<style>
.ai-chat-wrap { display:grid; grid-template-columns: 280px 1fr; gap:18px; }
.ai-sidebar { background:#fff; border:1px solid #EEF1F8; border-radius:14px; padding:18px; height:fit-content; position:sticky; top:60px; }
.ai-sidebar h3 { font-size:13px; font-weight:800; color:#0F1C3A; margin-bottom:10px; letter-spacing:.05em }
.ai-suggest { display:flex; flex-direction:column; gap:6px; }
.ai-suggest button {
  background:#F7F8FC; border:1px solid #EEF1F8; padding:9px 11px; border-radius:8px;
  font-family:inherit; font-size:12px; text-align:left; color:#374151; cursor:pointer;
  transition:all .15s;
}
.ai-suggest button:hover { background:#F0FDFB; border-color:#35E8D5; color:#0891B2 }

.ai-main { display:flex; flex-direction:column; height:calc(100vh - 130px); min-height:500px;
  background:#fff; border:1px solid #EEF1F8; border-radius:14px; overflow:hidden }
.ai-thread { flex:1; overflow-y:auto; padding:20px 24px; display:flex; flex-direction:column; gap:18px }
.ai-msg { display:flex; gap:11px; max-width:88% }
.ai-msg.user { margin-left:auto; flex-direction:row-reverse }
.ai-avatar { width:32px; height:32px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:16px }
.ai-msg.user .ai-avatar { background:#E0F2FE; color:#0369A1 }
.ai-msg.bot .ai-avatar { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff }
.ai-bubble { padding:11px 14px; border-radius:14px; font-size:14px; line-height:1.55; word-break:break-word }
.ai-msg.user .ai-bubble { background:#0F1C3A; color:#fff; border-bottom-right-radius:4px }
.ai-msg.bot .ai-bubble { background:#F7F8FC; color:#1F2937; border-bottom-left-radius:4px; border:1px solid #EEF1F8 }
.ai-bubble.error { background:#FEE2E2; color:#991B1B; border-color:#FCA5A5 }

.ai-detail { margin-top:8px; font-size:11px; color:#6B7280 }
.ai-detail summary { cursor:pointer; user-select:none; padding:4px 0; font-weight:600 }
.ai-detail summary:hover { color:#0F1C3A }
.ai-sql { background:#1F2937; color:#A7F3D0; padding:10px 12px; border-radius:8px;
  font-family:monospace; font-size:11px; white-space:pre-wrap; margin-top:6px; overflow-x:auto }
.ai-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; background:#fff; border:1px solid #EEF1F8; border-radius:8px; overflow:hidden }
.ai-table th { background:#F7F8FC; color:#374151; font-weight:700; padding:7px 10px; text-align:left; border-bottom:1px solid #EEF1F8; font-size:11px }
.ai-table td { padding:6px 10px; border-bottom:1px solid #F7F8FC; color:#4B5563 }
.ai-table tr:last-child td { border-bottom:none }

.ai-input-bar { padding:14px 18px; border-top:1px solid #EEF1F8; background:#fff;
  display:flex; gap:8px; align-items:flex-end }
.ai-input-bar textarea {
  flex:1; resize:none; border:1px solid #E5E9F2; border-radius:10px; padding:10px 12px;
  font-family:inherit; font-size:14px; color:#1F2937; outline:none;
  min-height:42px; max-height:120px; line-height:1.4;
}
.ai-input-bar textarea:focus { border-color:#35E8D5 }
.ai-input-bar button {
  background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; border:none;
  padding:10px 18px; border-radius:10px; font-weight:700; font-size:13px; cursor:pointer;
  font-family:inherit; white-space:nowrap;
}
.ai-input-bar button:hover { opacity:.92 }
.ai-input-bar button:disabled { opacity:.4; cursor:wait }

.ai-empty { text-align:center; padding:40px 20px; color:#6B7280; font-size:14px }
.ai-empty .ico { font-size:48px; margin-bottom:12px }

.ai-typing { display:flex; gap:4px; align-items:center; padding:8px 0 }
.ai-typing span { width:7px; height:7px; background:#9CA3AF; border-radius:50%; animation: aiTyp 1.4s infinite }
.ai-typing span:nth-child(2) { animation-delay:.2s }
.ai-typing span:nth-child(3) { animation-delay:.4s }
@keyframes aiTyp { 0%,60%,100% { opacity:.3 } 30% { opacity:1 } }

@media (max-width:900px) {
  .ai-chat-wrap { grid-template-columns:1fr }
  .ai-sidebar { position:static; height:auto }
}
</style>

<div class="ai-chat-wrap">
  <aside class="ai-sidebar">
    <h3>💡 CONTOH PERTANYAAN</h3>
    <div class="ai-suggest">
      <button onclick="askExample(this)">Berapa total omset bulan ini?</button>
      <button onclick="askExample(this)">Siapa 5 pelanggan terbesar bulan ini?</button>
      <button onclick="askExample(this)">Layanan apa yang paling laku minggu ini?</button>
      <button onclick="askExample(this)">Berapa order yang masih proses sekarang?</button>
      <button onclick="askExample(this)">Outlet mana yang omsetnya tertinggi bulan ini?</button>
      <button onclick="askExample(this)">Kasir mana yang paling banyak melayani order minggu ini?</button>
      <button onclick="askExample(this)">Berapa pelanggan baru bulan ini?</button>
      <button onclick="askExample(this)">Total kas keluar bulan lalu?</button>
    </div>
    <div style="margin-top:18px;padding-top:14px;border-top:1px solid #EEF1F8;font-size:11px;color:#6B7280;line-height:1.5">
      <strong style="color:#0F1C3A">⚠️ Tips:</strong><br>
      Tanya dalam bahasa Indonesia. Sebut periode (hari ini, minggu ini, bulan ini, dst) supaya jawaban presisi. Setiap pertanyaan = 50 coin.
    </div>
  </aside>

  <main class="ai-main">
    <div class="ai-thread" id="thread">
      <div class="ai-empty" id="empty">
        <div class="ico">✨</div>
        <div>Tanyakan apapun tentang data bisnis Anda</div>
        <div style="font-size:12px;margin-top:6px;color:#9CA3AF">Klik contoh di samping atau ketik pertanyaan</div>
      </div>
    </div>
    <div class="ai-input-bar">
      <textarea id="qInput" placeholder="Tanya tentang data Anda..." rows="1"
                onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); submitQ();}"></textarea>
      <button id="qSubmit" onclick="submitQ()">Tanya →</button>
    </div>
  </main>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const thread = document.getElementById('thread');
const empty = document.getElementById('empty');
const qInput = document.getElementById('qInput');
const qSubmit = document.getElementById('qSubmit');

function askExample(btn) {
  qInput.value = btn.textContent;
  submitQ();
}

function addMsg(role, html, extra='') {
  if (empty) empty.style.display = 'none';
  const div = document.createElement('div');
  div.className = 'ai-msg ' + role;
  div.innerHTML = `
    <div class="ai-avatar">${role === 'user' ? '👤' : '✨'}</div>
    <div>
      <div class="ai-bubble ${extra}">${html}</div>
    </div>
  `;
  thread.appendChild(div);
  thread.scrollTop = thread.scrollHeight;
  return div;
}

function addTyping() {
  if (empty) empty.style.display = 'none';
  const div = document.createElement('div');
  div.className = 'ai-msg bot';
  div.id = 'typingIndicator';
  div.innerHTML = `
    <div class="ai-avatar">✨</div>
    <div>
      <div class="ai-bubble">
        <div class="ai-typing"><span></span><span></span><span></span></div>
      </div>
    </div>
  `;
  thread.appendChild(div);
  thread.scrollTop = thread.scrollHeight;
}

function removeTyping() {
  const t = document.getElementById('typingIndicator');
  if (t) t.remove();
}

function renderResultTable(rows) {
  if (!rows || rows.length === 0) return '';
  const cols = Object.keys(rows[0]);
  const headerRow = cols.map(c => `<th>${esc(c)}</th>`).join('');
  const dataRows = rows.slice(0, 50).map(r => {
    return '<tr>' + cols.map(c => `<td>${esc(r[c])}</td>`).join('') + '</tr>';
  }).join('');
  const note = rows.length > 50 ? `<div style="font-size:10px;color:#9CA3AF;margin-top:4px">(Menampilkan 50 dari ${rows.length} baris)</div>` : '';
  return `<table class="ai-table"><thead><tr>${headerRow}</tr></thead><tbody>${dataRows}</tbody></table>${note}`;
}

async function submitQ() {
  const q = qInput.value.trim();
  if (!q) return;

  addMsg('user', esc(q));
  qInput.value = '';
  qInput.style.height = 'auto';
  qSubmit.disabled = true;
  addTyping();

  try {
    const fd = new FormData();
    fd.append('question', q);
    const r = await fetch('/ERP/harpy/hq/ai-chat.php?action=ask', { method: 'POST', body: fd });
    const d = await r.json();
    removeTyping();
    qSubmit.disabled = false;

    if (d.error) {
      addMsg('bot', '⚠️ ' + esc(d.error), 'error');
      return;
    }

    const tableHtml = renderResultTable(d.rows);
    const detailHtml = `
      <details class="ai-detail">
        <summary>🔍 Lihat SQL & data mentah (${d.row_count} baris)</summary>
        <div class="ai-sql">${esc(d.sql)}</div>
        ${tableHtml}
        <div style="margin-top:6px;font-size:10px;color:#9CA3AF">${d.tokens_used} tokens · ${d.generated_at}</div>
      </details>
    `;

    addMsg('bot', esc(d.answer) + detailHtml);
  } catch (e) {
    removeTyping();
    qSubmit.disabled = false;
    addMsg('bot', '⚠️ Gagal koneksi: ' + esc(e.message), 'error');
  }
}

// Auto-resize textarea
qInput.addEventListener('input', () => {
  qInput.style.height = 'auto';
  qInput.style.height = Math.min(qInput.scrollHeight, 120) + 'px';
});
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>
