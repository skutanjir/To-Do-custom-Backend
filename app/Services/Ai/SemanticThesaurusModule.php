<?php

namespace App\Services\Ai;

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║        S E M A N T I C   T H E S A U R U S   M O D U L E           ║
 * ║   Expands AI vocabulary without external LLM API calls (v12.1)     ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */
class SemanticThesaurusModule
{
    private static array $synonyms = [
        'id' => [
            'buat'    => ['bikin', 'tambah', 'create', 'add', 'pabrikasi', 'susun', 'rencanakan'],
            'hapus'   => ['buang', 'delete', 'remove', 'hilangkan', 'enyahkan', 'bersihkan', 'tong sampah'],
            'ubah'    => ['ganti', 'update', 'edit', 'modifikasi', 'koreksi', 'setel ulang', 'revisi'],
            'lihat'   => ['list', 'daftar', 'show', 'tampilkan', 'intip', 'cek', 'tengok'],
            'selesai' => ['done', 'finish', 'beres', 'tuntas', 'mantap', 'siap', 'sudah'],
            'cari'    => ['search', 'find', 'temukan', 'mana', 'dimana', 'lacak', 'hunting'],
            'statistik'=>['stats', 'rekap', 'laporan', 'report', 'kinerja', 'grafik'],
            'jadwal'  => ['schedule', 'agenda', 'kalender', 'kegiatan', 'planning'],
            'fokus'   => ['focus', 'konsentrasi', 'deep work', 'pomodoro', 'serius'],
            'tujuan'  => ['goal', 'target', 'pencapaian', 'visi', 'misi'],
            'ingat'   => ['remember', 'catat', 'simpan', 'hafalkan', 'jangan lupa'],
            'lupa'    => ['forget', 'hapus memori', 'bersihkan riwayat'],
        ],
        'en' => [
            'create'  => ['make', 'add', 'new', 'generate', 'construct', 'plan'],
            'delete'  => ['remove', 'destroy', 'clear', 'erase', 'bin', 'trash'],
            'update'  => ['change', 'edit', 'modify', 'revise', 'adjust'],
            'list'    => ['show', 'display', 'view', 'browse', 'index'],
            'finish'  => ['done', 'complete', 'end', 'stop', 'settled'],
            'search'  => ['find', 'lookup', 'locate', 'track', 'where'],
            'stats'   => ['report', 'analytics', 'data', 'metrics', 'performance'],
            'focus'   => ['concentrate', 'attention', 'deep work', 'pomodoro'],
        ],
    ];

    private static array $cache = [];

    public static function expand(array $keywords, string $lang = 'id'): array
    {
        $cacheKey = $lang . '_' . implode('|', $keywords);
        if (isset(self::$cache[$cacheKey])) return self::$cache[$cacheKey];

        $expanded = $keywords;
        $map = self::$synonyms[$lang] ?? self::$synonyms['id'];

        foreach ($keywords as $kw) {
            if (isset($map[$kw])) {
                $expanded = array_merge($expanded, $map[$kw]);
            }
            foreach ($map as $primary => $syns) {
                if (in_array($kw, $syns)) {
                    $expanded[] = $primary;
                }
            }
        }

        $result = array_unique($expanded);
        self::$cache[$cacheKey] = $result;
        return $result;
    }

    public static function resolvePrimary(string $word, string $lang = 'id'): string
    {
        $map = self::$synonyms[$lang] ?? self::$synonyms['id'];
        foreach ($map as $primary => $syns) {
            if ($word === $primary || in_array($word, $syns)) {
                return $primary;
            }
        }
        return $word;
    }
}
