<?php

declare(strict_types=1);

require_once __DIR__ . '/Controllers/openrouterController.php';

/**
 * Расширение AutoFilter для автоматической фильтрации рекламы через AI.
 * 
 * @version 0.2.0
 */
class AutoFilterExtension extends Minz_Extension
{
    /**
     * Инициализация расширения.
     */
    public function init(): void
    {
        $this->registerTranslates();
        $this->registerController('openrouter');
        $this->registerHook('entry_before_insert', [$this, 'onEntryBeforeInsert']);
    }

    /**
     * Обработка сохранения настроек.
     *
     * Очищает кэш при изменении ключевых параметров конфигурации.
     */
    public function handleConfigureAction(): void
    {
        if (Minz_Request::isPost()) {
            $oldConfig = $this->getSystemConfiguration();

            // Получаем список выбранных каналов из POST-данных
            $channelsFilter = Minz_Request::paramArray('auto_filter_channels_filter', []);
            // Преобразуем к строковому типу для консистентности
            $channelsFilter = array_map('strval', $channelsFilter);

            $newConfig = [
                'openrouter_api_key' => Minz_Request::paramString('auto_filter_openrouter_api_key'),
                'openrouter_model' => Minz_Request::paramString('auto_filter_openrouter_model'),
                'confidence_threshold_high' => (float)Minz_Request::param('auto_filter_confidence_threshold_high', 0.8),
                'confidence_threshold_low' => (float)Minz_Request::param('auto_filter_confidence_threshold_low', 0.5),
                'prompt' => Minz_Request::paramString('auto_filter_prompt'),
                'enable_logging' => Minz_Request::paramString('auto_filter_enable_logging') === '1',
                'channels_filter' => $channelsFilter,
            ];

            $this->setSystemConfiguration($newConfig);

            // Очистка кэша при изменении ключевой конфигурации
            if ($this->shouldInvalidateCache($oldConfig, $newConfig)) {
                $this->invalidateControllerCache();
            }
        }
    }

    /**
     * Проверка необходимости очистки кэша.
     * 
     * @param array<string, mixed> $oldConfig Старая конфигурация
     * @param array<string, mixed> $newConfig Новая конфигурация
     */
    private function shouldInvalidateCache(array $oldConfig, array $newConfig): bool
    {
        $criticalKeys = ['openrouter_api_key', 'openrouter_model', 'prompt'];
        
        foreach ($criticalKeys as $key) {
            $oldValue = $oldConfig[$key] ?? null;
            $newValue = $newConfig[$key] ?? null;
            
            if ($oldValue !== $newValue) {
                Minz_Log::warning('AutoFilter: Configuration change detected for "' . $key . '", invalidating cache');
                return true;
            }
        }
        
        return false;
    }

    /**
     * Очистка кэша в контроллере.
     */
    private function invalidateControllerCache(): void
    {
        $config = [
            'openrouter_api_key' => $this->getSystemConfigurationValue('openrouter_api_key'),
            'openrouter_model' => $this->getSystemConfigurationValue('openrouter_model'),
            'confidence_threshold_high' => $this->getSystemConfigurationValue('confidence_threshold_high'),
            'confidence_threshold_low' => $this->getSystemConfigurationValue('confidence_threshold_low'),
            'prompt' => $this->getSystemConfigurationValue('prompt'),
            'enable_logging' => $this->getSystemConfigurationValue('enable_logging'),
        ];

        $controller = new FreshExtension_AutoFilter_openrouter_Controller($config);
        $controller->invalidateCache();
    }

    /**
     * Хук вызывается при добавлении новой записи в БД.
     *
     * @param FreshRSS_Entry|null $entry Запись для обработки
     * @return FreshRSS_Entry|null Modified entry или null для отмены добавления
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

        // Проверка: включен ли канал для фильтрации
        if (!$this->isChannelEnabled($entry)) {
            if ($enableLogging) {
                $feedId = $entry->feedId();
                Minz_Log::warning('AutoFilter: Channel ' . $feedId . ' is NOT in filter list, skipping');
            }
            return $entry;
        }

        $apiKey = $this->getSystemConfigurationValue('openrouter_api_key');

        if (empty($apiKey)) {
            if ($enableLogging) {
                Minz_Log::warning('AutoFilter: API key NOT configured, skipping');
            }
            return $entry;
        }

        // Получаем всю конфигурацию и передаём в контроллер
        $config = [
            'openrouter_api_key' => $apiKey,
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

    /**
     * Проверка: включен ли канал для фильтрации.
     *
     * @param FreshRSS_Entry $entry Запись для проверки
     * @return bool true если канал в списке фильтрации или список пуст (все каналы)
     */
    private function isChannelEnabled(FreshRSS_Entry $entry): bool
    {
        $channelsFilter = $this->getSystemConfigurationValue('channels_filter');
        
        // Если фильтр пуст - проверяем все каналы
        if (empty($channelsFilter) || !is_array($channelsFilter)) {
            return true;
        }

        $feedId = $entry->feedId();
        
        // Проверяем, есть ли feedId в списке выбранных каналов
        return in_array((string)$feedId, $channelsFilter, true);
    }
}
