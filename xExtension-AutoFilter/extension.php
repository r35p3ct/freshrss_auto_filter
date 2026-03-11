<?php

class AutoFilterExtension extends Minz_Extension
{
    public function init(): void
    {
        $this->registerHooks();
        $this->registerControllers();
        $this->registerTranslates();
    }

    private function registerHooks(): void
    {
        Minz_ExtensionManager::addHook('entry_before_display', [$this, 'applyLabelStyles']);
        Minz_ExtensionManager::addHook('freshrss.user.init', [$this, 'initUserConfig']);
        Minz_ExtensionManager::addHook('entry_unread', [$this, 'onEntryUnread']);
        Minz_ExtensionManager::addHook('entry_insert', [$this, 'onEntryInsert']);
        Minz_ExtensionManager::addHook('js_before_dom_load', [$this, 'addScripts']);
        Minz_ExtensionManager::addHook('css_loaded', [$this, 'addStyles']);
    }

    private function registerControllers(): void
    {
        $this->registerController('openrouter');
        $this->registerController('configuration');
    }

    public function initConfig(): void
    {
        $this->initUserConfig();
    }

    public function initUserConfig(): void
    {
        if (!FreshRSS_Context::$user_conf->hasParam('auto_filter_openrouter_api_key')) {
            FreshRSS_Context::$user_conf->auto_filter_openrouter_api_key = '';
            FreshRSS_Context::$user_conf->auto_filter_openrouter_model = 'openai/gpt-3.5-turbo';
            FreshRSS_Context::$user_conf->auto_filter_enabled = true;
            FreshRSS_Context::$user_conf->auto_filter_check_on_fetch = true;
            FreshRSS_Context::$user_conf->auto_filter_action_on_advertisement = 'hide';
            FreshRSS_Context::$user_conf->auto_filter_action_on_possible_advertisement = 'lower';
            FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_high = 0.8;
            FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_low = 0.5;
            FreshRSS_Context::$user_conf->save();
        }
    }

    public function getAutoFilterConfig(string $key, $default = null)
    {
        $userConf = FreshRSS_Context::$user_conf;
        $paramName = 'auto_filter_' . $key;
        if (isset($userConf->$paramName)) {
            return $userConf->$paramName;
        }
        return $default;
    }

    public function setAutoFilterConfig(string $key, $value): void
    {
        $paramName = 'auto_filter_' . $key;
        FreshRSS_Context::$user_conf->$paramName = $value;
        FreshRSS_Context::$user_conf->save();
    }

    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (!Minz_Request::isPost()) {
            return;
        }

        FreshRSS_Context::$user_conf->auto_filter_openrouter_api_key = Minz_Request::param('auto_filter_openrouter_api_key', '');
        FreshRSS_Context::$user_conf->auto_filter_openrouter_model = Minz_Request::param('auto_filter_openrouter_model', 'openai/gpt-3.5-turbo');
        FreshRSS_Context::$user_conf->auto_filter_enabled = Minz_Request::param('auto_filter_enabled', '0') === '1';
        FreshRSS_Context::$user_conf->auto_filter_check_on_fetch = Minz_Request::param('auto_filter_check_on_fetch', '0') === '1';
        FreshRSS_Context::$user_conf->auto_filter_action_on_advertisement = Minz_Request::param('auto_filter_action_on_advertisement', 'hide');
        FreshRSS_Context::$user_conf->auto_filter_action_on_possible_advertisement = Minz_Request::param('auto_filter_action_on_possible_advertisement', 'lower');
        FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_high = floatval(Minz_Request::param('auto_filter_confidence_threshold_high', 0.8));
        FreshRSS_Context::$user_conf->auto_filter_confidence_threshold_low = floatval(Minz_Request::param('auto_filter_confidence_threshold_low', 0.5));
        FreshRSS_Context::$user_conf->save();

        Minz_Request::good(_t('feedback.config.saved'), ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]]);
    }

    public function applyLabelStyles($entry): ?FreshRSS_Entry
    {
        if (!$entry) {
            return $entry;
        }

        $tags = $entry->tags();
        $css = '';

        if (in_array('advertisement', $tags)) {
            $css .= 'background-color: #ffebee !important; ';
            $css .= 'border-left: 4px solid #f44336 !important; ';
        } elseif (in_array('possible_advertisement', $tags)) {
            $css .= 'background-color: #fff3e0 !important; ';
            $css .= 'border-left: 4px solid #ff9800 !important; ';
        }

        if ($css) {
            $entry->_content('<div style="' . $css . '">' . $entry->content() . '</div>');
        }

        return $entry;
    }

    public function onEntryUnread($entry): ?FreshRSS_Entry
    {
        if (!$entry || !$this->getAutoFilterConfig('check_on_fetch')) {
            return $entry;
        }

        $apiKey = $this->getAutoFilterConfig('openrouter_api_key');
        if (empty($apiKey)) {
            Minz_Log::debug('AutoFilter: API key not configured, skipping check for entry ' . $entry->id());
            return $entry;
        }

        Minz_Log::debug('AutoFilter: Starting check for entry ' . $entry->id() . ' - ' . substr($entry->title(), 0, 50));

        // Асинхронная проверка через cURL в фоне
        $apiUrl = Minz_Url::display([
            'c' => 'autoFilter_openrouter',
            'a' => 'checkEntry',
            'params' => ['entry_id' => $entry->id()],
        ], 'html', true);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Minz_Log::warning('AutoFilter: cURL error for entry ' . $entry->id() . ': ' . $error);
        }

        return $entry;
    }

    public function onEntryInsert($entry): ?FreshRSS_Entry
    {
        if (!$entry || !$this->getAutoFilterConfig('check_on_fetch')) {
            return $entry;
        }

        $apiKey = $this->getAutoFilterConfig('openrouter_api_key');
        if (empty($apiKey)) {
            Minz_Log::debug('AutoFilter: API key not configured, skipping check for entry ' . $entry->id());
            return $entry;
        }

        Minz_Log::debug('AutoFilter: Starting check for NEW entry ' . $entry->id() . ' - ' . substr($entry->title(), 0, 50));

        // Асинхронная проверка через cURL в фоне
        $apiUrl = Minz_Url::display([
            'c' => 'autoFilter_openrouter',
            'a' => 'checkEntry',
            'params' => ['entry_id' => $entry->id()],
        ], 'html', true);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Minz_Log::warning('AutoFilter: cURL error for NEW entry ' . $entry->id() . ': ' . $error);
        }

        return $entry;
    }

    public function addScripts(): void
    {
        $scriptUrl = $this->getResourceUrl('static/autofilter.js');
        Minz_View::appendScript($scriptUrl);
    }

    public function addStyles(): void
    {
        $styleUrl = $this->getResourceUrl('static/autofilter.css');
        Minz_View::appendStyle($styleUrl);
    }
}
