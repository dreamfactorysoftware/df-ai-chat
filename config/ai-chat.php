<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Internal API URL
    |--------------------------------------------------------------------------
    |
    | Base URL used by the DataToolClient to make internal API calls to
    | DreamFactory. Auto-detected when null.
    |
    */
    'internal_api_url' => env('AI_CHAT_INTERNAL_API_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Tool Call Limits
    |--------------------------------------------------------------------------
    |
    | Maximum number of tool-call iterations the AI can make in a single
    | message exchange. Prevents runaway loops.
    |
    */
    'max_tool_calls_per_message' => env('AI_CHAT_MAX_TOOL_CALLS', 25),

    /*
    |--------------------------------------------------------------------------
    | Session Limits
    |--------------------------------------------------------------------------
    */
    'max_messages_per_session' => env('AI_CHAT_MAX_MESSAGES', 200),

    /*
    |--------------------------------------------------------------------------
    | Tool Result Truncation
    |--------------------------------------------------------------------------
    |
    | Maximum characters of a single tool result before truncation.
    | Prevents context-window overflow from large query results.
    |
    */
    'tool_result_max_length' => (int) env('AI_CHAT_TOOL_RESULT_MAX_LENGTH', 50000),

    /*
    |--------------------------------------------------------------------------
    | Default System Prompt
    |--------------------------------------------------------------------------
    */
    'default_system_prompt' => 'You are a helpful data assistant with access to database tools. '
        . 'Use the available tools to query data and answer questions accurately. '
        . 'Always verify your understanding of the data structure before querying. '
        . 'Use LIMIT to avoid returning excessively large result sets.',
];
