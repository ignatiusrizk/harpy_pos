<?php
// ══════════════════════════════════════════════════════
// core/Broadcast.php — Broadcast SOP/instruksi ke staff outlet
//
// HQ tulis pesan + pilih outlet. Sistem kumpulkan staff (yang punya
// telepon) di outlet tsb sebagai recipient. Kirim via wa.me (manual
// klik per recipient), status di-track.
// ══════════════════════════════════════════════════════

class Broadcast
{
    /**
     * Preview recipient untuk daftar outlet (sebelum create).
     * @return array list {user_id, nama, telepon, outlet_id, nama_outlet, role}
     */
    public static function recipientsForOutlets(int $tenantId, array $outletIds): array
    {
        if (!$outletIds) return [];
        $db = Database::get();
        $ph = implode(',', array_fill(0, count($outletIds), '?'));
        // Staff aktif di outlet tsb (via pivot) yang punya telepon
        $sql = "SELECT DISTINCT u.id user_id, u.nama, u.telepon, u.role,
                       ko.outlet_id, o.nama_outlet
                  FROM hl_karyawan_outlet ko
                  JOIN hl_users u ON u.id = ko.karyawan_id AND u.tenant_id = ko.tenant_id
                  JOIN outlets o ON o.id = ko.outlet_id
                 WHERE ko.tenant_id=? AND ko.is_active=1
                   AND ko.outlet_id IN ($ph)
                   AND u.telepon IS NOT NULL AND u.telepon <> ''
                 ORDER BY o.nama_outlet, u.nama";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$tenantId], $outletIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['telepon'] = self::normalizePhone($r['telepon']);
        unset($r);
        return $rows;
    }

    /** Buat broadcast + simpan recipients. Return broadcast_id. */
    public static function create(
        int $tenantId, string $judul, string $pesan, array $outletIds,
        ?int $userId, ?string $userNama
    ): int {
        $judul = trim($judul); $pesan = trim($pesan);
        if ($judul === '') throw new RuntimeException('Judul wajib diisi.');
        if ($pesan === '') throw new RuntimeException('Isi pesan wajib diisi.');

        $recipients = self::recipientsForOutlets($tenantId, $outletIds);
        if (!$recipients) throw new RuntimeException('Tidak ada staff dengan nomor WA di outlet terpilih.');

        $db = Database::get();
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO hl_broadcast (tenant_id, judul, pesan, target_json, created_by, created_by_nama)
                          VALUES (?,?,?,?,?,?)")
               ->execute([$tenantId, $judul, $pesan, json_encode(array_values($outletIds)), $userId, $userNama]);
            $bid = (int)$db->lastInsertId();

            $ins = $db->prepare("INSERT INTO hl_broadcast_recipient
                                   (broadcast_id, tenant_id, outlet_id, user_id, nama, telepon, status)
                                 VALUES (?,?,?,?,?,?,'pending')");
            foreach ($recipients as $r) {
                $ins->execute([$bid, $tenantId, $r['outlet_id'], $r['user_id'], $r['nama'], $r['telepon']]);
            }
            $db->commit();
            return $bid;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Detail broadcast + recipients */
    public static function get(int $tenantId, int $broadcastId): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM hl_broadcast WHERE id=? AND tenant_id=?");
        $stmt->execute([$broadcastId, $tenantId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) return null;

        $rStmt = $db->prepare("SELECT r.*, o.nama_outlet FROM hl_broadcast_recipient r
                               LEFT JOIN outlets o ON o.id=r.outlet_id
                               WHERE r.broadcast_id=? AND r.tenant_id=? ORDER BY o.nama_outlet, r.nama");
        $rStmt->execute([$broadcastId, $tenantId]);
        $b['recipients'] = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        return $b;
    }

    /** Riwayat broadcast (ringkas + count recipient/sent) */
    public static function history(int $tenantId, int $limit = 30): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT b.*,
                   (SELECT COUNT(*) FROM hl_broadcast_recipient r WHERE r.broadcast_id=b.id) total,
                   (SELECT COUNT(*) FROM hl_broadcast_recipient r WHERE r.broadcast_id=b.id AND r.status='sent') sent
              FROM hl_broadcast b
             WHERE b.tenant_id=?
             ORDER BY b.created_at DESC LIMIT ?
        ");
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tandai 1 recipient sudah dikirim */
    public static function markSent(int $tenantId, int $recipientId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare("UPDATE hl_broadcast_recipient SET status='sent', sent_at=NOW()
                               WHERE id=? AND tenant_id=?");
        $stmt->execute([$recipientId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    private static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($p, '0') === 0) $p = '62' . substr($p, 1);
        if (strpos($p, '62') !== 0) $p = '62' . $p;
        return $p;
    }
}
