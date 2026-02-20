<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat;

use DreamFactory\Core\AIChat\Models\AiChatConfig;
use DreamFactory\Core\AIChat\Services\AiChat;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-chat.php', 'ai-chat');

        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType([
                    'name'           => 'ai_chat',
                    'label'          => 'AI Chat',
                    'description'    => 'Chat with your DreamFactory data using AI and MCP tools.',
                    'group'          => ServiceTypeGroups::AI_CHAT,
                    'config_handler' => AiChatConfig::class,
                    'factory'        => function ($config) {
                        return new AiChat($config);
                    },
                ])
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // If the service manager was already resolved before our resolving
        // callback was registered, add the type directly.
        if ($this->app->resolved('df.service')) {
            $this->app->make('df.service')->addType(
                new ServiceType([
                    'name'           => 'ai_chat',
                    'label'          => 'AI Chat',
                    'description'    => 'Chat with your DreamFactory data using AI and MCP tools.',
                    'group'          => ServiceTypeGroups::AI_CHAT,
                    'config_handler' => AiChatConfig::class,
                    'factory'        => function ($config) {
                        return new AiChat($config);
                    },
                ])
            );
        }
    }
}
