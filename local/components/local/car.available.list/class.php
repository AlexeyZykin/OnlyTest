<?php

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Context;
use Bitrix\Main\GroupTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

class CarAvailableList extends CBitrixComponent
{

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function onPrepareComponentParams($arParams): array
    {
        $arParams['POSITION_CATEGORY_HL_TABLE'] = trim((string)$arParams['POSITION_CATEGORY_HL_TABLE']);
        $arParams['COMFORT_CATEGORY_HL_TABLE'] = trim((string)$arParams['COMFORT_CATEGORY_HL_TABLE']);
        $arParams['CARS_IBLOCK_API_CODE'] = trim((string)$arParams['CARS_IBLOCK_API_CODE']);
        $arParams['CAR_BOOKINGS_IBLOCK_API_CODE'] = trim((string)$arParams['CAR_BOOKINGS_IBLOCK_API_CODE']);
        $arParams['DRIVERS_USER_GROUP_CODE'] = trim((string)$arParams['DRIVERS_USER_GROUP_CODE']);
        $arParams['EMPLOYEES_USER_GROUP_CODE'] = trim((string)$arParams['EMPLOYEES_USER_GROUP_CODE']);

        return $arParams;
    }


    public function executeComponent()
    {
        try {
            $this->validateParams();

            $this->checkModules();

            $this->checkAccess();

            $period = $this->getPeriodFromRequest();

            $allowedCategoryIds = $this->getAllowedComfortCategoryIds();
            if (empty($allowedCategoryIds)) {
                throw new AccessDeniedException('Нет доступных категорий комфорта авто для вашей должности');
            }

            $busyDrivers = $this->getBusyDriversByPeriod($period);

            $this->arResult['ITEMS'] = $this->getAvailableCars(
                categoryIds: $allowedCategoryIds,
                excludedDrivers: $busyDrivers
            );
        } catch (AccessDeniedException $e) {
            $this->arResult['ERROR'] = [
                'TYPE' => 'ACCESS_DENIED',
                'MESSAGE' => $e->getMessage()
            ];
            ShowError($e->getMessage());
        } catch (ObjectException|InvalidArgumentException $e) {
            $this->arResult['ERROR'] = [
                'TYPE' => 'INVALID_PARAMS',
                'MESSAGE' => $e->getMessage()
            ];
            ShowError($e->getMessage());
        } catch (Exception $e) {
            AddMessage2Log($e->getMessage());
            $this->arResult['ERROR'] = [
                'TYPE' => 'SERVER_ERROR',
                'MESSAGE' => $e->getMessage()
            ];
        }

        return $this->arResult;
    }

    private function validateParams(): void
    {
        $errors = [];
        if (empty($this->arParams['CARS_IBLOCK_API_CODE'])) {
            $errors[] = 'Не указан апи код инфоблока машин (CARS_IBLOCK_CODE).';
        }

        if (empty($this->arParams['CAR_BOOKINGS_IBLOCK_API_CODE'])) {
            $errors[] = 'Не указан апи код инфоблока бронирований (CAR_BOOKINGS_API_CODE).';
        }

        if (empty($this->arParams['POSITION_CATEGORY_HL_TABLE'])) {
            $errors[] = 'Не указано имя таблицы HL-блока соответствия должностей и категорий.';
        }

        if (empty($this->arParams['COMFORT_CATEGORY_HL_TABLE'])) {
            $errors[] = 'Не указано имя таблицы HL-блока категорий комфорта.';
        }

        if (empty($this->arParams['DRIVERS_USER_GROUP_CODE'])) {
            $errors[] = 'Не указан символьный код группы водителей.';
        }

        if (empty($this->arParams['EMPLOYEES_USER_GROUP_CODE'])) {
            $errors[] = 'Не указан символьный код группы сотрудников.';
        }

        if (count($errors) > 0) {
            throw new InvalidArgumentException($errors[0]);
        }
    }


    private function checkModules(): void
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('highloadblock')) {
            throw new Exception('Не загружены необходимые модули');
        }
    }

    /**
     * @throws AccessDeniedException
     */
    private function checkAccess(): void
    {
        global $USER;

        if (!$USER->IsAuthorized()) {
            throw new AccessDeniedException('Пользователь не авторизован.');
        }

        $userGroups = $USER->GetUserGroupArray();

        $employeeGroupId = $this->getGroupIdByCode($this->arParams['EMPLOYEES_USER_GROUP_CODE']);
        $driversGroupId = $this->getGroupIdByCode($this->arParams['DRIVERS_USER_GROUP_CODE']);
        if (!$employeeGroupId || !$driversGroupId) {
            throw new AccessDeniedException("Отсутствуют группы пользователей с разрешенным доступом");
        }

        if (!in_array($employeeGroupId, $userGroups)) {
            throw new AccessDeniedException('Доступ запрещен. Пользователь не является сотрудником.');
        }

        if (in_array($driversGroupId, $userGroups)) {
            throw new AccessDeniedException('Доступ запрещен. Пользователь является водителем.');
        }
    }


    /**
     * Получить ID группы пользователей по символьному коду
     * @param string $code - символьный код
     */
    private function getGroupIdByCode(string $code): ?int
    {
        return GroupTable::query()
            ->setSelect(['ID'])
            ->setFilter(['=STRING_ID' => $code])
            ->setLimit(1)
            ->fetchObject()
            ?->getId();
    }


    /**
     * Получить период поездки из GET параметров
     */
    private function getPeriodFromRequest(): array
    {
        $period = [];

        $request = Context::getCurrent()->getRequest();

        $dateStartStr = $request->getQuery('date_start');
        $dateEndStr = $request->getQuery('date_end');

        if (empty($dateStartStr) || empty($dateEndStr)) {
            throw new InvalidArgumentException("Не указан полный период поездки");
        }

        $period['date_start'] = (new DateTime($dateStartStr))->format(self::DATETIME_FORMAT);
        $period['date_end'] = (new DateTime($dateEndStr))->format(self::DATETIME_FORMAT);

        return $period;
    }

    /**
     * Получить доступные категории комфорта для бронирования
     */
    private function getAllowedComfortCategoryIds(): array
    {
        $positionId = $this->getUserPositionId();

        if (empty($positionId)) {
            throw new AccessDeniedException('У пользователя не назначена должность');
        }

        $positionCategoryHLBlock = HighloadBlockTable::getList([
            'filter' => ['=TABLE_NAME' => $this->arParams['POSITION_CATEGORY_HL_TABLE']]
        ])->fetch();

        if (!$positionCategoryHLBlock) {
            throw new Exception('Отсутствует positionCategory highload');
        }

        $hlDataClass = HighloadBlockTable::compileEntity($positionCategoryHLBlock)->getDataClass();
        $arrRes = $hlDataClass::query()
            ->setSelect(['UF_CATEGORY_ID'])
            ->setDistinct()
            ->setFilter(['=UF_POSITION_ID' => $positionId])
            ->fetchAll();;

        $categoryIds = array_column($arrRes, 'UF_CATEGORY_ID');
        return array_map('intval', $categoryIds);
    }


    private function getUserPositionId(): int|string|null {
        global $USER;
        $currentUserId = $USER->GetID();
        return UserTable::query()
            ->setSelect(['UF_POSITION_ID'])
            ->setFilter(['=ID' => $currentUserId])
            ->fetchObject()
            ?->getUfPositionId();
    }


    /**
     * Получить недоступных для брони водителей
     * @param array $period - Период поездки для брони
     */
    private function getBusyDriversByPeriod(array $period): array {
        if (empty($period['date_start']) || empty($period['date_end'])) {
            return [];
        }

        $carBookingsTable = IblockTable::compileEntity($this->arParams['CAR_BOOKINGS_IBLOCK_API_CODE'])->getDataClass();
        if (!$carBookingsTable) return [];

        $excludedStatuses = PropertyEnumerationTable::query()
            ->setSelect(['ID'])
            ->setFilter([
                '=PROPERTY.CODE' => 'STATUS',
                '=XML_ID' => ['COMPLETED', 'CANCELLED']
            ])
            ->fetchAll();
        $excludedStatusIds = array_column($excludedStatuses, 'ID');

        $arrRes = $carBookingsTable::query()
            ->setSelect(['DRIVER_ID' => 'CAR.ELEMENT.DRIVER.VALUE'])
            ->setDistinct()
            ->setFilter([
                '!@STATUS.VALUE' => $excludedStatusIds,
                '<=DATE_START.VALUE' => $period['date_end'],
                '>=DATE_END.VALUE' => $period['date_start']
            ])
            ->fetchAll();

        $busyDriversIds = array_column($arrRes, 'DRIVER_ID');

        return array_map('intval', $busyDriversIds);
    }


    /**
     * Получить список доступных авто для бронирования
     * @param array $categoryIds - список доступных категорий комфорта
     * @param array $excludedDrivers - список недоступных водителей
     */
    private function getAvailableCars(array $categoryIds, array $excludedDrivers): array
    {
        $carsTable = IblockTable::compileEntity($this->arParams['CARS_IBLOCK_API_CODE'])->getDataClass();
        if (!$carsTable) return [];

        $comfortCategoryHlBlock = HighloadBlockTable::getList([
            'filter' => ['=TABLE_NAME' => $this->arParams['COMFORT_CATEGORY_HL_TABLE']]
        ])->fetch();
        if (!$comfortCategoryHlBlock) return [];

        $comfortCategoryTable = HighloadBlockTable::compileEntity($comfortCategoryHlBlock)->getDataClass();

        $filter = [
            '@COMFORT_CATEGORY_REF.ID' => $categoryIds,
        ];
        if (!empty($excludedDrivers)) {
            $filter['!@DRIVER_REF.ID'] = $excludedDrivers;
        }

        return $carsTable::query()
            ->registerRuntimeField(
                'COMFORT_CATEGORY_REF',
                new Reference(
                    'COMFORT_CATEGORY_REF',
                    $comfortCategoryTable::getEntity(),
                    Join::on('this.COMFORT_CATEGORY.VALUE', 'ref.UF_XML_ID')
                )
            )
            ->registerRuntimeField(
                'DRIVER_REF',
                new Reference(
                    'DRIVER_REF',
                    UserTable::getEntity(),
                    Join::on('this.DRIVER.VALUE', 'ref.ID')
                )
            )
            ->setSelect([
                'CAR_ID' => 'ID',
                'CAR_MODEL' => 'NAME',
                'COMFORT_CATEGORY_NAME' => 'COMFORT_CATEGORY_REF.UF_NAME',
                'DRIVER_NAME' => 'DRIVER_REF.NAME'
            ])
            ->setFilter($filter)
            ->fetchAll();
    }

}