<?php

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require_once 'vendor/autoload.php';
require_once 'helpers.php';
require_once 'Telegram.php';

try {
    if (!file_exists('config.php')) {
        throw new \RuntimeException("Config file config.php doesn't exist");
    }

    $config = require 'config.php';

    if (!is_dir('logs') && !mkdir('logs', 755) && !is_dir('logs')) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', 'logs'));
    }

    $log = new Logger('logger');
    $log->pushHandler(new StreamHandler(sprintf('%s/app-%s.log', $config['logDir'], date('Y-m-d')), $config['logLevel'] ?? Logger::DEBUG));
} catch (Throwable $throwable) {
    jsonResponse(['status' => false, 'message' => $throwable->getMessage()]);
}

try {
    $bitrix = new Bitrix24($config['bitrix24Url'], $log);
    $telegram = new Telegram($config['botToken'], $bitrix, $log);

    $maintenance = isset($_GET['maintenance']) && !empty($_GET['maintenance']) ? $_GET['maintenance'] : '';
    $error_shift_end = isset($_GET['error_shift_end']) && !empty($_GET['error_shift_end']) ? $_GET['error_shift_end'] : '';
    $chatId = $_GET['chat_id'] ?? '';

    if (!empty($maintenance) || !empty($error_shift_end)) {
        if (empty($chatId)) {
            throw new RuntimeException('Empty chatId from Bitrix', ['maintenance' => $maintenance, 'errorShiftEnd' => $error_shift_end]);
        }

        $telegram->startFromBitrix24($chatId, $_GET['message'] ?? '', $maintenance, $error_shift_end);
    } else {
        $telegram->startFromTelegram();
    }

    jsonResponse(['status' => true]);
} catch (Throwable $throwable) {
    $log->error('Error on process request', [
        'message' => $throwable->getMessage(),
        'file' => $throwable->getFile(),
        'line' => $throwable->getLine(),
        'trace' => $throwable->getTrace(),
    ]);

    jsonResponse(['status' => false, 'message' => 'Error on process request']);
}
