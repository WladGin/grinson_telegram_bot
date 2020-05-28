<?php


require_once 'Bitrix24.php';

use Monolog\Logger;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\Update;

class Telegram
{
    /**
     * @var Client|BotApi
     */
    private $bot;

    /**
     * @var Logger
     */
    private $log;

    /**
     * @var Bitrix24
     */
    private $bitrix;

    private $supportTextCommands = ['Начать смену', 'Закрыть смену'];

    public function __construct(string $token, Bitrix24 $bitrix24, Logger $log)
    {
        if (empty($token)) {
            throw new RuntimeException('Token required for ' . self::class);
        }

        $this->bot = new Client($token);
        $this->bitrix = $bitrix24;
        $this->log = $log;

        $this->checkWebhook();
    }

    /**
     * @param $chatId
     * @param string $message
     * @param string $maintenance
     * @param string $errorShiftEnd
     * @throws \TelegramBot\Api\Exception
     * @throws \TelegramBot\Api\InvalidArgumentException
     */
    public function startFromBitrix24($chatId, $message = '', $maintenance = '', $errorShiftEnd = ''): void
    {
        $this->log->debug('Handle request from bitrix', compact($chatId, $message, $maintenance, $errorShiftEnd));

        // если с битрикса прилетает тех обслуживание - выводим сообщ и доп Inline кнопку
        if ($maintenance === 'open') {
            $this->bot->sendMessage(
                $chatId,
                $message,
                null,
                false,
                null,
                $this->inlineKeyboard([[['callback_data' => 'start_maintenance', 'text' => 'Начать Техническое обслуживание']]])
            );
        }

        if ($errorShiftEnd === 'return_start') {
            $this->bot->sendMessage(
                $chatId,
                'Вы не закрыли Техническое обслуживание! Закройте сначала тех.обслуживание',
                null,
                false,
                null,
                $this->replyKeyboard([[['text' => 'Закрыть смену']]])
            );
        }
    }

    public function startFromTelegram(): void
    {
        $this->log->debug('Handle request from telegram', ['update' => $this->bot->getRawBody()]);

        // обязательное. Запуск бота
        $this->bot->command('start', function (Message $message) {
            $this->log->debug('Run start command', ['from' => $message->getChat()->getId()]);

            $answer = 'Добро пожаловать! Начните смену';
            //$bot->sendMessage($message->getChat()->getId(), $answer);
            $this->bot->sendMessage(
                $message->getChat()->getId(),
                $answer,
                null,
                false,
                null,
                $this->replyKeyboard([[['text' => 'Начать смену']]])
            );
        });

        // помощь
        $this->bot->command('help', function (Message $message) {
            $this->log->debug('Run help command', ['from' => $message->getChat()->getId()]);

            $this->bot->sendMessage($message->getChat()->getId(), "*Команды:*\n/help - помощь", 'Markdown');
        });

        $this->bot->on(function (Update $update) {
            $callback = $update->getCallbackQuery();
            $message = $callback->getMessage();
            $chatId = $message->getChat()->getId();
            $data = $callback->getData();
            $date = date('Y-m-d H:i:s');
            $date_bitrix = date('c');
            $deal = $this->bitrix->dealListChat($chatId);

            $this->log->debug('Start Handle callback query', compact($chatId, $message, $data, $date, $deal));
            sleep(1);

            switch ($data) {
                case 'start_maintenance':
                    $this->bot->answerCallbackQuery($callback->getId());
                    $this->bot->sendMessage(
                        $chatId,
                        sprintf('Тех. Обслуживание началось! Дата начала: %s', $date),
                        null,
                        false,
                        null,
                        $this->inlineKeyboard([[['callback_data' => 'finish_maintenance', 'text' => 'Закончить Техническое обслуживание']]])
                    );

                    $comments = sprintf('%s<br/>Водитель начал тех. обслуживание. Дата начала: %s', $deal['result'][0]['COMMENTS'], $date);
                    $this->bitrix->updateDeal($deal['result'][0]['ID'], $comments, 'start_TO', $date_bitrix);
                    break;
                case 'finish_maintenance':
                    //$bot->answerCallbackQuery( $callback->getId(), "Тех. Обслуживание закончилось! Дата начала: " . date("Y-m-d H:i:s"),true);
                    $this->bot->answerCallbackQuery($callback->getId());
                    $this->bot->sendMessage($chatId, 'Тех. Обслуживание закончилось! Дата окончания: ' . $date);

                    $comments = sprintf('%s<br/>Водитель закончил тех. обслуживание. Дата окончания: %s', $deal['result'][0]['COMMENTS'], $date);
                    $this->bitrix->updateDeal($deal['result'][0]['ID'], $comments, 'finish_TO', $date_bitrix);
                    break;
                case 'work_shift_12':
                case 'work_shift_24':
                    $hours = $data === 'work_shift_12' ? 12 : 24;
                    $this->bot->answerCallbackQuery($callback->getId());
                    $this->bot->sendMessage(
                        $chatId,
                        'Ваша смена открыта! Дата начала: ' . $date,
                        null,
                        false,
                        null,
                        $this->replyKeyboard([[['text' => 'Закрыть смену']]])
                    );

                    $comments = sprintf('%s<br/>Водитель выбрал смену: %s ч.', $deal['result'][0]['COMMENTS'], $hours);
                    sleep(1);
                    $this->bitrix->updateDeal($deal['result'][0]['ID'], $comments, 'work_shift', $date_bitrix, $hours);
                    break;
                default:
                    $this->bot->answerCallbackQuery($callback->getId());
                    $this->log->debug('Undefined callback command');
                    break;
            }
            $this->log->debug('End Handle callback query', compact($chatId, $message, $data, $date, $deal));
        }, static function (Update $update) {
            $callback = $update->getCallbackQuery();

            return $callback !== null && !empty($callback->getData());
        });

        // Отлов любых сообщений + обработка reply-кнопок
        $this->bot->on(function (Update $update) {
            $message = $update->getMessage();
            $mtext = $message->getText();
            $chatId = $message->getChat()->getId();
            $login = $message->getFrom()->getUsername();
            $contact_bitrix = $this->bitrix->findContactByLogin($login);
            $date = date('Y-m-d H:i:s');
            $date_bitrix = date('c');

            $this->log->debug('Start Handle update', compact($chatId, $mtext, $date));

            if (mb_stripos($mtext, 'Начать смену') !== false) {
                if ($contact_bitrix['total'] == 0){
                    $this->bot->sendMessage($chatId, 'Доступ запрещен, обратитесь к вашему менеджеру');
                    $this->log->debug('Login isn`t set', $contact_bitrix);
                }else{
                    $this->bot->sendMessage($chatId, 'Введите номер борта:');
                    $this->log->debug('Login is set', $contact_bitrix);
                }

            }

            if (mb_stripos($mtext, 'Закрыть смену') !== false) {
                $this->bot->sendMessage(
                    $chatId,
                    'Ваша смена Закрыта! Дата закрытия: ' . $date,
                    null,
                    false,
                    null,
                    $this->replyKeyboard([[['text' => 'Начать смену']]])
                );
                $deal = $this->bitrix->dealListChat($chatId);

                $comments = sprintf('%s<br/>Водитель закрыл смену. Дата окончания: %s', $deal['result'][0]['COMMENTS'], $date);
                $this->bitrix->updateDeal($deal['result'][0]['ID'], $comments, 'finish_work_shift', $date_bitrix);
            }

            $data = kama_parse_csv_file('grinson.csv');

            $result = [];
//            $message = str_replace(" ", "", $mtext);

            foreach ($data as $car) {
                $car1 = str_replace(' ', '', $car[0]);
                if ($car1 == $mtext) {
                    $result[0] = $car1;
                    $result[1] = $car[2];
                    $this->log->debug('Find car', ['car' => $result]);
                    break;
                }
            }

            if (!empty($result)) {
                $dealList = $this->bitrix->dealListCar($result);
                if ($dealList['total'] === 0) {

                    $this->bot->sendMessage($message->getChat()->getId(), sprintf("Номер вашего борта: %s\r\nНомер машины: %s", $result[0], $result[1]));
                    $this->bot->sendMessage(
                        $message->getChat()->getId(),
                        'Выберите тип смены:',
                        null,
                        false,
                        null,
                        $this->inlineKeyboard([[
                            ['callback_data' => 'work_shift_12', 'text' => 'На 12 часов'], ['callback_data' => 'work_shift_24', 'text' => 'На 24 часа']
                        ]])
                    );

                    $this->bitrix->addDeal($contact_bitrix['result'][0]['NAME'], $contact_bitrix['result'][0]['LAST_NAME'], $date, $result, $contact_bitrix['result'][0]['ID'], $message->getChat()->getId(), $date_bitrix);
                } else {
                    $this->bot->sendMessage($message->getChat()->getId(), 'К сожалению машина занята кем-то другим, проверьте правильность номера или позвоните в службу поддержки');
                }
            } elseif (empty($result) && !in_array($mtext, $this->supportTextCommands, true)) {
                $this->bot->sendMessage($message->getChat()->getId(), 'Номер вашего борта нет в нашей базе, попробуйте ввести ещё раз ');
            }

            $this->log->debug('End Handle update', compact($chatId, $mtext, $date));
        }, static function (Update $update) {
            $message = $update->getMessage();

            return $message !== null && !empty($message->getText());
        });

        // запускаем обработку
        if (empty($this->bot->getRawBody())) {
            throw new RuntimeException('Empty telegram request');
        }

        $this->bot->run();
    }

    private function replyKeyboard(array $buttons): ReplyKeyboardMarkup
    {
        return new ReplyKeyboardMarkup($buttons, true, true);
    }

    private function inlineKeyboard(array $buttons): InlineKeyboardMarkup
    {
        return new InlineKeyboardMarkup($buttons);
    }

    private function checkWebhook(): void
    {
        if (!file_exists('registered.trigger')) {
            /**
             * файл registered.trigger будет создаваться после регистрации бота.
             * если этого файла нет значит бот не зарегистрирован
             */

            // URl текущей страницы
            $page_url = 'https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
            $result = $this->bot->setWebhook($page_url);
            if ($result) {
                file_put_contents('registered.trigger', time() . '-----' . $page_url); // создаем файл дабы прекратить повторные регистрации
                $this->log->debug('Webhook successful setup', ['url' => $page_url]);
            } else {
                $this->log->error('Error on setup webhook url', ['url' => $page_url, 'error' => $result]);
            }
        } else {
            $this->log->debug('Webhook trigger file already exist');
        }
    }
}