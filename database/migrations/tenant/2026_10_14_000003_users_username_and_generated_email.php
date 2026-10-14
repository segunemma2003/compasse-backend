<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * username: an optional short login (students also sign in with their admission number).
     * email_generated: the address was made up by the system (no mailbox), so nothing is emailed to it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'username')) {
                $t->string('username', 100)->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'email_generated')) {
                $t->boolean('email_generated')->default(false)->after('email');
            }
        });

        // Flag addresses the importers made up: name.surname<digits>@<school subdomain>.compasse.net,
        // @compasse.net, @*.samschool.com — and the same pattern on the school's own website domain.
        $website = Schema::hasTable('schools') ? (string) DB::table('schools')->value('website') : '';
        $siteDomain = strtolower(preg_replace('/^(https?:\/\/)?(www\.)?/', '', $website));
        $siteDomain = rtrim(explode('/', $siteDomain)[0] ?? '', '/');

        DB::table('users')->where('email_generated', false)->orderBy('id')->select('id', 'email')
            ->chunkById(500, function ($users) use ($siteDomain) {
                $ids = [];
                foreach ($users as $u) {
                    $email = strtolower((string) $u->email);
                    $domain = substr(strrchr($email, '@') ?: '', 1);
                    $local = strstr($email, '@', true) ?: '';
                    $generatedShape = (bool) preg_match('/^[a-z]+\.[a-z]+\d+(\.[a-z0-9]+)?$/', $local);
                    $platformDomain = $domain === 'compasse.net' || str_ends_with($domain, '.compasse.net') || str_ends_with($domain, 'samschool.com');
                    if (($platformDomain && $generatedShape) || str_ends_with($domain, '.compasse.net') || str_ends_with($domain, '.samschool.com')
                        || ($siteDomain !== '' && $domain === $siteDomain && $generatedShape)) {
                        $ids[] = $u->id;
                    }
                }
                if ($ids) {
                    DB::table('users')->whereIn('id', $ids)->update(['email_generated' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'username')) {
                $t->dropUnique(['username']);
                $t->dropColumn('username');
            }
            if (Schema::hasColumn('users', 'email_generated')) {
                $t->dropColumn('email_generated');
            }
        });
    }
};
