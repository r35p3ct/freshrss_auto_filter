<?php

require_once __DIR__ . '/Controllers/openrouterController.php';

class AutoFilterExtension extends Minz_Extension
{
    public function init(): void
    {
        $this->registerTranslates();
        $this->registerController('openrouter');
        $this->registerHook('entry_before_insert', [$this, 'onEntryBeforeInsert']);
    }

    /**
     * Обработка сохранения настроек
     */
    public function handleConfigureAction(): void
    {
        if (Minz_Request::isPost()) {
            $configuration = [
                'openrouter_api_key' => Minz_Request::paramString('auto_filter_openrouter_api_key'),
                'openrouter_model' => Minz_Request::paramString('auto_filter_openrouter_model'),
                'confidence_threshold_high' => (float)Minz_Request::paramString('auto_filter_confidence_threshold_high', '0.8'),
                'confidence_threshold_low' => (float)Minz_Request::paramString('auto_filter_confidence_threshold_low', '0.5'),
                'prompt' => Minz_Request::paramString('auto_filter_prompt'),
                'enable_logging' => Minz_Request::paramString('auto_filter_enable_logging') === '1',
            ];
            $this->setSystemConfiguration($configuration);
        }
    }

    /**
     * Хук вызывается при добавлении новой записи в БД.
     * Возвращает modified entry или null для отмены добавления.
     */
    public function onEntryBeforeInsert($entry): ?FreshRSS_Entry
    {
        $enableLogging = $this->getSystemConfigurationValue('enable_logging');

        if ($enableLogging) {
            Minz_Log::warning('AutoFilter: HOOK CALLED for entry: ' . ($entry ? $entry->title() : 'NULL entry'));
        }

        if (!$entry) {
            return $entry;
        }

        $apiKey = $this->getSystemConfigurationValue('openrouter_api_key');

        if (empty($apiKey)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoFilter: API key NOT configured, skipping');
            }
            return $entry;
        }

        if ($enableLogging) {
            Minz_Log::warning('AutoFilter: API key = SET (' . strlen($apiKey) . ' chars)');
        }

        // Получаем всю конфигурацию и передаём в контроллер
        $config = [
            'openrouter_api_key' => $this->getSystemConfigurationValue('openrouter_api_key'),
            'openrouter_model' => $this->getSystemConfigurationValue('openrouter_model'),
            'confidence_threshold_high' => $this->getSystemConfigurationValue('confidence_threshold_high'),
            'confidence_threshold_low' => $this->getSystemConfigurationValue('confidence_threshold_low'),
            'prompt' => $this->getSystemConfigurationValue('prompt'),
            'enable_logging' => $enableLogging,
        ];

        $controller = new FreshExtension_AutoFilter_openrouter_Controller($config);
        $result = $controller->analyzeEntryDirect($entry);

        if ($result['success']) {
            if ($enableLogging) {
                Minz_Log::warning(sprintf(
                    'AutoFilter: Entry ID=%d, Label=%s, Confidence=%.2f',
                    $entry->id(),
                    $result['analysis']['label'],
                    $result['analysis']['confidence']
                ));
            }
        } else {
            // При ошибке API - просто пропускаем запись без меток
            if ($enableLogging) {
                Minz_Log::warning('AutoFilter: Skipping entry due to API error: ' . ($result['error'] ?? 'unknown'));
            }
        }

        return $entry;
    }
}
