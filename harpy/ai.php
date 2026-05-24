<?php
// ══════════════════════════════════════════════════════
// ai.php — Outlet-side AI endpoints
//
// Endpoints:
//   GET  ?action=briefing          → AI briefing harian (dashboard floating panel)
//   POST ?action=laporan_analyze   → AI Q&A laporan (laporan.php chat)
//   POST ?action=upselling         → AI rekomendasi upsell per pelanggan (POS)
//
// Semua endpoint:
//   - Tenant-guarded
//   - Cache 24h di hl_ai_cache (hemat coin)
//   - Deduct coin via CoinLedger
//   - Return JSON: { ok|error, data?, jawaban?, from_cache, tokens_used? }
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);

// ── Hardening: jangan biarkan PHP warning/notice/fatal leak HTML ke client ──
@ini_set('display_errors', '0');
if (ob_get_level() === 0) ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Server error: ' . $err['message']]);
    }
});

require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/AnthropicClient.php';
require_once ROOT . '/core/CoinLedger.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$tid    = TenantResolver::id();
$oid    = TenantResolver::outletId();
$user   = currentUser();
$today  = date('Y-m-d');

// ── Cache helpers (lokal — pakai hl_ai_cache) ────────
function ai_cache_get(int $tid, string $key): ?array {
    try {
        $db = Database::get();
        $st = $db->prepare("SELECT response_json FROM hl_ai_cache
                             WHERE tenant_id=? AND cache_key=? AND expires_at > NOW()
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$tid, $key]);
        $row = $st->fetch();
        if (!$row) return null;
        $d = json_decode($row['response_json'], true);
        return is_array($d) ? $d : null;
    } catch (Throwable $e) { error_log('[ai_cache_get] '.$e->getMessage()); return null; }
}
function ai_cache_put(int $tid, ?int $oid, string $key, array $out, int $tokIn, int $tokOut, int $ttlH = 24): void {
    try {
        $db = Database::get();
        $st = $db->prepare("INSERT INTO hl_ai_cache
            (tenant_id,outlet_id,cache_key,prompt_hash,response_json,tokens_in,tokens_out,expires_at)
            VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))");
        $st->execute([
            $tid, $oid, $key, substr($key, -64),
            json_encode($out, JSON_UNESCAPED_UNICODE),
            $tokIn, $tokOut, $ttlH,
        ]);
    } catch (Throwable $e) { error_log('[ai_cache_put] '.$e->getMessage()); }
}

function ai_err(string $msg, int $code = 400): never {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => $msg]);
    exit;
}

function ai_ok(array $payload): never {
    while (ob_get_level() > 0) { $junk = ob_get_clean(); if (!empty($junk)) error_log('[ai.php] stray output: '.substr($junk,0,300)); }
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// ══════════════════════════════════════════════════════
// 1) AI BRIEFING HARIAN (dashboard)
// ══════════════════════════════════════════════════════
if ($action === 'briefing') {
    // Cache key per tenant+outlet+tanggal
    $key = sprintf('briefing_outlet_%d_%s', $oid, $today);

    $cached = ai_cache_get($tid, $key);
    if ($cached !== null) {
        $cached['from_cache'] = true;
        ai_ok(['ok'=>true, 'data'=>$cached]);
        exit;
    }

    // Cek coin
    if (!CoinLedger::canAfford('ai_briefing_hq')) {
        ai_err('Coin tidak cukup untuk AI Briefing (butuh '.CoinLedger::COSTS['ai_briefing_hq'].' coin)');
    }

    // ── Kumpul data hari ini ──
    try {
        $db = Database::get();

        // Omset + order hari ini di outlet ini
        $st = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) omset,
                                   SUM(CASE WHEN status_proses='siap' THEN 1 ELSE 0 END) siap,
                                   SUM(CASE WHEN status_proses NOT IN ('diambil','selesai','batal','dibatalkan') THEN 1 ELSE 0 END) aktif
                              FROM hl_transaksi
                             WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
        $st->execute([$tid, $oid, $today]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // Omset kemarin (untuk delta)
        $st = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) omset
                              FROM hl_transaksi
                             WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=DATE_SUB(?, INTERVAL 1 DAY)");
        $st->execute([$tid, $oid, $today]);
        $prev = $st->fetch(PDO::FETCH_ASSOC) ?: ['c'=>0,'omset'=>0];

        // Top layanan hari ini
        $st = $db->prepare("SELECT i.nama_layanan, COUNT(*) qty, COALESCE(SUM(i.subtotal),0) total
                              FROM hl_transaksi_item i
                              JOIN hl_transaksi t ON t.id=i.transaksi_id
                             WHERE t.tenant_id=? AND t.outlet_id=? AND DATE(t.tanggal)=?
                          GROUP BY i.nama_layanan
                          ORDER BY total DESC LIMIT 3");
        $st->execute([$tid, $oid, $today]);
        $topLayanan = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Order terlambat (estimasi < now & belum siap/diambil)
        $st = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                             WHERE tenant_id=? AND outlet_id=? AND estimasi_selesai IS NOT NULL
                               AND estimasi_selesai < NOW()
                               AND status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan')");
        $st->execute([$tid, $oid]);
        $telat = (int)$st->fetchColumn();

        // Piutang outstanding
        $st = $db->prepare("SELECT COALESCE(SUM(sisa_bayar),0) FROM hl_transaksi
                             WHERE tenant_id=? AND outlet_id=? AND sisa_bayar > 0
                               AND status_proses != 'batal'");
        $st->execute([$tid, $oid]);
        $piutang = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        ai_err('Gagal kumpul data: '.$e->getMessage(), 500);
    }

    $omsetNow = (int)($row['omset'] ?? 0);
    $omsetPrev = (int)($prev['omset'] ?? 0);
    $deltaPct = $omsetPrev > 0 ? round((($omsetNow - $omsetPrev) / $omsetPrev) * 100, 1) : null;

    // ── Build prompt ──
    $tglLabel = date('l, d F Y', strtotime($today));
    $tglLabel = str_replace(
        ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday',
         'January','February','March','April','May','June','July','August','September','October','November','December'],
        ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu',
         'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'],
        $tglLabel
    );

    $promptLines = [
        "Buat briefing operasional hari ini ($tglLabel) untuk outlet laundry.",
        "",
        "DATA:",
        "- Omset hari ini: Rp " . number_format($omsetNow, 0, ',', '.'),
        "- Order hari ini: " . (int)($row['c'] ?? 0) . " transaksi",
        "- Omset kemarin: Rp " . number_format($omsetPrev, 0, ',', '.') . ($deltaPct !== null ? " (delta " . ($deltaPct >= 0 ? '+' : '') . "{$deltaPct}%)" : ""),
        "- Order aktif (proses): " . (int)($row['aktif'] ?? 0),
        "- Order siap diambil: " . (int)($row['siap'] ?? 0),
        "- Order TERLAMBAT (estimasi terlewat, belum siap): $telat",
        "- Piutang outstanding: Rp " . number_format($piutang, 0, ',', '.'),
    ];
    if ($topLayanan) {
        $promptLines[] = "";
        $promptLines[] = "TOP LAYANAN HARI INI:";
        foreach ($topLayanan as $i => $l) {
            $promptLines[] = ($i+1).". {$l['nama_layanan']} — {$l['qty']} order, Rp ".number_format((int)$l['total'], 0, ',', '.');
        }
    }
    $promptLines[] = "";
    $promptLines[] = "Tentukan kondisi: 'baik' (semua lancar), 'waspada' (ada 1-2 hal perlu diperhatikan), atau 'kritis' (ada masalah serius — telat banyak / piutang besar / omset anjlok).";
    $promptLines[] = "";
    $promptLines[] = "Respond dalam JSON dengan struktur:";
    $promptLines[] = '{';
    $promptLines[] = '  "kondisi": "baik" | "waspada" | "kritis",';
    $promptLines[] = '  "ringkasan": "1 kalimat ringkas kondisi outlet hari ini (sebut angka spesifik)",';
    $promptLines[] = '  "poin_penting": ["bullet 1 — yang perlu diperhatikan", "bullet 2", "bullet 3 (2-4 bullet total)"],';
    $promptLines[] = '  "peluang": "1 kalimat saran konkret untuk hari ini (actionable, bukan generic). Boleh null kalau tidak ada."';
    $promptLines[] = '}';

    $prompt = implode("\n", $promptLines);
    $system = "Kamu chief of staff untuk outlet laundry. Briefing pagi singkat dan tajam. "
            . "Bahasa Indonesia hangat tapi profesional. Sebut angka spesifik, bukan kata-kata generic.";

    try {
        $result = AnthropicClient::askJson($prompt, [
            'system'      => $system,
            'max_tokens'  => 800,
            'temperature' => 0.6,
        ]);
    } catch (Throwable $e) {
        ai_err('AI gagal merespons: ' . $e->getMessage(), 500);
    }

    $json = $result['json'];
    $output = [
        'kondisi'      => in_array(($json['kondisi'] ?? 'baik'), ['baik','waspada','kritis'], true)
                            ? $json['kondisi'] : 'baik',
        'ringkasan'    => (string)($json['ringkasan'] ?? ''),
        'poin_penting' => is_array($json['poin_penting'] ?? null) ? array_values(array_filter(array_map('strval', $json['poin_penting']))) : [],
        'peluang'      => isset($json['peluang']) && $json['peluang'] !== null ? (string)$json['peluang'] : '',
        'tokens_used'  => $result['tokens_in'] + $result['tokens_out'],
        'generated_at' => date('Y-m-d H:i:s'),
        'from_cache'   => false,
    ];

    // Deduct coin & cache
    CoinLedger::deduct('ai_briefing_hq', 'briefing_'.$today);
    ai_cache_put($tid, $oid, $key, $output, $result['tokens_in'], $result['tokens_out'], 24);
    if (function_exists('logAudit')) logAudit('ai_briefing', 'dashboard', 'Outlet briefing '.$today);

    ai_ok(['ok'=>true, 'data'=>$output]);
    exit;
}

// ══════════════════════════════════════════════════════
// 2) AI LAPORAN ANALYZE (Q&A di laporan.php)
// ══════════════════════════════════════════════════════
if ($action === 'laporan_analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $pertanyaan = trim($body['pertanyaan'] ?? '');
    if ($pertanyaan === '') ai_err('Pertanyaan kosong');
    if (mb_strlen($pertanyaan) > 500) ai_err('Pertanyaan terlalu panjang (maks 500 karakter)');

    if (!CoinLedger::canAfford('ai_chat_data')) {
        ai_err('Coin tidak cukup (butuh '.CoinLedger::COSTS['ai_chat_data'].' coin)');
    }

    $tipe    = (string)($body['tipe'] ?? 'harian');
    $periode = (string)($body['periode'] ?? '');
    $dataCtx = $body['data'] ?? [];
    $history = is_array($body['history'] ?? null) ? array_slice($body['history'], -4) : [];

    // Tidak di-cache (chat dinamis), tapi tetap deduct
    $promptLines = [];
    $promptLines[] = "Konteks laporan: tipe=$tipe, periode=$periode";
    $promptLines[] = "Data laporan (JSON):";
    $promptLines[] = json_encode($dataCtx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($history) {
        $promptLines[] = "";
        $promptLines[] = "Riwayat 4 pesan terakhir:";
        foreach ($history as $h) {
            $q = strip_tags((string)($h['q'] ?? ''));
            $a = strip_tags((string)($h['a'] ?? ''));
            if ($q) $promptLines[] = "USER: " . mb_substr($q, 0, 300);
            if ($a) $promptLines[] = "AI: "   . mb_substr($a, 0, 300);
        }
    }
    $promptLines[] = "";
    $promptLines[] = "Pertanyaan user: " . $pertanyaan;
    $promptLines[] = "";
    $promptLines[] = "Jawab dengan bahasa Indonesia natural, langsung ke poin. Sebut angka spesifik dari data. Maks 3 paragraf pendek. Boleh pakai bullet kalau perlu. JANGAN bilang 'berdasarkan data yang diberikan' — langsung jawab.";

    $prompt = implode("\n", $promptLines);
    $system = "Kamu konsultan bisnis laundry. Jawab pertanyaan owner/manager dari data laporan yang dikirim. "
            . "Singkat, akurat, actionable.";

    try {
        $result = AnthropicClient::ask($prompt, [
            'system'      => $system,
            'max_tokens'  => 700,
            'temperature' => 0.5,
        ]);
    } catch (Throwable $e) {
        ai_err('AI gagal merespons: ' . $e->getMessage(), 500);
    }

    CoinLedger::deduct('ai_chat_data', 'laporan_chat');
    if (function_exists('logAudit')) logAudit('ai_chat', 'laporan', mb_substr($pertanyaan, 0, 80));

    ai_ok([
        'ok'          => true,
        'jawaban'     => trim($result['text']),
        'tokens_used' => $result['tokens_in'] + $result['tokens_out'],
    ]);
}

// ══════════════════════════════════════════════════════
// 3) AI UPSELLING (POS — rekomendasi per pelanggan)
// ══════════════════════════════════════════════════════
if ($action === 'upselling' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $pid  = (int)($body['pelanggan_id'] ?? 0);
    $currentItems = is_array($body['current_items'] ?? null) ? $body['current_items'] : [];
    if (!$pid) ai_err('pelanggan_id wajib');

    // Cache per pelanggan per hari
    $key = sprintf('upsell_%d_%d_%s', $oid, $pid, $today);
    $cached = ai_cache_get($tid, $key);
    if ($cached !== null) {
        $cached['from_cache'] = true;
        ai_ok(['ok'=>true, 'data'=>$cached]);
        exit;
    }

    if (!CoinLedger::canAfford('ai_upselling')) {
        ai_err('Coin tidak cukup (butuh '.CoinLedger::COSTS['ai_upselling'].' coin)');
    }

    // Ambil profil + history
    try {
        $db = Database::get();
        $st = $db->prepare("SELECT nama, telepon, total_order, total_visit_count, COALESCE(total_spent, 0) total_spent
                              FROM hl_pelanggan WHERE id=? AND tenant_id=?");
        $st->execute([$pid, $tid]);
        $pel = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pel) ai_err('Pelanggan tidak ditemukan');

        // Top layanan yang pernah dipakai pelanggan ini
        $st = $db->prepare("SELECT i.nama_layanan, COUNT(*) qty, MAX(t.tanggal) terakhir
                              FROM hl_transaksi_item i
                              JOIN hl_transaksi t ON t.id=i.transaksi_id
                             WHERE t.tenant_id=? AND t.pelanggan_id=?
                          GROUP BY i.nama_layanan
                          ORDER BY qty DESC LIMIT 5");
        $st->execute([$tid, $pid]);
        $history = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Katalog layanan aktif outlet ini
        $st = $db->prepare("SELECT nama, kategori, harga, satuan FROM hl_layanan
                             WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY kategori, urutan");
        $st->execute([$tid, $oid]);
        $katalog = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        ai_err('Gagal load data: ' . $e->getMessage(), 500);
    }

    // Segmen otomatis
    $totalOrder = (int)($pel['total_order'] ?? 0);
    $segmen = $totalOrder >= 10 ? 'vip' : ($totalOrder >= 3 ? 'regular' : 'new');

    $currentNames = [];
    foreach ($currentItems as $it) {
        $currentNames[] = (string)($it['nama_layanan'] ?? '');
    }

    // Build prompt
    $promptLines = [];
    $promptLines[] = "Buat 3 rekomendasi upsell layanan untuk pelanggan laundry.";
    $promptLines[] = "";
    $promptLines[] = "PROFIL PELANGGAN:";
    $promptLines[] = "- Nama: " . ($pel['nama'] ?? '-');
    $promptLines[] = "- Total order historis: $totalOrder";
    $promptLines[] = "- Segmen: $segmen";
    $promptLines[] = "";
    if ($history) {
        $promptLines[] = "LAYANAN YANG PERNAH DIPAKAI:";
        foreach ($history as $h) {
            $promptLines[] = "- {$h['nama_layanan']} ({$h['qty']}x, terakhir " . date('d M', strtotime($h['terakhir'])) . ")";
        }
        $promptLines[] = "";
    }
    if ($currentNames) {
        $promptLines[] = "SEDANG DIPILIH SEKARANG:";
        $promptLines[] = "- " . implode(', ', array_filter($currentNames));
        $promptLines[] = "";
    }
    $promptLines[] = "KATALOG LAYANAN OUTLET (yang bisa direkomendasikan):";
    foreach (array_slice($katalog, 0, 20) as $k) {
        $promptLines[] = "- {$k['nama']} ({$k['kategori']}) — Rp " . number_format((int)$k['harga'], 0, ',', '.') . "/{$k['satuan']}";
    }
    $promptLines[] = "";
    $promptLines[] = "Pilih 3 layanan paling relevan untuk upsell. Hindari yang SUDAH dipilih saat ini.";
    $promptLines[] = "Respond JSON:";
    $promptLines[] = '{';
    $promptLines[] = '  "segmen": "new" | "regular" | "vip",';
    $promptLines[] = '  "insight": "1 kalimat ringkas pola customer ini",';
    $promptLines[] = '  "rekomendasi": [';
    $promptLines[] = '    {"layanan":"nama dari katalog","potensi_revenue":"Rp X.XXX","alasan":"kenapa cocok (8-12 kata)","script":"kalimat offering yang kasir bisa langsung pakai (1 kalimat natural)"},';
    $promptLines[] = '    {...}, {...}';
    $promptLines[] = '  ]';
    $promptLines[] = '}';

    $prompt = implode("\n", $promptLines);
    $system = "Kamu sales coach untuk kasir laundry. Tujuan: bantu kasir kasih offering natural, bukan pushy. "
            . "Bahasa Indonesia santai-profesional.";

    try {
        $result = AnthropicClient::askJson($prompt, [
            'system'      => $system,
            'max_tokens'  => 900,
            'temperature' => 0.6,
        ]);
    } catch (Throwable $e) {
        ai_err('AI gagal merespons: ' . $e->getMessage(), 500);
    }

    $json = $result['json'];
    $output = [
        'segmen'       => in_array(($json['segmen'] ?? $segmen), ['new','regular','vip'], true) ? $json['segmen'] : $segmen,
        'insight'      => (string)($json['insight'] ?? ''),
        'rekomendasi'  => is_array($json['rekomendasi'] ?? null) ? array_slice($json['rekomendasi'], 0, 3) : [],
        'tokens_used'  => $result['tokens_in'] + $result['tokens_out'],
        'generated_at' => date('Y-m-d H:i:s'),
        'from_cache'   => false,
    ];

    CoinLedger::deduct('ai_upselling', 'upsell_p'.$pid);
    ai_cache_put($tid, $oid, $key, $output, $result['tokens_in'], $result['tokens_out'], 24);
    if (function_exists('logAudit')) logAudit('ai_upselling', 'pelanggan#'.$pid, '');

    ai_ok(['ok'=>true, 'data'=>$output]);
    exit;
}

// ── Unknown action ──
ai_err('Unknown action: ' . $action, 404);
