<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Models;

use DreamFactory\Core\Models\BaseModel;

class AiChatSession extends BaseModel
{
    protected $table = 'ai_chat_sessions';

    protected $fillable = [
        'service_id',
        'ai_service_id',
        'user_id',
        'user_role_id',
        'ai_role_id',
        'data_services',
        'allowed_resources',
        'title',
        'system_prompt',
        'status',
        'total_input_tokens',
        'total_output_tokens',
        'tool_call_count',
    ];

    protected $casts = [
        'service_id'          => 'integer',
        'ai_service_id'       => 'integer',
        'user_id'             => 'integer',
        'user_role_id'        => 'integer',
        'ai_role_id'          => 'integer',
        'data_services'       => 'array',
        'allowed_resources'   => 'array',
        'total_input_tokens'  => 'integer',
        'total_output_tokens' => 'integer',
        'tool_call_count'     => 'integer',
    ];

    public function messages()
    {
        return $this->hasMany(AiChatMessage::class, 'session_id');
    }

    public function chatConfig()
    {
        return $this->belongsTo(AiChatConfig::class, 'service_id', 'service_id');
    }
}
