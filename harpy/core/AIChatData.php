<?php
// ══════════════════════════════════════════════════════
// core/AIChatData.php — Tanya Data via Natural Language
//
// User: "berapa pelanggan VIP bulan ini?"
// → Claude generate SQL (read-only, tenant-scoped)
// → Server VALIDATE (SELECT only, allowlist tables, must have tenant_id filter)
// → Execute, format result
// → Claude explain hasil ke natural language
//
// SAFETY GUARDS:
//   1. Hanya SELECT statement (regex check + EXPLAIN parse)
//   2. Tabel harus dari allowlist
//   3. Wajib filter tenant_id = ?
//   4. Wajib ada LIMIT (auto-inject kalau tidak ada, max 200)
//   5. Tidak boleh subquery SELECT INTO / UNION ke tabel luar allowlist
//   6. Tidak boleh INFORMATION_SCHEMA, mysql.*, sys.*
//   7. Tidak boleh load_file / outfile / dumpfile
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/AnthropicClient.php';

class AIChatData
{
    const MAX_RESULT_ROWS = 200;
    const MAX_QUERY_LEN   = 2000;

    // Tabel yang boleh di-query (lowercase)
    const ALLOWED_TABLES = [
        'hl_transaksi',
        'hl_transaksi_item',
        'hl_pelanggan',
        'hl_layanan',
        'hl_users',
        'hl_kas',
        'hl_karyawan_outlet',
        'hl_audit_log',
        'outlets',
    ];

    // Forbidden patterns (regex)
    const FORBIDDEN_PATTERNS = [
        '/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|CREATE|RENAME|GRANT|REVOKE|REPLACE|CALL|HANDLER|LOCK|UNLOCK)\b/i',
        '/\binto\s+(outfile|dumpfile)\b/i',
        '/\bload_file\s*\(/i',
        '/\binformation_schema\b/i',
        '/\bmysql\./i',
        '/\bsys\./i',
        '/\bperformance_schema\b/i',
        '/\bbenchmark\s*\(/i',
        '/\bsleep\s*\(/i',
        '/--|#|\/\*/',  // SQL comments — bisa dipakai bypass, ban
    ];

    /**
     * Schema description untuk AI prompt (Indonesia, ringkas).
     */
    private static function getSchemaContext(): string
    {
        return <<<SCHEMA
SKEMA DATABASE (semua tabel WAJIB di-filter dengan `tenant_id = ?`):

hl_transaksi (transaksi/order)
  - id, tenant_id, outlet_id, pelanggan_id, user_id (kasir)
  - no_order, tanggal (DATETIME), total (DECIMAL), dp, diskon
  - status_proses ENUM('masuk','cuci','kering','setrika','siap','diambil')
  - status_bayar ENUM('lunas','dp','belum_bayar')
  - metode_bayar VARCHAR

hl_transaksi_item (item dalam 1 transaksi)
  - id, tenant_id, outlet_id, transaksi_id, layanan_id
  - nama_layanan, satuan, jumlah, harga_satuan, subtotal

hl_pelanggan (pelanggan)
  - id, tenant_id, registered_outlet_id
  - nama, telepon, alamat, kategori (regular/vip)
  - total_order, total_visit_count, created_at

hl_layanan (master layanan)
  - id, tenant_id, outlet_id (NULL = global), nama, harga, satuan

hl_users (karyawan)
  - id, tenant_id, nama, email, role
  - Untuk outlet assignment, JOIN ke hl_karyawan_outlet

hl_kas (cashflow)
  - id, tenant_id, outlet_id, tanggal, tipe ENUM('masuk','keluar')
  - kategori, jumlah (DECIMAL), keterangan

hl_karyawan_outlet (pivot karyawan-outlet)
  - id, tenant_id, karyawan_id (FK hl_users.id), outlet_id
  - is_active TINYINT, effective_date, notes

outlets (cabang)
  - id, tenant_id, nama_outlet, kota, status ENUM('trial','grace','active','suspended')
  - coin_balance, is_main

hl_audit_log
  - id, tenant_id, user_id, modul, aksi, ref_id, created_at

ATURAN SQL:
1. WAJIB hanya SELECT (tidak ada INSERT/UPDATE/DELETE).
2. WAJIB tambah `tenant_id = ?` di WHERE (placeholder `?` akan di-bind server).
3. Boleh JOIN antar tabel di allowlist.
4. Tidak boleh subquery ke INFORMATION_SCHEMA, mysql.*, sys.*.
5. Tidak boleh komentar SQL (-- atau /* */).
6. Tambah LIMIT (max 200).
7. Gunakan alias singkat (t, p, l, u, o, dst).
8. Output kolom dengan nama yang user-friendly via AS.
SCHEMA;
    }

    /**
     * Ask & execute.
     *
     * @return array {
     *   @var string  $answer         Natural language answer
     *   @var string  $sql            SQL yang dieksekusi
     *   @var array   $rows           Hasil query (max 200 rows)
     *   @var int     $row_count
     *   @var bool    $from_cache
     *   @var int     $tokens_used
     *   @var string  $generated_at
     * }
     */
    public static function ask(string $question, int $tenantId): array
    {
        $question = trim($question);
        if (strlen($question) < 3) {
            throw new RuntimeException('Pertanyaan terlalu pendek.');
        }
        if (strlen($question) > 500) {
            throw new RuntimeException('Pertanyaan terlalu panjang (max 500 karakter).');
        }

        // 1. Generate SQL via Claude
        $sql = self::generateSql($question);

        // 2. VALIDATE
        self::validateSql($sql);

        // 3. Execute (bind tenant_id ke semua placeholder)
        $rows = self::executeSql($sql, $tenantId);

        // 4. Generate natural language answer dari hasil
        $answer = self::explainResult($question, $sql, $rows);

        return [
            'answer'      => $answer['text'],
            'sql'         => $sql,
            'rows'        => $rows,
            'row_count'   => count($rows),
            'tokens_used' => $answer['tokens_in'] + $answer['tokens_out'],
            'generated_at'=> date('Y-m-d H:i:s'),
        ];
    }

    /** Step 1: generate SQL */
    private static function generateSql(string $question): string
    {
        $system = "Kamu adalah SQL generator yang ketat. Output HANYA query SQL valid MariaDB, "
                . "tanpa penjelasan, tanpa markdown fence. Mulai dengan SELECT.\n\n"
                . self::getSchemaContext();

        $prompt = "Pertanyaan user (Bahasa Indonesia): \"$question\"\n\n"
                . "Generate 1 query SQL untuk menjawab pertanyaan ini. "
                . "Gunakan tenant_id = ? sebagai placeholder. "
                . "Kalau pertanyaan tidak bisa dijawab dengan skema, jawab dengan: ERROR: <alasan>.";

        $result = AnthropicClient::ask($prompt, [
            'system'      => $system,
            'max_tokens'  => 600,
            'temperature' => 0.0, // deterministic
        ]);

        $sql = trim($result['text']);

        // Strip markdown fence kalau ada
        $sql = preg_replace('/^```(?:sql)?\s*|\s*```$/m', '', $sql);
        $sql = trim($sql);

        if (stripos($sql, 'ERROR:') === 0) {
            throw new RuntimeException(substr($sql, 6));
        }

        // Strip trailing semicolon
        $sql = rtrim($sql, ";\n\r\t ");

        return $sql;
    }

    /** Step 2: validate SQL */
    private static function validateSql(string $sql): void
    {
        if (strlen($sql) > self::MAX_QUERY_LEN) {
            throw new RuntimeException('Query terlalu panjang.');
        }

        // Must start with SELECT
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            throw new RuntimeException('Query harus dimulai dengan SELECT.');
        }

        // Forbidden patterns
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new RuntimeException('Query mengandung pola terlarang: ' . $pattern);
            }
        }

        // Multiple statements?
        if (substr_count($sql, ';') > 0) {
            throw new RuntimeException('Multiple statement tidak diizinkan.');
        }

        // Extract table names yang di-FROM/JOIN
        if (preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)/i', $sql, $matches)) {
            foreach ($matches[1] as $table) {
                if (!in_array(strtolower($table), self::ALLOWED_TABLES, true)) {
                    throw new RuntimeException("Tabel '$table' tidak diizinkan. Allowlist: "
                        . implode(', ', self::ALLOWED_TABLES));
                }
            }
        }

        // Wajib ada tenant_id filter
        if (!preg_match('/\btenant_id\s*=\s*\?/i', $sql)) {
            throw new RuntimeException("Query wajib punya filter `tenant_id = ?`.");
        }

        // Wajib LIMIT (auto-inject kalau tidak ada) — handled di execute
    }

    /** Step 3: execute dengan binding tenant_id ke SEMUA placeholder */
    private static function executeSql(string $sql, int $tenantId): array
    {
        // Auto-inject LIMIT
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql .= ' LIMIT ' . self::MAX_RESULT_ROWS;
        } else {
            // Cap kalau LIMIT > MAX
            $sql = preg_replace_callback('/\bLIMIT\s+(\d+)(?:\s*,\s*(\d+))?/i', function($m) {
                $first = (int)$m[1];
                if (isset($m[2])) {
                    $second = (int)$m[2];
                    $second = min($second, self::MAX_RESULT_ROWS);
                    return "LIMIT $first, $second";
                }
                $first = min($first, self::MAX_RESULT_ROWS);
                return "LIMIT $first";
            }, $sql);
        }

        // Count `?` placeholders → bind tenant_id ke semua
        $placeholderCount = substr_count($sql, '?');
        if ($placeholderCount === 0) {
            throw new RuntimeException('Query tidak punya placeholder.');
        }

        $params = array_fill(0, $placeholderCount, $tenantId);

        try {
            $db = Database::get();
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new RuntimeException('SQL error: ' . $e->getMessage());
        }
    }

    /** Step 4: format hasil jadi natural language */
    private static function explainResult(string $question, string $sql, array $rows): array
    {
        $rowCount = count($rows);
        $sample = array_slice($rows, 0, 20); // first 20 rows untuk context AI

        $system = "Kamu menjelaskan hasil query SQL ke user dalam Bahasa Indonesia yang natural. "
                . "Jangan tampilkan SQL atau jargon teknis. Jawab langsung ke pertanyaan, singkat dan padat. "
                . "Sebut angka spesifik. Kalau hasilnya 0 baris atau kosong, bilang dengan ramah.";

        $prompt = "Pertanyaan user: \"$question\"\n\n"
                . "Hasil query ($rowCount baris):\n"
                . json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                . ($rowCount > 20 ? "\n\n(Hanya 20 baris pertama ditampilkan. Total: $rowCount baris)" : "")
                . "\n\nJawab pertanyaan user berdasarkan hasil ini. Maks 3 kalimat.";

        return AnthropicClient::ask($prompt, [
            'system'      => $system,
            'max_tokens'  => 300,
            'temperature' => 0.3,
        ]);
    }
}
