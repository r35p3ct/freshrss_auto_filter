<?php

declare(strict_types=1);

require_once __DIR__ . '/Controllers/openrouterController.php';

/**
 * Расширение AutoFilter для автоматической фильтрации рекламы через AI.
 *
 * @version 0.3.0
 */
class AutoFilterExtension extends Minz_Extension
{
    public function init(): void
    {
        $this->registerTranslates();
        $this->registerController('openrouter');

        // entry_after_insert — запись уже сохранена в БД, есть ID.
        // Это позволяет надёжно применять метки через LabelDAO и помечать прочитанными.
        $this->registerHook('entry_after_insert', [$this, 'onEntryAfterInsert']);
    }

    /**
     * Обработка сохранения настроек.
     */
    public function handleConfigureAction(): void
    {
        if (Minz_Request::isPost()) {
            $oldConfig = $this->getSystemConfiguration();

            $channelsFilter = $_POST['auto_filter_channels_filter'] ?? [];
            $channelsFilter = array_map('strval', $channelsFilter);

            $newConfig = [
                'openrouter_api_key'          => Minz_Request::paramString('auto_filter_openrouter_api_key'),
                'openrouter_model'            => Minz_Request::paramString('auto_filter_openrouter_model'),
                'confidence_threshold_high'   => (float)Minz_Request::param('auto_filter_confidence_threshold_high', 0.8),
                'confidence_threshold_low'    => (float)Minz_Request::param('auto_filter_confidence_threshold_low', 0.5),
                'prompt'                      => Minz_Request::paramString('auto_filter_prompt'),
                'enable_logging'              => Minz_Request::paramString('auto_filter_enable_logging') === '1',
                'channels_filter'             => $channelsFilter,
            ];

            $this->setSystemConfiguration($newConfig);

            if ($this->shouldInvalidateCache($oldConfig, $newConfig)) {
                $this->invalidateControllerCache();
            }
        }
    }

    /**
     * @param array<string, mixed> $oldConfig
     * @param array<string, mixed> $newConfig
     */
    private function shouldInvalidateCache(array $oldConfig, array $newConfig): bool
    {
        foreach (['openrouter_api_key', 'openrouter_model', 'prompt'] as $key) {
            if (($oldConfig[$key] ?? null) !== ($newConfig[$key] ?? null)) {
                Minz_Log::warning('AutoFilter: Configuration change detected for "' . $key . '", invalidating cache');
                return true;
            }
        }
        return false;
    }

    private function invalidateControllerCache(): void
    {
        $config = [
            'openrouter_api_key'        => $this->getSystemConfigurationValue('openrouter_api_key'),
            'openrouter_model'          => $this->getSystemConfigurationValue('openrouter_model'),
            'confidence_threshold_high' => $this->getSystemConfigurationValue('confidence_threshold_high'),
            'confidence_threshold_low'  => $this->getSystemConfigurationValue('confidence_threshold_low'),
            'prompt'                    => $this->getSystemConfigurationValue('prompt'),
            'enable_logging'            => $this->getSystemConfigurationValue('enable_logging'),
        ];

        $controller = new FreshExtension_AutoFilter_openrouter_Controller($config);
        $controller->invalidateCache();
    }

    /**
     * Хук entry_after_insert — запись уже в БД, есть ID.
     *
     * @param FreshRSS_Entry|null $entry
     * @return FreshRSS_Entry|null
     */
    public function onEntryAfterInsert($entry): ?FreshRSS_Entry
    {
        $enableLogging = $this->getSystemConfigurationValue('enable_logging');

        if ($enableLogging) {
            Minz_Log::warning('AutoFilter: entry_after_insert HOOK called for: ' . ($entry ? $entry->title() : 'NULL'));
        }

        if (!$entry) {
            return $entry;
        }

        if (!$this->isChannelEnabled($entry)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoFilter: Channel ' . $entry->feedId() . ' not in filter list, skipping');
            }
            return $entry;
        }

        $apiKey = $this->getSystemConfigurationValue('openrouter_api_key');

        if (empty($apiKey)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoFilter: API key not configured, skipping');
            }
            return $entry;
        }

        $config = [
            'openrouter_api_key'        => $apiKey,
            'openrouter_model'          => $this->getSystemConfigurationValue('openrouter_model'),
            'confidence_threshold_high' => $this->getSystemConfigurationValue('confidence_threshold_high'),
            'confidence_threshold_low'  => $this->getSystemConfigurationValue('confidence_threshold_low'),
            'prompt'                    => $this->getSystemConfigurationValue('prompt'),
            'enable_logging'            => $enableLogging,
        ];

        $controller = new FreshExtension_AutoFilter_openrouter_Controller($config);
        $result     = $controller->analyzeEntryAfterInsert($entry);

        if ($enableLogging) {
            if ($result['success']) {
                Minz_Log::warning(sprintf(
                    'AutoFilter: Done — ID=%d, Label=%s, Confidence=%.2f',
                    $entry->id(),
                    $result['analysis']['label'],
                    $result['analysis']['confidence']
                ));
            } else {
                Minz_Log::warning('AutoFilter: Error — ' . ($result['error'] ?? 'unknown'));
            }
        }

        // Всегда возвращаем запись — удалять из ленты не нужно,
        // реклама помечается прочитанной и скрыта через метку.
        return $entry;
    }

    /**
     * @return bool true если канал в списке фильтрации или список пуст
     */
    private function isChannelEnabled(FreshRSS_Entry $entry): bool
    {
        $channelsFilter = $this->getSystemConfigurationValue('channels_filter');

        if (empty($channelsFilter) || !is_array($channelsFilter)) {
            return true;
        }

        return in_array((string)$entry->feedId(), $channelsFilter, true);
    }
}
