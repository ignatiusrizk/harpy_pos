<?php
// ══════════════════════════════════════════════════════
// core/Loyalty.php — Loyalty poin lintas outlet
//
// Poin = account-level (hl_pelanggan.poin_balance), bisa dikumpulkan
// & ditukar di outlet mana saja. Ledger di hl_loyalty_log.
// ══════════════════════════════════════════════════════

class Loyalty
{
    /** Setting loyalty tenant (cache per request) */
    private static array $cfgCache = [];

    public static function config(int $tenantId): array
    {
        if (isset(self::$cfgCache[$tenantId])) return self::$cfgCache[$tenantId];
        $cfg = ['enabled'=>false, 'rupiah_per_poin'=>1000, 'poin_value'=>100];
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT loyalty_enabled, loyalty_rupiah_per_poin, loyalty_poin_value
                                    FROM tenants WHERE id=?");
            $stmt->execute([$tenantId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cfg = [
                    'enabled'         => (int)($row['loyalty_enabled'] ?? 0) === 1,
                    'rupiah_per_poin' => max(1, (int)($row['loyalty_rupiah_per_poin'] ?? 1000)),
                    'poin_value'      => max(1, (int)($row['loyalty_poin_value'] ?? 100)),
                ];
            }
        } catch (Throwable) {}
        self::$cfgCache[$tenantId] = $cfg;
        return $cfg;
    }

    public static function isEnabled(int $tenantId): bool
    {
        return self::config($tenantId)['enabled'];
    }

    public static function balance(int $tenantId, int $pelangganId): int
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=?");
            $stmt->execute([$pelangganId, $tenantId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    /**
     * Earn poin untuk 1 transaksi (idempotent — tidak dobel per transaksi).
     * @return int poin yang ditambahkan (0 kalau tidak earn)
     */
    public static function earnForTransaction(
        int $tenantId, ?int $outletId, int $transaksiId, int $pelangganId, float $total
    ): int {
        if (!self::isEnabled($tenantId) || $pelangganId <= 0 || $total <= 0) return 0;

        $cfg = self::config($tenantId);
        $poin = (int)floor($total / $cfg['rupiah_per_poin']);
        if ($poin <= 0) return 0;

        $db = Database::get();
        try {
            // Idempotency: sudah pernah earn utk transaksi ini?
            $chk = $db->prepare("SELECT 1 FROM hl_loyalty_log
                                  WHERE tenant_id=? AND transaksi_id=? AND type='earn' LIMIT 1");
            $chk->execute([$tenantId, $transaksiId]);
            if ($chk->fetchColumn()) return 0;

            $db->beginTransaction();
            $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $cur->execute([$pelangganId, $tenantId]);
            $bal = (int)$cur->fetchColumn();
            $newBal = $bal + $poin;

            $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);
            $db->prepare("INSERT INTO hl_loyalty_log
                            (tenant_id, outlet_id, pelanggan_id, transaksi_id, type, poin, balance_after, keterangan)
                          VALUES (?,?,?,?,'earn',?,?,?)")
               ->execute([$tenantId, $outletId, $pelangganId, $transaksiId, $poin, $newBal,
                          'Earn dari transaksi #'.$transaksiId]);
            $db->commit();
            return $poin;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[Loyalty::earn] '.$e->getMessage());
            return 0;
        }
    }

    /**
     * Redeem poin → return nilai rupiah diskon.
     * @throws RuntimeException kalau poin kurang / disabled.
     */
    public static function redeem(
        int $tenantId, ?int $outletId, int $pelangganId, int $poin, ?int $transaksiId, ?int $userId = null
    ): int {
        if (!self::isEnabled($tenantId)) throw new RuntimeException('Loyalty tidak aktif.');
        if ($poin <= 0) throw new RuntimeException('Jumlah poin tidak valid.');

        $cfg = self::config($tenantId);
        $db = Database::get();
        $db->beginTransaction();
        try {
            $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $cur->execute([$pelangganId, $tenantId]);
            $bal = (int)$cur->fetchColumn();
            if ($bal < $poin) { throw new RuntimeException("Poin tidak cukup (saldo: $bal)."); }

            $newBal = $bal - $poin;
            $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);
            $db->prepare("INSERT INTO hl_loyalty_log
                            (tenant_id, outlet_id, pelanggan_id, transaksi_id, type, poin, balance_after, keterangan, created_by)
                          VALUES (?,?,?,?,'redeem',?,?,?,?)")
               ->execute([$tenantId, $outletId, $pelangganId, $transaksiId, -$poin, $newBal,
                          "Redeem $poin poin", $userId]);
            $db->commit();
            return $poin * $cfg['poin_value'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Penyesuaian manual (admin) */
    public static function adjust(int $tenantId, int $pelangganId, int $poinDelta, string $note, ?int $userId = null): int
    {
        $db = Database::get();
        $db->beginTransaction();
        try {
            $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $cur->execute([$pelangganId, $tenantId]);
            $bal = (int)$cur->fetchColumn();
            $newBal = max(0, $bal + $poinDelta);
            $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);
            $db->prepare("INSERT INTO hl_loyalty_log
                            (tenant_id, pelanggan_id, type, poin, balance_after, keterangan, created_by)
                          VALUES (?,?,'adjust',?,?,?,?)")
               ->execute([$tenantId, $pelangganId, $poinDelta, $newBal, $note ?: 'Penyesuaian manual', $userId]);
            $db->commit();
            return $newBal;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Riwayat poin pelanggan */
    public static function history(int $tenantId, int $pelangganId, int $limit = 50): array
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT l.*, (SELECT nama_outlet FROM outlets WHERE id=l.outlet_id) nama_outlet
                                    FROM hl_loyalty_log l
                                   WHERE l.tenant_id=? AND l.pelanggan_id=?
                                   ORDER BY l.id DESC LIMIT ?");
            $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(2, $pelangganId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return []; }
    }
}
