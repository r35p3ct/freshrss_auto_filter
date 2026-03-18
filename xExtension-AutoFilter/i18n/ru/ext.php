<?php

return [
    'field' => [
        'openrouter_api_key' => 'OpenRouter API Key',
        'openrouter_model' => 'AI модель',
        'action_on_advertisement' => 'Действие для рекламы',
        'action_on_possible_advertisement' => 'Действие для возможной рекламы',
        'confidence_threshold_high' => 'Порог уверенности (высокий)',
        'confidence_threshold_high_hint' => 'Записи с уверенностью выше этого значения помечаются как реклама',
        'confidence_threshold_low' => 'Порог уверенности (низкий)',
        'confidence_threshold_low_hint' => 'Записи с уверенностью выше этого значения помечаются как возможная реклама',
        'action_hide' => 'Скрыть (пометить как прочитанное + визуальное скрытие)',
        'action_read' => 'Пометить как прочитанное',
        'action_label' => 'Только метка (тег)',
        'action_none' => 'Ничего не делать',
        'prompt' => 'Промт для проверки нейросетью',
        'prompt_placeholder' => 'Введите промт с плейсхолдерами {title}, {author}, {url}, {content}',
        'prompt_hint' => 'Оставьте пустым для использования промта по умолчанию. Используйте плейсхолдеры: {title}, {author}, {url}, {content}',
        'enable_logging' => 'Включить логирование',
        'enable_logging_hint' => 'Записывать подробные логи работы расширения',
        'enable_logging_help' => 'При включении в логи записываются промты, ответы API и результаты анализа. Используйте для отладки.',
        'channels_filter' => 'Каналы для фильтрации',
        'channels_filter_hint' => 'Выберите каналы, для которых будет работать автоматическая фильтрация рекламы. Если ничего не выбрано — проверяются все каналы.',
        'select_all_channels' => 'Выбрать все каналы',
    ],
];
