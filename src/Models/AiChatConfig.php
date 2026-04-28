<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;

class AiChatConfig extends BaseServiceConfigModel
{
    protected $table = 'ai_chat_config';

    protected $fillable = [
        'service_id',
        'ai_service_id',
        'ai_role_id',
        'default_data_services',
        'system_prompt',
        'max_tool_calls',
        'max_messages',
    ];

    protected $casts = [
        'service_id'     => 'integer',
        'ai_service_id'  => 'integer',
        'ai_role_id'     => 'integer',
        'max_tool_calls' => 'integer',
        'max_messages'   => 'integer',
    ];

    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'ai_service_id':
                $schema['label'] = 'AI Connection';
                $schema['type'] = 'integer';
                $schema['description'] = 'The DF AI Connection this chat uses for LLM calls. Pick the connection whose model + provider should drive the conversation. The connection\'s system prompt, allowed models, and rate limits all still apply.';
                $schema['required'] = true;
                break;

            case 'ai_role_id':
                $schema['label'] = 'AI Role (data access)';
                $schema['type'] = 'integer';
                $schema['description'] = 'When the chat runs tools (query database, list files, etc.) the AI acts under this role\'s permissions. Use a least-privilege role specifically scoped to what you want the AI to read — not the user\'s own role and not an admin role.';
                $schema['required'] = true;
                break;

            case 'default_data_services':
                $schema['label'] = 'Default data services';
                $schema['type'] = 'text';
                $schema['description'] = 'Optional JSON array of service names the AI is allowed to read. Acts as a whitelist on top of the AI Role\'s permissions — the AI sees the intersection. Leave blank to expose every service the role can access. Example: ["dellstore_db","hr_db"]';
                break;

            case 'system_prompt':
                $schema['label'] = 'Default system prompt';
                $schema['type'] = 'text';
                $schema['description'] = 'Instructions injected at the top of every chat session. Use it to set the assistant\'s persona, restrict topics, or describe the schema it should query. Each session can override per-call.';
                break;

            case 'max_tool_calls':
                $schema['label'] = 'Max tool calls per turn';
                $schema['description'] = 'Hard cap on how many times the AI can call a tool in a single user turn before being forced to respond. Stops infinite tool loops. Default: 25 — bump for complex multi-step workflows.';
                break;

            case 'max_messages':
                $schema['label'] = 'Max messages per session';
                $schema['description'] = 'Hard cap on how long a single chat session can grow before it stops accepting new messages. Protects against runaway context costs. Default: 200.';
                break;
        }
    }
}
