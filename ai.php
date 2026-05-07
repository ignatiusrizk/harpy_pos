<?php
// ai.php — Backend AI Harpy Laundry
// Handle semua call ke Anthropic API
// API key disimpan di server, tidak pernah exposed ke browser

require_once 'auth.php';
require_once 'components.php';
requireLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$pdo    = getDB();
$user   = currentUser();

// ── KONFIGURASI ───────────────────────────────────────
// ANTHROPIC_API_KEY didefinisikan di config.local.php
define('ANTHROPIC_MODEL',   'claude-sonnet-4-5');
define('ANTHROPIC_MAX_TOKENS', 1024);

// ── ROLE-BASED PERSONA ────────────────────────────────
// Menentukan fokus & gaya komunikasi AI berdasarkan role user
function getRolePersona(array $user): array {
    $roleLower = strtolower($user['role_nama'] ?? $user['role'] ?? 'staff');
    $nama      = $user['nama'] ?? 'User';

    // Owner / Superadmin → fokus bisnis & strategis
    if (in_array($roleLower, ['owner','superadmin'])) {
        return [
            'persona' => "Kamu berbicara dengan {$nama}, pemilik Harpy Laundry.\nFokuskan jawaban pada:\n- Kesehatan bisnis, profit, dan tren pendapatan\n- Insight strategis untuk pertumbuhan bisnis\n- Efisiensi biaya dan peluang peningkatan margin\n- Perbandingan performa vs periode sebelumnya\n- Rekomendasi bisnis jangka menengah-panjang\n- Risiko bisnis yang perlu diwaspadai\nGunakan bahasa profesional dan data-driven. Sertakan angka spesifik.\nFokus pada \"big picture\" — bukan detail operasional harian.",
            'tone'    => 'strategis',
            'fokus'   => 'bisnis & profit',
        ];
    }

    // Manager / Admin → fokus operasional + performa
    if (in_array($roleLower, ['manager','admin'])) {
        return [
            'persona' => "Kamu berbicara dengan {$nama}, manager operasional Harpy Laundry.\nFokuskan jawaban pada:\n- Status operasional hari ini dan alert yang perlu ditangani\n- Performa tim dan karyawan\n- Efisiensi alur kerja laundry (antrian, bottleneck)\n- Pengelolaan piutang dan cash flow harian\n- Target harian/mingguan dan pencapaiannya\nGunakan bahasa praktis dan actionable. Prioritaskan hal yang urgent.",
            'tone'    => 'operasional',
            'fokus'   => 'operasional & performa tim',
        ];
    }

    // Default: Staff / Kasir → fokus tugas harian & customer
    return [
        'persona' => "Kamu berbicara dengan {$nama}, kasir/staf Harpy Laundry.\nFokuskan jawaban pada:\n- Order yang perlu segera diproses atau disiapkan\n- Customer yang menunggu atau perlu dihubungi\n- Tugas-tugas operasional yang harus diselesaikan hari ini\n- Tips layanan customer yang lebih baik\nGunakan bahasa yang sederhana, singkat, dan langsung to the point.\nHindari informasi finansial detail — fokus pada tugas harian.",
        'tone'    => 'operasional harian',
        'fokus'   => 'tugas harian & customer service',
    ];
}

$rolePersona = getRolePersona($user);

// ── HELPER: Call Anthropic API ────────────────────────
function callClaude(string $systemPrompt, string $userMessage): array {
    $payload = [
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => ANTHROPIC_MAX_TOKENS,
        'system'     => $systemPrompt,
        'messages'   => [
            ['role' => 'user', 'content' => $userMessage]
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['error' => 'Connection error: ' . $curlError];
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? $response;
        return ['error' => 'API error HTTP ' . $httpCode . ': ' . $errMsg];
    }

    $data = json_decode($response, true);
    if (empty($data['content'][0]['text'])) return ['error' => 'Empty response from AI'];

    return ['text' => $data['content'][0]['text']];
}

// Semua POST ke ai.php wajib valid CSRF + rate limit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!checkRateLimit('ai', 20, 3600)) {
        http_response_code(429);
        echo json_encode(['error' => 'Terlalu banyak request AI. Batas 20 request/jam. Coba lagi nanti.']);
        exit;
    }
    recordRateLimit('ai');
}

// ── ACTION: UPSELLING REKOMENDASI ─────────────────────
if ($action === 'upselling' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input       = json_decode(file_get_contents('php://input'), true);
    $pelangganId = intval($input['pelanggan_id'] ?? 0);
    $currentItems= $input['current_items'] ?? []; // item yang sudah dipilih kasir

    // Ambil histori order customer
    $histori = [];
    if ($pelangganId) {
        $stmt = $pdo->prepare("SELECT t.no_order, t.tanggal, t.total,
            GROUP_CONCAT(i.nama_layanan ORDER BY i.id SEPARATOR ', ') as layanan,
            GROUP_CONCAT(i.jumlah ORDER BY i.id SEPARATOR ', ') as jumlah,
            GROUP_CONCAT(i.satuan ORDER BY i.id SEPARATOR ', ') as satuan
            FROM hl_transaksi t
            JOIN hl_transaksi_item i ON i.transaksi_id = t.id
            WHERE t.pelanggan_id = ?
            GROUP BY t.id ORDER BY t.tanggal DESC LIMIT 10");
        $stmt->execute([$pelangganId]);
        $histori = $stmt->fetchAll();

        // Data customer
        $custStmt = $pdo->prepare("SELECT nama, telepon,
            (SELECT COUNT(*) FROM hl_transaksi WHERE pelanggan_id=?) as total_order,
            (SELECT COALESCE(SUM(total),0) FROM hl_transaksi WHERE pelanggan_id=?) as total_omset,
            (SELECT MAX(tanggal) FROM hl_transaksi WHERE pelanggan_id=?) as last_order
            FROM hl_pelanggan WHERE id=?");
        $custStmt->execute([$pelangganId,$pelangganId,$pelangganId,$pelangganId]);
        $customer = $custStmt->fetch();
    }

    // Ambil semua layanan aktif
    $semuaLayanan = $pdo->query("SELECT nama, kategori, satuan, harga FROM hl_layanan WHERE is_active=1 ORDER BY kategori, nama")->fetchAll();

    // Ambil top kombinasi layanan (collaborative filtering sederhana)
    $topKombinasi = $pdo->query("SELECT i1.nama_layanan as layanan1, i2.nama_layanan as layanan2,
        COUNT(*) as frekuensi
        FROM hl_transaksi_item i1
        JOIN hl_transaksi_item i2 ON i1.transaksi_id = i2.transaksi_id AND i1.id < i2.id
        GROUP BY layanan1, layanan2
        ORDER BY frekuensi DESC LIMIT 15")->fetchAll();

    // Build context untuk AI
    $historiText = '';
    foreach ($histori as $h) {
        $historiText .= "- {$h['tanggal']}: {$h['layanan']} | Total Rp " . number_format($h['total'],0,',','.') . "\n";
    }

    $layananText = '';
    $lastKat = '';
    foreach ($semuaLayanan as $l) {
        if ($l['kategori'] !== $lastKat) { $layananText .= "\n[{$l['kategori']}]\n"; $lastKat = $l['kategori']; }
        $layananText .= "- {$l['nama']} (Rp " . number_format($l['harga'],0,',','.') . "/{$l['satuan']})\n";
    }

    $kombinasiText = '';
    foreach ($topKombinasi as $k) {
        $kombinasiText .= "- {$k['layanan1']} + {$k['layanan2']} ({$k['frekuensi']}x)\n";
    }

    $currentText = '';
    foreach ($currentItems as $item) {
        $currentText .= "- {$item['nama_layanan']} ({$item['jumlah']} {$item['satuan']})\n";
    }

    $namaCustomer = $customer['nama'] ?? 'Customer baru';
    $totalOrder   = $customer['total_order'] ?? 0;
    $totalOmset   = $customer['total_omset'] ?? 0;
    $lastOrder    = $customer['last_order'] ?? '-';

    $persona = $rolePersona['persona'];
    $systemPrompt = <<<PROMPT
Kamu adalah AI asisten upselling untuk Harpy Laundry, sebuah bisnis laundry di Jakarta.

{$persona}

TUGAS UPSELLING:
- Berikan maksimal 3 rekomendasi layanan tambahan yang relevan
- Setiap rekomendasi harus ada alasan spesifik berdasarkan data histori
- Gunakan bahasa Indonesia yang singkat dan mudah dipahami kasir
- Fokus pada layanan yang belum pernah dicoba atau yang sering dibeli bersamaan
- Pertimbangkan nilai transaksi customer (jangan over-push ke customer baru)
- Response HARUS dalam format JSON valid, tidak ada teks lain di luar JSON

Format response JSON:
{
  "segmen": "string (new/regular/vip)",
  "insight": "string (1 kalimat insight tentang customer ini)",
  "rekomendasi": [
    {
      "layanan": "nama layanan",
      "alasan": "alasan singkat 1 kalimat",
      "script": "kalimat yang bisa langsung diucapkan kasir ke customer",
      "potensi_revenue": "estimasi tambahan revenue"
    }
  ]
}
PROMPT;

    $userMessage = <<<MSG
DATA CUSTOMER:
Nama: {$namaCustomer}
Total order: {$totalOrder} kali
Total spending: Rp {$totalOmset}
Order terakhir: {$lastOrder}

HISTORI ORDER (10 terakhir):
{$historiText}

ORDER SAAT INI (yang sudah dipilih kasir):
{$currentText}

SEMUA LAYANAN TERSEDIA:
{$layananText}

KOMBINASI LAYANAN POPULER DI HARPY LAUNDRY:
{$kombinasiText}

Berikan rekomendasi upselling yang tepat untuk customer ini berdasarkan data di atas.
MSG;

    $result = callClaude($systemPrompt, $userMessage);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]); exit;
    }

    // Parse JSON dari response AI
    $text = $result['text'];
    // Bersihkan jika ada markdown code block
    $text = preg_replace('/```json\s*|\s*```/', '', $text);
    $text = trim($text);

    $parsed = json_decode($text, true);
    if (!$parsed) {
        echo json_encode(['error' => 'AI response tidak valid', 'raw' => $text]); exit;
    }

    echo json_encode(['success' => true, 'data' => $parsed]);
    exit;
}

// ── ACTION: AI BRIEFING HARIAN (untuk dashboard) ──────
if ($action === 'briefing') {
    $today = date('Y-m-d');
    $user  = currentUser();

    // Ambil data relevan
    // Pastikan kolom metode_bayar ada
    try { $pdo->exec("ALTER TABLE hl_pelanggan ADD COLUMN metode_bayar ENUM('langsung','bulanan') DEFAULT 'langsung'"); } catch(Exception $e) {}

    $statsOrder = $pdo->query("SELECT COUNT(*) as total,
        COALESCE(SUM(total),0) as omset,
        SUM(CASE WHEN status_proses='siap' THEN 1 ELSE 0 END) as siap,
        SUM(CASE WHEN status_bayar!='lunas' THEN 1 ELSE 0 END) as belum_lunas
        FROM hl_transaksi WHERE DATE(tanggal)='$today'")->fetch();

    $orderAktif = $pdo->query("SELECT COUNT(*) FROM hl_transaksi WHERE status_proses NOT IN ('diambil')")->fetchColumn();

    $mepet = $pdo->query("SELECT COUNT(*) FROM hl_transaksi
        WHERE estimasi_selesai <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        AND status_proses NOT IN ('siap','diambil')")->fetchColumn();

    $piutang = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(t.sisa_bayar),0) as total_piutang
        FROM hl_transaksi t
        LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
        WHERE t.status_bayar != 'lunas'
        AND t.tanggal <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
        AND t.status_proses != 'diambil'
        AND (p.metode_bayar IS NULL OR p.metode_bayar = 'langsung')")->fetch();

    $omset7 = $pdo->query("SELECT COALESCE(SUM(total),0) as total
        FROM hl_transaksi
        WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

    $omset7prev = $pdo->query("SELECT COALESCE(SUM(total),0) as total
        FROM hl_transaksi
        WHERE tanggal BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

    $hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][date('w')];

    $persona = $rolePersona['persona'];
    $fokus   = $rolePersona['fokus'];

    $systemPrompt = <<<PROMPT
Kamu adalah AI asisten operasional untuk Harpy Laundry Jakarta.

{$persona}

TUGAS BRIEFING HARIAN:
Buat briefing yang singkat, actionable, dan relevan untuk fokus: {$fokus}
Gunakan bahasa Indonesia yang natural dan profesional.
Response HARUS dalam format JSON valid.

Format JSON:
{
  "kondisi": "baik/waspada/kritis",
  "ringkasan": "1-2 kalimat kondisi bisnis hari ini sesuai peran user",
  "poin_penting": ["max 3 poin aksi yang relevan dengan peran user"],
  "peluang": "1 peluang atau insight yang relevan dengan peran user",
  "salam": "sapaan singkat yang natural"
}
PROMPT;

    $trendPct = $omset7prev > 0 ? round(($omset7 - $omset7prev) / $omset7prev * 100) : 0;
    $trendTxt = $trendPct >= 0 ? "+{$trendPct}%" : "{$trendPct}%";

    $userMessage = <<<MSG
Hari ini: {$hari}, {$today}
Nama pengguna: {$user['nama']}

DATA HARI INI:
- Order masuk: {$statsOrder['total']}
- Omset: Rp {$statsOrder['omset']}
- Order aktif (belum diambil): {$orderAktif}
- Siap diambil: {$statsOrder['siap']}
- Belum bayar: {$statsOrder['belum_lunas']}

ALERT:
- Order mendekati estimasi: {$mepet}
- Piutang >3 hari: {$piutang['cnt']} order (total Rp {$piutang['total_piutang']})

TREND:
- Omset 7 hari ini vs 7 hari lalu: {$trendTxt}

Buatkan briefing harian yang singkat dan actionable.
MSG;

    $result = callClaude($systemPrompt, $userMessage);
    if (isset($result['error'])) { echo json_encode(['error' => $result['error']]); exit; }

    $text   = preg_replace('/```json\s*|\s*```/', '', $result['text']);
    $parsed = json_decode(trim($text), true);
    if (!$parsed) { echo json_encode(['error' => 'Parse error', 'raw' => $result['text']]); exit; }

    echo json_encode(['success' => true, 'data' => $parsed]);
    exit;
}

// ── ACTION: ANALISIS LAPORAN ──────────────────────────
if ($action === 'laporan_analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d          = json_decode(file_get_contents('php://input'), true);
    $pertanyaan = sanitizeStr($d['pertanyaan'] ?? '', 500);
    $tipe       = $d['tipe']    ?? 'harian';
    $periode    = $d['periode'] ?? '-';
    $data       = $d['data']    ?? [];
    $history    = array_slice($d['history'] ?? [], -10); // maks 10 history

    if (!$pertanyaan) { echo json_encode(['error' => 'Pertanyaan tidak boleh kosong']); exit; }

    // Build context data
    $dataCtx = '';
    if ($tipe === 'harian' && !empty($data)) {
        $ord = $data['order'] ?? [];
        $kas = $data['kas']   ?? [];
        $dataCtx = "LAPORAN HARIAN — {$periode}\n\n";
        $dataCtx .= "Order:\n";
        $dataCtx .= "- Total order: " . ($ord['total_order']??0) . "\n";
        $dataCtx .= "- Omset: Rp " . number_format($ord['omset']??0,0,',','.') . "\n";
        $dataCtx .= "- Terkumpul: Rp " . number_format($ord['terkumpul']??0,0,',','.') . "\n";
        $dataCtx .= "- Diskon: Rp " . number_format($ord['total_diskon']??0,0,',','.') . "\n";
        $dataCtx .= "- Lunas: " . ($ord['lunas']??0) . " | DP: " . ($ord['dp_count']??0) . " | Belum bayar: " . ($ord['belum_bayar']??0) . "\n\n";
        $dataCtx .= "Layanan hari ini:\n";
        foreach (($data['layanan']??[]) as $l) {
            $dataCtx .= "- {$l['nama_layanan']}: {$l['total_order']} order, Rp " . number_format($l['total_omset'],0,',','.') . "\n";
        }
        $dataCtx .= "\nKas:\n";
        $dataCtx .= "- Masuk: Rp " . number_format($kas['kas_masuk']??0,0,',','.') . "\n";
        $dataCtx .= "- Keluar: Rp " . number_format($kas['kas_keluar']??0,0,',','.') . "\n";
        $dataCtx .= "- Saldo: Rp " . number_format(($kas['kas_masuk']??0) - ($kas['kas_keluar']??0),0,',','.') . "\n";

    } elseif ($tipe === 'bulanan' && !empty($data)) {
        $sm  = $data['summary'] ?? [];
        $kas = $data['kas']     ?? [];
        $dataCtx = "LAPORAN BULANAN — {$periode}\n\n";
        $dataCtx .= "Ringkasan:\n";
        $dataCtx .= "- Total order: " . ($sm['total_order']??0) . "\n";
        $dataCtx .= "- Omset: Rp " . number_format($sm['omset']??0,0,',','.') . "\n";
        $dataCtx .= "- Terkumpul: Rp " . number_format($sm['terkumpul']??0,0,',','.') . "\n";
        $dataCtx .= "- Piutang: Rp " . number_format($sm['total_piutang']??0,0,',','.') . "\n";
        $dataCtx .= "- Diskon: Rp " . number_format($sm['total_diskon']??0,0,',','.') . "\n";
        $dataCtx .= "- Order selesai: " . ($sm['selesai']??0) . "\n\n";
        $dataCtx .= "Layanan terlaris:\n";
        foreach (($data['top_layanan']??[]) as $i => $l) {
            $dataCtx .= ($i+1) . ". {$l['nama_layanan']}: {$l['total_order']} order, Rp " . number_format($l['total_omset'],0,',','.') . "\n";
        }
        $dataCtx .= "\nKas:\n";
        $dataCtx .= "- Masuk: Rp " . number_format($kas['kas_masuk']??0,0,',','.') . "\n";
        $dataCtx .= "- Keluar: Rp " . number_format($kas['kas_keluar']??0,0,',','.') . "\n";
        $dataCtx .= "- Saldo bersih: Rp " . number_format(($kas['kas_masuk']??0)-($kas['kas_keluar']??0),0,',','.') . "\n";
        $dataCtx .= "\nPengeluaran per kategori:\n";
        foreach (($data['pengeluaran']??[]) as $p) {
            $dataCtx .= "- {$p['kategori']}: Rp " . number_format($p['total'],0,',','.') . " ({$p['count']}x)\n";
        }
        $dataCtx .= "\nOmset harian:\n";
        foreach (($data['daily']??[]) as $day) {
            $dataCtx .= "- {$day['tgl']}: {$day['total_order']} order, Rp " . number_format($day['omset'],0,',','.') . "\n";
        }
    }

    // Build messages dengan history
    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $pertanyaan];

    $persona = $rolePersona['persona'];
    $fokus   = $rolePersona['fokus'];

    $systemPrompt = <<<PROMPT
Kamu adalah AI analis bisnis untuk Harpy Laundry, bisnis laundry di Jakarta.

{$persona}

TUGAS ANALISIS LAPORAN:
- Jawab pertanyaan berdasarkan data laporan yang diberikan
- Sesuaikan kedalaman analisis dengan fokus: {$fokus}
- Berikan insight yang actionable dan relevan dengan peran user
- Gunakan angka spesifik dari data yang diberikan
- Jika ada tren atau anomali, sebutkan dan jelaskan kemungkinan penyebabnya
- Jawaban maksimal 3-4 paragraf, jelas dan to the point
- Jika pertanyaan tidak bisa dijawab dari data yang ada, katakan dengan jujur

DATA LAPORAN SAAT INI:
{$dataCtx}

Tipe laporan: {$tipe}
Periode: {$periode}
PROMPT;

    // Build payload dengan history
    $payload = [
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => 1024,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { echo json_encode(['error' => 'Connection error: ' . $curlError]); exit; }
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        echo json_encode(['error' => 'API error HTTP ' . $httpCode . ': ' . ($errData['error']['message']??$response)]); exit;
    }

    $respData = json_decode($response, true);
    $jawaban  = $respData['content'][0]['text'] ?? '';
    echo json_encode(['success' => true, 'jawaban' => $jawaban]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);