<?php

class FreshExtension_AutoFilter_configuration_Controller extends FreshRSS_ActionController
{
    public function indexAction(): void
    {
        $this->view->api_key = FreshRSS_Context::$user_conf->auto_filter_openrouter_api_key ?? '';
        $this->view->model = FreshRSS_Context::$user_conf->auto_filter_openrouter_model ?? 'openai/gpt-3.5-turbo';
        $this->view->enabled = FreshRSS_Context::$user_conf->auto_filter_enabled ?? true;
        $this->view->check_on_fetch = FreshRSS_Context::$user_conf->auto_filter_check_on_fetch ?? true;
        $this->view->action_ad = FreshRSS_Context::$user_conf->auto_filter_action_on_advertisement ?? 'hide';
        $this->view->action_possible = FreshRSS_Context::$user_conf->auto_filter_action_on_possible_advertisement ?? 'lower';
        $this->view->threshold_high = FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_high ?? 0.8;
        $this->view->threshold_low = FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_low ?? 0.5;
    }

    public function checkEntryAction(): void
    {
        $entryId = Minz_Request::param('entry_id', 0);
        if (!$entryId) {
            $this->view->success = false;
            $this->view->message = 'Entry ID not provided';
            return;
        }

        $entryDao = FreshRSS_Factory::createEntryDao();
        $entry = $entryDao->searchById($entryId);

        if (!$entry) {
            $this->view->success = false;
            $this->view->message = 'Entry not found';
            return;
        }

        $openRouter = new FreshExtension_AutoFilter_openrouter_Controller();
        $result = $openRouter->checkEntryAction();

        $this->view->success = $result['success'];
        $this->view->message = $result['success']
            ? 'Label: ' . ($result['analysis']['label'] ?? 'none')
            : ($result['error'] ?? 'Unknown error');
        $this->view->analysis = $result['analysis'] ?? null;
    }

    public function testAction(): void
    {
        $apiKey = FreshRSS_Context::$user_conf->auto_filter_openrouter_api_key ?? '';
        $model = FreshRSS_Context::$user_conf->auto_filter_openrouter_model ?? 'openai/gpt-3.5-turbo';

        if (empty($apiKey)) {
            $this->view->success = false;
            $this->view->message = 'API key not set';
            return;
        }

        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => 'Test'],
            ],
            'max_tokens' => 10,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->view->success = false;
            $this->view->message = 'Connection error: ' . $error;
            return;
        }

        if ($httpCode === 200) {
            $this->view->success = true;
            $this->view->message = 'Connection successful! Model: ' . $model;
        } else {
            $this->view->success = false;
            $this->view->message = 'API error (' . $httpCode . '): ' . $response;
        }
    }
}
