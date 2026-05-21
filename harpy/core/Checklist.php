<?php
// ══════════════════════════════════════════════════════
// core/Checklist.php — Checklist harian outlet
//
// HQ buat template, staff outlet isi tiap hari, HQ monitor compliance.
// ══════════════════════════════════════════════════════

class Checklist
{
    // ── TEMPLATE (HQ) ────────────────────────────────────

    public static function listTemplates(int $tenantId, bool $activeOnly = false): array
    {
        $db = Database::get();
        $sql = "SELECT * FROM hl_checklist_template WHERE tenant_id=?";
        if ($activeOnly) $sql .= " AND is_active=1";
        $sql .= " ORDER BY judul ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['items'] = json_decode($r['items_json'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    public static function getTemplate(int $tenantId, int $id): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM hl_checklist_template WHERE tenant_id=? AND id=?");
        $stmt->execute([$tenantId, $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['items'] = json_decode($r['items_json'] ?? '[]', true) ?: [];
        return $r;
    }

    public static function saveTemplate(int $tenantId, array $data, ?int $id = null): int
    {
        $db = Database::get();
        $judul = trim($data['judul'] ?? '');
        if ($judul === '') throw new RuntimeException('Judul checklist wajib diisi.');

        // Normalisasi items: array of {text, required}
        $rawItems = $data['items'] ?? [];
        $items = [];
        foreach ((array)$rawItems as $it) {
            if (is_string($it)) {
                $text = trim($it);
                $req = 0;
            } else {
                $text = trim($it['text'] ?? '');
                $req  = !empty($it['required']) ? 1 : 0;
            }
            if ($text !== '') $items[] = ['text'=>$text, 'required'=>$req];
        }
        if (!$items) throw new RuntimeException('Minimal 1 item checklist.');

        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
        $deskripsi = trim($data['deskripsi'] ?? '') ?: null;
        $frequency = in_array($data['frequency'] ?? 'daily', ['daily','weekly'], true) ? $data['frequency'] : 'daily';
        $isActive  = (int)($data['is_active'] ?? 1);

        if ($id) {
            $db->prepare("UPDATE hl_checklist_template
                             SET judul=?, deskripsi=?, items_json=?, frequency=?, is_active=?
                           WHERE id=? AND tenant_id=?")
               ->execute([$judul, $deskripsi, $itemsJson, $frequency, $isActive, $id, $tenantId]);
            return $id;
        } else {
            $db->prepare("INSERT INTO hl_checklist_template
                            (tenant_id, judul, deskripsi, items_json, frequency, is_active)
                          VALUES (?,?,?,?,?,?)")
               ->execute([$tenantId, $judul, $deskripsi, $itemsJson, $frequency, $isActive]);
            return (int)$db->lastInsertId();
        }
    }

    public static function deleteTemplate(int $tenantId, int $id): void
    {
        $db = Database::get();
        $db->prepare("UPDATE hl_checklist_template SET is_active=0 WHERE id=? AND tenant_id=?")
           ->execute([$id, $tenantId]);
    }

    // ── SUBMISSION (Outlet) ──────────────────────────────

    /** Submission untuk 1 outlet+template+tanggal (kalau ada) */
    public static function getSubmission(int $tenantId, int $outletId, int $templateId, string $tanggal): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM hl_checklist_submission
                               WHERE tenant_id=? AND outlet_id=? AND template_id=? AND tanggal=?");
        $stmt->execute([$tenantId, $outletId, $templateId, $tanggal]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['answers'] = json_decode($r['answers_json'] ?? '{}', true) ?: [];
        return $r;
    }

    /**
     * Submit/update isian checklist (upsert).
     * @param array $answers  {index: {checked: 0|1, note: ''}}
     */
    public static function submit(
        int $tenantId, int $outletId, int $templateId, string $tanggal,
        array $answers, ?int $userId, ?string $userNama
    ): void {
        $tpl = self::getTemplate($tenantId, $templateId);
        if (!$tpl) throw new RuntimeException('Template tidak ditemukan.');
        $totalItems = count($tpl['items']);

        // Validasi required + hitung checked
        $checked = 0;
        foreach ($tpl['items'] as $idx => $item) {
            $ans = $answers[$idx] ?? $answers[(string)$idx] ?? null;
            $isChecked = !empty($ans['checked']);
            if ($isChecked) $checked++;
            if (!empty($item['required']) && !$isChecked) {
                throw new RuntimeException('Item wajib belum dicentang: "' . $item['text'] . '"');
            }
        }

        $answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE);
        $db = Database::get();
        $db->prepare("
            INSERT INTO hl_checklist_submission
              (tenant_id, outlet_id, template_id, tanggal, answers_json, total_items, checked_items, submitted_by, submitted_by_nama)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              answers_json=VALUES(answers_json), total_items=VALUES(total_items),
              checked_items=VALUES(checked_items), submitted_by=VALUES(submitted_by),
              submitted_by_nama=VALUES(submitted_by_nama)
        ")->execute([
            $tenantId, $outletId, $templateId, $tanggal, $answersJson,
            $totalItems, $checked, $userId, $userNama
        ]);
    }

    // ── COMPLIANCE (HQ Monitor) ──────────────────────────

    /**
     * Compliance matrix untuk 1 tanggal: outlet × template → submitted?
     * @return array {
     *   templates: [{id, judul}],
     *   outlets: [{id, nama_outlet}],
     *   matrix: {outlet_id: {template_id: {submitted, checked, total, by, at}}}
     * }
     */
    public static function compliance(int $tenantId, string $tanggal): array
    {
        $db = Database::get();

        $templates = self::listTemplates($tenantId, true);
        $tplSlim = array_map(fn($t) => ['id'=>(int)$t['id'], 'judul'=>$t['judul']], $templates);

        $oStmt = $db->prepare("SELECT id, nama_outlet FROM outlets
                                WHERE tenant_id=? AND status IN ('trial','grace','active')
                                ORDER BY is_main DESC, nama_outlet ASC");
        $oStmt->execute([$tenantId]);
        $outlets = $oStmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil semua submission tanggal tsb
        $sStmt = $db->prepare("SELECT outlet_id, template_id, total_items, checked_items,
                                      submitted_by_nama, submitted_at
                                 FROM hl_checklist_submission
                                WHERE tenant_id=? AND tanggal=?");
        $sStmt->execute([$tenantId, $tanggal]);
        $subs = [];
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $subs[(int)$s['outlet_id']][(int)$s['template_id']] = [
                'submitted' => true,
                'checked'   => (int)$s['checked_items'],
                'total'     => (int)$s['total_items'],
                'by'        => $s['submitted_by_nama'],
                'at'        => $s['submitted_at'],
            ];
        }

        $matrix = [];
        foreach ($outlets as $o) {
            $oid = (int)$o['id'];
            foreach ($tplSlim as $t) {
                $matrix[$oid][$t['id']] = $subs[$oid][$t['id']] ?? ['submitted'=>false];
            }
        }

        return [
            'templates' => $tplSlim,
            'outlets'   => array_map(fn($o)=>['id'=>(int)$o['id'],'nama_outlet'=>$o['nama_outlet']], $outlets),
            'matrix'    => $matrix,
        ];
    }
}
