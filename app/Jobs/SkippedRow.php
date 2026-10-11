<?php

namespace App\Jobs;

/**
 * Thrown by ProcessBulkUploadJob for a row whose person is already on the
 * books: the row is skipped and reported, not counted as a failure.
 */
class SkippedRow extends \Exception
{
}
