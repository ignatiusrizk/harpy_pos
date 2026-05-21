<?php
// ══════════════════════════════════════════════════════
// core/AIInsight.php — Domain logic AI Insight
//
// Pakai AnthropicClient + cache di tabel hl_ai_cache.
//
// Usage:
//   $insight = AIInsight::analyzeLaporan($laporanData, $tenantId, $outletId);
//   echo $insight['summary'];
//   foreach ($insight['highlights'] as $h) { ... }
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/AnthropicClient.php';

class AIInsight
{
    // Cost coin per request — sync dengan CoinLedger::COSTS['ai_insight_laporan']
    const COIN_PER_INSIGHT = 100;
    // Cache TTL (24 jam)
    const CACHE_TTL_HOURS  = 24;

    /**
     * Analisa data laporan dengan AI.
     * Return cached kalau request sama dalam 24 jam.
     *
     * @param array $data Data laporan {
     *   @var string $periode_label  "01 Mei - 19 Mei 2026"
     *   @var int    $omset
     *   @var int    $omset_prev     Omset periode sebelumnya (opsional)
     *   @var int    $order_count
     *   @var int    $avg_ticket
     *   @var array  $top_layanan    [['nama'=>..., 'qty'=>..., 'total'=>...], ...]
     *   @var array  $top_karyawan   [['nama'=>..., 'order'=>...], ...]
     *   @var array  $per_outlet     (HQ only) [['nama'=>..., 'omset'=>...], ...]
     *   @var string $scope          'outlet' atau 'hq'
     * }
     * @param int $tenantId
     * @param int|null $outletId (null = HQ scope)
     *
     * @return array {
     *   @var string $summary
     *   @var array  $highlights     Bullet points
     *   @var array  $recommendations Bullet points
     *   @var bool   $from_cache
     *   @var int    $tokens_used
     *   @var float  $cost_usd
     *   @var string $generated_at
     * }
     */
    public static function analyzeLaporan(array $data, int $tenantId, ?int $outletId = null): array
    {
        $scope = $outletId ? 'outlet' : 'hq';
        $cacheKey = sprintf('laporan_%s_%s_%s',
            $scope,
            $outletId ?: '0',
            md5(json_encode($data))
        );

        // Cek cache
        $cached = self::getCache($tenantId, $cacheKey);
        if ($cached !== null) {
            $cached['from_cache'] = true;
            return $cached;
        }

        // Build prompt
        $prompt = self::buildLaporanPrompt($data);

        $system = "Kamu adalah konsultan bisnis laundry berpengalaman 10 tahun. "
                . "Kamu membantu owner outlet laundry memahami kondisi bisnis dari data laporan. "
                . "Gaya bahasa: profesional tapi hangat, tidak formal kaku, langsung ke poin. "
                . "Bahasa Indonesia.";

        try {
            $result = AnthropicClient::askJson($prompt, [
                'system'      => $system,
                'max_tokens'  => 1500,
                'temperature' => 0.5,
            ]);
        } catch (Throwable $e) {
            error_log('[AIInsight::analyzeLaporan] ' . $e->getMessage());
            throw $e;
        }

        $json = $result['json'];
        $output = [
            'summary'         => (string)($json['summary'] ?? ''),
            'highlights'      => is_array($json['highlights'] ?? null) ? $json['highlights'] : [],
            'recommendations' => is_array($json['recommendations'] ?? null) ? $json['recommendations'] : [],
            'tokens_used'     => $result['tokens_in'] + $result['tokens_out'],
            'cost_usd'        => $result['cost_usd'],
            'generated_at'    => date('Y-m-d H:i:s'),
            'from_cache'      => false,
        ];

        // Simpan ke cache
        self::saveCache($tenantId, $outletId, $cacheKey, $output, $result['tokens_in'], $result['tokens_out']);

        return $output;
    }

    /**
     * Briefing HQ harian — ringkasan kondisi semua outlet dalam 1 paragraf.
     * Cache per HARI (key = tanggal), jadi cuma 1x hit API per hari.
     *
     * @param array $data {
     *   @var string $tanggal_label  "Selasa, 19 Mei 2026"
     *   @var int    $omset_today
     *   @var int    $order_today
     *   @var int    $order_aktif
     *   @var int    $pipeline_siap
     *   @var int    $pipeline_selesai
     *   @var array  $outlets    [['nama'=>, 'omset_today'=>, 'order_today'=>, 'order_aktif'=>], ...]
     *   @var array  $alerts     list string alert operasional
     * }
     * @return array { briefing, from_cache, tokens_used, generated_at }
     */
    public static function briefingHQ(array $data, int $tenantId): array
    {
        // Cache key per hari (tidak include data hash — biar stabil sepanjang hari,
        // di-refresh otomatis besok)
        $cacheKey = 'briefing_hq_' . date('Y-m-d');

        $cached = self::getCache($tenantId, $cacheKey);
        if ($cached !== null) {
            $cached['from_cache'] = true;
            return $cached;
        }

        $prompt = self::buildBriefingPrompt($data);

        $system = "Kamu adalah asisten eksekutif yang memberikan briefing pagi singkat untuk owner "
                . "jaringan laundry. Gaya: ringkas, to the point, seperti chief of staff melapor ke bos. "
                . "1-2 paragraf saja (maks 5 kalimat). Sebut angka spesifik dan outlet by name. "
                . "Kalau ada masalah operasional, tekankan. Kalau semua baik, apresiasi singkat. "
                . "Bahasa Indonesia profesional tapi hangat. Mulai dengan sapaan singkat sesuai waktu.";

        try {
            $result = AnthropicClient::ask($prompt, [
                'system'      => $system,
                'max_tokens'  => 500,
                'temperature' => 0.6,
            ]);
        } catch (Throwable $e) {
            error_log('[AIInsight::briefingHQ] ' . $e->getMessage());
            throw $e;
        }

        $output = [
            'briefing'     => trim($result['text']),
            'tokens_used'  => $result['tokens_in'] + $result['tokens_out'],
            'cost_usd'     => $result['cost_usd'],
            'generated_at' => date('Y-m-d H:i:s'),
            'from_cache'   => false,
        ];

        self::saveCache($tenantId, null, $cacheKey, $output, $result['tokens_in'], $result['tokens_out']);

        return $output;
    }

    /** Build prompt briefing HQ */
    private static function buildBriefingPrompt(array $data): string
    {
        $lines = [];
        $lines[] = "Buat briefing pagi untuk owner. Data hari ini (" . ($data['tanggal_label'] ?? date('d M Y')) . "):";
        $lines[] = "";
        $lines[] = "RINGKASAN GLOBAL:";
        $lines[] = "- Omset hari ini: Rp " . number_format((int)($data['omset_today'] ?? 0), 0, ',', '.');
        $lines[] = "- Total order hari ini: " . (int)($data['order_today'] ?? 0);
        $lines[] = "- Order dalam proses: " . (int)($data['order_aktif'] ?? 0);
        $lines[] = "- Order siap diambil: " . (int)($data['pipeline_siap'] ?? 0);
        $lines[] = "- Order selesai hari ini: " . (int)($data['pipeline_selesai'] ?? 0);

        if (!empty($data['outlets']) && is_array($data['outlets'])) {
            $lines[] = "";
            $lines[] = "PER OUTLET:";
            foreach ($data['outlets'] as $o) {
                $lines[] = sprintf("- %s: omset Rp %s, %d order hari ini, %d order proses",
                    $o['nama'] ?? '?',
                    number_format((int)($o['omset_today'] ?? 0), 0, ',', '.'),
                    (int)($o['order_today'] ?? 0),
                    (int)($o['order_aktif'] ?? 0)
                );
            }
        }

        if (!empty($data['alerts']) && is_array($data['alerts'])) {
            $lines[] = "";
            $lines[] = "ALERT OPERASIONAL:";
            foreach ($data['alerts'] as $a) {
                $lines[] = "- " . strip_tags((string)$a);
            }
        } else {
            $lines[] = "";
            $lines[] = "ALERT OPERASIONAL: tidak ada masalah.";
        }

        $lines[] = "";
        $lines[] = "Tulis briefing pagi 1-2 paragraf. Highlight outlet terbaik, masalah yang perlu perhatian, dan saran prioritas hari ini.";

        return implode("\n", $lines);
    }

    /** Build prompt untuk laporan analysis */
    private static function buildLaporanPrompt(array $data): string
    {
        $periode = $data['periode_label'] ?? 'periode terakhir';
        $omset   = number_format((int)($data['omset'] ?? 0), 0, ',', '.');
        $order   = (int)($data['order_count'] ?? 0);
        $avg     = number_format((int)($data['avg_ticket'] ?? 0), 0, ',', '.');
        $scope   = $data['scope'] ?? 'outlet';

        $lines = [];
        $lines[] = "Analisa kondisi bisnis " . ($scope === 'hq' ? "seluruh outlet (konsolidasi)" : "outlet ini") . " untuk periode $periode.";
        $lines[] = "";
        $lines[] = "DATA:";
        $lines[] = "- Omset: Rp $omset";
        $lines[] = "- Jumlah order: $order transaksi";
        $lines[] = "- Avg ticket: Rp $avg per order";

        if (!empty($data['omset_prev'])) {
            $prev = (int)$data['omset_prev'];
            $delta = (int)($data['omset'] ?? 0) - $prev;
            $pct = $prev > 0 ? round(($delta / $prev) * 100, 1) : 0;
            $arrow = $delta >= 0 ? '↑' : '↓';
            $lines[] = "- vs periode sebelumnya: Rp " . number_format($prev, 0, ',', '.') . " ($arrow " . abs($pct) . "%)";
        }

        if (!empty($data['top_layanan']) && is_array($data['top_layanan'])) {
            $lines[] = "";
            $lines[] = "TOP LAYANAN:";
            foreach (array_slice($data['top_layanan'], 0, 5) as $i => $l) {
                $nm = $l['nama'] ?? '?';
                $qty = (int)($l['qty'] ?? 0);
                $tot = number_format((int)($l['total'] ?? 0), 0, ',', '.');
                $lines[] = sprintf("%d. %s — %d order, total Rp %s", $i+1, $nm, $qty, $tot);
            }
        }

        if (!empty($data['top_karyawan']) && is_array($data['top_karyawan'])) {
            $lines[] = "";
            $lines[] = "TOP KARYAWAN:";
            foreach (array_slice($data['top_karyawan'], 0, 3) as $i => $k) {
                $lines[] = sprintf("%d. %s — %d order ditangani",
                    $i+1, $k['nama'] ?? '?', (int)($k['order'] ?? 0));
            }
        }

        if (!empty($data['per_outlet']) && is_array($data['per_outlet'])) {
            $lines[] = "";
            $lines[] = "PERFORMANCE PER OUTLET:";
            foreach ($data['per_outlet'] as $o) {
                $lines[] = sprintf("- %s: Rp %s (%d order)",
                    $o['nama'] ?? '?',
                    number_format((int)($o['omset'] ?? 0), 0, ',', '.'),
                    (int)($o['order'] ?? 0)
                );
            }
        }

        $lines[] = "";
        $lines[] = "Berikan analisa dalam format JSON dengan struktur:";
        $lines[] = '{';
        $lines[] = '  "summary": "1 paragraf (maks 3 kalimat) ringkas kondisi bisnis. Sebut angka spesifik.",';
        $lines[] = '  "highlights": [';
        $lines[] = '    "Bullet 1 — pattern/insight menarik dari data (sebut angka)",';
        $lines[] = '    "Bullet 2",';
        $lines[] = '    "Bullet 3 (3-5 bullet total)"';
        $lines[] = '  ],';
        $lines[] = '  "recommendations": [';
        $lines[] = '    "Rekomendasi konkret 1 (actionable, bukan generic)",';
        $lines[] = '    "Rekomendasi 2 (2-3 bullet total)"';
        $lines[] = '  ]';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    // ── Cache helpers ────────────────────────────────────

    /** Public peek — cek cache tanpa generate (untuk auto-load tanpa charge) */
    public static function peekCache(int $tenantId, string $cacheKey): ?array
    {
        return self::getCache($tenantId, $cacheKey);
    }

    /** Cache key briefing harian (dipakai dashboard untuk peek) */
    public static function briefingCacheKey(): string
    {
        return 'briefing_hq_' . date('Y-m-d');
    }

    private static function getCache(int $tenantId, string $cacheKey): ?array
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare(
                "SELECT response_json FROM hl_ai_cache
                 WHERE tenant_id = ? AND cache_key = ? AND expires_at > NOW()
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$tenantId, $cacheKey]);
            $row = $stmt->fetch();
            if (!$row) return null;
            $data = json_decode($row['response_json'], true);
            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            error_log('[AIInsight::getCache] ' . $e->getMessage());
            return null;
        }
    }

    private static function saveCache(int $tenantId, ?int $outletId, string $cacheKey, array $output, int $tokIn, int $tokOut): void
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare(
                "INSERT INTO hl_ai_cache
                 (tenant_id, outlet_id, cache_key, prompt_hash, response_json, tokens_in, tokens_out, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))"
            );
            $stmt->execute([
                $tenantId,
                $outletId,
                $cacheKey,
                substr($cacheKey, -64),
                json_encode($output, JSON_UNESCAPED_UNICODE),
                $tokIn,
                $tokOut,
                self::CACHE_TTL_HOURS,
            ]);
        } catch (Throwable $e) {
            error_log('[AIInsight::saveCache] ' . $e->getMessage());
        }
    }

    /** Force invalidate cache untuk key tertentu */
    public static function invalidateCache(int $tenantId, string $cacheKey): void
    {
        try {
            $db = Database::get();
            $db->prepare("DELETE FROM hl_ai_cache WHERE tenant_id=? AND cache_key=?")
               ->execute([$tenantId, $cacheKey]);
        } catch (Throwable $e) {
            // silent
        }
    }
}
