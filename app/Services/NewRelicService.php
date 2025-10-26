<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class NewRelicService
{
    protected $enabled;
    protected $appName;
    protected $licenseKey;

    public function __construct()
    {
        $this->enabled = config('newrelic.enabled', false);
        $this->appName = config('newrelic.app_name');
        $this->licenseKey = config('newrelic.license_key');
    }

    /**
     * Record custom metric
     */
    public function recordMetric(string $name, float $value, array $attributes = []): void
    {
        if (!$this->enabled || !function_exists('newrelic_record_custom_event')) {
            return;
        }

        try {
            newrelic_record_custom_event('CustomMetric', [
                'name' => $name,
                'value' => $value,
                'timestamp' => time(),
                ...$attributes
            ]);
        } catch (\Exception $e) {
            Log::error('New Relic metric recording failed: ' . $e->getMessage());
        }
    }

    /**
     * Record custom event
     */
    public function recordEvent(string $eventType, array $attributes = []): void
    {
        if (!$this->enabled || !function_exists('newrelic_record_custom_event')) {
            return;
        }

        try {
            newrelic_record_custom_event($eventType, [
                'timestamp' => time(),
                ...$attributes
            ]);
        } catch (\Exception $e) {
            Log::error('New Relic event recording failed: ' . $e->getMessage());
        }
    }

    /**
     * Set custom attributes
     */
    public function setCustomAttributes(array $attributes): void
    {
        if (!$this->enabled || !function_exists('newrelic_add_custom_parameter')) {
            return;
        }

        try {
            foreach ($attributes as $key => $value) {
                newrelic_add_custom_parameter($key, $value);
            }
        } catch (\Exception $e) {
            Log::error('New Relic custom attributes failed: ' . $e->getMessage());
        }
    }

    /**
     * Record API usage
     */
    public function recordApiUsage(string $endpoint, string $method, int $responseTime, int $statusCode, array $attributes = []): void
    {
        $this->recordEvent('APIUsage', [
            'endpoint' => $endpoint,
            'method' => $method,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'success' => $statusCode >= 200 && $statusCode < 300,
            ...$attributes
        ]);

        $this->recordMetric('API.ResponseTime', $responseTime, [
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode
        ]);
    }

    /**
     * Record user activity
     */
    public function recordUserActivity(string $userId, string $action, array $attributes = []): void
    {
        $this->recordEvent('UserActivity', [
            'user_id' => $userId,
            'action' => $action,
            'timestamp' => time(),
            ...$attributes
        ]);
    }

    /**
     * Record tenant activity
     */
    public function recordTenantActivity(string $tenantId, string $action, array $attributes = []): void
    {
        $this->recordEvent('TenantActivity', [
            'tenant_id' => $tenantId,
            'action' => $action,
            'timestamp' => time(),
            ...$attributes
        ]);
    }

    /**
     * Record school activity
     */
    public function recordSchoolActivity(string $schoolId, string $action, array $attributes = []): void
    {
        $this->recordEvent('SchoolActivity', [
            'school_id' => $schoolId,
            'action' => $action,
            'timestamp' => time(),
            ...$attributes
        ]);
    }

    /**
     * Record performance metrics
     */
    public function recordPerformanceMetrics(array $metrics): void
    {
        foreach ($metrics as $name => $value) {
            $this->recordMetric($name, $value);
        }
    }

    /**
     * Record database performance
     */
    public function recordDatabasePerformance(string $query, float $executionTime, int $rowsAffected = 0): void
    {
        $this->recordEvent('DatabaseQuery', [
            'query' => $query,
            'execution_time' => $executionTime,
            'rows_affected' => $rowsAffected,
            'timestamp' => time()
        ]);

        $this->recordMetric('Database.QueryTime', $executionTime);
        $this->recordMetric('Database.RowsAffected', $rowsAffected);
    }

    /**
     * Record cache performance
     */
    public function recordCachePerformance(string $operation, string $key, float $executionTime, bool $hit = null): void
    {
        $this->recordEvent('CacheOperation', [
            'operation' => $operation,
            'key' => $key,
            'execution_time' => $executionTime,
            'hit' => $hit,
            'timestamp' => time()
        ]);

        $this->recordMetric('Cache.ExecutionTime', $executionTime, [
            'operation' => $operation,
            'hit' => $hit
        ]);
    }

    /**
     * Record queue performance
     */
    public function recordQueuePerformance(string $queue, string $job, float $processingTime, bool $success = true): void
    {
        $this->recordEvent('QueueJob', [
            'queue' => $queue,
            'job' => $job,
            'processing_time' => $processingTime,
            'success' => $success,
            'timestamp' => time()
        ]);

        $this->recordMetric('Queue.ProcessingTime', $processingTime, [
            'queue' => $queue,
            'job' => $job,
            'success' => $success
        ]);
    }

    /**
     * Record file upload performance
     */
    public function recordFileUpload(string $filename, int $fileSize, float $uploadTime, string $status): void
    {
        $this->recordEvent('FileUpload', [
            'filename' => $filename,
            'file_size' => $fileSize,
            'upload_time' => $uploadTime,
            'status' => $status,
            'timestamp' => time()
        ]);

        $this->recordMetric('FileUpload.Size', $fileSize);
        $this->recordMetric('FileUpload.Time', $uploadTime);
    }

    /**
     * Record email performance
     */
    public function recordEmailPerformance(string $recipient, string $subject, float $sendTime, bool $success = true): void
    {
        $this->recordEvent('EmailSent', [
            'recipient' => $recipient,
            'subject' => $subject,
            'send_time' => $sendTime,
            'success' => $success,
            'timestamp' => time()
        ]);

        $this->recordMetric('Email.SendTime', $sendTime, [
            'success' => $success
        ]);
    }

    /**
     * Record SMS performance
     */
    public function recordSMSPerformance(string $recipient, string $message, float $sendTime, bool $success = true): void
    {
        $this->recordEvent('SMSSent', [
            'recipient' => $recipient,
            'message' => $message,
            'send_time' => $sendTime,
            'success' => $success,
            'timestamp' => time()
        ]);

        $this->recordMetric('SMS.SendTime', $sendTime, [
            'success' => $success
        ]);
    }

    /**
     * Record business metrics
     */
    public function recordBusinessMetrics(array $metrics): void
    {
        foreach ($metrics as $name => $value) {
            $this->recordMetric("Business.{$name}", $value);
        }
    }

    /**
     * Record error
     */
    public function recordError(\Throwable $exception, array $context = []): void
    {
        if (!$this->enabled || !function_exists('newrelic_notice_error')) {
            return;
        }

        try {
            newrelic_notice_error($exception->getMessage(), $exception);

            if (!empty($context)) {
                $this->setCustomAttributes($context);
            }
        } catch (\Exception $e) {
            Log::error('New Relic error recording failed: ' . $e->getMessage());
        }
    }

    /**
     * Start custom transaction
     */
    public function startTransaction(string $name): void
    {
        if (!$this->enabled || !function_exists('newrelic_start_transaction')) {
            return;
        }

        try {
            newrelic_start_transaction($this->licenseKey, $name);
        } catch (\Exception $e) {
            Log::error('New Relic transaction start failed: ' . $e->getMessage());
        }
    }

    /**
     * End custom transaction
     */
    public function endTransaction(): void
    {
        if (!$this->enabled || !function_exists('newrelic_end_transaction')) {
            return;
        }

        try {
            newrelic_end_transaction();
        } catch (\Exception $e) {
            Log::error('New Relic transaction end failed: ' . $e->getMessage());
        }
    }

    /**
     * Set transaction name
     */
    public function setTransactionName(string $name): void
    {
        if (!$this->enabled || !function_exists('newrelic_name_transaction')) {
            return;
        }

        try {
            newrelic_name_transaction($name);
        } catch (\Exception $e) {
            Log::error('New Relic transaction naming failed: ' . $e->getMessage());
        }
    }

    /**
     * Add custom parameter
     */
    public function addCustomParameter(string $key, $value): void
    {
        if (!$this->enabled || !function_exists('newrelic_add_custom_parameter')) {
            return;
        }

        try {
            newrelic_add_custom_parameter($key, $value);
        } catch (\Exception $e) {
            Log::error('New Relic custom parameter failed: ' . $e->getMessage());
        }
    }
}
