#!/usr/bin/env php
<?php
/**
 * Фоновый скрипт для проверки записей с меткой "Непроверено" через AI.
 *
 * Рекомендуется добавить в cron (каждые 5 минут):
 *   php /path/to/freshrss/extensions/xExtension-AutoFilter/scripts/process_pending.php
 *
 * Пример cron-записи:
 *   /5 * * * * docker exec freshrss php /var/www/FreshRSS/extensions/xExtension-AutoFilter/scripts/process_pending.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Находим корень FreshRSS
// ---------------------------------------------------------------------------

$freshrssRoot = null;
$possiblePaths = [
    __DIR__ . '/../../../',
    __DIR__ . '/../../../../',
    __DIR__ . '/../../../../../',
];

foreach ($possiblePaths as $path) {
    $realPath = realpath($path);
    if ($realPath && file_exists($realPath . '/constants.php')) {
        $freshrssRoot = $realPath;
        break;
    }
}

if ($freshrssRoot === null) {
    fwrite(STDERR, "Failed to find FreshRSS root directory.\n");
    fwrite(STDERR, "Please run this script from inside the extension directory.\n");
    exit(1);
}

require $freshrssRoot . '/constants.php';
require LIB_PATH . '/lib_rss.php';
require LIB_PATH . '/lib_install.php';

Minz_Session::init('FreshRSS', true);
FreshRSS_Context::initSystem();
Minz_ExtensionManager::init();
Minz_Translate::init(Minz_Translate::DEFAULT_LANGUAGE);

FreshRSS_Context::$isCli = true;

if (!FreshRSS_Context::hasSystemConf()) {
    fwrite(STDERR, "Failed to initialize FreshRSS system context.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Параметры
// ---------------------------------------------------------------------------

$extensionName = 'AutoFilter';
$mutexFile = TMP_PATH . '/autofilter_background.lock';
$mutexTtl = 600; // 10 минут — если скрипт завис

// ---------------------------------------------------------------------------
// Mutex: не запускать несколько копий одновременно
// ---------------------------------------------------------------------------

if (file_exists($mutexFile) && ((time() - (@filemtime($mutexFile) ?: 0)) > $mutexTtl)) {
    @unlink($mutexFile);
}

if (($handle = @fopen($mutexFile, 'x')) === false) {
    fwrite(STDERR, "AutoFilter background process is already running.\n");
    exit(0);
}

fclose($handle);
register_shutdown_function(static function () use ($mutexFile) {
    @unlink($mutexFile);
});

// ---------------------------------------------------------------------------
// Вспомогательные функции
// ---------------------------------------------------------------------------

function notice(string $message): void {
    Minz_Log::notice($message, ADMIN_LOG);
    if (defined('STDOUT')) {
        fwrite(STDOUT, $message . "\n");
    }
}

// ---------------------------------------------------------------------------
// Обрабатываем каждого пользователя
// ---------------------------------------------------------------------------

$users = FreshRSS_user_Controller::listUsers();
$totalProcessed = 0;
$totalErrors = 0;

foreach ($users as $user) {
    FreshRSS_Context::initUser($user);

    if (!FreshRSS_Context::hasUserConf()) {
        notice("AutoFilter: Skip invalid user {$user}");
        continue;
    }

    if (!FreshRSS_Context::userConf()->enabled) {
        notice("AutoFilter: Skip disabled user {$user}");
        continue;
    }

    FreshRSS_Auth::giveAccess();

    $app = new FreshRSS();
    $app->init();

    // Явно включаем пользовательские расширения
    $extList = FreshRSS_Context::userConf()->extensions_enabled ?? [];
    Minz_ExtensionManager::enableByList($extList, 'user');

    // Проверяем, включено ли расширение у данного пользователя
    $isEnabled = false;
    if (is_array($extList)) {
        $isEnabled = isset($extList[$extensionName]) || in_array($extensionName, $extList, true);
    }
    if (!$isEnabled) {
        notice("AutoFilter: Extension not enabled for user {$user}, skipping.");
        continue;
    }

    // Находим активное расширение в менеджере
    $extension = Minz_ExtensionManager::findExtension($extensionName);
    if ($extension === null) {
        notice("AutoFilter: Extension not loaded for user {$user}");
        continue;
    }

    // Получаем актуальный конфиг из расширения
    $backgroundMode = (bool)$extension->getSystemConfigurationValue('background_mode');
    if (!$backgroundMode) {
        notice("AutoFilter: Background mode is disabled for user {$user}. Skipping.");
        continue;
    }

    $batchSize = (int)($extension->getSystemConfigurationValue('batch_size') ?? 5);
    $requestDelayMs = (int)($extension->getSystemConfigurationValue('request_delay_ms') ?? 2000);
    $channelsFilter = $extension->getSystemConfigurationValue('channels_filter') ?? [];

    $config = [
        'openrouter_api_key'        => $extension->getSystemConfigurationValue('openrouter_api_key'),
        'openrouter_model'          => $extension->getSystemConfigurationValue('openrouter_model'),
        'confidence_threshold_high' => $extension->getSystemConfigurationValue('confidence_threshold_high'),
        'prompt'                    => $extension->getSystemConfigurationValue('prompt'),
        'enable_logging'            => $extension->getSystemConfigurationValue('enable_logging'),
    ];

    notice(sprintf(
        'AutoFilter: User %s config — batch=%d, delay=%dms, background=%s, channels=%s',
        $user,
        $batchSize,
        $requestDelayMs,
        $backgroundMode ? 'on' : 'off',
        empty($channelsFilter) ? 'all' : implode(',', $channelsFilter)
    ));

    $controller = new FreshExtension_AutoFilter_openrouter_Controller($config);
    $result = $controller->processPendingEntries($batchSize, $requestDelayMs, $channelsFilter);

    $totalProcessed += $result['processed'];
    $totalErrors += $result['errors'];

    if ($result['processed'] > 0 || $result['errors'] > 0) {
        notice(sprintf(
            'AutoFilter: User %s — processed=%d, errors=%d',
            $user,
            $result['processed'],
            $result['errors']
        ));
    }

    gc_collect_cycles();
}

notice(sprintf(
    'AutoFilter: Background check finished — total processed=%d, total errors=%d',
    $totalProcessed,
    $totalErrors
));

exit(0);
