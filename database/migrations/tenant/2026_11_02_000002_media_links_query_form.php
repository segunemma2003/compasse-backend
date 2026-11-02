<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pictures stored on this server were linked as /api/v1/files/{school}/{path}.jpg, which the
 * web server answers itself with 404 (its rule for .jpg/.png files). Rewrite saved links to
 * /api/v1/media/{school}?p={path}, which reaches Laravel.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'students' => ['profile_picture'], 'users' => ['profile_picture'], 'teachers' => ['profile_picture'],
        'staff' => ['profile_picture'], 'guardians' => ['profile_picture'], 'schools' => ['logo'],
        'school_signatures' => ['signature_path', 'signature_url'], 'student_portfolio_items' => ['image_url'],
        'settings' => ['value'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }
                DB::table($table)->where($col, 'like', '%/api/v1/files/%')->orderBy('id')->each(function ($row) use ($table, $col) {
                    $new = preg_replace_callback('#/api/v1/files/([^/"\\s]+)/([^"\\s?]+)#',
                        fn ($m) => '/api/v1/media/' . $m[1] . '?p=' . rawurlencode(rawurldecode($m[2])), (string) $row->{$col});
                    if ($new !== $row->{$col}) {
                        DB::table($table)->where('id', $row->id)->update([$col => $new]);
                    }
                });
            }
        }
    }

    public function down(): void
    {
    }
};
