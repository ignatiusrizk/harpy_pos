<?php
// ══════════════════════════════════════════════════════
// core/TenantQuery.php — Query wrapper dengan tenant_id otomatis
//
// ATURAN WAJIB:
//   Semua query ke tabel operasional (hl_*) HARUS lewat class ini.
//   Tidak boleh ada PDO query langsung tanpa filter tenant_id.
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
// ══════════════════════════════════════════════════════

class TenantQuery
{
    // ── SELECT banyak row ─────────────────────────────
    public static function fetch(
        string $table,
        string $where  = '1',
        array  $params = [],
        string $extra  = ''     // ORDER BY, LIMIT, dll
    ): array {
        $tid  = TenantResolver::id();
        $sql  = "SELECT * FROM `{$table}` WHERE tenant_id = ? AND ({$where}) {$extra}";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute(array_merge([$tid], $params));
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
        $tid  = TenantResolver::id();
        $sql  = "SELECT {$select} FROM `{$table}` WHERE tenant_id = ? AND ({$where}) {$extra}";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute(array_merge([$tid], $params));
        return $stmt->fetchAll();
    }

    // ── COUNT ─────────────────────────────────────────
    public static function count(
        string $table,
        string $where  = '1',
        array  $params = []
    ): int {
        $tid  = TenantResolver::id();
        $sql  = "SELECT COUNT(*) AS total FROM `{$table}` WHERE tenant_id = ? AND ({$where})";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute(array_merge([$tid], $params));
        return (int) $stmt->fetch()['total'];
    }

    // ── SUM ───────────────────────────────────────────
    public static function sum(
        string $table,
        string $column,
        string $where  = '1',
        array  $params = []
    ): float {
        $tid  = TenantResolver::id();
        $sql  = "SELECT COALESCE(SUM(`{$column}`), 0) AS total
                 FROM `{$table}` WHERE tenant_id = ? AND ({$where})";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute(array_merge([$tid], $params));
        return (float) $stmt->fetch()['total'];
    }

    // ── INSERT ────────────────────────────────────────
    // tenant_id & created_at di-inject otomatis
    public static function insert(string $table, array $data): int
    {
        $data['tenant_id'] = TenantResolver::id();
        if (!array_key_exists('created_at', $data)) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        $cols = implode(',', array_map(fn($k) => "`{$k}`", array_keys($data)));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        $stmt = Database::get()->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$ph})");
        $stmt->execute(array_values($data));
        return (int) Database::get()->lastInsertId();
    }

    // ── UPDATE ────────────────────────────────────────
    // tenant_id filter otomatis — tidak bisa update data tenant lain
    public static function update(
        string $table,
        array  $data,
        string $where,
        array  $whereParams = []
    ): int {
        $tid  = TenantResolver::id();
        $set  = implode(',', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
        $sql  = "UPDATE `{$table}` SET {$set} WHERE tenant_id = ? AND ({$where})";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute([...array_values($data), $tid, ...$whereParams]);
        return $stmt->rowCount();
    }

    // ── DELETE ────────────────────────────────────────
    // tenant_id filter otomatis — tidak bisa hapus data tenant lain
    public static function delete(
        string $table,
        string $where,
        array  $params = []
    ): int {
        $tid  = TenantResolver::id();
        $sql  = "DELETE FROM `{$table}` WHERE tenant_id = ? AND ({$where})";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute(array_merge([$tid], $params));
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

    // ── Raw query dengan tenant_id manual ─────────────
    // Pakai ini untuk JOIN antar tabel — tetap wajib filter tenant_id
    // Contoh:
    //   TenantQuery::raw(
    //     "SELECT t.*, p.nama AS nama_pelanggan
    //      FROM hl_transaksi t
    //      LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
    //      WHERE t.tenant_id = ? AND t.status_proses = ?
    //      ORDER BY t.created_at DESC LIMIT 50",
    //     [TenantResolver::id(), 'masuk']
    //   )
    public static function raw(string $sql, array $params = []): array
    {
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Raw untuk non-SELECT (exec dengan tenant scope) ─
    public static function rawOne(string $sql, array $params = []): ?array
    {
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
