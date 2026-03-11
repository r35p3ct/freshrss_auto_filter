<?php

class FreshExtension_AutoFilter_openrouter_Controller extends FreshRSS_ActionController
{
    private string $apiKey;
    private string $model;
    private float $confidenceHigh;
    private float $confidenceLow;

    public function __construct()
    {
        $this->apiKey = FreshRSS_Context::$user_conf->auto_filter_openrouter_api_key ?? '';
        $this->model = FreshRSS_Context::$user_conf->auto_filter_openrouter_model ?? 'openai/gpt-3.5-turbo';
        $this->confidenceHigh = FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_high ?? 0.8;
        $this->confidenceLow = FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_low ?? 0.5;
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

    private function analyzeEntry(FreshRSS_Entry $entry): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }

        $prompt = $this->buildPrompt($entry);
        $response = $this->callOpenRouter($prompt);

        if (!$response['success']) {
            Minz_Log::warning('AutoFilter: API error for entry ' . $entry->id() . ': ' . ($response['error'] ?? 'unknown error'));
            return $response;
        }

        $analysis = $this->parseResponse($response['content']);
        
        // Логирование результата анализа
        Minz_Log::info(sprintf(
            'AutoFilter: Entry ID=%d, Title="%s", Label=%s, Confidence=%.2f, Reason=%s',
            $entry->id(),
            substr($entry->title(), 0, 50),
            $analysis['label'],
            $analysis['confidence'],
            $analysis['reason']
        ));
        
        if ($analysis['label'] === 'advertisement') {
            Minz_Log::warning('AutoFilter: RECLAME detected for entry ' . $entry->id() . ' - ' . $entry->title());
        } elseif ($analysis['label'] === 'possible_advertisement') {
            Minz_Log::notice('AutoFilter: POSSIBLE RECLAME for entry ' . $entry->id() . ' - ' . $entry->title());
        }
        
        $this->applyLabels($entry, $analysis);

        return [
            'success' => true,
            'entry_id' => $entry->id(),
            'analysis' => $analysis,
        ];
    }

    private function buildPrompt(FreshRSS_Entry $entry): string
    {
        $title = $entry->title();
        $content = strip_tags($entry->content());
        $author = $entry->authors()[0] ?? '';
        $url = $entry->link();

        return <<<PROMPT
Ты должен определить, является ли следующая новостная запись рекламой.

Заголовок: {$title}
Автор: {$author}
Ссылка: {$url}
Содержание: {$content}

Проанализируй запись и определи:
1. Является ли это рекламой (коммерческое продвижение товара/услуги)
2. Уровень уверенности от 0 до 1
3. Краткое обоснование решения

Верни ответ ТОЛЬКО в формате JSON:
{
    "is_advertisement": true/false,
    "confidence": 0.0-1.0,
    "reason": "краткое объяснение"
}
PROMPT;
    }

    private function callOpenRouter(string $prompt): array
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . $_SERVER['HTTP_HOST'] ?? 'localhost',
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
            return ['success' => false, 'error' => 'CURL error: ' . $error];
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'API error: ' . $httpCode . ' - ' . $response];
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['choices'][0]['message']['content'])) {
            return ['success' => false, 'error' => 'Invalid API response'];
        }

        return [
            'success' => true,
            'content' => $decoded['choices'][0]['message']['content'],
        ];
    }

    private function parseResponse(string $content): array
    {
        $json = json_decode($content, true);

        if (!$json) {
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
        $entryDao = FreshRSS_Factory::createEntryDao();
        $tags = $entry->tags();

        $removeLabels = ['advertisement', 'possible_advertisement'];
        $tags = array_filter($tags, fn($tag) => !in_array($tag, $removeLabels));

        if ($analysis['label'] !== 'none') {
            $tags[] = $analysis['label'];
        }

        $entry->_tags($tags);
        $entryDao->updateEntry($entry);

        $this->applyAutoAction($entry, $analysis['label']);
    }

    private function applyAutoAction(FreshRSS_Entry $entry, string $label): void
    {
        $entryDao = FreshRSS_Factory::createEntryDao();
        $actionAd = FreshRSS_Context::$user_conf->auto_filter_action_on_advertisement ?? 'hide';
        $actionPossible = FreshRSS_Context::$user_conf->auto_filter_action_on_possible_advertisement ?? 'lower';

        if ($label === 'advertisement') {
            if ($actionAd === 'hide' || $actionAd === 'read') {
                $entry->_isRead($actionAd === 'read');
                $entryDao->updateEntry($entry);
            }
        } elseif ($label === 'possible_advertisement') {
            if ($actionPossible === 'lower') {
                $entry->_date(time() - 86400);
                $entryDao->updateEntry($entry);
            }
        }
    }
}
