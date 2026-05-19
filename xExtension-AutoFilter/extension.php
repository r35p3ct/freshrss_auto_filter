<?php

declare(strict_types=1);

require_once __DIR__ . '/Controllers/openrouterController.php';

/**
 * Расширение AutoFilter для автоматической фильтрации рекламы через AI.
 *
 * @version 0.4.0
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

            // Получаем и нормализуем настройки фильтрации каналов
            $channelsFilter = $_POST['auto_filter_channels_filter'] ?? [];
            if (!is_array($channelsFilter)) {
                $channelsFilter = [];
            }
            $channelsFilter = array_map('strval', $channelsFilter);
            // Удаляем дубликаты и пустые значения
            $channelsFilter = array_values(array_unique(array_filter($channelsFilter)));

            // Создаем новый конфиг
            $newConfig = [
                'openrouter_api_key'          => Minz_Request::paramString('auto_filter_openrouter_api_key'),
                'openrouter_model'            => Minz_Request::paramString('auto_filter_openrouter_model'),
                'confidence_threshold_high'   => (float)Minz_Request::param('auto_filter_confidence_threshold_high', 0.8),
                'confidence_threshold_low'    => (float)Minz_Request::param('auto_filter_confidence_threshold_low', 0.5),
                'prompt'                      => Minz_Request::paramString('auto_filter_prompt'),
                'enable_logging'              => Minz_Request::paramString('auto_filter_enable_logging') === '1',
                'channels_filter'             => $channelsFilter,
                'background_mode'             => Minz_Request::paramString('auto_filter_background_mode') === '1',
                'batch_size'                  => (int)Minz_Request::param('auto_filter_batch_size', 5),
                'request_delay_ms'            => (int)Minz_Request::param('auto_filter_request_delay_ms', 2000),
            ];

            // Сохраняем конфиг
            $this->setSystemConfiguration($newConfig);

            // Очищаем кэш при изменении ключевых настроек
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
                return true;
            }
        }

        // Очищаем кэш при изменении списка фильтруемых каналов
        if (isset($oldConfig['channels_filter'], $newConfig['channels_filter'])) {
            $oldChannels = is_array($oldConfig['channels_filter']) ? $oldConfig['channels_filter'] : [];
            $newChannels = is_array($newConfig['channels_filter']) ? $newConfig['channels_filter'] : [];

            sort($oldChannels);
            sort($newChannels);

            if ($oldChannels !== $newChannels) {
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
            'batch_size' => $this->getSystemConfigurationValue('batch_size'),
            'request_delay_ms' => $this->getSystemConfigurationValue('request_delay_ms'),
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
        if (!$entry) {
            return $entry;
        }

        $enableLogging = $this->getSystemConfigurationValue('enable_logging');
        $backgroundMode = $this->getSystemConfigurationValue('background_mode');

        // Проверка: если уже есть метка "Реклама" или "Подозрение" в тегах записи, пропускаем
        $tags = $entry->tags(true);
        if (!is_array($tags)) {
            $tags = is_string($tags) && $tags !== '' ? explode(';', $tags) : [];
        }
        foreach ($tags as $tag) {
            $tagString = (string)$tag;
            if ($tagString !== '' && str_starts_with($tagString, 't:')) {
                $tagId = (int)substr($tagString, 2);
                if ($tagId <= 0) {
                    continue;
                }
                $tagDao = FreshRSS_Factory::createTagDao();
                try {
                    $allTags = $tagDao->listTags();
                    foreach ($allTags as $t) {
                        if ($t->id() === $tagId) {
                            if (in_array($t->name(), [
                                FreshExtension_AutoFilter_Labels::ADVERTISEMENT,
                                FreshExtension_AutoFilter_Labels::POSSIBLE
                            ], true)) {
                                return $entry;
                            }
                            break;
                        }
                    }
                } catch (Exception $e) {
                    if ($enableLogging) {
                        Minz_Log::warning('AutoFilter: Failed to check existing label: ' . $e->getMessage());
                    }
                }
            }
        }

        if (!$this->isChannelEnabled($entry)) {
            return $entry;
        }

        // Фоновый режим: ставим метку "Непроверено" и пропускаем синхронную проверку
        if ($backgroundMode) {
            $this->applyPendingLabel($entry);
            return $entry;
        }

        // Синхронный режим: проверяем сразу через AI
        $apiKey = $this->getSystemConfigurationValue('openrouter_api_key');
        if (empty($apiKey)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoFilter: API key not configured, skipping');
            }
            return $entry;
        }

        $controller = new FreshExtension_AutoFilter_openrouter_Controller($this->buildConfigArray());
        $result = $controller->analyzeEntryBeforeAdd($entry);

        if ($enableLogging && !$result['success']) {
            Minz_Log::warning('AutoFilter: Analysis failed — ' . ($result['error'] ?? 'unknown error'));
        }

        return $entry;
    }

    /**
     * Добавляет метку "Непроверено" к записи (до вставки в БД).
     */
    private function applyPendingLabel(FreshRSS_Entry $entry): void
    {
        $tagDao = FreshRSS_Factory::createTagDao();
        $pendingLabel = null;

        try {
            foreach ($tagDao->listTags() as $t) {
                if ($t->name() === FreshExtension_AutoFilter_Labels::PENDING) {
                    $pendingLabel = $t;
                    break;
                }
            }
        } catch (Exception $e) {
            return;
        }

        if ($pendingLabel === null) {
            return;
        }

        $currentTagsId = [];
        $tags = $entry->tags(true);
        if (!is_array($tags)) {
            $tags = is_string($tags) && $tags !== '' ? explode(';', $tags) : [];
        }
        foreach ($tags as $tag) {
            $tagString = (string)$tag;
            if ($tagString !== '' && str_starts_with($tagString, 't:')) {
                $currentTagsId[] = (int)substr($tagString, 2);
            }
        }

        if (!in_array($pendingLabel->id(), $currentTagsId, true)) {
            $currentTagsId[] = $pendingLabel->id();
            $newTagsString = implode(';', array_map(fn($id) => 't:' . $id, $currentTagsId));
            $entry->_tags($newTagsString);
        }
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

        $feedId = (string)$entry->feedId();
        return in_array($feedId, $channelsFilter, true);
    }
}
