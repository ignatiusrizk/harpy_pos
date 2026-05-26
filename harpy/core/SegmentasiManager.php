<?php
// ══════════════════════════════════════════════════════
// core/SegmentasiManager.php
// Auto-kategorikan pelanggan ke segmen baru/regular/vip/dormant
// berdasarkan pola transaksi.
//
// Aturan:
//   - baru     : total_order <= 1 dan terakhir order ≤ 30 hari
//   - dormant  : tidak ada transaksi > 30 hari (lebih prioritas dari regular)
//   - vip      : ≥ 5 order di bulan kalender saat ini
//   - regular  : default (selain di atas)
//
// Dipanggil 1x per hari per tenant+outlet via pseudo-cron di tenant_guard.
// ══════════════════════════════════════════════════════

class SegmentasiManager
{
    /**
     * Update segmen semua pelanggan di tenant+outlet ini.
     * Idempotent — cek hl_notif_log untuk hindari double-run dalam 20 jam.
     *
     * @return int jumlah pelanggan yang segmen-nya berubah
     */
    public static function updateAll(int $tenantId, int $outletId, bool $forceRun = false): int
    {
        // Idempotency: cek apakah sudah jalan 20 jam terakhir
        if (!$forceRun) {
            try {
                $db = Database::get();
                $st = $db->prepare("SELECT 1 FROM hl_notif_log
                                     WHERE tenant_id=? AND outlet_id=? AND type='segmentasi_run'
                                       AND sent_at > DATE_SUB(NOW(), INTERVAL 20 HOUR)
                                     LIMIT 1");
                $st->execute([$tenantId, $outletId]);
                if ($st->fetchColumn()) return 0;
            } catch (Throwable) {}
        }

        $changed = 0;
        try {
            $db = Database::get();

            // Ambil semua pelanggan aktif tenant ini (segmen lintas outlet — pakai tenant scope)
            $sel = $db->prepare("SELECT id, segmen FROM hl_pelanggan
                                  WHERE tenant_id=? AND is_active=1");
            $sel->execute([$tenantId]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $upd = $db->prepare("UPDATE hl_pelanggan
                                    SET segmen=?, segmen_updated_at=CURDATE()
                                  WHERE id=? AND tenant_id=?");

            foreach ($rows as $p) {
                $newSeg = self::computeSegmen($tenantId, (int)$p['id']);
                if ($newSeg !== ($p['segmen'] ?? 'baru')) {
                    $upd->execute([$newSeg, $p['id'], $tenantId]);
                    $changed++;
                }
            }

            // Log run (anti-spam) — pakai schema existing hl_notif_log
            $db->prepare("INSERT INTO hl_notif_log
                            (tenant_id, outlet_id, type, channel, body_summary, status)
                          VALUES (?,?,'segmentasi_run','inapp',?,'sent')")
               ->execute([$tenantId, $outletId, "Updated $changed pelanggan"]);
        } catch (Throwable $e) {
            error_log('[SegmentasiManager::updateAll] '.$e->getMessage());
        }
        return $changed;
    }

    /** Hitung segmen 1 pelanggan dari pola transaksi tenant-wide */
    public static function computeSegmen(int $tenantId, int $pelangganId): string
    {
        try {
            $db = Database::get();

            // Total order historis (tenant-wide, lintas outlet)
            $st = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                                 WHERE tenant_id=? AND pelanggan_id=?
                                   AND status_proses NOT IN ('batal','dibatalkan')");
            $st->execute([$tenantId, $pelangganId]);
            $totalOrder = (int)$st->fetchColumn();

            // Tanggal order terakhir
            $st = $db->prepare("SELECT MAX(DATE(tanggal)) FROM hl_transaksi
                                 WHERE tenant_id=? AND pelanggan_id=?
                                   AND status_proses NOT IN ('batal','dibatalkan')");
            $st->execute([$tenantId, $pelangganId]);
            $lastDate = $st->fetchColumn();

            // Order bulan kalender ini
            $st = $db->prepare("SELECT COUNT(*) FROM hl_transaksi
                                 WHERE tenant_id=? AND pelanggan_id=?
                                   AND status_proses NOT IN ('batal','dibatalkan')
                                   AND DATE_FORMAT(tanggal,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
            $st->execute([$tenantId, $pelangganId]);
            $orderBulanIni = (int)$st->fetchColumn();

            $hariSejakOrder = 999;
            if ($lastDate) {
                $hariSejakOrder = (int)floor((time() - strtotime($lastDate)) / 86400);
            }

            // Aturan urutan:
            // 1. Belum pernah order ATAU baru 1 order dalam 30 hari → 'baru'
            if ($totalOrder <= 1 && $hariSejakOrder <= 30) return 'baru';
            // 2. Tidak transaksi > 30 hari → 'dormant'
            if ($hariSejakOrder > 30) return 'dormant';
            // 3. ≥ 5 order bulan ini → 'vip'
            if ($orderBulanIni >= 5) return 'vip';
            // 4. Default
            return 'regular';
        } catch (Throwable $e) {
            error_log('[SegmentasiManager::computeSegmen] '.$e->getMessage());
            return 'regular';
        }
    }

    /** Stats agregat segmen untuk display di customer.php */
    public static function stats(int $tenantId): array
    {
        $out = ['baru'=>0, 'regular'=>0, 'vip'=>0, 'dormant'=>0, 'total'=>0];
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT segmen, COUNT(*) c FROM hl_pelanggan
                                 WHERE tenant_id=? AND is_active=1
                              GROUP BY segmen");
            $st->execute([$tenantId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $seg = $r['segmen'] ?: 'baru';
                if (isset($out[$seg])) $out[$seg] = (int)$r['c'];
                $out['total'] += (int)$r['c'];
            }
        } catch (Throwable $e) {
            error_log('[SegmentasiManager::stats] '.$e->getMessage());
        }
        return $out;
    }
}
