<?php

declare(strict_types=1);

/**
 * Контроллер для анализа записей через OpenRouter API.
 * 
 * Использует AI для автоматического определения рекламных записей
 * и применения меток "реклама" или "возможно реклама".
 */
class FreshExtension_AutoFilter_openrouter_Controller extends FreshRSS_ActionController
{
    /** Метка: не реклама */
    private const LABEL_NONE = 'none';
    /** Метка: реклама */
    private const LABEL_ADVERTISEMENT = 'advertisement';
    /** Метка: возможная реклама */
    private const LABEL_POSSIBLE = 'possible_advertisement';
    /** TTL кэша в секундах (1 час) */
    private const CACHE_TTL = 3600;
    /** Максимум символов в содержимом для отправки в API */
    private const MAX_CONTENT_LENGTH = 2000;
    /** Максимум символов для логирования промпта */
    private const MAX_LOG_PROMPT_LENGTH = 200;

    private string $apiKey;
    private string $model;
    private float $confidenceHigh;
    private float $confidenceLow;
    private string $prompt;
    private bool $enableLogging;
    
    /** @var array<string, array{timestamp: int, result: array}> Кэш результатов анализа */
    private array $cache = [];
    
    /** Флаг необходимости очистки кэша */
    private bool $cacheInvalidated = false;

    /**
     * @param array<string, mixed> $config Конфигурация из getSystemConfigurationValue()
     */
    public function __construct(array $config = [])
    {
        $this->apiKey = $this->validateApiKey($config['openrouter_api_key'] ?? '');
        $this->model = $this->validateModel($config['openrouter_model'] ?? 'openai/gpt-3.5-turbo');
        $this->confidenceHigh = $this->validateConfidenceThreshold(
            $config['confidence_threshold_high'] ?? 0.8,
            'high'
        );
        $this->confidenceLow = $this->validateConfidenceThreshold(
            $config['confidence_threshold_low'] ?? 0.5,
            'low'
        );
        $this->prompt = $config['prompt'] ?? '';
        $this->enableLogging = !empty($config['enable_logging']);
        
        parent::__construct();
    }

    /**
     * Валидация API ключа.
     */
    private function validateApiKey(string $key): string
    {
        if (empty($key)) {
            Minz_Log::warning('AutoFilter: API key is empty - extension will not function');
            return '';
        }
        return $key;
    }

    /**
     * Валидация названия модели.
     */
    private function validateModel(string $model): string
    {
        $model = trim($model);
        if (empty($model)) {
            Minz_Log::warning('AutoFilter: Model is empty, using default');
            return 'openai/gpt-3.5-turbo';
        }
        // Базовая валидация формата: provider/model-name
        if (!str_contains($model, '/')) {
            Minz_Log::warning('AutoFilter: Invalid model format "' . $model . '", expected provider/model-name');
        }
        return $model;
    }

    /**
     * Валидация порога уверенности.
     * 
     * @param mixed $value Значение порога
     * @param string $type Тип порога ('high' или 'low')
     */
    private function validateConfidenceThreshold(mixed $value, string $type): float
    {
        $floatValue = (float)$value;
        
        if ($floatValue < 0.0 || $floatValue > 1.0) {
            Minz_Log::warning('AutoFilter: Invalid confidence_threshold_' . $type . ' (' . $floatValue . '), using default');
            return $type === 'high' ? 0.8 : 0.5;
        }
        
        return $floatValue;
    }

    /**
     * Проверка валидности метки.
     */
    public static function validateLabel(string $label): bool
    {
        return in_array($label, [
            self::LABEL_NONE,
            self::LABEL_ADVERTISEMENT,
            self::LABEL_POSSIBLE
        ], true);
    }

    /**
     * Очистка кэша при изменении конфигурации.
     */
    public function invalidateCache(): void
    {
        $this->cache = [];
        $this->cacheInvalidated = true;
        if ($this->enableLogging) {
            Minz_Log::warning('AutoFilter: Cache invalidated due to configuration change');
        }
    }

    /**
     * Действие для проверки отдельной записи по ID.
     * 
     * @return array{success: bool, analysis?: array, error?: string}
     */
    public function checkEntryAction(): array
    {
        $entryId = Minz_Request::param('entry_id', 0);
        if (!$entryId) {
            return ['success' => false, 'error' => 'Entry ID not provided'];
        }

        $entryDao = FreshRSS_Factory::createEntryDao();
        $entry = $entryDao->searchById($entryId);

        if (!$entry) {
            return ['success' => false, 'error' => 'Entry not found'];
        }

        return $this->analyzeEntry($entry);
    }

    /**
     * Прямой анализ записи для вызова из хука entry_before_insert.
     * 
     * @param FreshRSS_Entry $entry Запись для анализа
     * @return array{success: bool, entry_id?: int, analysis?: array, error?: string}
     */
    public function analyzeEntryDirect(FreshRSS_Entry $entry): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }

        // Проверка кэша по URL записи
        $cachedResult = $this->getCachedResult($entry);
        if ($cachedResult !== null) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: Cache HIT for entry ' . $entry->id());
            }
            return [
                'success' => true,
                'entry_id' => $entry->id(),
                'analysis' => $cachedResult,
            ];
        }

        $prompt = $this->buildPrompt($entry);

        // Логируем только метаданные промпта
        if ($this->enableLogging) {
            $this->logPromptMetadata($entry, $prompt);
        }

        $response = $this->callOpenRouter($prompt);

        if (!$response['success']) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: API error: ' . ($response['error'] ?? 'unknown'));
            }
            return $response;
        }

        // Логируем только начало ответа API
        if ($this->enableLogging) {
            Minz_Log::warning('AutoFilter: API response (first 100 chars): ' . substr($response['content'], 0, 100));
        }

        $analysis = $this->parseResponse($response['content']);

        // Логирование результата анализа
        if ($this->enableLogging) {
            $this->logAnalysisResult($entry, $analysis);
        }

        // Применение меток
        $this->applyLabels($entry, $analysis);

        // Сохранение в кэш
        $this->setCachedResult($entry, $analysis);

        return [
            'success' => true,
            'entry_id' => $entry->id(),
            'analysis' => $analysis,
        ];
    }

    /**
     * Пакетная проверка записей.
     * 
     * @return array{success: bool, results?: array}
     */
    public function checkBatchAction(): array
    {
        $entryIds = Minz_Request::param('entry_ids', []);
        if (empty($entryIds)) {
            return ['success' => false, 'error' => 'Entry IDs not provided'];
        }

        $entryDao = FreshRSS_Factory::createEntryDao();
        $results = [];

        foreach ($entryIds as $entryId) {
            $entry = $entryDao->searchById($entryId);
            if ($entry) {
                $results[] = $this->analyzeEntry($entry);
            }
        }

        return ['success' => true, 'results' => $results];
    }

    /**
     * Внутренний метод анализа для HTTP-вызовов.
     * 
     * @param FreshRSS_Entry $entry Запись для анализа
     * @return array{success: bool, entry_id?: int, analysis?: array, error?: string}
     */
    private function analyzeEntry(FreshRSS_Entry $entry): array
    {
        return $this->analyzeEntryDirect($entry);
    }

    /**
     * Получение результата из кэша.
     * 
     * @return array|null Результат анализа или null если нет в кэше
     */
    private function getCachedResult(FreshRSS_Entry $entry): ?array
    {
        $cacheKey = $this->getCacheKey($entry);
        
        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if (time() - $cached['timestamp'] < self::CACHE_TTL) {
                return $cached['result'];
            }
            // Истёк TTL - удаляем из кэша
            unset($this->cache[$cacheKey]);
        }
        
        return null;
    }

    /**
     * Сохранение результата в кэш.
     * 
     * @param FreshRSS_Entry $entry Запись
     * @param array $analysis Результат анализа
     */
    private function setCachedResult(FreshRSS_Entry $entry, array $analysis): void
    {
        $cacheKey = $this->getCacheKey($entry);
        $this->cache[$cacheKey] = [
            'timestamp' => time(),
            'result' => $analysis,
        ];
    }

    /**
     * Генерация ключа кэша по URL записи.
     */
    private function getCacheKey(FreshRSS_Entry $entry): string
    {
        return md5($entry->link());
    }

    /**
     * Логирование метаданных промпта (без чувствительных данных).
     */
    private function logPromptMetadata(FreshRSS_Entry $entry, string $prompt): void
    {
        Minz_Log::warning(sprintf(
            'AutoFilter: Prompt [%s] title="%s", authors=%d, content_chars=%d, prompt_chars=%d',
            get_called_class(),
            substr($entry->title(), 0, 50),
            count($entry->authors()),
            strlen(strip_tags($entry->content())),
            strlen($prompt)
        ));
        
        // Короткий фрагмент промпта для отладки
        if (strlen($prompt) > self::MAX_LOG_PROMPT_LENGTH) {
            Minz_Log::warning('AutoFilter: Prompt preview: ' . substr($prompt, 0, self::MAX_LOG_PROMPT_LENGTH) . '...');
        }
    }

    /**
     * Логирование результата анализа.
     * 
     * @param FreshRSS_Entry $entry Запись
     * @param array $analysis Результат анализа
     */
    private function logAnalysisResult(FreshRSS_Entry $entry, array $analysis): void
    {
        Minz_Log::warning(sprintf(
            'AutoFilter: Analysis ID=%d, Label=%s, Confidence=%.2f, Reason=%s',
            $entry->id(),
            $analysis['label'],
            $analysis['confidence'],
            $analysis['reason']
        ));

        if ($analysis['label'] === self::LABEL_ADVERTISEMENT) {
            Minz_Log::warning('AutoFilter: RECLAME detected for entry ' . $entry->id() . ' - ' . $entry->title());
        } elseif ($analysis['label'] === self::LABEL_POSSIBLE) {
            Minz_Log::warning('AutoFilter: POSSIBLE RECLAME for entry ' . $entry->id() . ' - ' . $entry->title());
        }
    }

    /**
     * Построение промпта для AI.
     * 
     * @param FreshRSS_Entry $entry Запись для анализа
     */
    private function buildPrompt(FreshRSS_Entry $entry): string
    {
        $title = $entry->title();
        $content = $this->truncateContent(strip_tags($entry->content()));
        $author = $entry->authors()[0] ?? '';
        $url = $entry->link();

        // Промт по умолчанию с улучшенной структурой
        $defaultPrompt = $this->getDefaultPrompt($title, $author, $url, $content);

        // Если промт задан в настройках, используем его с заменой плейсхолдеров
        if (!empty($this->prompt)) {
            $prompt = $this->prompt;
            $prompt = str_replace('{title}', $title, $prompt);
            $prompt = str_replace('{author}', $author, $prompt);
            $prompt = str_replace('{url}', $url, $prompt);
            $prompt = str_replace('{content}', $content, $prompt);
            return $prompt;
        }

        return $defaultPrompt;
    }

    /**
     * Промт по умолчанию с чётким форматом вывода.
     */
    private function getDefaultPrompt(string $title, string $author, string $url, string $content): string
    {
        return <<<PROMPT
Ты должен определить, является ли следующая новостная запись рекламой.

ВЕРНИ ТОЛЬКО JSON БЕЗ ПОЯСНЕНИЙ В ТАКОМ ФОРМАТЕ:
{
    "is_advertisement": true|false,
    "confidence": 0.0-1.0,
    "reason": "краткое объяснение до 5 слов"
}

КРИТЕРИИ РЕКЛАМЫ (is_advertisement = true):
1. Коммерческое продвижение или продажа товара/услуги
2. Призыв подписаться на другой канал/соцсеть за плату (НЕ на автора)
3. Заманивание бесплатными товарами/услугами для привлечения клиентов

НЕ РЕКЛАМА (is_advertisement = false):
- Обычные новости и статьи
- Призывы подписаться на того же автора
- Личные мнения и блоги без коммерции

УРОВНИ УВЕРЕННОСТИ:
- 0.9-1.0: явная реклама с прямым призывом к покупке
- 0.7-0.9: коммерческий контент с элементами продвижения
- 0.4-0.7: подозрительный контент, но неясно
- 0.0-0.3: точно не реклама

КОНТЕКСТ ЗАПИСИ:
- Заголовок: {$title}
- Автор: {$author}
- Ссылка: {$url}
- Содержание: {$content}

PROMPT;
    }

    /**
     * Обрезка содержимого до максимальной длины.
     */
    private function truncateContent(string $content): string
    {
        if (strlen($content) <= self::MAX_CONTENT_LENGTH) {
            return $content;
        }
        return substr($content, 0, self::MAX_CONTENT_LENGTH) . '...';
    }

    /**
     * Вызов OpenRouter API.
     * 
     * @param string $prompt Промт для отправки
     * @return array{success: bool, content?: string, error?: string, http_code?: int}
     */
    private function callOpenRouter(string $prompt): array
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: FreshRSS-AutoFilter',
        ];

        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Minz_Log::warning('AutoFilter: CURL error: ' . $error);
            return ['success' => false, 'error' => 'CURL error: ' . $error];
        }

        if ($httpCode !== 200) {
            $errorMsg = $this->getHttpErrorMessage($httpCode, $response);
            Minz_Log::warning('AutoFilter: HTTP ' . $httpCode . ' - ' . $errorMsg);
            return ['success' => false, 'error' => $errorMsg, 'http_code' => $httpCode];
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['choices'][0]['message']['content'])) {
            Minz_Log::warning('AutoFilter: Invalid API response structure');
            return ['success' => false, 'error' => 'Invalid API response'];
        }

        return [
            'success' => true,
            'content' => $decoded['choices'][0]['message']['content'],
        ];
    }

    /**
     * Формирование сообщения об ошибке по HTTP коду.
     */
    private function getHttpErrorMessage(int $httpCode, string $response): string
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthorized - check API key',
            403 => 'Forbidden - API key may be invalid',
            404 => 'Model not found',
            429 => 'Rate limit exceeded - too many requests',
            500 => 'OpenRouter internal error',
            502 => 'OpenRouter service unavailable',
            503 => 'OpenRouter service unavailable',
            504 => 'OpenRouter gateway timeout',
        ];

        $message = $messages[$httpCode] ?? 'Unknown error';

        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['error']['message'])) {
            $message .= ': ' . $decoded['error']['message'];
        }

        return $message;
    }

    /**
     * Парсинг ответа API.
     * 
     * @param string $content Содержимое ответа
     * @return array{is_advertisement: bool, confidence: float, reason: string, label: string}
     */
    private function parseResponse(string $content): array
    {
        $json = json_decode($content, true);

        if (!$json) {
            Minz_Log::warning('AutoFilter: Failed to parse JSON response: ' . substr($content, 0, 100));
            return [
                'is_advertisement' => false,
                'confidence' => 0.0,
                'reason' => 'Failed to parse AI response',
                'label' => self::LABEL_NONE,
            ];
        }

        $isAd = $json['is_advertisement'] ?? false;
        $confidence = floatval($json['confidence'] ?? 0.0);
        $reason = $json['reason'] ?? 'No reason provided';

        if (!isset($json['is_advertisement']) || !isset($json['confidence'])) {
            Minz_Log::warning('AutoFilter: Missing required fields in response: ' . $content);
            return [
                'is_advertisement' => false,
                'confidence' => 0.0,
                'reason' => 'Invalid response format',
                'label' => self::LABEL_NONE,
            ];
        }

        $label = $this->determineLabel($isAd, $confidence);

        return [
            'is_advertisement' => $isAd,
            'confidence' => $confidence,
            'reason' => $reason,
            'label' => $label,
        ];
    }

    /**
     * Определение метки по результату анализа.
     */
    private function determineLabel(bool $isAd, float $confidence): string
    {
        if ($isAd && $confidence >= $this->confidenceHigh) {
            return self::LABEL_ADVERTISEMENT;
        }
        
        if ($confidence >= $this->confidenceLow) {
            return self::LABEL_POSSIBLE;
        }
        
        return self::LABEL_NONE;
    }

    /**
     * Применение меток к записи.
     * 
     * @param FreshRSS_Entry $entry Запись
     * @param array $analysis Результат анализа
     */
    private function applyLabels(FreshRSS_Entry $entry, array $analysis): void
    {
        $tags = $entry->tags() ?: [];

        // Удаляем старые метки расширения
        $tags = array_filter($tags, fn($tag) => !in_array($tag, [
            self::LABEL_ADVERTISEMENT,
            self::LABEL_POSSIBLE
        ], true));

        // Добавляем новую метку
        if ($analysis['label'] !== self::LABEL_NONE) {
            $tags[] = $analysis['label'];
        }

        $entry->_tags($tags);
    }
}
