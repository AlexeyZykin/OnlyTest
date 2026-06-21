<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

$arComponentDescription = [
    "NAME" => "Список доступных автомобилей",

    "DESCRIPTION" => "Выводит список свободных автомобилей для текущего сотрудника с учетом фильтров",

    "PATH" => [
        "ID" => "local",
        "NAME" => "Локальные компоненты",
        "CHILD" => [
            "ID" => "car_booking",
            "NAME" => "Бронирование автотранспорта",
            "SORT" => 10,
        ],
    ],
];