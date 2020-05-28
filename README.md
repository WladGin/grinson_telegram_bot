Перед запуском необходимо создать файл конфигурации ```config.php```

Пример файла:
```php
<?php

return [
    'logDir' => __DIR__ . DIRECTORY_SEPARATOR . 'logs', // путь к папке для хранения логов
    'logLevel' => \Monolog\Logger::DEBUG, // уровень логгирования
    'botToken' => '**************:*****************', // АПИ ключ бота Telegram
    'bitrix24Url' => 'https://******.bitrix24.ua/rest/6/**********', // ссылка на битрикс клиента
];
```
