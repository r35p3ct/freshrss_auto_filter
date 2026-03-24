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

        // entry_before_add — запись ещё не в БД, но мы можем установить метки через setTagsId().
        // Метки сохранятся вместе с записью при вставке.
        $this->registerHook('entry_before_add', [$this, 'onEntryBeforeAdd']);
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

    private function buildConfigArray(): array
    {
        return [
            'openrouter_api_key' => $this->getSystemConfigurationValue('openrouter_api_key'),
            'openrouter_model' => $this->getSystemConfigurationValue('openrouter_model'),
            'confidence_threshold_high' => $this->getSystemConfigurationValue('confidence_threshold_high'),
            'confidence_threshold_low' => $this->getSystemConfigurationValue('confidence_threshold_low'),
            'prompt' => $this->getSystemConfigurationValue('prompt'),
            'enable_logging' => $this->getSystemConfigurationValue('enable_logging'),
        ];
    }

    /**
     * Хук entry_before_add — запись ещё не в БД, но мы можем установить метки.
     * Метки сохранятся вместе с записью при вставке.
     *
     * @param FreshRSS_Entry|null $entry
     * @return FreshRSS_Entry|null
     */
    public function onEntryBeforeAdd($entry): ?FreshRSS_Entry
    {
        $config = $this->buildConfigArray();

        $enableLogging = $config['enable_logging'];

//        if ($enableLogging) {
//            Minz_Log::warning('AutoFilter: entry_before_add HOOK called for: ' . ($entry ? $entry->title() : 'NULL'));
//        }

        if (!$entry) {
            return $entry;
        }

        // Проверка: если уже есть метка "Реклама" или "Подозрение" в тегах записи, пропускаем
        $tags = $entry->tags(true);
        // tags(true) должен возвращать массив, но на всякий случай проверяем
        if (!is_array($tags)) {
            // Если вернулась строка, парсим её
            $tags = is_string($tags) && $tags !== '' ? explode(';', $tags) : [];
        }
        foreach ($tags as $tag) {
            $tagString = (string)$tag;
            if ($tagString !== '' && str_starts_with($tagString, 't:')) {
                $tagId = (int)substr($tagString, 2);
                if ($tagId <= 0) {
                    continue;
                }
                // Проверяем, является ли этот тег одной из наших меток
                // Для этого получаем все метки и ищем по ID
                $tagDao = FreshRSS_Factory::createTagDao();
                try {
                    $allTags = $tagDao->listTags();
                    foreach ($allTags as $t) {
                        if ($t->id() === $tagId) {
                            if (in_array($t->name(), [
                                FreshExtension_AutoFilter_Labels::ADVERTISEMENT,
                                FreshExtension_AutoFilter_Labels::POSSIBLE
                            ], true)) {
                                if ($enableLogging) {
                                    Minz_Log::info('AutoFilter: Entry already has ad-related label, skipping');
                                }
                                return $entry;
                            }
                            break;
                        }
                    }
                } catch (Exception $e) {
                    // Если не удалось получить метки, продолжаем анализ
                    if ($enableLogging) {
                        Minz_Log::warning('AutoFilter: Failed to check existing label: ' . $e->getMessage());
                    }
                }
            }
        }

        if (!$this->isChannelEnabled($entry)) {
//            if ($enableLogging) {
//                Minz_Log::warning('AutoFilter: Channel ' . $entry->feedId() . ' not in filter list, skipping');
//            }
            return $entry;
        }

        $apiKey = $config['openrouter_api_key'];

        if (empty($apiKey)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoFilter: API key not configured, skipping');
            }
            return $entry;
        }

        $controller = new FreshExtension_AutoFilter_openrouter_Controller($config);
        $result     = $controller->analyzeEntryBeforeAdd($entry);

        if ($enableLogging) {
            if ($result['success']) {
                Minz_Log::warning(sprintf(
                    'AutoFilter: Done — Label=%s, Confidence=%.2f',
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
