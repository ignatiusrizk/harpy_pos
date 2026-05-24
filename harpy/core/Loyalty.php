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

    /** Update tier pelanggan berdasarkan poin_balance saat ini */
    public static function updateTier(int $tenantId, int $pelangganId): string
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=?");
            $stmt->execute([$pelangganId, $tenantId]);
            $poin = (int)$stmt->fetchColumn();
            $tier = 'regular';
            if      ($poin >= 500) $tier = 'platinum';
            elseif  ($poin >= 200) $tier = 'gold';
            elseif  ($poin >= 100) $tier = 'silver';
            $db->prepare("UPDATE hl_pelanggan SET tier=? WHERE id=? AND tenant_id=?")
               ->execute([$tier, $pelangganId, $tenantId]);
            return $tier;
        } catch (Throwable $e) {
            error_log('[Loyalty::updateTier] '.$e->getMessage());
            return 'regular';
        }
    }

    /** Touch last_transaksi pelanggan ke CURDATE() */
    public static function touchLastTransaksi(int $tenantId, int $pelangganId): void
    {
        try {
            Database::get()
                ->prepare("UPDATE hl_pelanggan SET last_transaksi=CURDATE() WHERE id=? AND tenant_id=?")
                ->execute([$pelangganId, $tenantId]);
        } catch (Throwable $e) {
            error_log('[Loyalty::touchLastTransaksi] '.$e->getMessage());
        }
    }

    /** Reward berikutnya yang bisa dicapai pelanggan (untuk pesan motivasi) */
    public static function nextReward(int $tenantId, int $outletId, int $poinSaatIni): ?array
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT id, nama_reward, poin_dibutuhkan
                                    FROM hl_poin_reward
                                   WHERE tenant_id=? AND outlet_id=? AND is_active=1
                                     AND poin_dibutuhkan > ?
                                   ORDER BY poin_dibutuhkan ASC LIMIT 1");
            $stmt->execute([$tenantId, $outletId, $poinSaatIni]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable) { return null; }
    }

    /** List reward aktif yang BISA diredeem pelanggan (poin cukup) */
    public static function availableRewards(int $tenantId, int $outletId, int $poinSaatIni): array
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT * FROM hl_poin_reward
                                   WHERE tenant_id=? AND outlet_id=? AND is_active=1
                                   ORDER BY poin_dibutuhkan ASC");
            $stmt->execute([$tenantId, $outletId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['bisa_redeem'] = $poinSaatIni >= (int)$r['poin_dibutuhkan'];
                $r['kurang']     = max(0, (int)$r['poin_dibutuhkan'] - $poinSaatIni);
            }
            return $rows;
        } catch (Throwable) { return []; }
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

            $db->prepare("UPDATE hl_pelanggan SET poin_balance=?, last_transaksi=CURDATE() WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);

            // Expiry — ambil dari setting tenant (default 12 bulan)
            $months = 12;
            try {
                $sst = $db->prepare("SELECT loyalty_expiry_months FROM tenants WHERE id=?");
                $sst->execute([$tenantId]);
                $months = max(1, (int)($sst->fetchColumn() ?: 12));
            } catch (Throwable) {}
            $expDate = date('Y-m-d', strtotime("+{$months} months"));

            $db->prepare("INSERT INTO hl_loyalty_log
                            (tenant_id, outlet_id, pelanggan_id, transaksi_id, type, poin, balance_after, keterangan, expired_at)
                          VALUES (?,?,?,?,'earn',?,?,?,?)")
               ->execute([$tenantId, $outletId, $pelangganId, $transaksiId, $poin, $newBal,
                          'Earn dari transaksi #'.$transaksiId, $expDate]);
            $db->commit();

            // Update tier (di luar transaction — non-critical)
            self::updateTier($tenantId, $pelangganId);
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

    /**
     * Redeem di dalam transaksi yang SUDAH dibuka caller (tidak begin/commit sendiri).
     * Dipakai POS saat create order. Return nilai rupiah diskon dari poin.
     * @throws RuntimeException kalau poin kurang.
     */
    public static function redeemInTx(
        PDO $db, int $tenantId, ?int $outletId, int $pelangganId, int $poin, ?int $transaksiId, ?int $userId = null
    ): int {
        if ($poin <= 0) return 0;
        $cfg = self::config($tenantId);

        $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
        $cur->execute([$pelangganId, $tenantId]);
        $bal = (int)$cur->fetchColumn();
        if ($bal < $poin) throw new RuntimeException("Poin tidak cukup (saldo: $bal).");

        $newBal = $bal - $poin;
        $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
           ->execute([$newBal, $pelangganId, $tenantId]);
        $db->prepare("INSERT INTO hl_loyalty_log
                        (tenant_id, outlet_id, pelanggan_id, transaksi_id, type, poin, balance_after, keterangan, created_by)
                      VALUES (?,?,?,?,'redeem',?,?,?,?)")
           ->execute([$tenantId, $outletId, $pelangganId, $transaksiId, -$poin, $newBal,
                      "Redeem $poin poin di POS", $userId]);
        return $poin * $cfg['poin_value'];
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
