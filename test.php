<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\ElementPropertyTable;

Loader::includeModule('iblock');

// ID города, по которому фильтруем мероприятия
$cityIdFilter = 36;

// Текущая дата в формате timestamp для сравнения с ACTIVE_FROM/ACTIVE_TO
$today = strtotime(date('d.m.Y'));

// Получаем список всех городов из инфоблока с ID=5
$cities = [];
$cityRes = ElementTable::getList([
    'filter' => ['IBLOCK_ID' => 5, 'ACTIVE' => 'Y'], // только активные города
    'select' => ['ID', 'NAME'] // выбираем только ID и название
]);
while ($city = $cityRes->fetch()) {
    $cities[$city['ID']] = $city['NAME']; // создаем массив [ID => NAME] для удобного поиска
}

// Получаем все активные мероприятия из инфоблока с ID=6
$events = [];
$eventRes = ElementTable::getList([
    'filter' => ['IBLOCK_ID' => 6, 'ACTIVE' => 'Y'], // только активные мероприятия
    'select' => ['ID', 'NAME', 'ACTIVE_FROM', 'ACTIVE_TO'] // основные поля
]);

$eventIds = [];
while ($event = $eventRes->fetch()) {
    $eventIds[] = $event['ID']; // собираем ID мероприятий для выборки свойств CITY

    // Преобразуем объекты DateTime в строку для JSON
    $activeFrom = $event['ACTIVE_FROM'] ? $event['ACTIVE_FROM']->format("d.m.Y") : null;
    $activeTo = $event['ACTIVE_TO'] ? $event['ACTIVE_TO']->format("d.m.Y") : null;

    // Инициализируем структуру мероприятия
    $events[$event['ID']] = [
        'ID' => $event['ID'],
        'NAME' => $event['NAME'],
        'ACTIVE_FROM' => $activeFrom,
        'ACTIVE_TO' => $activeTo,
        'CITY_IDS' => [],       // сюда будем записывать ID городов
        'PARTICIPANTS' => []    // сюда будем записывать участников
    ];
}

// Получаем ID свойства CITY у инфоблока мероприятий
$cityProperty = CIBlockProperty::GetList([], ['IBLOCK_ID' => 6, 'CODE' => 'CITY'])->Fetch();
$cityPropId = $cityProperty['ID'] ?? 0; // если свойства нет — 0

// Получаем значения свойства CITY для всех мероприятий
if (!empty($eventIds) && $cityPropId) {
    $propRes = ElementPropertyTable::getList([
        'filter' => [
            'IBLOCK_ELEMENT_ID' => $eventIds,      // только для выбранных мероприятий
            'IBLOCK_PROPERTY_ID' => $cityPropId    // свойство CITY
        ],
        'select' => ['IBLOCK_ELEMENT_ID', 'VALUE'] // ID элемента и значение свойства (ID города)
    ]);
    while ($prop = $propRes->fetch()) {
        $events[$prop['IBLOCK_ELEMENT_ID']]['CITY_IDS'][] = (int)$prop['VALUE']; // связываем города с мероприятием
    }
}

// Фильтруем мероприятия по выбранному городу и текущей дате
$eventsFiltered = [];
foreach ($events as $event) {
    // Проверяем, есть ли выбранный город у мероприятия
    if (!in_array($cityIdFilter, $event['CITY_IDS'], true)) continue;

    // Преобразуем даты начала и окончания активности в timestamp для фильтрации
    $from = strtotime($event['ACTIVE_FROM']);
    $to   = $event['ACTIVE_TO'] ? strtotime($event['ACTIVE_TO']) : null;

    // Проверяем, что мероприятие активно на текущий день
    if ($from <= $today && (!$to || $to >= $today)) {
        // Преобразуем ID городов в имена
        $event['CITY'] = array_map(fn($id) => $cities[$id] ?? null, $event['CITY_IDS']);
        unset($event['CITY_IDS']); // больше не нужно
        $eventsFiltered[$event['ID']] = $event; // сохраняем отфильтрованное мероприятие
    }
}

// Получаем участников
$eventProperty = CIBlockProperty::GetList([], ['IBLOCK_ID' => 7, 'CODE' => 'EVENT'])->Fetch();
$eventPropId = $eventProperty['ID'] ?? 0;

if ($eventPropId) {
    // Получаем все связи участников с мероприятиями
    $participantProps = ElementPropertyTable::getList([
        'filter' => ['IBLOCK_PROPERTY_ID' => $eventPropId],
        'select' => ['IBLOCK_ELEMENT_ID', 'VALUE'] // ID участника и ID мероприятия
    ])->fetchAll();

    // Получаем имена участников отдельным запросом
    $participantIds = array_column($participantProps, 'IBLOCK_ELEMENT_ID');
    $participantNames = [];
    if (!empty($participantIds)) {
        $res = ElementTable::getList([
            'filter' => ['ID' => $participantIds],
            'select' => ['ID', 'NAME'] // получаем имя участника
        ]);
        while ($p = $res->fetch()) {
            $participantNames[$p['ID']] = $p['NAME'];
        }
    }

    // Привязываем участников к соответствующим мероприятиям
    foreach ($participantProps as $prop) {
        $participantId = (int)$prop['IBLOCK_ELEMENT_ID'];
        $eventId = (int)$prop['VALUE'];
        if (isset($eventsFiltered[$eventId])) {
            $eventsFiltered[$eventId]['PARTICIPANTS'][] = [
                'ID' => $participantId,
                'NAME' => $participantNames[$participantId] ?? ''
            ];
        }
    }
}

// Выводим результат в формате JSON
header('Content-Type: application/json');
echo json_encode(array_values($eventsFiltered), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
