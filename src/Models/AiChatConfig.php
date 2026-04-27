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
                $schema['label'] = 'AI Service';
                $schema['type'] = 'integer';
                $schema['description'] = 'Service ID of the df-ai AI Connection to use for LLM calls.';
                $schema['required'] = true;
                break;

            case 'ai_role_id':
                $schema['label'] = 'AI Role';
                $schema['type'] = 'integer';
                $schema['description'] = 'DreamFactory role ID the AI operates under when accessing data. Create a restricted role for this purpose.';
                $schema['required'] = true;
                break;

            case 'default_data_services':
                $schema['label'] = 'Default Data Services';
                $schema['type'] = 'text';
                $schema['description'] = 'Optional JSON array of DreamFactory service names the AI can access. Example: ["dellstore_db","hr_db"]. Leave blank to default to every service the AI Role grants access to.';
                break;

            case 'system_prompt':
                $schema['label'] = 'System Prompt';
                $schema['type'] = 'text';
                $schema['description'] = 'Default system prompt for all chat sessions. Overridable per session.';
                break;

            case 'max_tool_calls':
                $schema['label'] = 'Max Tool Calls';
                $schema['description'] = 'Maximum tool-call iterations per message exchange (default: 25).';
                break;

            case 'max_messages':
                $schema['label'] = 'Max Messages';
                $schema['description'] = 'Maximum messages per chat session (default: 200).';
                break;
        }
    }
}
