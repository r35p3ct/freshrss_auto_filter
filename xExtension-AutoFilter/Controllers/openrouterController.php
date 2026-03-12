<?php

class FreshExtension_AutoFilter_openrouter_Controller extends FreshRSS_ActionController
{
    private string $apiKey;
    private string $model;
    private float $confidenceHigh;
    private float $confidenceLow;
    private string $prompt;
    private bool $enableLogging;

    /**
     * @param array<string, mixed> $config Конфигурация из getSystemConfigurationValue()
     */
    public function __construct(array $config = [])
    {
        $this->apiKey = $config['openrouter_api_key'] ?? '';
        $this->model = $config['openrouter_model'] ?? 'openai/gpt-3.5-turbo';
        $this->confidenceHigh = $config['confidence_threshold_high'] ?? 0.8;
        $this->confidenceLow = $config['confidence_threshold_low'] ?? 0.5;
        $this->prompt = $config['prompt'] ?? '';
        $this->enableLogging = !empty($config['enable_logging']);
        parent::__construct();
    }

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
     * Принимает объект FreshRSS_Entry напрямую.
     */
    public function analyzeEntryDirect(FreshRSS_Entry $entry): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }

        $prompt = $this->buildPrompt($entry);

        // Логируем промпт для отладки (только если включено логирование)
        if ($this->enableLogging) {
            Minz_Log::warning('AutoFilter: Prompt sent to API: ' . substr($prompt, 0, 500) . '...');
        }

        $response = $this->callOpenRouter($prompt);

        // При ошибке API - возвращаем ошибку, запись остаётся без меток
        if (!$response['success']) {
            if ($this->enableLogging) {
                Minz_Log::warning('AutoFilter: API error: ' . ($response['error'] ?? 'unknown'));
            }
            return $response;
        }

        // Логируем сырой ответ API (только если включено логирование)
        if ($this->enableLogging) {
            Minz_Log::warning('AutoFilter: Raw API response: ' . substr($response['content'], 0, 300));
        }

        $analysis = $this->parseResponse($response['content']);

        // Полное логирование результата анализа (только если включено логирование)
        if ($this->enableLogging) {
            Minz_Log::warning(sprintf(
                'AutoFilter: Entry ID=%d, Title="%s", Label=%s, Confidence=%.2f, Reason=%s, RawJSON=%s',
                $entry->id(),
                substr($entry->title(), 0, 50),
                $analysis['label'],
                $analysis['confidence'],
                $analysis['reason'],
                json_encode($analysis, JSON_UNESCAPED_UNICODE)
            ));
        }

        if ($analysis['label'] === 'advertisement') {
            Minz_Log::warning('AutoFilter: RECLAME detected for entry ' . $entry->id() . ' - ' . $entry->title());
        } elseif ($analysis['label'] === 'possible_advertisement') {
            Minz_Log::warning('AutoFilter: POSSIBLE RECLAME for entry ' . $entry->id() . ' - ' . $entry->title());
        }

        $this->applyLabels($entry, $analysis);

        return [
            'success' => true,
            'entry_id' => $entry->id(),
            'analysis' => $analysis,
        ];
    }

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
     * Внутренний метод анализа для вызова из checkEntryAction и checkBatchAction.
     * Использует поиск записи по ID из БД.
     */
    private function analyzeEntry(FreshRSS_Entry $entry): array
    {
        // Копия логики из analyzeEntryDirect для HTTP-вызовов
        return $this->analyzeEntryDirect($entry);
    }

    private function buildPrompt(FreshRSS_Entry $entry): string
    {
        $title = $entry->title();
        $content = strip_tags($entry->content());
        $author = $entry->authors()[0] ?? '';
        $url = $entry->link();

        // Промт по умолчанию, если не задан в настройках
        $defaultPrompt = <<<PROMPT
Ты должен определить, является ли следующая новостная запись рекламой.

Заголовок: {$title}
Автор: {$author}
Ссылка: {$url}
Содержание: {$content}

Проанализируй запись и определи:
1. Является ли это рекламой:
    a. коммерческое продвижение или продажа товара
    b. продвижение соцсети (призыв перейти или подписаться на другой канал или соцсеть), но если это призыв подписаться на автора сообщения, то игнорировать
    c. заманивание бесплатными услугами или товаром
2. Уровень уверенности от 0 до 1
3. Краткое обоснование решения

Верни ответ ТОЛЬКО в формате JSON:
{
    "is_advertisement": true/false,
    "confidence": 0.0-1.0,
    "reason": "краткое объяснение"
}
PROMPT;

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

        // CURL ошибки
        if ($error) {
            Minz_Log::warning('AutoFilter: CURL error: ' . $error);
            return ['success' => false, 'error' => 'CURL error: ' . $error];
        }

        // Обработка HTTP кодов
        if ($httpCode !== 200) {
            $errorMsg = $this->getHttpErrorMessage($httpCode, $response);
            Minz_Log::warning('AutoFilter: HTTP ' . $httpCode . ' - ' . $errorMsg);
            
            // При ошибках API (429, 503, 502, 504) или auth (401, 403) - не блокируем запись
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
        
        // Попытка извлечь сообщение из ответа API
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['error']['message'])) {
            $message .= ': ' . $decoded['error']['message'];
        }

        return $message;
    }

    private function parseResponse(string $content): array
    {
        $json = json_decode($content, true);

        // Если не удалось распарсить JSON
        if (!$json) {
            Minz_Log::warning('AutoFilter: Failed to parse JSON response: ' . substr($content, 0, 100));
            return [
                'is_advertisement' => false,
                'confidence' => 0.0,
                'reason' => 'Failed to parse AI response',
                'label' => 'none',
            ];
        }

        $isAd = $json['is_advertisement'] ?? false;
        $confidence = floatval($json['confidence'] ?? 0.0);
        $reason = $json['reason'] ?? 'No reason provided';

        // Валидация данных
        if (!isset($json['is_advertisement']) || !isset($json['confidence'])) {
            Minz_Log::warning('AutoFilter: Missing required fields in response: ' . $content);
            return [
                'is_advertisement' => false,
                'confidence' => 0.0,
                'reason' => 'Invalid response format',
                'label' => 'none',
            ];
        }

        $label = 'none';
        if ($isAd && $confidence >= $this->confidenceHigh) {
            $label = 'advertisement';
        } elseif ($confidence >= $this->confidenceLow) {
            $label = 'possible_advertisement';
        }

        return [
            'is_advertisement' => $isAd,
            'confidence' => $confidence,
            'reason' => $reason,
            'label' => $label,
        ];
    }

    private function applyLabels(FreshRSS_Entry $entry, array $analysis): void
    {
        $tags = $entry->tags() ?: [];

        // Удаляем старые метки
        $tags = array_filter($tags, fn($tag) => !in_array($tag, ['advertisement', 'possible_advertisement']));

        // Добавляем новую метку
        if ($analysis['label'] !== 'none') {
            $tags[] = $analysis['label'];
        }

        $entry->_tags($tags);
    }
}
