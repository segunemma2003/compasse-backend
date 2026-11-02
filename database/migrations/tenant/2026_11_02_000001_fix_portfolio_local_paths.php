<?php

use App\Support\TenantUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio pictures uploaded while cloud storage was refusing writes were saved on
 * this server but recorded as a bare path ("portfolio/222/x.jpg"), which no page can
 * show. Turn them into full links served by TenantFileController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_portfolio_items')) {
            return;
        }
        foreach (DB::table('student_portfolio_items')->where('image_url', 'not like', 'http%')->get(['id', 'image_url']) as $row) {
            DB::table('student_portfolio_items')->where('id', $row->id)
                ->update(['image_url' => TenantUrl::forPublicDiskKey(ltrim($row->image_url, '/'))]);
        }
    }

    public function down(): void
    {
    }
};
