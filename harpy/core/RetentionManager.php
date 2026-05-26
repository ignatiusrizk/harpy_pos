<?php
// ══════════════════════════════════════════════════════
// core/RetentionManager.php
// Manajemen retensi pelanggan dormant — generate WA reminder.
//
// Karena sistem tidak punya WA API otomatis, "send" di sini =
// generate WA URL + log ke hl_notif_log. Kasir/admin klik WA link
// satu per satu via halaman retention.php.
//
// Aturan acceptance #7:
//   - Max 20 pelanggan/hari (per outlet)
//   - Skip pelanggan yang sudah di-reminder dalam 14 hari terakhir
// ══════════════════════════════════════════════════════

class RetentionManager
{
    const MAX_PER_DAY  = 20;
    const COOLDOWN_DAY = 14;

    /**
     * List pelanggan dormant yang bisa di-reminder hari ini.
     * @param int $tenantId
     * @param int $outletId
     * @param int $limit (max default 20)
     * @return array of [id, nama, telepon, last_transaksi, poin_balance, hari_absen, wa_url, pesan]
     */
    public static function dueReminders(int $tenantId, int $outletId, int $limit = self::MAX_PER_DAY): array
    {
        try {
            $db = Database::get();

            // Quota harian: hitung sudah berapa yang dikirim hari ini
            $st = $db->prepare("SELECT COUNT(*) FROM hl_notif_log
                                 WHERE tenant_id=? AND outlet_id=?
                                   AND type='dormant_reminder'
                                   AND DATE(sent_at)=CURDATE()");
            $st->execute([$tenantId, $outletId]);
            $sentToday = (int)$st->fetchColumn();
            $remaining = max(0, $limit - $sentToday);
            if ($remaining <= 0) return ['data'=>[], 'sent_today'=>$sentToday, 'remaining'=>0];

            // Ambil dormant yang belum di-reminder dalam 14 hari
            $st = $db->prepare("
                SELECT p.id, p.nama, p.telepon, p.last_transaksi,
                       p.poin_balance, p.segmen,
                       DATEDIFF(CURDATE(), p.last_transaksi) AS hari_absen
                  FROM hl_pelanggan p
                 WHERE p.tenant_id=? AND p.outlet_id=?
                   AND p.is_active=1
                   AND p.segmen='dormant'
                   AND p.telepon IS NOT NULL AND p.telepon <> ''
                   AND p.last_transaksi IS NOT NULL
                   AND p.last_transaksi < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND NOT EXISTS (
                       SELECT 1 FROM hl_notif_log n
                        WHERE n.tenant_id=p.tenant_id
                          AND n.pelanggan_id=p.id
                          AND n.type='dormant_reminder'
                          AND n.sent_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                   )
                 ORDER BY p.last_transaksi ASC
                 LIMIT ?
            ");
            $st->bindValue(1, $tenantId, PDO::PARAM_INT);
            $st->bindValue(2, $outletId, PDO::PARAM_INT);
            $st->bindValue(3, self::COOLDOWN_DAY, PDO::PARAM_INT);
            $st->bindValue(4, $remaining, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Ambil promo aktif (random 1) sekali — untuk include di pesan
            $promoAktif = null;
            try {
                $ps = $db->prepare("SELECT nama FROM hl_promo
                                     WHERE tenant_id=? AND outlet_id=? AND is_active=1
                                       AND (tgl_akhir IS NULL OR DATE(tgl_akhir) >= CURDATE())
                                     ORDER BY RAND() LIMIT 1");
                $ps->execute([$tenantId, $outletId]);
                $promoAktif = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) {}

            foreach ($rows as &$p) {
                $msg     = self::buildPesan($p, $promoAktif);
                $phone   = self::normalizePhone($p['telepon']);
                $p['wa_url']     = $phone ? "https://wa.me/$phone?text=" . urlencode($msg) : '';
                $p['pesan']      = $msg;
                $p['hari_absen'] = (int)$p['hari_absen'];
                $p['poin_balance'] = (int)$p['poin_balance'];
            }
            unset($p);

            return ['data'=>$rows, 'sent_today'=>$sentToday, 'remaining'=>$remaining];
        } catch (Throwable $e) {
            error_log('[RetentionManager::dueReminders] '.$e->getMessage());
            return ['data'=>[], 'sent_today'=>0, 'remaining'=>0, 'error'=>$e->getMessage()];
        }
    }

    /** Tandai reminder sudah dikirim (dipanggil saat kasir klik WA / bulk send) */
    public static function markSent(int $tenantId, int $outletId, int $pelangganId): bool
    {
        try {
            $db = Database::get();
            // Anti-double: skip kalau sudah di-mark dalam 1 jam terakhir
            $chk = $db->prepare("SELECT 1 FROM hl_notif_log
                                  WHERE tenant_id=? AND outlet_id=? AND pelanggan_id=?
                                    AND type='dormant_reminder'
                                    AND sent_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                                  LIMIT 1");
            $chk->execute([$tenantId, $outletId, $pelangganId]);
            if ($chk->fetchColumn()) return true;

            // Pakai schema existing hl_notif_log (dari Owner POV)
            $db->prepare("INSERT INTO hl_notif_log
                            (tenant_id, outlet_id, pelanggan_id, type, channel, body_summary, status)
                          VALUES (?,?,?,'dormant_reminder','inapp','Reminder WA dikirim manual','sent')")
               ->execute([$tenantId, $outletId, $pelangganId]);
            return true;
        } catch (Throwable $e) {
            error_log('[RetentionManager::markSent] '.$e->getMessage());
            return false;
        }
    }

    /** Build pesan personal */
    private static function buildPesan(array $p, ?array $promoAktif): string
    {
        $hari = (int)$p['hari_absen'];
        $nama = $p['nama'] ?: 'Customer';
        $msg  = "Halo *{$nama}*! 👋\n\n";
        $msg .= "Sudah *{$hari} hari* nih kamu belum laundry di sini 😊\n\n";

        if ((int)$p['poin_balance'] > 0) {
            $msg .= "Btw, poin kamu masih ada: *{$p['poin_balance']} poin* 🌟\n";
            $msg .= "Sayang kalau hangus! Yuk laundry lagi & tukar poinnya.\n\n";
        } else {
            $msg .= "Kami kangen! Yuk mampir lagi, kami siap melayani 🧺\n\n";
        }

        if ($promoAktif && !empty($promoAktif['nama'])) {
            $msg .= "Ada promo: *{$promoAktif['nama']}* 🎁\n\n";
        }
        $msg .= "Hubungi kami untuk antar-jemput ya! 📞";
        return $msg;
    }

    private static function normalizePhone(?string $raw): string
    {
        $p = preg_replace('/[^0-9]/', '', (string)$raw);
        if ($p === '') return '';
        if (str_starts_with($p, '0'))   $p = '62' . substr($p, 1);
        elseif (!str_starts_with($p, '62')) $p = '62' . $p;
        return $p;
    }
}
