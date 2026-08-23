<?php

declare(strict_types=1);

require_once __DIR__ . '/../Labels.php';
require_once __DIR__ . '/../Models/PendingEntriesModel.php';

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
    private const MAX_LOG_TITLE_LENGTH  = 150;

    // Используем константы из общего класса
    private const LABEL_NONE            = FreshExtension_AutoFilter_Labels::NONE;
    private const LABEL_ADVERTISEMENT   = FreshExtension_AutoFilter_Labels::ADVERTISEMENT;
    private const LABEL_POSSIBLE        = FreshExtension_AutoFilter_Labels::POSSIBLE;
    private const LABEL_PENDING         = FreshExtension_AutoFilter_Labels::PENDING;
    private const LABEL_CHECKED         = FreshExtension_AutoFilter_Labels::CHECKED;

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
            return 'openai/gpt-3.5-turbo';
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
            return $response;
        }

        if (empty($response['content'])) {
            $error = 'Empty response from AI service';
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: ' . $error);
            }
            return ['success' => false, 'error' => $error];
        }

        $analysis = $this->parseResponse($response['content']);

        if ($this->enableLogging) {
            $this->logAnalysisResult($entry, $analysis);
        }

        if (!isset($analysis['label'])) {
            $error = 'Invalid analysis result';
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: ' . $error);
            }
            return ['success' => false, 'error' => $error];
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

        $tagDao      = FreshRSS_Factory::createTagDao();
        $targetLabel = null;

        try {
            $targetLabel = $tagDao->searchByName($label);
        } catch (Exception $e) {
            Minz_Log::warning('AutoFilter: Failed to search label "' . $label . '": ' . $e->getMessage());
            return;
        }

        if ($targetLabel === null) {
            Minz_Log::warning(
                'AutoFilter: Label "' . $label . '" not found. '
                . 'Create it manually: Settings -> Labels.'
            );
            return;
        }

        $currentTagsId = [];
        foreach ($entry->tags() as $tag) {
            if (is_string($tag) && str_starts_with($tag, 't:')) {
                $currentTagsId[] = (int)substr($tag, 2);
            }
        }

        if (!in_array($targetLabel->id(), $currentTagsId, true)) {
            $currentTagsId[] = $targetLabel->id();
            $entry->_tags(array_map(fn(int $id): string => 't:' . $id, $currentTagsId));
        }

        if ($label === self::LABEL_ADVERTISEMENT) {
            $entry->_isRead(true);
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
            return $response;
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
        if (!isset($analysis['label'])) {
            Minz_Log::warning('AutoFilter: Invalid analysis result in applyLabelsViaDao');
            return;
        }

        $label = $analysis['label'] ?? self::LABEL_NONE;
        $entryId = $entry->id();
        if (empty($entryId)) {
            Minz_Log::warning('AutoFilter: Cannot apply label - entry has no ID');
            return;
        }

        $tagDao      = FreshRSS_Factory::createTagDao();
        $targetLabel = null;

        try {
            $targetLabel = $tagDao->searchByName($label);
        } catch (Exception $e) {
            Minz_Log::warning('AutoFilter: Failed to search label "' . $label . '": ' . $e->getMessage());
            return;
        }

        if ($targetLabel === null && $label !== self::LABEL_NONE) {
            Minz_Log::warning(
                'AutoFilter: Label "' . $label . '" not found. '
                . 'Create it manually: Settings -> Labels.'
            );
            return;
        }

        if ($targetLabel !== null) {
            try {
                $tagDao->tagEntry($targetLabel->id(), $entryId);
            } catch (Exception $e) {
                Minz_Log::warning('AutoFilter: Failed to add tag: ' . $e->getMessage());
                return;
            }

            // Синхронизируем поле tags в _entry
            $pendingModel = new FreshExtension_AutoFilter_PendingEntries_Model();
            $pendingModel->addTagToEntry($targetLabel->id(), $entryId);
        }

        if ($label === self::LABEL_ADVERTISEMENT) {
            try {
                $entryDao = FreshRSS_Factory::createEntryDao();
                $entryDao->markRead([$entryId], true);
            } catch (Throwable $e) {
                Minz_Log::warning('AutoFilter: Failed to mark entry as read: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Фоновая обработка записей с меткой "Непроверено"
    // -------------------------------------------------------------------------

    /**
     * Находит записи с меткой "Непроверено", проверяет их через AI,
     * применяет результат и удаляет метку "Непроверено".
     *
     * @param int $limit Максимальное количество записей за один запуск
     * @param int $delayMs Задержка между запросами в мс
     * @param array<int, string> $channelsFilter Список ID каналов для фильтрации (пустой = все)
     * @return array{processed: int, errors: int, details: array}
     */
    public function processPendingEntries(int $limit, int $delayMs, array $channelsFilter = []): array
    {
        $result = [
            'processed' => 0,
            'errors'    => 0,
            'details'   => [],
        ];

        if (empty($this->apiKey)) {
            Minz_Log::warning('AutoFilter: API key not configured, skipping background processing');
            return $result;
        }

        $tagDao = FreshRSS_Factory::createTagDao();
        $pendingTag = null;

        try {
            $pendingTag = $tagDao->searchByName(self::LABEL_PENDING);
        } catch (Exception $e) {
            Minz_Log::warning('AutoFilter: Failed to search pending tag: ' . $e->getMessage());
            return $result;
        }

        if ($pendingTag === null) {
            try {
                $tagId = $tagDao->addTag(['name' => self::LABEL_PENDING]);
                if ($tagId === false) {
                    Minz_Log::warning('AutoFilter: Pending label not found and could not be created');
                    return $result;
                }
                $pendingTag = $tagDao->searchByName(self::LABEL_PENDING);
            } catch (Exception $e) {
                Minz_Log::warning('AutoFilter: Failed to create pending tag: ' . $e->getMessage());
                return $result;
            }
        }

        if ($pendingTag === null) {
            Minz_Log::warning('AutoFilter: Pending label not found');
            return $result;
        }

        $model = new FreshExtension_AutoFilter_PendingEntries_Model();
        $entryIds = $model->getPendingEntryIds((int)$pendingTag->id(), $limit, $channelsFilter);

        if ($this->enableLogging) {
            Minz_Log::warning(sprintf(
                'AutoFilter: Found %d pending entries (tag_id=%d, limit=%d, channels=%s)',
                count($entryIds),
                $pendingTag->id(),
                $limit,
                empty($channelsFilter) ? 'all' : implode(',', $channelsFilter)
            ));
        }

        if (empty($entryIds)) {
            return $result;
        }

        $entryDao = FreshRSS_Factory::createEntryDao();

        foreach ($entryIds as $entryId) {
            $entry = $entryDao->searchById($entryId);
            if (!$entry) {
                $result['errors']++;
                $result['details'][] = ['entry_id' => $entryId, 'error' => 'Entry not found'];
                continue;
            }

            $analysisResult = $this->analyzeExistingEntry($entry);

            if (!$analysisResult['success']) {
                $errorMsg = $analysisResult['error'] ?? 'unknown error';
                $result['errors']++;
                $result['details'][] = [
                    'entry_id' => $entryId,
                    'error'    => $errorMsg,
                ];
                if ($this->enableLogging) {
                    Minz_Log::warning(sprintf(
                        'AutoFilter: Failed entry_id=%s error="%s"',
                        $entryId,
                        $errorMsg
                    ));
                }
                // Не удаляем метку "Непроверено" при ошибке, чтобы повторить позже
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
                continue;
            }

            // Удаляем метку "Непроверено"
            $model->removeTagFromEntry((int)$pendingTag->id(), $entryId);

            $result['processed']++;
            $result['details'][] = [
                'entry_id' => $entryId,
                'label'    => $analysisResult['analysis']['label'] ?? self::LABEL_NONE,
            ];

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        if ($this->enableLogging) {
            Minz_Log::warning(sprintf(
                'AutoFilter: Background processing done — processed=%d, errors=%d',
                $result['processed'],
                $result['errors']
            ));
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Построение промпта
    // -------------------------------------------------------------------------

    private function buildPrompt(FreshRSS_Entry $entry): string
    {
        $title   = $entry->title();
        $content = $this->truncateContent(strip_tags($entry->content()));
        $authors = $entry->authors();
        $author  = reset($authors) ?: '';
        $url     = $entry->link();

        $promptTemplate = !empty($this->prompt)
            ? self::sanitizeStoredPrompt($this->prompt)
            : self::getDefaultPromptTemplate();

        $prompt = str_replace(
            ['{title}', '{author}', '{url}', '{content}'],
            [$title,    $author,    $url,    $content],
            $promptTemplate
        );

        // Контекст источника: позволяет отличить ссылки автора на свой канал от рекламы чужих каналов
        $prompt .= "\n\nКАНАЛ-ИСТОЧНИК записи (автор, на которого подписан пользователь): "
            . self::resolveFeedName($entry)
            . "\nЛюбые ссылки и призывы, ведущие на этот же канал или его зеркала — НЕ реклама.";

        $handle = self::resolveFeedHandle($entry);
        if ($handle !== '') {
            $prompt .= "\nЮзернейм канала-источника: @" . $handle
                . "\nСсылки вида t.me/" . $handle . ", t.me/boost/" . $handle . " или на его зеркала — это САМ канал-источник, НЕ реклама.";
        }

        return $prompt . "\n\nВАЖНО: Значение поля \"reason\" пиши строго НА РУССКОМ ЯЗЫКЕ. "
        . 'Верни только чистый JSON без markdown и пояснений.';
    }

    /**
     * Название канала-источника записи (безопасно, даже если фид недоступен).
     */
    private static function resolveFeedName(FreshRSS_Entry $entry): string
    {
        try {
            $feed = $entry->feed(false);
            if ($feed !== null) {
                $name = trim((string)$feed->name());
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (Throwable $e) {
            // фид недоступен — не критично для анализа
        }
        return 'неизвестен';
    }

    /**
     * Юзернейм телеграм-канала источника: из ссылки записи (t.me/<handle>/<пост>)
     * или из URL фида rss-bridge (...username=<handle>). Пустая строка, если не телеграм.
     */
    private static function resolveFeedHandle(FreshRSS_Entry $entry): string
    {
        $link = (string)$entry->link();
        if (preg_match('~t\.me/([A-Za-z0-9_]+)/~i', $link, $m)) {
            return strtolower($m[1]);
        }
        try {
            $feed = $entry->feed(false);
            if ($feed !== null && preg_match('~[?&]username=([A-Za-z0-9_]+)~i', (string)$feed->url(), $m)) {
                return strtolower($m[1]);
            }
        } catch (Throwable $e) {
            // фид недоступен — не критично для анализа
        }
        return '';
    }

    /**
     * Восстанавливает промпт, испорченный многократным HTML-экранированием
     * (исторически Minz_Request::paramString кодировал кавычки в &quot; при каждом сохранении).
     */
    public static function sanitizeStoredPrompt(string $prompt): string
    {
        $decoded = $prompt;
        for ($i = 0; $i < 10; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        return trim($decoded);
    }

    public static function getDefaultPromptTemplate(): string
    {
        return <<<'PROMPT'
Ты должен определить, является ли следующая новостная запись рекламой или продвижением чужого телеграм-канала.

ВЕРНИ ТОЛЬКО JSON БЕЗ ПОЯСНЕНИЙ В ТАКОМ ФОРМАТЕ:
{
    "is_advertisement": true|false,
    "confidence": 0.0-1.0,
    "reason": "краткое объяснение на русском языке до 5 слов"
}

        КРИТЕРИИ РЕКЛАМЫ (is_advertisement = true):
1. Коммерческое продвижение или продажа КОНКРЕТНОГО товара, услуги, бренда или магазина
2. Призыв подписаться или перейти на ДРУГОЙ канал/соцсеть/чат (НЕ на автора самой записи), в том числе ссылки t.me на чужие каналы
3. Заманивание бесплатными товарами/услугами для привлечения клиентов/подписчиков
4. Сбор денег на покупку, лечение, помощь или отчет о сборе
5. Бесплатное или платное обучение чему-то
6. Призывы идти работать, служить в армию или еще куда-то
7. Указаны реквизиты карт, номера телефонов
8. Розыгрыши, бесплатные призы

НЕ РЕКЛАМА (is_advertisement = false):
- Обычные новости и статьи
- Призывы подписаться на свой канал (канал самой записи)
- Призыв подписаться на зеркало ЭТОГО ЖЕ канала в другом мессенджере (MAX, VK и т.п.), даже если приписка в конце поста
- Пост из одной картинки/видео со ссылкой только на канал-источник, без рекламного текста
- Общие советы, лайфхаки и рекомендации без указания конкретного товара, бренда, магазина или ссылки на покупку
- Личные мнения и блоги без коммерции

ПРИНЦИП: рекламой считай только ЯВНОЕ продвижение ЧУЖОГО канала/товара (чужое название, @ник, бренд).
Рекламные записи обычно содержат несколько предложений убеждающего текста; короткая ссылка или приписка на канал-источник рекламой НЕ является.
Новостной пост с короткой припиской-приглашением в мессенджер-зеркало (MAX, VK и т.п.) — НЕ реклама; рекламой считается пост, ЦЕЛИКОМ посвящённый продвижению чужого канала.
Если сомневаешься — отвечай is_advertisement = false с низкой confidence.

УРОВНИ УВЕРЕННОСТИ (насколько уверен, что это РЕКЛАМА):
- 0.9-1.0: явная реклама с прямым призывом к покупке
- 0.7-0.9: коммерческий контент с элементами продвижения
- 0.4-0.7: подозрительный контент, но неясно
- 0.0-0.3: точно не реклама

КОНТЕКСТ ЗАПИСИ:
- Заголовок: {title}
- Автор: {author}
- Ссылка: {url}
- Содержание: {content}
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
        if (empty($content)) {
            Minz_Log::warning('AutoFilter: Empty content in parseResponse');
            return [
                'is_advertisement' => false,
                'confidence'       => 0.0,
                'reason'           => 'Empty response from AI service',
                'label'            => self::LABEL_NONE,
            ];
        }

        // Убираем блоки размышлений и markdown-обёртку, если модель их вернула
        $cleaned = preg_replace('/<think>.*?<\/think>/is', '', $content) ?? $content;
        $cleaned = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        $json = json_decode($cleaned, true);

        // Fallback: модель добавила текст вокруг JSON — извлекаем объект целиком
        if (!is_array($json)) {
            if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
                $json = json_decode($matches[0], true);
            }
        }

        if (!is_array($json) || !isset($json['is_advertisement'], $json['confidence'])) {
            Minz_Log::warning('AutoFilter: Failed to parse JSON: ' . substr($cleaned, 0, 200));
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
        if (!$isAd) {
            // AI явно ответил "не реклама" — уверенность означает уверенность в отсутствии рекламы
            return self::LABEL_NONE;
        }
        if ($confidence >= $this->confidenceHigh) {
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
        $maxRetries = 2;
        $attempt    = 0;

        while (true) {
            $attempt++;

            $result = $this->callOpenRouterOnce($prompt);

            if ($result['success'] || ($result['http_code'] ?? 0) !== 429) {
                return $result;
            }

            if ($attempt <= $maxRetries) {
                $delay = random_int(1000, 3000);
                if ($this->enableLogging) {
                    Minz_Log::warning('AutoFilter: Rate limit hit, retrying in ' . ($delay / 1000) . 's (attempt ' . $attempt . '/' . $maxRetries . ')');
                }
                usleep($delay * 1000);
            } else {
                return $result;
            }
        }
    }

    /**
     * Единичный вызов OpenRouter API без повторных попыток
     *
     * @return array{success: bool, content?: string, error?: string, http_code?: int}
     */
    private function callOpenRouterOnce(string $prompt): array
    {
        $url     = 'https://openrouter.ai/api/v1/chat/completions';
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: FreshRSS-AutoFilter',
        ];
        $data = [
            'model'    => $this->model,
            // Низкая температура: классификация должна быть детерминированной
            'temperature' => 0.2,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            $jsonError = json_last_error_msg();
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: Failed to encode JSON: ' . $jsonError);
            }
            return ['success' => false, 'error' => 'JSON encode error: ' . $jsonError];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: CURL error: ' . $error);
            }
            return ['success' => false, 'error' => 'CURL error: ' . $error];
        }

        if ($httpCode !== 200) {
            $msg = $this->getHttpErrorMessage($httpCode, $response);
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: HTTP ' . $httpCode . ' — ' . $msg);
                Minz_Log::warning('AutoFilter: Request payload: ' . substr($payload, 0, 500));
            }
            return ['success' => false, 'error' => $msg, 'http_code' => $httpCode];
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['choices'][0]['message']['content'])) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: Invalid API response structure: ' . substr($response, 0, 200));
            }
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
            'AutoFilter: Processing title="%s"',
            self::formatTitleForLog($entry)
        ));
    }

    private function logAnalysisResult(FreshRSS_Entry $entry, array $analysis): void
    {
        Minz_Log::warning(sprintf(
            'AutoFilter: Result Label=%s Ad=%s Confidence=%.2f Reason="%s" Title="%s"',
            $analysis['label'] ?? 'N/A',
            !empty($analysis['is_advertisement']) ? 'yes' : 'no',
            $analysis['confidence'] ?? 0.0,
            $analysis['reason'] ?? 'N/A',
            self::formatTitleForLog($entry)
        ));
    }

    /**
     * Заголовок для лога: без обрыва многобайтовых символов UTF-8.
     */
    public static function formatTitleForLog(FreshRSS_Entry $entry): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string)$entry->title()) ?? '');
        if (mb_strlen($title) > self::MAX_LOG_TITLE_LENGTH) {
            $title = mb_substr($title, 0, self::MAX_LOG_TITLE_LENGTH) . '…';
        }
        return $title;
    }

    public function invalidateCache(): void
    {
        // Здесь можно реализовать логику очистки кэша, если она понадобится
    }
}
