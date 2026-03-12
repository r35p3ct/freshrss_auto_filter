<?php

return [
    'field' => [
        'openrouter_api_key' => 'OpenRouter API Key',
        'openrouter_model' => 'AI Model',
        'action_on_advertisement' => 'Action on advertisement',
        'action_on_possible_advertisement' => 'Action on possible advertisement',
        'confidence_threshold_high' => 'Confidence threshold (high)',
        'confidence_threshold_high_hint' => 'Entries with confidence above this value are marked as advertisement',
        'confidence_threshold_low' => 'Confidence threshold (low)',
        'confidence_threshold_low_hint' => 'Entries with confidence above this value are marked as possible advertisement',
        'action_hide' => 'Hide (mark as read + visual hide)',
        'action_read' => 'Mark as read',
        'action_label' => 'Label only (tag)',
        'action_none' => 'Do nothing',
        'prompt' => 'AI prompt for verification',
        'prompt_placeholder' => 'Enter prompt with placeholders {title}, {author}, {url}, {content}',
        'prompt_hint' => 'Leave empty to use default prompt. Use placeholders: {title}, {author}, {url}, {content}',
        'enable_logging' => 'Enable logging',
        'enable_logging_hint' => 'Write detailed extension logs',
        'enable_logging_help' => 'When enabled, logs include prompts, API responses, and analysis results. Use for debugging.',
    ],
];
