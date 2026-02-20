<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Services;

use DreamFactory\Core\AIChat\Resources\SessionResource;
use DreamFactory\Core\Services\BaseRestService;

class AiChat extends BaseRestService
{
    protected static $resources = [
        SessionResource::RESOURCE_NAME => [
            'name'       => SessionResource::RESOURCE_NAME,
            'class_name' => SessionResource::class,
            'label'      => 'Chat Sessions',
        ],
    ];

    /**
     * Get the config value by key.
     */
    public function getConfig($key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }

        return data_get($this->config, $key, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessList()
    {
        $resources = [];
        foreach (static::$resources as $name => $info) {
            $resources[] = $name . '/';
            $resources[] = $name . '/*';
        }
        return $resources;
    }
}
