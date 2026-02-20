<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Models;

use DreamFactory\Core\Models\BaseModel;

class AiChatMessage extends BaseModel
{
    protected $table = 'ai_chat_messages';

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'tool_name',
        'is_error',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'created_at',
    ];

    protected $casts = [
        'session_id'    => 'integer',
        'tool_calls'    => 'array',
        'is_error'      => 'boolean',
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'latency_ms'    => 'integer',
        'created_at'    => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }
}
