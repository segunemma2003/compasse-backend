<?php

namespace App\Events;

use App\Models\BulkUpload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BulkUploadProgressEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly BulkUpload $upload) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("bulk-upload.{$this->upload->id}"),
            new PrivateChannel("school.{$this->upload->school_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'upload.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'id'             => $this->upload->id,
            'type'           => $this->upload->type,
            'status'         => $this->upload->status,
            'progress'       => $this->upload->progress,
            'total_rows'     => $this->upload->total_rows,
            'processed_rows' => $this->upload->processed_rows,
            'success_rows'   => $this->upload->success_rows,
            'failed_rows'    => $this->upload->failed_rows,
            'errors'         => array_slice($this->upload->errors ?? [], -20), // last 20 errors
            'started_at'     => $this->upload->started_at?->toIso8601String(),
            'completed_at'   => $this->upload->completed_at?->toIso8601String(),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
