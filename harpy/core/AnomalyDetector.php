<?php
// ══════════════════════════════════════════════════════
// core/AnomalyDetector.php — Deteksi anomali outlet & kirim alert
//
// Dipanggil dari:
//   - pos.php (setelah create order)
//   - kas.php (setelah create entry kas)
//   - tenant_guard.php (1x per session per 30 menit)
//
// Rate limit: tiap type alert tidak terkirim >1x dalam 6 jam (via Notifier).
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Notifier.php';

class AnomalyDetector
{
    /** Run semua check. Idempotent + rate-limited via Notifier. */
    public static function check(int $tenantId, int $outletId): void
    {
        try {
            self::checkOmsetDrop($tenantId, $outletId);
            self::checkKasBelumDiinput($tenantId, $outletId);
            self::checkOrderMenumpuk($tenantId, $outletId);
            self::checkAbsensiRendah($tenantId, $outletId);
            self::checkCoinRendah($tenantId, $outletId);
        } catch (Throwable $e) {
            error_log('[AnomalyDetector::check] ' . $e->getMessage());
        }
    }

    // 1) Omset hari ini turun ≥30% dari rata-rata 7 hari terakhir
    private static function checkOmsetDrop(int $tenantId, int $outletId): void
    {
        $db = Database::get();
        $today = date('Y-m-d');

        $s = $db->prepare("SELECT COALESCE(SUM(total),0) FROM hl_transaksi
                            WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
        $s->execute([$tenantId, $outletId, $today]);
        $omsetToday = (int)$s->fetchColumn();

        // Skip pagi (sebelum jam 14) — terlalu dini
        if ((int)date('H') < 14) return;

        $s = $db->prepare("SELECT COALESCE(AVG(daily),0) FROM (
              SELECT DATE(tanggal) d, SUM(total) daily FROM hl_transaksi
               WHERE tenant_id=? AND outlet_id=?
                 AND DATE(tanggal) BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_SUB(?, INTERVAL 1 DAY)
               GROUP BY DATE(tanggal)
            ) x");
        $s->execute([$tenantId, $outletId, $today, $today]);
        $avg7 = (float)$s->fetchColumn();

        if ($avg7 <= 0 || $omsetToday <= 0) return;
        $dropPct = round((($avg7 - $omsetToday) / $avg7) * 100, 1);
        if ($dropPct < 30) return;

        $nama = self::outletNama($outletId);
        $subject = "⚠️ [{$nama}] Omset turun {$dropPct}% vs rata-rata 7 hari";
        $body = "<p>Omset hari ini <strong>Rp " . number_format($omsetToday, 0, ',', '.') .
                "</strong> turun <strong>{$dropPct}%</strong> dari rata-rata 7 hari (Rp " .
                number_format($avg7, 0, ',', '.') . ").</p>";
        $sum = "Omset hari ini Rp " . number_format($omsetToday) . " — turun {$dropPct}% vs rata-rata 7H.";
        Notifier::notifyOwner($tenantId, $outletId, [
            'type'=>'alert_omset_drop', 'subject'=>$subject,
            'body_html'=>$body, 'body_summary'=>$sum,
            'coin_feature'=>'alert_anomali',
        ]);
    }

    // 2) Kas belum diinput > 1 hari kerja (padahal ada transaksi)
    private static function checkKasBelumDiinput(int $tenantId, int $outletId): void
    {
        $db = Database::get();

        // Ada transaksi hari ini?
        $s = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                            WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=CURDATE()");
        $s->execute([$tenantId, $outletId]);
        if ((int)$s->fetchColumn() === 0) return; // tidak ada order = wajar tidak ada kas

        // Last kas entry
        $s = $db->prepare("SELECT MAX(DATE(tanggal)) FROM hl_kas
                            WHERE tenant_id=? AND outlet_id=?");
        $s->execute([$tenantId, $outletId]);
        $last = $s->fetchColumn();
        if (!$last) {
            $hari = 99; // never
        } else {
            $hari = (int)((strtotime(date('Y-m-d')) - strtotime($last)) / 86400);
        }
        if ($hari < 2) return; // 0/1 hari ok

        $nama = self::outletNama($outletId);
        $subject = "⚠️ [{$nama}] Kas belum diinput {$hari} hari";
        $body = "<p>Kas terakhir di-input <strong>" . ($last ?: 'belum pernah') .
                "</strong>. Sudah <strong>{$hari} hari</strong> tanpa input kas, padahal ada order hari ini.</p>";
        Notifier::notifyOwner($tenantId, $outletId, [
            'type'=>'alert_kas_tidak_diinput', 'subject'=>$subject,
            'body_html'=>$body, 'body_summary'=>"Kas belum diinput {$hari} hari (last: ".($last??'-').")",
            'coin_feature'=>'alert_anomali',
        ]);
    }

    // 3) Order menumpuk — ≥10 order status proses (bukan siap/diambil/selesai/batal), >24 jam lalu
    private static function checkOrderMenumpuk(int $tenantId, int $outletId): void
    {
        $db = Database::get();
        $s = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                            WHERE tenant_id=? AND outlet_id=?
                              AND status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan')
                              AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $s->execute([$tenantId, $outletId]);
        $stuck = (int)$s->fetchColumn();
        if ($stuck < 10) return;

        $nama = self::outletNama($outletId);
        $subject = "⚠️ [{$nama}] {$stuck} order sudah proses >24 jam";
        $body = "<p>Ada <strong>{$stuck} order</strong> masih dalam proses >24 jam. Perlu perhatian segera!</p>";
        Notifier::notifyOwner($tenantId, $outletId, [
            'type'=>'alert_order_menumpuk', 'subject'=>$subject,
            'body_html'=>$body, 'body_summary'=>"{$stuck} order stuck >24 jam",
            'coin_feature'=>'alert_anomali',
        ]);
    }

    // 4) Absensi <50% dari karyawan aktif outlet (skip kalau total <2)
    private static function checkAbsensiRendah(int $tenantId, int $outletId): void
    {
        $db = Database::get();
        if ((int)date('H') < 10) return; // skip pagi

        // Total karyawan aktif di outlet
        $s = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                            WHERE tenant_id=? AND outlet_id=? AND is_active=1");
        $s->execute([$tenantId, $outletId]);
        $total = (int)$s->fetchColumn();
        if ($total < 2) return;

        $s = $db->prepare("SELECT COUNT(*) FROM hl_absensi
                            WHERE tenant_id=? AND outlet_id=? AND tanggal=CURDATE() AND status='hadir'");
        $s->execute([$tenantId, $outletId]);
        $hadir = (int)$s->fetchColumn();

        $pct = $total > 0 ? round($hadir / $total * 100, 1) : 0;
        if ($pct >= 50) return;

        $nama = self::outletNama($outletId);
        $subject = "⚠️ [{$nama}] Absensi rendah {$hadir}/{$total} ({$pct}%)";
        $body = "<p>Hanya <strong>{$hadir} dari {$total}</strong> karyawan hadir hari ini (<strong>{$pct}%</strong>).</p>";
        Notifier::notifyOwner($tenantId, $outletId, [
            'type'=>'alert_absensi_rendah', 'subject'=>$subject,
            'body_html'=>$body, 'body_summary'=>"Absensi {$hadir}/{$total} ({$pct}%)",
            'coin_feature'=>'alert_anomali',
        ]);
    }

    // 5) Saldo coin rendah <1000 (active) atau <200 (trial)
    private static function checkCoinRendah(int $tenantId, int $outletId): void
    {
        $db = Database::get();
        $s = $db->prepare("SELECT t.coin_balance, t.coin_mode, o.status outlet_status,
                                  o.coin_balance outlet_coin, o.trial_coin_balance
                             FROM tenants t LEFT JOIN outlets o ON o.id=? AND o.tenant_id=t.id
                            WHERE t.id=?");
        $s->execute([$outletId, $tenantId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return;

        $isTrial = ($r['outlet_status'] ?? '') === 'trial';
        $bal = $isTrial
            ? (int)($r['trial_coin_balance'] ?? 0)
            : (($r['coin_mode'] ?? 'shared') === 'per_outlet' ? (int)$r['outlet_coin'] : (int)$r['coin_balance']);
        $threshold = $isTrial ? 200 : 1000;
        if ($bal >= $threshold) return;

        $nama = self::outletNama($outletId);
        $subject = "🪙 [{$nama}] Saldo coin rendah: " . number_format($bal);
        $body = "<p>Saldo coin tinggal <strong>" . number_format($bal) .
                "</strong>. Segera topup agar fitur tidak terhenti.</p>";
        Notifier::notifyOwner($tenantId, $outletId, [
            'type'=>'alert_coin_rendah', 'subject'=>$subject,
            'body_html'=>$body, 'body_summary'=>"Coin tinggal ".number_format($bal),
            'coin_feature'=>'alert_anomali',
        ]);
    }

    /** Cache nama outlet supaya tidak query berulang */
    private static array $namaCache = [];
    private static function outletNama(int $outletId): string
    {
        if (isset(self::$namaCache[$outletId])) return self::$namaCache[$outletId];
        try {
            $s = Database::get()->prepare("SELECT nama_outlet FROM outlets WHERE id=?");
            $s->execute([$outletId]);
            self::$namaCache[$outletId] = (string)($s->fetchColumn() ?: 'Outlet');
        } catch (Throwable) { self::$namaCache[$outletId] = 'Outlet'; }
        return self::$namaCache[$outletId];
    }
}
