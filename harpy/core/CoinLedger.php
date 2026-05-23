<?php
// ══════════════════════════════════════════════════════
// core/CoinLedger.php — Kelola saldo coin tenant/outlet
//
// CARA PAKAI:
//   // Sebelum pakai fitur berbayar
//   if (!CoinLedger::canAfford('send_wa_notif')) {
//       echo json_encode(['error' => 'Coin tidak cukup']);
//       exit;
//   }
//   // Setelah fitur berhasil dijalankan
//   CoinLedger::deduct('send_wa_notif', $orderId);
// ══════════════════════════════════════════════════════

class CoinLedger
{
    // ── Biaya per fitur (dalam coin) ──────────────────
    const COSTS = [
        'generate_nota'      =>  50,
        'send_wa_notif'      => 100,
        'send_wa_nota'       => 150,
        'ai_briefing'        => 500,
        'ai_upselling'       =>  50,
        'ai_analyst'         => 200,
        'ai_review'          => 300,
        'ai_insight_laporan' => 100,
        'ai_chat_data'       =>  50,
        'ai_churn_message'   =>  30,
        'ai_briefing_hq'     =>  80,
        // Owner POV (BAGIAN 7)
        'daily_report'       => 100,   // per kirim laporan harian ke owner
        'alert_anomali'      =>  50,   // per alert anomali ke owner
        'invoice_b2b'        => 200,   // per invoice B2B
        'reminder_piutang'   => 100,   // per reminder piutang
        'generate_invoice'   => 200,
        'wa_blast'           => 100,
        'export_pdf'         => 500,
    ];

    // ── Cek saldo (dari cache, tanpa query DB) ─────────
    public static function canAfford(string $feature): bool
    {
        $cost = self::COSTS[$feature] ?? 0;
        if ($cost === 0) return true;
        return TenantResolver::coinBalance() >= $cost;
    }

    // ── Potong coin (atomic dengan transaction) ────────
    // Prioritas pemotongan:
    //   1. Jika outlet dalam status 'trial' → potong trial_coin_balance dulu
    //   2. Jika trial habis / outlet active → potong coin_balance (shared/per_outlet)
    // Return true jika berhasil, false jika saldo tidak cukup
    public static function deduct(
        string  $feature,
        ?string $refId = null
    ): bool {
        $cost = self::COSTS[$feature] ?? 0;
        if ($cost === 0) return true;

        $tenantId  = TenantResolver::id();
        $outletId  = TenantResolver::outletId();
        $isShared  = TenantResolver::isSharedCoin();
        $outlet    = TenantResolver::getOutlet();
        $db        = Database::get();

        $db->beginTransaction();
        try {
            // ── Coba potong dari trial_coin_balance dulu ──
            if (
                !empty($outlet) &&
                ($outlet['status'] ?? '') === 'trial' &&
                ($outlet['trial_coin_balance'] ?? 0) > 0
            ) {
                $stmt = $db->prepare(
                    "SELECT trial_coin_balance FROM outlets WHERE id = ? AND tenant_id = ? FOR UPDATE"
                );
                $stmt->execute([$outletId, $tenantId]);
                $trialBalance = (int)($stmt->fetch()['trial_coin_balance'] ?? 0);

                if ($trialBalance >= $cost) {
                    $newTrialBalance = $trialBalance - $cost;
                    $db->prepare(
                        "UPDATE outlets SET trial_coin_balance = ? WHERE id = ? AND tenant_id = ?"
                    )->execute([$newTrialBalance, $outletId, $tenantId]);

                    // Catat di ledger sebagai trial deduction
                    $db->prepare("
                        INSERT INTO coin_ledger
                          (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                        VALUES (?, ?, 'deduct', ?, ?, ?, ?, ?)
                    ")->execute([
                        $tenantId,
                        $outletId,
                        $cost,
                        $feature,
                        '[TRIAL] Penggunaan fitur: ' . $feature,
                        $newTrialBalance,
                        $refId,
                    ]);

                    $db->commit();
                    $_SESSION['tenant_coin_balance'] = $newTrialBalance;
                    TenantResolver::refresh();
                    return true;
                }
                // trial_coin_balance tidak cukup → lanjut potong dari regular balance
            }

            // ── Potong dari regular coin_balance ──────────
            $newBalance = 0;

            if ($isShared) {
                // Lock dan potong dari tenants.coin_balance
                $stmt = $db->prepare(
                    "SELECT coin_balance FROM tenants WHERE id = ? FOR UPDATE"
                );
                $stmt->execute([$tenantId]);
                $current = (int)$stmt->fetch()['coin_balance'];

                if ($current < $cost) {
                    $db->rollBack();
                    return false;
                }

                $newBalance = $current - $cost;
                $db->prepare(
                    "UPDATE tenants SET coin_balance = ? WHERE id = ?"
                )->execute([$newBalance, $tenantId]);

            } else {
                // Lock dan potong dari outlets.coin_balance
                $stmt = $db->prepare(
                    "SELECT coin_balance FROM outlets WHERE id = ? AND tenant_id = ? FOR UPDATE"
                );
                $stmt->execute([$outletId, $tenantId]);
                $current = (int)$stmt->fetch()['coin_balance'];

                if ($current < $cost) {
                    $db->rollBack();
                    return false;
                }

                $newBalance = $current - $cost;
                $db->prepare(
                    "UPDATE outlets SET coin_balance = ? WHERE id = ? AND tenant_id = ?"
                )->execute([$newBalance, $outletId, $tenantId]);
            }

            // Catat di ledger dengan tenant_id + outlet_id
            $db->prepare("
                INSERT INTO coin_ledger
                  (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                VALUES (?, ?, 'deduct', ?, ?, ?, ?, ?)
            ")->execute([
                $tenantId,
                $outletId,
                $cost,
                $feature,
                'Penggunaan fitur: ' . $feature,
                $newBalance,
                $refId,
            ]);

            $db->commit();

            // Sinkronkan ke session & refresh cache
            $_SESSION['tenant_coin_balance'] = $newBalance;
            TenantResolver::refresh();

            return true;

        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── Top-up coin ────────────────────────────────────
    // Dipanggil setelah payment sukses.
    // Jika $outletId diberikan dan coin_mode=per_outlet → topup outlet,
    // otherwise topup tenant (shared).
    public static function topup(
        int    $tenantId,
        int    $amount,
        string $gatewayRef = '',
        string $description = '',
        int    $outletId = 0
    ): int {
        $db = Database::get();

        // Cek coin_mode
        $tenantRow = $db->prepare("SELECT coin_mode FROM tenants WHERE id = ? LIMIT 1");
        $tenantRow->execute([$tenantId]);
        $coinMode = $tenantRow->fetch()['coin_mode'] ?? 'shared';

        $db->beginTransaction();
        try {
            if ($coinMode === 'per_outlet' && $outletId > 0) {
                $stmt = $db->prepare(
                    "SELECT coin_balance FROM outlets WHERE id = ? AND tenant_id = ? FOR UPDATE"
                );
                $stmt->execute([$outletId, $tenantId]);
                $current    = (int)$stmt->fetch()['coin_balance'];
                $newBalance = $current + $amount;

                $db->prepare(
                    "UPDATE outlets SET coin_balance = ? WHERE id = ? AND tenant_id = ?"
                )->execute([$newBalance, $outletId, $tenantId]);

            } else {
                $stmt = $db->prepare(
                    "SELECT coin_balance FROM tenants WHERE id = ? FOR UPDATE"
                );
                $stmt->execute([$tenantId]);
                $current    = (int)$stmt->fetch()['coin_balance'];
                $newBalance = $current + $amount;

                $db->prepare(
                    "UPDATE tenants SET coin_balance = ? WHERE id = ?"
                )->execute([$newBalance, $tenantId]);
                $outletId = 0; // simpan 0 di ledger untuk topup shared
            }

            $db->prepare("
                INSERT INTO coin_ledger
                  (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                VALUES (?, ?, 'topup', ?, 'topup', ?, ?, ?)
            ")->execute([
                $tenantId,
                $outletId ?: null,
                $amount,
                $description ?: 'Top-up coin',
                $newBalance,
                $gatewayRef ?: null,
            ]);

            $db->commit();
            return $newBalance;

        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── Ambil balance langsung dari DB ─────────────────
    public static function balance(int $tenantId): int
    {
        $stmt = Database::get()->prepare(
            "SELECT coin_balance FROM tenants WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$tenantId]);
        return (int)($stmt->fetch()['coin_balance'] ?? 0);
    }

    // ── Ambil balance outlet dari DB ───────────────────
    public static function outletBalance(int $outletId): int
    {
        $stmt = Database::get()->prepare(
            "SELECT coin_balance FROM outlets WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$outletId]);
        return (int)($stmt->fetch()['coin_balance'] ?? 0);
    }

    // ── Saldo saat ini (berdasarkan context TenantResolver) ─
    public static function getBalance(): int
    {
        return TenantResolver::coinBalance();
    }

    // ── Riwayat transaksi coin ─────────────────────────
    public static function history(int $limit = 50): array
    {
        $tid = TenantResolver::id();
        $oid = TenantResolver::outletId();
        $stmt = Database::get()->prepare(
            "SELECT * FROM coin_ledger
             WHERE tenant_id = ? AND (outlet_id = ? OR outlet_id IS NULL)
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$tid, $oid, $limit]);
        return $stmt->fetchAll();
    }

    // ── Shorthand untuk fitur-fitur umum ──────────────
    public static function deductNota(?string $noOrder = null): bool
    {
        return self::deduct('generate_nota', $noOrder);
    }

    public static function deductWaNotif(?string $refId = null): bool
    {
        return self::deduct('send_wa_notif', $refId);
    }

    public static function deductWaNota(?string $noOrder = null): bool
    {
        return self::deduct('send_wa_nota', $noOrder);
    }

    public static function deductAiBriefing(): bool
    {
        return self::deduct('ai_briefing');
    }

    // ════════════════════════════════════════════════════
    // HQ BILLING — monitor, transfer, budget
    // ════════════════════════════════════════════════════

    /** Penggunaan coin per outlet (deduct) dalam rentang tanggal + budget bulanan */
    public static function usageByOutlet(int $tenantId, string $start, string $end): array
    {
        $db = Database::get();
        // coin_budget_monthly mungkin belum ada (migration) → coba, fallback tanpa
        try {
            $stmt = $db->prepare("
                SELECT o.id outlet_id, o.nama_outlet, o.coin_balance, o.coin_budget_monthly,
                       COALESCE(SUM(ABS(cl.amount)),0) used, COUNT(cl.id) cnt
                  FROM outlets o
                  LEFT JOIN coin_ledger cl ON cl.outlet_id=o.id AND cl.tenant_id=o.tenant_id
                       AND cl.type='deduct' AND DATE(cl.created_at) BETWEEN ? AND ?
                 WHERE o.tenant_id=? AND o.status IN ('trial','grace','active')
                 GROUP BY o.id, o.nama_outlet, o.coin_balance, o.coin_budget_monthly
                 ORDER BY used DESC
            ");
            $stmt->execute([$start, $end, $tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $stmt = $db->prepare("
                SELECT o.id outlet_id, o.nama_outlet, o.coin_balance, 0 AS coin_budget_monthly,
                       COALESCE(SUM(ABS(cl.amount)),0) used, COUNT(cl.id) cnt
                  FROM outlets o
                  LEFT JOIN coin_ledger cl ON cl.outlet_id=o.id AND cl.tenant_id=o.tenant_id
                       AND cl.type='deduct' AND DATE(cl.created_at) BETWEEN ? AND ?
                 WHERE o.tenant_id=? AND o.status IN ('trial','grace','active')
                 GROUP BY o.id, o.nama_outlet, o.coin_balance
                 ORDER BY used DESC
            ");
            $stmt->execute([$start, $end, $tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /** Penggunaan coin per fitur dalam rentang (opsional 1 outlet) */
    public static function usageByFeature(int $tenantId, string $start, string $end, int $outletId = 0): array
    {
        $db = Database::get();
        $filter = $outletId > 0 ? " AND outlet_id=?" : "";
        $sql = "SELECT COALESCE(feature_used,'(lainnya)') feature,
                       COALESCE(SUM(ABS(amount)),0) used, COUNT(*) cnt
                  FROM coin_ledger
                 WHERE tenant_id=? AND type='deduct' AND DATE(created_at) BETWEEN ? AND ? $filter
                 GROUP BY feature_used ORDER BY used DESC";
        $stmt = $db->prepare($sql);
        $params = [$tenantId, $start, $end];
        if ($outletId > 0) $params[] = $outletId;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Coin terpakai bulan berjalan untuk 1 outlet (untuk cek budget) */
    public static function monthlyUsage(int $tenantId, int $outletId): int
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT COALESCE(SUM(ABS(amount)),0) FROM coin_ledger
                               WHERE tenant_id=? AND outlet_id=? AND type='deduct'
                                 AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')");
        $stmt->execute([$tenantId, $outletId]);
        return (int)$stmt->fetchColumn();
    }

    /** Set budget coin bulanan per outlet (0 = unlimited) */
    public static function setBudget(int $tenantId, int $outletId, int $budget): bool
    {
        $db = Database::get();
        $stmt = $db->prepare("UPDATE outlets SET coin_budget_monthly=? WHERE id=? AND tenant_id=?");
        $stmt->execute([max(0, $budget), $outletId, $tenantId]);
        return $stmt->rowCount() >= 0;
    }

    /**
     * Transfer coin antar outlet (hanya mode per_outlet).
     * @throws RuntimeException kalau mode shared / saldo kurang.
     */
    public static function transferBetweenOutlets(
        int $tenantId, int $fromOutlet, int $toOutlet, int $amount, string $desc = ''
    ): void {
        if ($amount <= 0) throw new RuntimeException('Jumlah transfer harus > 0.');
        if ($fromOutlet === $toOutlet) throw new RuntimeException('Outlet asal & tujuan sama.');

        $db = Database::get();
        $mode = $db->prepare("SELECT coin_mode FROM tenants WHERE id=?");
        $mode->execute([$tenantId]);
        if (($mode->fetchColumn() ?: 'shared') !== 'per_outlet') {
            throw new RuntimeException('Transfer hanya tersedia di mode coin per-outlet.');
        }

        $db->beginTransaction();
        try {
            $src = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? FOR UPDATE");
            $src->execute([$fromOutlet, $tenantId]);
            $srcBal = (int)$src->fetchColumn();
            if ($srcBal < $amount) throw new RuntimeException('Saldo outlet asal tidak cukup (' . $srcBal . ').');

            $dst = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? FOR UPDATE");
            $dst->execute([$toOutlet, $tenantId]);
            $dstBal = (int)$dst->fetchColumn();

            $newSrc = $srcBal - $amount;
            $newDst = $dstBal + $amount;
            $db->prepare("UPDATE outlets SET coin_balance=? WHERE id=? AND tenant_id=?")->execute([$newSrc, $fromOutlet, $tenantId]);
            $db->prepare("UPDATE outlets SET coin_balance=? WHERE id=? AND tenant_id=?")->execute([$newDst, $toOutlet, $tenantId]);

            $ins = $db->prepare("INSERT INTO coin_ledger
                  (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                  VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$tenantId, $fromOutlet, 'deduct', $amount, 'transfer_out',
                ($desc ?: 'Transfer ke outlet #'.$toOutlet), $newSrc, 'TRF']);
            $ins->execute([$tenantId, $toOutlet, 'topup', $amount, 'transfer_in',
                ($desc ?: 'Transfer dari outlet #'.$fromOutlet), $newDst, 'TRF']);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
