<?php
// ══════════════════════════════════════════════════════
// core/TenantQuery.php — Query wrapper dengan tenant_id + outlet_id otomatis
//
// ATURAN WAJIB:
//   Semua query ke tabel operasional (hl_*) HARUS lewat class ini.
//   Tidak boleh ada PDO query langsung tanpa filter tenant_id.
//
// OUTLET SCOPE:
//   Tabel dalam $outletTables otomatis difilter juga dengan outlet_id.
//   Tabel lain (hl_users, hl_roles, dll) hanya difilter tenant_id.
//
// CARA PAKAI:
//   // SELECT
//   $orders = TenantQuery::fetch('hl_transaksi', 'status_proses = ?', ['masuk']);
//   $order  = TenantQuery::fetchOne('hl_transaksi', 'id = ?', [42]);
//   $total  = TenantQuery::sum('hl_kas', 'jumlah', 'tipe = ?', ['masuk']);
//   $count  = TenantQuery::count('hl_transaksi', 'status_bayar = ?', ['lunas']);
//
//   // INSERT
//   $id = TenantQuery::insert('hl_pelanggan', ['nama' => 'Budi', 'telepon' => '08xx']);
//
//   // UPDATE
//   TenantQuery::update('hl_transaksi', ['status_proses' => 'siap'], 'id = ?', [42]);
//
//   // DELETE
//   TenantQuery::delete('hl_transaksi', 'id = ?', [42]);
//
//   // RAW (caller wajib filter tenant_id + outlet_id sendiri)
//   TenantQuery::raw("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? ...", [$tid, $oid]);
// ══════════════════════════════════════════════════════

class TenantQuery
{
    // Tabel yang punya outlet_id dan perlu outlet filter
    // CATATAN per brief HQ-Outlet:
    //   - hl_pelanggan (Fase 2): account-level, lookup lintas outlet
    //   - hl_karyawan / hl_users (Fase 3): account-level, penugasan via
    //     hl_karyawan_outlet. hl_users selalu tenant-scoped (bukan outlet).
    //   - Karyawan view di outlet = JOIN hl_karyawan_outlet WHERE outlet_id=?
    private static array $outletTables = [
        'hl_transaksi', 'hl_transaksi_item',
        'hl_kas', 'hl_absensi', 'hl_layanan', 'hl_gaji', 'hl_izin', 'hl_promo', 'hl_audit_log'
    ];

    private static function hasOutletScope(string $table): bool
    {
        return in_array($table, self::$outletTables, true);
    }

    // ── SELECT banyak row ─────────────────────────────
    public static function fetch(
        string $table,
        string $where  = '1',
        array  $params = [],
        string $extra  = ''     // ORDER BY, LIMIT, dll
    ): array {
        $tid = TenantResolver::id();
        if (self::hasOutletScope($table)) {
            $oid = TenantResolver::outletId();
            $sql = "SELECT * FROM `{$table}` WHERE tenant_id = ? AND outlet_id = ? AND ({$where}) {$extra}";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid, $oid], $params));
        } else {
            $sql = "SELECT * FROM `{$table}` WHERE tenant_id = ? AND ({$where}) {$extra}";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid], $params));
        }
        return $stmt->fetchAll();
    }

    // ── SELECT satu row ───────────────────────────────
    public static function fetchOne(
        string $table,
        string $where,
        array  $params = []
    ): ?array {
        $rows = self::fetch($table, $where, $params, 'LIMIT 1');
        return $rows[0] ?? null;
    }

    // ── SELECT kolom custom ───────────────────────────
    public static function fetchSelect(
        string $table,
        string $select,
        string $where  = '1',
        array  $params = [],
        string $extra  = ''
    ): array {
        $tid = TenantResolver::id();
        if (self::hasOutletScope($table)) {
            $oid = TenantResolver::outletId();
            $sql = "SELECT {$select} FROM `{$table}` WHERE tenant_id = ? AND outlet_id = ? AND ({$where}) {$extra}";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid, $oid], $params));
        } else {
            $sql = "SELECT {$select} FROM `{$table}` WHERE tenant_id = ? AND ({$where}) {$extra}";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid], $params));
        }
        return $stmt->fetchAll();
    }

    // ── COUNT ─────────────────────────────────────────
    public static function count(
        string $table,
        string $where  = '1',
        array  $params = []
    ): int {
        $tid = TenantResolver::id();
        if (self::hasOutletScope($table)) {
            $oid = TenantResolver::outletId();
            $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE tenant_id = ? AND outlet_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid, $oid], $params));
        } else {
            $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE tenant_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid], $params));
        }
        return (int)$stmt->fetch()['total'];
    }

    // ── SUM ───────────────────────────────────────────
    public static function sum(
        string $table,
        string $column,
        string $where  = '1',
        array  $params = []
    ): float {
        $tid = TenantResolver::id();
        if (self::hasOutletScope($table)) {
            $oid = TenantResolver::outletId();
            $sql = "SELECT COALESCE(SUM(`{$column}`), 0) AS total
                    FROM `{$table}` WHERE tenant_id = ? AND outlet_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid, $oid], $params));
        } else {
            $sql = "SELECT COALESCE(SUM(`{$column}`), 0) AS total
                    FROM `{$table}` WHERE tenant_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid], $params));
        }
        return (float)$stmt->fetch()['total'];
    }

    // ── INSERT ────────────────────────────────────────
    // tenant_id (dan outlet_id jika relevan) di-inject otomatis
    public static function insert(string $table, array $data): int
    {
        $data['tenant_id'] = TenantResolver::id();
        if (self::hasOutletScope($table) && !isset($data['outlet_id'])) {
            $data['outlet_id'] = TenantResolver::outletId();
        }
        if (!array_key_exists('created_at', $data)) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        $cols = implode(',', array_map(fn($k) => "`{$k}`", array_keys($data)));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        $stmt = Database::get()->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$ph})");
        $stmt->execute(array_values($data));
        return (int)Database::get()->lastInsertId();
    }

    // ── UPDATE ────────────────────────────────────────
    // tenant_id (dan outlet_id jika relevan) filter otomatis
    public static function update(
        string $table,
        array  $data,
        string $where,
        array  $whereParams = []
    ): int {
        $tid = TenantResolver::id();
        $set = implode(',', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
        if (self::hasOutletScope($table)) {
            $oid = TenantResolver::outletId();
            $sql = "UPDATE `{$table}` SET {$set} WHERE tenant_id = ? AND outlet_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute([...array_values($data), $tid, $oid, ...$whereParams]);
        } else {
            $sql = "UPDATE `{$table}` SET {$set} WHERE tenant_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute([...array_values($data), $tid, ...$whereParams]);
        }
        return $stmt->rowCount();
    }

    // ── DELETE ────────────────────────────────────────
    // tenant_id (dan outlet_id jika relevan) filter otomatis
    public static function delete(
        string $table,
        string $where,
        array  $params = []
    ): int {
        $tid = TenantResolver::id();
        if (self::hasOutletScope($table)) {
            $oid = TenantResolver::outletId();
            $sql = "DELETE FROM `{$table}` WHERE tenant_id = ? AND outlet_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid, $oid], $params));
        } else {
            $sql = "DELETE FROM `{$table}` WHERE tenant_id = ? AND ({$where})";
            $stmt = Database::get()->prepare($sql);
            $stmt->execute(array_merge([$tid], $params));
        }
        return $stmt->rowCount();
    }

    // ── EXISTS ────────────────────────────────────────
    public static function exists(
        string $table,
        string $where,
        array  $params = []
    ): bool {
        return self::count($table, $where, $params) > 0;
    }

    // ── Raw query — caller bertanggung jawab WHERE lengkap ─
    // Untuk JOIN antar tabel — tetap wajib filter tenant_id + outlet_id
    // Contoh:
    //   $tid = TenantResolver::id();
    //   $oid = TenantResolver::outletId();
    //   TenantQuery::raw(
    //     "SELECT t.*, p.nama AS nama_pelanggan
    //      FROM hl_transaksi t
    //      LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
    //      WHERE t.tenant_id = ? AND t.outlet_id = ? AND t.status_proses = ?
    //      ORDER BY t.created_at DESC LIMIT 50",
    //     [$tid, $oid, 'masuk']
    //   )
    public static function raw(string $sql, array $params = []): array
    {
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Raw single row ────────────────────────────────
    public static function rawOne(string $sql, array $params = []): ?array
    {
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
