<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversionJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'batch_id' => $this->batch_id, 'tool' => $this->tool, 'name' => $this->original_name,
            'reduction_percent' => $this->operation_type !== 'social_download' && $this->output_size !== null ? round((1 - $this->output_size / $this->input_size) * 100, 1) : null,
            'attribution' => $this->operation_type === 'social_download' ? ($this->options_json['attribution'] ?? null) : null,
            'source_format' => $this->operation_type === 'social_download' ? 'commons-url' : $this->source_format, 'target_format' => $this->target_format,
            'status' => $this->expires_at->isPast() && ! in_array($this->status, ['processing', 'cancelled'], true) ? 'expired' : $this->status,
            'input_size' => $this->input_size, 'output_size' => $this->output_size,
            'progress' => $this->progress, 'error_code' => $this->error_code,
            'cancel_requested' => $this->cancel_requested_at !== null,
            'delete_requested' => $this->delete_requested_at !== null,
            'download_available' => $this->status === 'completed' && $this->expires_at->isFuture() && ! $this->delete_requested_at,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'history_expires_at' => $this->history_expires_at?->toIso8601String(),
        ];
    }
}
