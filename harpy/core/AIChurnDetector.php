<?php
// ══════════════════════════════════════════════════════
// core/AIChurnDetector.php
//
// Detect pelanggan churning + generate personalized message.
//
// Algoritma churning:
//   1. Pelanggan dengan ≥ MIN_ORDERS (default 3) order historis
//   2. Hitung avg interval days antar order (last 6 bulan)
//   3. Days since last order > MULTIPLIER × avg_interval → CHURNING
//   4. Sort priority desc by overdue ratio
//
// AI message:
//   - Personalized: nama pelanggan, layanan favorit, durasi overdue
//   - Bahasa Indonesia ramah, tidak pushy
//   - Include CTA (datang lagi, hubungi outlet)
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/AnthropicClient.php';

class AIChurnDetector
{
    const MIN_ORDERS_THRESHOLD = 3;     // pelanggan dengan ≥ 3 order historis
    const OVERDUE_MULTIPLIER   = 2.0;   // 2x avg interval = churning
    const LOOKBACK_MONTHS      = 6;     // analisa data 6 bulan terakhir

    /**
     * Detect pelanggan churning.
     *
     * @return array list of {pelanggan_id, nama, telepon, total_order,
     *                        last_order_date, days_since_last,
     *                        avg_interval_days, overdue_ratio, top_layanan}
     */
    public static function detect(int $tenantId, ?int $outletId = null, int $limit = 100): array
    {
        $db = Database::get();
        $lookbackDate = date('Y-m-d', strtotime("-" . self::LOOKBACK_MONTHS . " months"));

        $outletFilter = $outletId ? " AND t.outlet_id = ?" : "";

        // Query pelanggan dengan stats
        $sql = "
            SELECT
                p.id AS pelanggan_id,
                p.nama,
                p.telepon,
                COUNT(t.id) AS total_order,
                MIN(t.tanggal) AS first_order,
                MAX(t.tanggal) AS last_order,
                DATEDIFF(NOW(), MAX(t.tanggal)) AS days_since_last,
                DATEDIFF(MAX(t.tanggal), MIN(t.tanggal)) AS span_days
            FROM hl_pelanggan p
            JOIN hl_transaksi t ON t.pelanggan_id = p.id AND t.tenant_id = p.tenant_id
            WHERE p.tenant_id = ?
              AND t.tanggal >= ?
              $outletFilter
              AND p.telepon IS NOT NULL
              AND p.telepon != ''
            GROUP BY p.id, p.nama, p.telepon
            HAVING total_order >= ?
            ORDER BY days_since_last DESC
            LIMIT 500
        ";
        $params = [$tenantId, $lookbackDate];
        if ($outletId) $params[] = $outletId;
        $params[] = self::MIN_ORDERS_THRESHOLD;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $churning = [];
        foreach ($rows as $r) {
            $totalOrder = (int)$r['total_order'];
            $spanDays   = max(1, (int)$r['span_days']);
            $daysSince  = (int)$r['days_since_last'];

            // Avg interval = span / (n-1)
            $avgInterval = $totalOrder > 1
                ? round($spanDays / ($totalOrder - 1), 1)
                : 30; // default kalau cuma 1 order

            // Overdue ratio
            $overdueRatio = $avgInterval > 0
                ? round($daysSince / $avgInterval, 2)
                : 0;

            if ($overdueRatio < self::OVERDUE_MULTIPLIER) continue;

            // Skip pelanggan yang baru order kurang dari avg_interval (belum churning)
            if ($daysSince < 14) continue;

            $churning[] = [
                'pelanggan_id'      => (int)$r['pelanggan_id'],
                'nama'              => $r['nama'],
                'telepon'           => self::normalizePhone($r['telepon']),
                'total_order'       => $totalOrder,
                'last_order_date'   => $r['last_order'],
                'days_since_last'   => $daysSince,
                'avg_interval_days' => $avgInterval,
                'overdue_ratio'     => $overdueRatio,
            ];
        }

        // Sort desc by overdue_ratio (yang paling overdue dulu)
        usort($churning, fn($a, $b) => $b['overdue_ratio'] <=> $a['overdue_ratio']);

        // Ambil top N + enrich dengan top layanan
        $top = array_slice($churning, 0, $limit);
        foreach ($top as &$c) {
            $c['top_layanan'] = self::getTopLayanan($tenantId, $c['pelanggan_id']);
        }
        unset($c);

        return $top;
    }

    /** Top layanan pelanggan (top 1) */
    private static function getTopLayanan(int $tenantId, int $pelangganId): ?string
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("
                SELECT ti.nama_layanan, COUNT(*) cnt
                  FROM hl_transaksi_item ti
                  JOIN hl_transaksi t ON t.id = ti.transaksi_id
                 WHERE t.tenant_id = ? AND t.pelanggan_id = ?
                 GROUP BY ti.nama_layanan
                 ORDER BY cnt DESC LIMIT 1
            ");
            $stmt->execute([$tenantId, $pelangganId]);
            $row = $stmt->fetch();
            return $row ? $row['nama_layanan'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Format nomor telepon → 62xxx (untuk wa.me) */
    private static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($p, '0') === 0) $p = '62' . substr($p, 1);
        if (strpos($p, '62') !== 0) $p = '62' . $p;
        return $p;
    }

    /**
     * Generate personalized WA message untuk 1 pelanggan churning.
     *
     * @return array {message, tokens_in, tokens_out}
     */
    public static function generateMessage(array $customer, string $outletNama = 'Outlet kami'): array
    {
        $nama = $customer['nama'] ?? 'Kak';
        $daysSince = (int)($customer['days_since_last'] ?? 30);
        $topLayanan = $customer['top_layanan'] ?? 'laundry';
        $totalOrder = (int)($customer['total_order'] ?? 0);

        $system = "Kamu menulis pesan WhatsApp untuk pelanggan laundry yang sudah lama tidak datang. "
                . "Gaya: ramah, personal, casual, TIDAK pushy/jualan-banget. "
                . "Tone seperti teman yang kangen, bukan sales. "
                . "Maks 3-4 baris. Pakai emoji secukupnya (max 2-3). "
                . "TIDAK pakai kata 'promo'/'diskon' kecuali ada konteks. "
                . "Bahasa Indonesia natural, boleh sapaan 'Kak'/'Bun'/'Pak'/'Bu' tergantung nama. "
                . "Output HANYA isi pesan, tanpa header/footer.";

        $prompt = "Buat pesan WA untuk pelanggan dengan data:\n"
                . "- Nama: $nama\n"
                . "- Sudah $daysSince hari tidak laundry\n"
                . "- Layanan favorit dulu: $topLayanan\n"
                . "- Total order historis: $totalOrder kali\n"
                . "- Outlet: $outletNama\n\n"
                . "Ajak datang lagi dengan tone hangat, sebut layanan favorit kalau natural.";

        $result = AnthropicClient::ask($prompt, [
            'system'      => $system,
            'max_tokens'  => 250,
            'temperature' => 0.8, // sedikit kreatif
        ]);

        // Strip kalau ada quote atau prefix
        $msg = trim($result['text']);
        $msg = trim($msg, "\"' ");

        return [
            'message'    => $msg,
            'tokens_in'  => $result['tokens_in'],
            'tokens_out' => $result['tokens_out'],
        ];
    }

    /** Log outreach ke DB */
    public static function logOutreach(
        int $tenantId, ?int $outletId, int $pelangganId,
        string $message, array $meta = []
    ): ?int {
        try {
            $db = Database::get();
            $stmt = $db->prepare("
                INSERT INTO hl_ai_outreach_log
                  (tenant_id, outlet_id, pelanggan_id, campaign_type, message_text, status, meta_json)
                VALUES (?, ?, ?, 'churn_winback', ?, 'generated', ?)
            ");
            $stmt->execute([
                $tenantId, $outletId, $pelangganId,
                $message,
                json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
            return (int)$db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[AIChurnDetector::logOutreach] ' . $e->getMessage());
            return null;
        }
    }

    /** Update status outreach (sent/skipped/dismissed) */
    public static function updateStatus(int $logId, int $tenantId, string $status, ?int $byUserId = null): bool
    {
        if (!in_array($status, ['sent','skipped','dismissed'], true)) return false;
        try {
            $db = Database::get();
            $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;
            $stmt = $db->prepare("
                UPDATE hl_ai_outreach_log
                   SET status=?, sent_at=?, by_user_id=?
                 WHERE id=? AND tenant_id=?
            ");
            $stmt->execute([$status, $sentAt, $byUserId, $logId, $tenantId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
