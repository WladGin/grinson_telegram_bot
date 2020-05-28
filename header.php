<?php
require_once "crest.php";

function writeToLog($data, $title = '')
{
    $log = "\n------------------------\n";
    $log .= date("Y.m.d G:i:s") . "\n";
    $log .= (strlen($title) > 0 ? $title : 'DEBUG') . "\n";
    $log .= print_r($data, 1);
    $log .= "\n------------------------\n";
    file_put_contents('/home/grins153/public_html/chatbot_v2/hook_deal.log', $log, FILE_APPEND);
    return true;
}

echo getcwd();

if (array_key_exists('data', $_REQUEST)) {
    writeToLog($_REQUEST, 'webform');

    $newDeal = CRest::call(
        "crm.deal.list",
        [
            'filter' => [
                "ID" => $_REQUEST["data"]["FIELDS"]["ID"],
            ],
            "select" => ["ID", "UF_CRM_1578933835"]
        ]

    );

    writeToLog($newDeal, '$newDeal');

    $secondDeal = CRest::call(
        "crm.deal.list",
        [
            'filter' => [
                "UF_CRM_1578933835" => $newDeal["result"]["0"]["UF_CRM_1578933835"],
                "STAGE_ID" => "1",
                //"CATEGORY_ID" => 0,
            ],
            "select" => ["ID", "UF_CRM_1579002147"]
        ]

    );

    writeToLog($secondDeal, '$secondDeal');

    if ($secondDeal['total'] == 0) {
        die();
    } else {
        $closeDeal = CRest::call(
            "crm.deal.update",
            [
                'id' => $secondDeal["result"]["0"]["ID"],
                'fields' => [
                    "STAGE_ID" => "WON",
                    "CATEGORY_ID" => 0,
                ]
            ]
        );

        sleep(1);

        $updateFirstDeal = CRest::call(
            "crm.deal.update",
            [
                'id' => $newDeal["result"]["0"]["ID"],
                'fields' => [
                    "UF_CRM_1579253260" =>$secondDeal["result"]["0"]["UF_CRM_1579002147"],
                ]
            ]
        );
    }
}