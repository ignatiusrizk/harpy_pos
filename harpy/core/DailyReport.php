<?php
// ══════════════════════════════════════════════════════
// core/DailyReport.php — Generator laporan harian outlet → owner
//
// Trigger via pseudo-cron di tenant_guard.php:
//   - Cek setiap request (max 1x per session per N menit)
//   - Kalau jam_sekarang >= jam_target & belum dikirim hari ini → send
//
// Konten configurable via tenants.notif_settings.daily_report_konten[]
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Notifier.php';

class DailyReport
{
    /** Coba kirim laporan hari ini. Idempotent + rate-limited via Notifier. */
    public static function maybeSend(int $tenantId, int $outletId): array
    {
        try {
            $cfg = self::readConfig($tenantId);
            if (!$cfg['enabled']) return ['ok'=>false, 'skipped'=>'disabled'];

            // Jam check — only send setelah jam yang ditentukan
            if (date('H:i') < $cfg['jam']) return ['ok'=>false, 'skipped'=>'jam belum'];

            // Sudah dikirim hari ini?
            if (Notifier::sentToday($tenantId, $outletId, 'daily_report')) {
                return ['ok'=>false, 'skipped'=>'sudah dikirim'];
            }

            // Build report
            $report = self::build($tenantId, $outletId, $cfg['konten']);

            // Channels: email (kalau dipilih) + inapp (selalu)
            $channels = ['inapp'];
            if (!empty($cfg['channel_email'])) $channels[] = 'email';

            $res = Notifier::notifyOwner($tenantId, $outletId, [
                'type'           => 'daily_report',
                'subject'        => $report['subject'],
                'body_html'      => $report['html'],
                'body_summary'   => $report['summary'],
                'channels'       => $channels,
                'coin_feature'   => 'daily_report',
            ]);
            return $res;
        } catch (Throwable $e) {
            error_log('[DailyReport::maybeSend] '.$e->getMessage());
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    /** Baca konfigurasi dari tenants.notif_settings JSON */
    private static function readConfig(int $tenantId): array
    {
        $cfg = ['enabled'=>true, 'jam'=>'21:00', 'konten'=>['omset','order','kas','absensi','alert'], 'channel_email'=>true];
        try {
            $db = Database::get();
            $s = $db->prepare("SELECT notif_settings FROM tenants WHERE id=?");
            $s->execute([$tenantId]);
            $raw = $s->fetchColumn();
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    if (isset($j['daily_report_jam']) && preg_match('/^\d{2}:\d{2}$/', $j['daily_report_jam']))
                        $cfg['jam'] = $j['daily_report_jam'];
                    if (isset($j['daily_report_konten']) && is_array($j['daily_report_konten']))
                        $cfg['konten'] = $j['daily_report_konten'];
                    // channel email per daily_report
                    if (isset($j['daily_report']['email'])) $cfg['channel_email'] = (int)$j['daily_report']['email'] === 1;
                }
            }
        } catch (Throwable) {}
        return $cfg;
    }

    /**
     * Build report content untuk outlet.
     * @return ['subject'=>..., 'html'=>..., 'summary'=>...]
     */
    public static function build(int $tenantId, int $outletId, array $konten): array
    {
        $db = Database::get();
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Nama outlet
        $s = $db->prepare("SELECT nama_outlet FROM outlets WHERE id=? AND tenant_id=?");
        $s->execute([$outletId, $tenantId]);
        $namaOutlet = $s->fetchColumn() ?: 'Outlet';

        $blocks = []; $sumParts = [];

        // 1) OMSET
        if (in_array('omset', $konten, true)) {
            $s = $db->prepare("SELECT COALESCE(SUM(total),0) omset, COUNT(*) cnt,
                                      COALESCE(SUM(CASE WHEN metode_bayar='cash'     THEN dp END),0) cash,
                                      COALESCE(SUM(CASE WHEN metode_bayar='qris'     THEN dp END),0) qris,
                                      COALESCE(SUM(CASE WHEN metode_bayar='transfer' THEN dp END),0) trf
                                 FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
            $s->execute([$tenantId,$outletId,$today]); $o = $s->fetch(PDO::FETCH_ASSOC);
            $s->execute([$tenantId,$outletId,$yesterday]); $y = $s->fetch(PDO::FETCH_ASSOC);
            $delta = '';
            if ($y && (int)$y['omset'] > 0) {
                $pct = round(((int)$o['omset'] - (int)$y['omset']) / (int)$y['omset'] * 100, 1);
                $delta = ' (' . ($pct>=0?'↑ +':'↓ ') . abs($pct) . '% vs kemarin)';
            }
            $blocks[] = "<h3 style='margin:14px 0 6px;color:#0F1C3A'>💰 Omset Hari Ini</h3>"
                      . "<p>Total: <strong>Rp " . number_format((int)$o['omset'],0,',','.') . "</strong>{$delta}<br>"
                      . "Cash: Rp " . number_format((int)$o['cash'],0,',','.') . " · "
                      . "QRIS: Rp " . number_format((int)$o['qris'],0,',','.') . " · "
                      . "Transfer: Rp " . number_format((int)$o['trf'],0,',','.') . "</p>";
            $sumParts[] = "💰 Rp " . number_format((int)$o['omset']) . " (" . (int)$o['cnt'] . " order)";
        }

        // 2) ORDER
        if (in_array('order', $konten, true)) {
            $s = $db->prepare("SELECT
                  SUM(CASE WHEN DATE(tanggal)=? THEN 1 ELSE 0 END) masuk_today,
                  SUM(CASE WHEN status_proses IN ('siap','diambil','selesai') AND DATE(tanggal)=? THEN 1 ELSE 0 END) selesai_today,
                  SUM(CASE WHEN status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan') THEN 1 ELSE 0 END) pending,
                  SUM(CASE WHEN drop_point_id IS NOT NULL AND DATE(tanggal)=? THEN 1 ELSE 0 END) drop_today
                FROM hl_transaksi WHERE tenant_id=? AND outlet_id=?");
            $s->execute([$today,$today,$today,$tenantId,$outletId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $blocks[] = "<h3 style='margin:14px 0 6px;color:#0F1C3A'>📦 Order</h3>"
                      . "<p>Masuk: <strong>{$r['masuk_today']}</strong> · "
                      . "Selesai: <strong>{$r['selesai_today']}</strong> · "
                      . "Pending: <strong>{$r['pending']}</strong>"
                      . (($r['drop_today']??0)>0 ? "<br>Drop point: {$r['drop_today']} order" : "") . "</p>";
        }

        // 3) KAS
        if (in_array('kas', $konten, true)) {
            try {
                $s = $db->prepare("SELECT
                      COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) masuk,
                      COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) keluar
                    FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tanggal=?");
                $s->execute([$tenantId,$outletId,$today]); $k = $s->fetch(PDO::FETCH_ASSOC);
                $saldo = (int)$k['masuk'] - (int)$k['keluar'];
                $blocks[] = "<h3 style='margin:14px 0 6px;color:#0F1C3A'>💵 Kas Hari Ini</h3>"
                          . "<p>Masuk: Rp " . number_format((int)$k['masuk'],0,',','.') . " · "
                          . "Keluar: Rp " . number_format((int)$k['keluar'],0,',','.') . "<br>"
                          . "Saldo: <strong>Rp " . number_format($saldo,0,',','.') . "</strong></p>";
            } catch (Throwable) {}
        }

        // 4) ABSENSI
        if (in_array('absensi', $konten, true)) {
            try {
                $s = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                    WHERE tenant_id=? AND outlet_id=? AND is_active=1");
                $s->execute([$tenantId,$outletId]); $total = (int)$s->fetchColumn();
                $s = $db->prepare("SELECT
                      SUM(status='hadir') hadir, SUM(status='izin') izin, SUM(status='alpha') alpha
                    FROM hl_absensi WHERE tenant_id=? AND outlet_id=? AND tanggal=?");
                $s->execute([$tenantId,$outletId,$today]); $a = $s->fetch(PDO::FETCH_ASSOC);
                $blocks[] = "<h3 style='margin:14px 0 6px;color:#0F1C3A'>👥 Karyawan</h3>"
                          . "<p>Hadir: <strong>" . (int)($a['hadir']??0) . "/{$total}</strong> · "
                          . "Izin: " . (int)($a['izin']??0) . " · Alfa: " . (int)($a['alpha']??0) . "</p>";
            } catch (Throwable) {}
        }

        // 5) ALERT (ringkas dari hl_notif_log hari ini)
        if (in_array('alert', $konten, true)) {
            try {
                $s = $db->prepare("SELECT subject FROM hl_notif_log
                                    WHERE tenant_id=? AND outlet_id=? AND type LIKE 'alert_%'
                                      AND DATE(sent_at)=? AND status='sent'
                                    ORDER BY sent_at DESC LIMIT 5");
                $s->execute([$tenantId,$outletId,$today]);
                $alerts = $s->fetchAll(PDO::FETCH_COLUMN);
                if ($alerts) {
                    $blocks[] = "<h3 style='margin:14px 0 6px;color:#991B1B'>⚠️ Alert Hari Ini</h3>"
                              . "<ul style='margin:0;padding-left:18px'>"
                              . implode('', array_map(fn($a)=>"<li>".htmlspecialchars($a)."</li>", $alerts))
                              . "</ul>";
                } else {
                    $blocks[] = "<h3 style='margin:14px 0 6px;color:#065F46'>✓ Alert</h3><p>Semua normal hari ini.</p>";
                }
            } catch (Throwable) {}
        }

        $subject = "📊 Laporan Harian — {$namaOutlet} · " . date('d M Y');
        $tglIndo = date('d M Y');
        $html = "<div style='font-family:Plus Jakarta Sans,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#fff'>"
              . "<h2 style='color:#0F1C3A;border-bottom:2px solid #35E8D5;padding-bottom:8px'>📊 LAMASY — {$namaOutlet}</h2>"
              . "<p style='color:#6B7280;font-size:13px;margin-bottom:18px'>Laporan otomatis · {$tglIndo}</p>"
              . implode('', $blocks)
              . "<hr style='border:none;border-top:1px solid #E5E9F2;margin-top:24px'>"
              . "<p style='font-size:11px;color:#9CA3AF'>Pengaturan jam & konten di HQ → Settings → Notifikasi</p>"
              . "</div>";

        $summary = "Laporan harian {$namaOutlet} · " . implode(' · ', $sumParts);
        return ['subject'=>$subject, 'html'=>$html, 'summary'=>$summary];
    }
}
