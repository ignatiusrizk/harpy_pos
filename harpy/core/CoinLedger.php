<?php
// ══════════════════════════════════════════════════════
// core/CoinLedger.php — Kelola saldo coin tenant
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
        'generate_nota'    =>  50,
        'send_wa_notif'    => 100,
        'send_wa_nota'     => 150,
        'ai_briefing'      => 500,
        'ai_upselling'     =>  50,
        'ai_analyst'       => 200,
        'ai_review'        => 300,
        'generate_invoice' => 200,
        'wa_blast'         => 100,
        'export_pdf'       => 500,
    ];

    // ── Cek saldo (dari session, tanpa query DB) ───────
    public static function canAfford(string $feature): bool
    {
        $cost = self::COSTS[$feature] ?? 0;
        if ($cost === 0) return true;
        return TenantResolver::coinBalance() >= $cost;
    }

    // ── Potong coin (atomic dengan transaction) ────────
    // Return true jika berhasil, false jika saldo tidak cukup
    public static function deduct(
        string  $feature,
        ?string $refId = null
    ): bool {
        $cost = self::COSTS[$feature] ?? 0;
        if ($cost === 0) return true;

        $tenantId = TenantResolver::id();
        $db       = Database::get();

        $db->beginTransaction();
        try {
            // Lock row agar tidak race condition
            $stmt = $db->prepare(
                "SELECT coin_balance FROM tenants WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$tenantId]);
            $current = (int) $stmt->fetch()['coin_balance'];

            if ($current < $cost) {
                $db->rollBack();
                return false;
            }

            $newBalance = $current - $cost;

            $db->prepare(
                "UPDATE tenants SET coin_balance = ? WHERE id = ?"
            )->execute([$newBalance, $tenantId]);

            $db->prepare("
                INSERT INTO coin_ledger
                  (tenant_id, type, amount, feature_used, description, balance_after, ref_id)
                VALUES (?, 'deduct', ?, ?, ?, ?, ?)
            ")->execute([
                $tenantId,
                $cost,
                $feature,
                'Penggunaan fitur: ' . $feature,
                $newBalance,
                $refId,
            ]);

            $db->commit();

            // Sinkronkan ke session & static cache
            $_SESSION['tenant_coin_balance'] = $newBalance;
            TenantResolver::refresh();

            return true;

        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── Top-up coin (dipanggil setelah payment sukses) ─
    public static function topup(
        int    $tenantId,
        int    $amount,
        string $gatewayRef = '',
        string $description = ''
    ): int {
        $db = Database::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "SELECT coin_balance FROM tenants WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$tenantId]);
            $current    = (int) $stmt->fetch()['coin_balance'];
            $newBalance = $current + $amount;

            $db->prepare(
                "UPDATE tenants SET coin_balance = ? WHERE id = ?"
            )->execute([$newBalance, $tenantId]);

            $db->prepare("
                INSERT INTO coin_ledger
                  (tenant_id, type, amount, feature_used, description, balance_after, ref_id)
                VALUES (?, 'topup', ?, 'topup', ?, ?, ?)
            ")->execute([
                $tenantId,
                $amount,
                $description ?: 'Top-up coin',
                $newBalance,
                $gatewayRef,
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
        return (int) ($stmt->fetch()['coin_balance'] ?? 0);
    }

    // ── Riwayat transaksi coin ─────────────────────────
    public static function history(int $limit = 50): array
    {
        $stmt = Database::get()->prepare(
            "SELECT * FROM coin_ledger
             WHERE tenant_id = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([TenantResolver::id(), $limit]);
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
}
