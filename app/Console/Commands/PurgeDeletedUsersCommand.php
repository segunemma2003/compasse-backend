<?php

namespace App\Console\Commands;

use App\Services\UserDeletionService;
use Illuminate\Console\Command;

class PurgeDeletedUsersCommand extends Command
{
    protected $signature = 'users:purge-deleted';

    protected $description = 'Permanently delete users archived more than 30 days ago';

    public function handle(UserDeletionService $deletionService): int
    {
        $count = $deletionService->purgeExpiredArchivedUsers();
        $this->info("Purged {$count} archived user(s).");

        return self::SUCCESS;
    }
}
