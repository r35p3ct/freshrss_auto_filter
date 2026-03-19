<?php

declare(strict_types=1);

require_once __DIR__ . '/../Labels.php';

/**
 * Контроллер для анализа записей через OpenRouter API.
 *
 * Использует AI для автоматического определения рекламных записей
 * и применения меток "Реклама" или "Подозрение".
 */
class FreshExtension_AutoFilter_openrouter_Controller extends FreshRSS_ActionController
{
    private const MAX_CONTENT_LENGTH    = 2000;
    private const MAX_LOG_PROMPT_LENGTH = 200;

    // Используем константы из общего класса
    private const LABEL_NONE            = FreshExtension_AutoFilter_Labels::NONE;
    private const LABEL_ADVERTISEMENT   = FreshExtension_AutoFilter_Labels::ADVERTISEMENT;
    private const LABEL_POSSIBLE        = FreshExtension_AutoFilter_Labels::POSSIBLE;

    private string $apiKey;
    private string $model;
    private float  $confidenceHigh;
    private float  $confidenceLow;
    private string $prompt;
    private bool   $enableLogging;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->apiKey         = $this->validateApiKey($config['openrouter_api_key'] ?? '');
        $this->model          = $this->validateModel($config['openrouter_model'] ?? 'openai/gpt-3.5-turbo');
        $this->confidenceHigh = $this->validateThreshold($config['confidence_threshold_high'] ?? 0.8, 'high');
        $this->confidenceLow  = $this->validateThreshold($config['confidence_threshold_low'] ?? 0.5, 'low');
        $this->prompt         = $config['prompt'] ?? '';
        $this->enableLogging  = !empty($config['enable_logging']);

        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Валидация
    // -------------------------------------------------------------------------

    private function validateApiKey(string $key): string
    {
        if (empty($key)) {
            Minz_Log::warning('AutoFilter: API key is empty - extension will not function');
        }
        return $key;
    }

    private function validateModel(string $model): string
    {
        $model = trim($model);
        if (empty($model)) {
            Minz_Log::warning('AutoFilter: Model is empty, using default');
            return 'openai/gpt-3.5-turbo';
        }
        if (!str_contains($model, '/')) {
            Minz_Log::warning('AutoFilter: Invalid model format "' . $model . '", expected provider/model-name');
        }
        return $model;
    }

    /**
     * @param mixed $value
     */
    private function validateThreshold($value, string $type): float
    {
        $f = (float)$value;
        if ($f < 0.0 || $f > 1.0) {
            Minz_Log::warning('AutoFilter: Invalid confidence_threshold_' . $type . ' (' . $f . '), using default');
            return $type === 'high' ? 0.8 : 0.5;
        }
        return $f;
    }

    // -------------------------------------------------------------------------
    // Публичный метод — вызывается из хука entry_before_add
    // -------------------------------------------------------------------------

    /**
     * Анализирует запись и применяет метки. Запись ещё не в БД, ID нет.
     * Метки устанавливаются через setTagsId() и сохраняются при вставке.
     *
     * Логика меток:
     *   LABEL_ADVERTISEMENT: метка + помечается прочитанной
     *   LABEL_POSSIBLE:      только метка
     *   LABEL_NONE:          ничего не делаем
     *
     * @return array{success: bool, analysis?: array, error?: string}
     */
    public function analyzeEntryBeforeAdd(FreshRSS_Entry $entry): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }

        $prompt = $this->buildPrompt($entry);

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

        if ($this->enableLogging) {
            Minz_Log::warning('AutoFilter: API response preview: ' . substr($response['content'], 0, 100));
        }

        $analysis = $this->parseResponse($response['content']);

        if ($this->enableLogging) {
            $this->logAnalysisResult($entry, $analysis);
        }

        $this->applyLabelsToEntry($entry, $analysis);

        return [
            'success'  => true,
            'analysis' => $analysis,
        ];
    }

    // -------------------------------------------------------------------------
    // Применение меток к записи (до вставки в БД)
    // -------------------------------------------------------------------------

    private function applyLabelsToEntry(FreshRSS_Entry $entry, array $analysis): void
    {
        $label = $analysis['label'] ?? self::LABEL_NONE;

        if ($label === self::LABEL_NONE) {
            return;
        }

        // Находим ID метки по имени (используем TagDao, т.к. в FreshRSS метки = tags)
        $tagDao      = FreshRSS_Factory::createTagDao();
        $targetLabel = null;

        foreach ($tagDao->listTags() as $l) {
            if ($l->name() === $label) {
                $targetLabel = $l;
                break;
            }
        }

        if ($targetLabel === null) {
            Minz_Log::warning(
                'AutoFilter: Label "' . $label . '" not found. '
                . 'Create it manually: Settings -> Labels.'
            );
            return;
        }

        // Получаем текущие теги записи и добавляем новый
        $currentTagsId = $entry->tagsId();
        if (!in_array($targetLabel->id(), $currentTagsId, true)) {
            $currentTagsId[] = $targetLabel->id();
            $entry->setTagsId($currentTagsId);

            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: Label "' . $label . '" (ID=' . $targetLabel->id() . ') applied to entry');
            }
        }

        // Подтверждённую рекламу помечаем прочитанной:
        // скрывается из непрочитанных, но доступна через фильтр label:Реклама
        if ($label === self::LABEL_ADVERTISEMENT) {
            $entry->_isRead(true);
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: Entry marked as read');
            }
        }
    }

    // -------------------------------------------------------------------------
    // HTTP-эндпоинты (ручная и пакетная проверка)
    // -------------------------------------------------------------------------

    /**
     * GET /api/p.php?c=autoFilter_openrouter&a=checkEntry&entry_id={ID}
     *
     * @return array{success: bool, analysis?: array, error?: string}
     */
    public function checkEntryAction(): array
    {
        $entryId = Minz_Request::param('entry_id', 0);
        if (!$entryId) {
            return ['success' => false, 'error' => 'Entry ID not provided'];
        }

        $entry = FreshRSS_Factory::createEntryDao()->searchById($entryId);
        if (!$entry) {
            return ['success' => false, 'error' => 'Entry not found'];
        }

        // Для уже существующей записи используем applyLabelsViaDao
        return $this->analyzeExistingEntry($entry);
    }

    /**
     * POST /api/p.php?c=autoFilter_openrouter&a=checkBatch
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
        $results  = [];

        foreach ($entryIds as $entryId) {
            $entry = $entryDao->searchById($entryId);
            if ($entry) {
                $results[] = $this->analyzeExistingEntry($entry);
            }
        }

        return ['success' => true, 'results' => $results];
    }

    /**
     * Анализ существующей записи (с ID в БД)
     * Применяет метки через DAO.
     */
    private function analyzeExistingEntry(FreshRSS_Entry $entry): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }

        $prompt = $this->buildPrompt($entry);

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

        if ($this->enableLogging) {
            Minz_Log::warning('AutoFilter: API response preview: ' . substr($response['content'], 0, 100));
        }

        $analysis = $this->parseResponse($response['content']);

        if ($this->enableLogging) {
            $this->logAnalysisResult($entry, $analysis);
        }

        $this->applyLabelsViaDao($entry, $analysis);

        return [
            'success'  => true,
            'entry_id' => $entry->id(),
            'analysis' => $analysis,
        ];
    }

    /**
     * Применение меток к существующей записи через DAO
     */
    private function applyLabelsViaDao(FreshRSS_Entry $entry, array $analysis): void
    {
        $label = $analysis['label'] ?? self::LABEL_NONE;

        if ($label === self::LABEL_NONE) {
            return;
        }

        $entryId = $entry->id();
        if (empty($entryId)) {
            Minz_Log::warning('AutoFilter: Cannot apply label - entry has no ID');
            return;
        }

        // Находим ID метки по имени (используем TagDao)
        $tagDao      = FreshRSS_Factory::createTagDao();
        $targetLabel = null;

        foreach ($tagDao->listTags() as $l) {
            if ($l->name() === $label) {
                $targetLabel = $l;
                break;
            }
        }

        if ($targetLabel === null) {
            Minz_Log::warning(
                'AutoFilter: Label "' . $label . '" not found. '
                . 'Create it manually: Settings -> Labels.'
            );
            return;
        }

        // Добавляем метку к записи
        $tagDao->tagEntry($targetLabel->id(), $entryId);

        if ($this->enableLogging) {
            Minz_Log::warning('AutoFilter: Label "' . $label . '" applied to entry ID=' . $entryId);
        }

        // Подтверждённую рекламу помечаем прочитанной
        if ($label === self::LABEL_ADVERTISEMENT) {
            try {
                $entryDao = FreshRSS_Factory::createEntryDao();
                $entryDao->markRead([$entryId], true);
                if ($this->enableLogging) {
                    Minz_Log::warning('AutoFilter: Entry ID=' . $entryId . ' marked as read');
                }
            } catch (Throwable $e) {
                Minz_Log::warning('AutoFilter: Failed to mark entry as read: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Построение промпта
    // -------------------------------------------------------------------------

    private function buildPrompt(FreshRSS_Entry $entry): string
    {
        $title   = $entry->title();
        $content = $this->truncateContent(strip_tags($entry->content()));
        $author  = reset($entry->authors()) ?: '';
        $url     = $entry->link();

        if (!empty($this->prompt)) {
            return str_replace(
                ['{title}', '{author}', '{url}', '{content}'],
                [$title,    $author,    $url,    $content],
                $this->prompt
            );
        }

        return $this->getDefaultPrompt($title, $author, $url, $content);
    }

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
    2. Призыв подписаться на другой канал/соцсеть (НЕ на автора)
    3. Заманивание бесплатными товарами/услугами для привлечения клиентов
    4. Сбор денег на покупку, лечение, помощь или отчет о сборе
    5. Бесплатное или платное обучение чему-то
    6. Призывы идти работать, служить в армию или еще куда-то
    7. Указаны реквизиты карт, номера телефонов
    8. Розыгрыши, бесплатные призы

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

    private function truncateContent(string $content): string
    {
        if (strlen($content) <= self::MAX_CONTENT_LENGTH) {
            return $content;
        }
        return substr($content, 0, self::MAX_CONTENT_LENGTH) . '...';
    }

    // -------------------------------------------------------------------------
    // Парсинг ответа
    // -------------------------------------------------------------------------

    /**
     * @return array{is_advertisement: bool, confidence: float, reason: string, label: string}
     */
    private function parseResponse(string $content): array
    {
        if (json_last_error() !== JSON_ERROR_NONE) {
            Minz_Log::warning('AutoFilter: json_last_error before parsing: ' . json_last_error());
        }

        $json = json_decode($content, true);

        if (!is_array($json) || !isset($json['is_advertisement'], $json['confidence'])) {
            Minz_Log::warning('AutoFilter: Failed to parse JSON: ' . substr($content, 0, 100));
            return [
                'is_advertisement' => false,
                'confidence'       => 0.0,
                'reason'           => 'Failed to parse AI response',
                'label'            => self::LABEL_NONE,
            ];
        }

        $isAd       = (bool)$json['is_advertisement'];
        $confidence = (float)$json['confidence'];
        $reason     = (string)($json['reason'] ?? 'No reason provided');
        $label      = $this->determineLabel($isAd, $confidence);

        return [
            'is_advertisement' => $isAd,
            'confidence'       => $confidence,
            'reason'           => $reason,
            'label'            => $label,
        ];
    }

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

    // -------------------------------------------------------------------------
    // Вызов OpenRouter API
    // -------------------------------------------------------------------------

    /**
     * @return array{success: bool, content?: string, error?: string, http_code?: int}
     */
    private function callOpenRouter(string $prompt): array
    {
        $url     = 'https://openrouter.ai/api/v1/chat/completions';
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: FreshRSS-AutoFilter',
        ];
        $data = [
            'model'           => $this->model,
            'messages'        => [['role' => 'user', 'content' => $prompt]],
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
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Minz_Log::warning('AutoFilter: CURL error: ' . $error);
            return ['success' => false, 'error' => 'CURL error: ' . $error];
        }

        if ($httpCode !== 200) {
            $msg = $this->getHttpErrorMessage($httpCode, $response);
            Minz_Log::warning('AutoFilter: HTTP ' . $httpCode . ' - ' . $msg);
            return ['success' => false, 'error' => $msg, 'http_code' => $httpCode];
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['choices'][0]['message']['content'])) {
            Minz_Log::warning('AutoFilter: Invalid API response structure');
            return ['success' => false, 'error' => 'Invalid API response'];
        }

        return ['success' => true, 'content' => $decoded['choices'][0]['message']['content']];
    }

    private function getHttpErrorMessage(int $httpCode, string $response): string
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthorized - check API key',
            403 => 'Forbidden - API key may be invalid',
            404 => 'Model not found',
            429 => 'Rate limit exceeded',
            500 => 'OpenRouter internal error',
            502 => 'OpenRouter service unavailable',
            503 => 'OpenRouter service unavailable',
            504 => 'OpenRouter gateway timeout',
        ];

        $message = $messages[$httpCode] ?? 'Unknown error';
        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message .= ': ' . $decoded['error']['message'];
        }
        return $message;
    }

    // -------------------------------------------------------------------------
    // Логирование
    // -------------------------------------------------------------------------

    private function logPromptMetadata(FreshRSS_Entry $entry, string $prompt): void
    {
        Minz_Log::warning(sprintf(
            'AutoFilter: Processing title="%s", content_chars=%d',
            substr($entry->title(), 0, 50),
            strlen(strip_tags($entry->content()))
        ));
    }

    private function logAnalysisResult(FreshRSS_Entry $entry, array $analysis): void
    {
        $contentPreview = substr(strip_tags($entry->content()), 0, 100);
        if (strlen(strip_tags($entry->content())) > 100) {
            $contentPreview .= '...';
        }
        
        Minz_Log::warning(sprintf(
            'AutoFilter: ID=%d Label=%s Confidence=%.2f Reason=%s Content="%s"',
            $entry->id(),
            $analysis['label'],
            $analysis['confidence'],
            $analysis['reason'],
            str_replace(["\n", "\r"], ' ', $contentPreview)
        ));
    }

    public function invalidateCache(): void
    {
        // Здесь можно реализовать логику очистки кэша, если она понадобится
        Minz_Log::info('AutoFilter: Cache invalidated');
    }
}
