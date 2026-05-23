<?php
// ══════════════════════════════════════════════════════
// core/Notifier.php — Channel-agnostic notification dispatcher
//
// Pakai untuk:
//   - Daily report owner (email + in-app feed)
//   - Alert anomali (email + in-app)
//   - Invoice B2B / reminder piutang (in-app, opsional email)
//
// Tiap kirim:
//   1. Cek rate-limit (kalau type sama sudah kirim dalam window) → skip
//   2. Coin check & deduct (kalau ada feature)
//   3. Kirim via channel (email pakai Mailer, in-app cuma simpan log)
//   4. Tulis ke hl_notif_log
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Mailer.php';

class Notifier
{
    /** Cek apakah type sudah dikirim dalam X menit terakhir untuk outlet ini */
    public static function recentlySent(int $tenantId, int $outletId, string $type, int $windowMinutes = 360): bool
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT 1 FROM hl_notif_log
                                   WHERE tenant_id=? AND outlet_id=? AND type=? AND status='sent'
                                     AND sent_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                                   LIMIT 1");
            $stmt->execute([$tenantId, $outletId, $type, $windowMinutes]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) { return false; }
    }

    /** Apakah daily_report sudah dikirim hari ini? */
    public static function sentToday(int $tenantId, int $outletId, string $type): bool
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT 1 FROM hl_notif_log
                                   WHERE tenant_id=? AND outlet_id=? AND type=? AND status='sent'
                                     AND DATE(sent_at)=CURDATE() LIMIT 1");
            $stmt->execute([$tenantId, $outletId, $type]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) { return false; }
    }

    /**
     * Kirim notifikasi ke owner.
     * @param array $opts {
     *   @var string $type             // 'daily_report' / 'alert_*' / 'invoice_b2b' / 'reminder_piutang'
     *   @var string $subject          // judul
     *   @var string $body_html        // body untuk email
     *   @var string $body_summary     // ringkas untuk in-app feed
     *   @var string $coin_feature     // CoinLedger feature key (kalau ada → deduct)
     *   @var string[] $channels       // ['email','inapp']
     *   @var int    $rate_limit_min   // default 360 (6 jam) — kecuali daily_report (24 jam, pakai sentToday)
     *   @var string $target_email     // override email tujuan
     * }
     * @return array {ok, channels_sent[], skipped, error}
     */
    public static function notifyOwner(int $tenantId, int $outletId, array $opts): array
    {
        $type        = $opts['type'] ?? '';
        $subject     = $opts['subject'] ?? '';
        $bodyHtml    = $opts['body_html'] ?? '';
        $bodySummary = $opts['body_summary'] ?? '';
        $channels    = $opts['channels'] ?? ['email','inapp'];
        $rateLimit   = (int)($opts['rate_limit_min'] ?? 360);
        $coinFeature = $opts['coin_feature'] ?? '';

        if (!$type || !$subject) return ['ok'=>false, 'error'=>'type+subject wajib'];

        // Rate limit
        $isDaily = $type === 'daily_report';
        $skipReason = '';
        if ($isDaily) {
            if (self::sentToday($tenantId, $outletId, $type)) $skipReason = 'sudah dikirim hari ini';
        } else {
            if (self::recentlySent($tenantId, $outletId, $type, $rateLimit)) $skipReason = "rate-limit ({$rateLimit} menit)";
        }
        if ($skipReason) return ['ok'=>false, 'skipped'=>$skipReason];

        // Coin check (kalau channel email/wa benar2 dikirim)
        $needCoin = $coinFeature && (in_array('email',$channels,true) || in_array('wa',$channels,true));
        if ($needCoin) {
            try {
                if (!CoinLedger::canAfford($coinFeature)) {
                    // Tetap simpan in-app sebagai fallback (gratis)
                    $channels = array_filter($channels, fn($c)=>$c==='inapp');
                    if (!$channels) return ['ok'=>false, 'error'=>'Coin tidak cukup untuk '.$coinFeature];
                }
            } catch (Throwable) {}
        }

        // Ambil target email dari tenants (owner email)
        $targetEmail = $opts['target_email'] ?? null;
        $ownerName = 'Owner';
        if (!$targetEmail) {
            try {
                $db = Database::get();
                $s = $db->prepare("SELECT email, owner_name FROM tenants WHERE id=?");
                $s->execute([$tenantId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) { $targetEmail = $row['email']; $ownerName = $row['owner_name'] ?? 'Owner'; }
            } catch (Throwable) {}
        }

        $sentChannels = [];
        $errorMsg = null;

        // Channel: EMAIL
        if (in_array('email', $channels, true) && $targetEmail) {
            try {
                $ok = Mailer::send($targetEmail, $ownerName, $subject, $bodyHtml);
                if ($ok) {
                    $sentChannels[] = 'email';
                    self::log($tenantId, $outletId, $type, 'email', $targetEmail, $subject, $bodySummary, 'sent');
                    if ($coinFeature) { try { CoinLedger::deduct($coinFeature); } catch (Throwable) {} }
                } else {
                    $errorMsg = Mailer::getLastError();
                    self::log($tenantId, $outletId, $type, 'email', $targetEmail, $subject, $bodySummary, 'failed', $errorMsg);
                }
            } catch (Throwable $e) {
                $errorMsg = $e->getMessage();
                self::log($tenantId, $outletId, $type, 'email', $targetEmail, $subject, $bodySummary, 'failed', $errorMsg);
            }
        }

        // Channel: IN-APP (selalu sukses, gratis)
        if (in_array('inapp', $channels, true)) {
            self::log($tenantId, $outletId, $type, 'inapp', null, $subject, $bodySummary, 'sent');
            $sentChannels[] = 'inapp';
        }

        return ['ok' => !empty($sentChannels), 'channels_sent'=>$sentChannels, 'error'=>$errorMsg];
    }

    /** Tulis 1 row ke hl_notif_log */
    public static function log(
        int $tenantId, int $outletId, string $type, string $channel,
        ?string $target, ?string $subject, ?string $bodySummary,
        string $status = 'sent', ?string $errorMsg = null
    ): void {
        try {
            $db = Database::get();
            $stmt = $db->prepare("INSERT INTO hl_notif_log
                  (tenant_id, outlet_id, type, channel, target, subject, body_summary, status, error_msg)
                  VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$tenantId, $outletId, $type, $channel, $target,
                $subject ? substr($subject,0,200) : null,
                $bodySummary, $status, $errorMsg ? substr($errorMsg,0,255) : null]);
        } catch (Throwable $e) { error_log('[Notifier::log] '.$e->getMessage()); }
    }

    /** Helper: jumlah unread in-app */
    public static function unreadCount(int $tenantId, int $outletId): int
    {
        try {
            $db = Database::get();
            $s = $db->prepare("SELECT COUNT(*) FROM hl_notif_log
                                WHERE tenant_id=? AND outlet_id=? AND channel='inapp'
                                  AND read_at IS NULL AND status='sent'");
            $s->execute([$tenantId, $outletId]);
            return (int)$s->fetchColumn();
        } catch (Throwable) { return 0; }
    }
}
