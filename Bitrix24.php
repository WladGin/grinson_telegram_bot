<?php

use Monolog\Logger;


class Bitrix24
{
    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var Logger
     */
    private $log;

    public function __construct(string $baseUrl, Logger $log)
    {
        if (empty($baseUrl)) {
            throw new RuntimeException('Base url required for ' . self::class);
        }

        $this->baseUrl = $baseUrl;
        $this->log = $log;
    }

    /**
     * @param string $login
     * @return array
     */
    public function findContactByLogin(string $login): array
    {
        return $this->call('/crm.contact.list.json', [
            'filter' => [
                'UF_CRM_1578932359' => $login,
            ],
        ]);
    }

    /**
     * @param $name
     * @param $last_name
     * @param $start_date
     * @param $car
     * @param $contact_id
     * @param $chatID
     * @param $date_bitrix
     * @return array
     */
    public function addDeal($name, $last_name, $start_date, $car, $contact_id, $chatID, $date_bitrix): array
    {
        $title = sprintf('Водитель %s %s начал смену в %s', $last_name, $name, $start_date);

        return $this->call('/crm.deal.add.json', [
            'fields' => [
                'TITLE' => $title,
                'CONTACT_ID' => $contact_id,
                'UF_CRM_1578933835' => $car[0], // номер борта
                'UF_CRM_1579002325' => $car[1], // номер авто
                'UF_CRM_1578996635' => $chatID, // chatID
                'UF_CRM_1579002168' => $date_bitrix, // $date_bitrix
                //"OPPORTUNITY" => $defaults["price"],
                'COMMENTS' => $title . ' на машине ' . $car[0],
            ],
        ]);
    }

    public function updateDeal($id, $comments, $flag, $date, $hours = 0): array
    {
        $query = [];

        switch ($flag) {
            case 'start_TO':
                $query = [
                    'id' => $id,
                    'fields' => [
                        'COMMENTS' => $comments,
                        'UF_CRM_1579002195' => $date, // start date for TO
                    ],
                ];
                break;
            case 'finish_TO':
                $query = [
                    'id' => $id,
                    'fields' => [
                        'COMMENTS' => $comments,
                        'UF_CRM_1579002216' => $date, // finish date for TO
                    ],
                ];
                break;
            case 'finish_work_shift':
                $query = [
                    'id' => $id,
                    'fields' => [
                        'COMMENTS' => $comments,
                        'UF_CRM_1579002147' => $date, // finish date for close
                        'STAGE_ID' => '1',
                        'CATEGORY_ID' => 0,
                    ],
                ];
                break;
            case 'work_shift':
                $query = [
                    'id' => $id,
                    'fields' => [
                        'COMMENTS' => $comments,
                        'UF_CRM_1579175377' => $hours,
                    ],
                ];
                break;
            default:
                $this->log->debug('Unexpected flag on update deal', compact($id, $comments, $flag, $date, $hours));
        }

        return $this->call('/crm.deal.update.json', $query);
    }

    /**
     * @param $car
     * @return array
     */
    public function dealListCar($car): array
    {
        return $this->call('/crm.deal.list.json', [
            'filter' => [
                'UF_CRM_1578933835' => $car,
                'STAGE_ID' => 'NEW',
                'CATEGORY_ID' => 0,
            ],
        ]);
    }

    /**
     * @param $chatID
     * @return array
     */
    public function dealListChat($chatID): array
    {
        return $this->call('/crm.deal.list.json', [
            'filter' => [
                'UF_CRM_1578996635' => $chatID, // Id Chat telegram
                'STAGE_ID' => 'NEW',
                'CATEGORY_ID' => 0,
            ]
        ]);
    }

    private function call(string $url, array $query): array
    {
        $this->log->debug('Send request to bitrix24', ['url' => $url, 'query' => $query]);
        if (empty($url)) {
            $this->log->warning('Empty request url');
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $this->baseUrl . $url,
            CURLOPT_POSTFIELDS => http_build_query($query),
        ]);
        $result = curl_exec($curl);
        $status = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($status !== 0) {
            throw new RuntimeException("Error on request '{$url}': " . $error);
        }

        $result = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Error on parse response '{$url}': " . json_last_error_msg());
        }

        $this->log->debug('Receive response from bitrix24', ['url' => $url, 'query' => $query, 'response' => $result]);

        return $result;
    }
}