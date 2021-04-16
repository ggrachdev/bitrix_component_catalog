<?php

use \Bitrix\Main\ {
    Loader,
    Application,
    Data\Cache,
    Entity\Query
};

use \Bitrix\Catalog\{
    CatalogIblockTable,
    PriceTable
};

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

class Nova extends \CBitrixComponent
{
    private $__keyCache = "nova.list";

    /**
     * Объект Request
     * @var \Bitrix\Main\Context::getCurrent()->getRequest()
     */
    private $__request;

    // Не обработанный arResult, используется для передачи результата между методами loadItems и loadOffers
    private $arResultRaw = [];

    /**
     * @var array - Регистр, используется для хранения данных, чтобы не засорять класс свойствами
     */
    private $registerSystemParams = [
        // Является ли детальной страницей
        'isDetailPage' => false,

        // ID Детальной страницы если является
        'idDetailPage' => false,

        // arSelect для товаров
        'arSelectItems' => [],

        // arSelect для торговых предложений
        'arSelectOffers' => [],

        // Структура ITEM'Ов
        'skeletonItem' => [],
        'skeletonOffer' => [],

        // Первичная выборка элементов
        'arSelectList' => [],

        // Данные о свойствах
        'properties' => [],
        'propertiesOffers' => [],

        // ID'ы у которых надо узнать цену
        'arIdElementsPrices' => [],
        'arIdOffersPrices' => []
    ];


    /**
     * Подготовка параметров компонента
     * @param $arParams
     * @return array
     */
    public function onPrepareComponentParams(array $arParams): array
    {
        $this->__request = Application::getInstance()->getContext()->getRequest();

        if (!empty($arParams['DETAIL_URL'])) {
            $this->checkIsDetailPage($arParams['DETAIL_URL'], $arParams['IBLOCK_ID']);
        }

        // тут пишем логику обработки параметров, дополнение параметрами по умолчанию
        // и прочие нужные вещи

        $arParams['FIELD_CODE'] = array_unique($arParams['FIELD_CODE']);
        $arParams['PROPERTIES'] = array_unique($arParams['PROPERTIES']);

        $arParams['FIELD_OFFERS'] = array_unique($arParams['FIELD_OFFERS']);
        $arParams['PROPERTIES_OFFERS'] = array_unique($arParams['PROPERTIES_OFFERS']);

        // Удаляем пустые свойства
        $arParams['FIELD_CODE'] = array_filter($arParams['FIELD_CODE'], function ($el) {
            return !empty(trim($el));
        });
        $arParams['PROPERTIES'] = array_filter($arParams['PROPERTIES'], function ($el) {
            return !empty(trim($el));
        });
        $arParams['FIELD_OFFERS'] = array_filter($arParams['FIELD_OFFERS'], function ($el) {
            return !empty(trim($el));
        });
        $arParams['PROPERTIES_OFFERS'] = array_filter($arParams['PROPERTIES_OFFERS'], function ($el) {
            return !empty(trim($el));
        });

        $arNavStartParams = [];

        if (!empty($arParams['COUNT_ELEMENTS_ON_PAGE']) && !$this->getSysParam('isDetailPage')) {
            // Массив для навигации
            $arNavStartParams["nPageSize"] = intval($arParams['COUNT_ELEMENTS_ON_PAGE']);
            $arNavStartParams["iNumPage"] = !empty($this->__request->getQuery('PAGEN')) ? explode('-', $this->__request->getQuery('PAGEN'))[1] : 1;

            $this->__keyCache = $this->__keyCache.'_'.$arNavStartParams["iNumPage"];

            $filter = array("=IBLOCK_ID" => $arParams['IBLOCK_ID'], "ACTIVE" => "y");

            $cnt = \Bitrix\Iblock\ElementTable::getCount($filter);

            $nav = new \Bitrix\Main\UI\PageNavigation("PAGEN");
            $nav->allowAllRecords(true)->setRecordCount($cnt)
                ->setPageSize($arParams['COUNT_ELEMENTS_ON_PAGE'])
                ->initFromUri();

            ob_start();
            global $APPLICATION;
            $APPLICATION->IncludeComponent(
                "bitrix:main.pagenavigation",
                $arParams['PAGER_TEMPLATE'],
                array(
                    "NAV_OBJECT" => $nav,
                    "SEF_MODE" => "N",
                ),
                false
            );

            $pagination = ob_get_contents();
            ob_end_clean();

            $this->setSysParam('htmlPagination', $pagination);

            $this->setSysParam('countAllElements', $cnt);
        }

        $this->setSysParam('arNavStartParams', $arNavStartParams);

        // Устанавливаем $arSelect для товаров
        $arSelectItems = [];
        $skeletonItem = [];
        if (!empty($arParams['FIELD_CODE'])) {
            $arSelectItems += $arParams['FIELD_CODE'];
            $skeletonItem = array_flip($arParams['FIELD_CODE']);
            array_walk($skeletonItem, function (&$v, &$k) {
                $v = null;
            });
        }

        if (!in_array('CODE', $arSelectItems)) {
            $arSelectItems[] = 'CODE';
        }


        if (!empty($arParams['PROPERTIES'])) {
            foreach ($arParams['PROPERTIES'] as $prop) {
                $arSelectItems[] = "PROPERTY_$prop";
                $skeletonItem['PROPERTIES'][$prop] = [];
            }
        }
        $this->setSysParam('arSelectItems', $arSelectItems);
        $this->setSysParam('skeletonItem', $skeletonItem);
        unset($arSelectItems);
        unset($skeletonItem);

        // Устанавливаем $arSelect для торговых предложений
        $arSelectOffers = [];
        $skeletonOffer = [];
        if (!empty($arParams['FIELD_OFFERS'])) {
            $arSelectOffers += $arParams['FIELD_OFFERS'];
            $skeletonItem = array_flip($arParams['FIELD_OFFERS']);
            array_walk($skeletonOffer, function (&$v, &$k) {
                $v = null;
            });
        }

        if (!empty($arParams['PROPERTIES_OFFERS'])) {
            foreach ($arParams['PROPERTIES_OFFERS'] as $prop) {
                $arSelectOffers[] = "PROPERTY_$prop";
                $skeletonOffer[$prop] = [];

            }
        }

        $this->setSysParam('arSelectOffers', $arSelectOffers);
        $this->setSysParam('skeletonOffer', $skeletonOffer);
        unset($arSelectOffers);
        unset($skeletonOffer);

        // Массив выборки для первичного массива элементов
        $this->setSysParam('arSelectList', ['ID']);

        return $arParams;
    }

    /**
     * Выполнение компонента
     */
    public function executeComponent(): void
    {
        $this->includeModule();

        if ($this->getSysParam('isDetailPage')) {
            global $APPLICATION;

            $cache = Cache::createInstance();

            if ($cache->initCache($this->arParams['CACHE_TIME'], $this->__keyCache . '_detail')) {
                $arResult = $cache->getVars();
            } elseif ($cache->startDataCache()) {
                $arProperties = [];
                $properties = CIBlockProperty::GetList(Array("sort" => "asc", "name" => "asc"), Array("ACTIVE" => "Y", "IBLOCK_ID" => $this->arParams['IBLOCK_ID']));
                while ($propFields = $properties->GetNext()) {
                    $arProperties[$propFields['CODE']] = $propFields;
                }
                $this->setSysParam('properties', $arProperties);
                unset($properties);
                unset($arProperties);

                $this->loadItems();
                $this->loadOffers();

                $arResult = $this->arResultRaw;

                $cache->endDataCache($arResult);
            }

            $this->arResult = reset($arResult['ITEMS']);

            $APPLICATION->AddChainItem($this->arResult['NAME'], "");

            $this->includeComponentTemplate('detail');
        } else {

            $cache = Cache::createInstance();

            if ($cache->initCache($this->arParams['CACHE_TIME'], $this->__keyCache)) {
                $arResult = $cache->getVars();
            } elseif ($cache->startDataCache()) {
                $arProperties = [];
                $properties = CIBlockProperty::GetList(Array("sort" => "asc", "name" => "asc"), Array("ACTIVE" => "Y", "IBLOCK_ID" => $this->arParams['IBLOCK_ID']));
                while ($propFields = $properties->GetNext()) {
                    $arProperties[$propFields['CODE']] = $propFields;
                }
                $this->setSysParam('properties', $arProperties);
                unset($properties);
                unset($arProperties);

                $this->loadItems();
                $this->loadOffers();

                $arResult = $this->arResultRaw;

                if ($this->hasSysParam('htmlPagination')) {
                    $arResult['NAVS'] = $this->getSysParam('htmlPagination');
                }

                /*if ($isInvalid) {
                    $cache->abortDataCache();
                }*/

                $cache->endDataCache($arResult);
            }

            $this->arResult = $arResult;

            $this->includeComponentTemplate('list');
        }

    }


    /**
     * Проверка наличия модулей требуемых для работы компонента
     * @return bool
     * @throws Exception
     */
    private function includeModule()
    {

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            throw new \Exception('Не загружены модули необходимые для работы модуля');
        }

        return true;
    }

    /**
     * Проверяет является ли текущий url path детальной страницей, соответствующей шаблону $detailUrl
     *
     * @param $detailUrl - шаблон вида /a/b/c/#ELEMENT_CODE#
     * @param $iblockId - id инфоблока в котором ищем соответствие
     * @return bool - результат, является или не является детальной страницей
     */
    private function checkIsDetailPage(string $detailUrl, int $iblockId): bool
    {
        $uri = new \Bitrix\Main\Web\Uri($this->__request->getRequestUri());

        $id_elem = null;

        $regExp = '~' . str_replace('#ELEMENT_CODE#', '(.*)', $detailUrl) . '~';

        if (preg_match($regExp, $uri->getPath(), $matches)) {
            $code = $matches[1];

            $dbResultIds = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code, 'ACTIVE' => 'Y'], false, [], ['ID']);

            if ($arItem = $dbResultIds->Fetch()) {
                $id_elem = $arItem['ID'];
                $this->setSysParam('isDetailPage', true);
                $this->setSysParam('idDetailPage', $id_elem);
            };
        }


        return !empty($id_elem);
    }

    /**
     * Подгрузить данные об элементах в $this->arResultRaw
     */
    private function loadItems(): void
    {
        $arSort = [];

        if (
            !empty($this->arParams['ELEMENT_FIELD_SORT']) &&
            !empty($this->arParams['ELEMENT_TYPE_SORT'])
        ) {
            $arSort[$this->arParams['ELEMENT_FIELD_SORT']] = $this->arParams['ELEMENT_TYPE_SORT'];
        }

        if (
            !empty($this->arParams['ELEMENT_FIELD_SORT_2']) &&
            !empty($this->arParams['ELEMENT_TYPE_SORT_2'])
        ) {
            $arSort[$this->arParams['ELEMENT_FIELD_SORT_2']] = $this->arParams['ELEMENT_TYPE_SORT_2'];
        }

        if (
            !empty($this->arParams['ELEMENT_FIELD_SORT_3']) &&
            !empty($this->arParams['ELEMENT_TYPE_SORT_3'])
        ) {
            $arSort[$this->arParams['ELEMENT_FIELD_SORT_3']] = $this->arParams['ELEMENT_TYPE_SORT_3'];
        }

        $arFilter = [];

        if (!empty($this->arParams['NAME_FILTER'])) {
            // todo refactoring
            $nameFilterGlobal = $this->arParams['NAME_FILTER'];
            global $$nameFilterGlobal;

            if (!empty($$nameFilterGlobal)) {
                $arFilterNew = $$nameFilterGlobal;
                if (!empty($arFilterNew)) {
                    $arFilter = $arFilterNew;
                }
            }
        }

        $arFilter['IBLOCK_ID'] = $this->arParams['IBLOCK_ID'];
        $arFilter['ACTIVE'] = "Y";

        $arResult = [
            'ITEMS' => []
        ];

        if ($this->getSysParam('isDetailPage')) {
            $arFilter['=ID'] = $this->getSysParam('idDetailPage');
        }

        // Формируем список id'ов для пагинации, ибо если брать иной arSelect - пагинация будет по количеству итераций
        $idsList = [];
        $arNav = $this->getSysParam('arNavStartParams');

        $dbResultList = \CIBlockElement::GetList($arSort, $arFilter, false, $arNav, $this->getSysParam('arSelectList'));
        while ($arItem = $dbResultList->Fetch()) {
            $idsList[] = $arItem['ID'];
        }

        $arPrices = [];

        // Получаем цены
        if (isset($this->arParams['PRICES'])) {
            $allProductPrices = PriceTable::getList([
                "select" => ["*"],
                "filter" => [
                    "=PRODUCT_ID" => $idsList,
                    "EXTRA_ID" => $this->arParams['PRICES']
                ],
                "order" => ["CATALOG_GROUP_ID" => "ASC"]
            ])->fetchAll();

            if (!empty($allProductPrices)) {
                foreach ($allProductPrices as $arPrice) {
                    $arPrices[$arPrice['PRODUCT_ID']] = [
                        'PRICE' => $arPrice['PRICE'],
                        'CURRENCY' => $arPrice['CURRENCY'],
                        'PRICE_SCALE' => $arPrice['PRICE_SCALE']
                    ];
                }
            }
        }

        $arFilter['=ID'] = $idsList;

        $dbResult = \CIBlockElement::GetList($arSort, $arFilter, false, [], []);

        $i = 0;
        while ($arItem = $dbResult->Fetch()) {
            $i++;

            if (!array_key_exists($arItem['ID'], $arResult['ITEMS'])) {


                if (!empty($this->arParams['DETAIL_URL'])) {
                    $arItem['DETAIL_PAGE_URL'] = str_replace('#ELEMENT_CODE#', $arItem['CODE'], $this->arParams['DETAIL_URL']);
                }

                if (array_key_exists($arItem['ID'], $arPrices)) {
                    $arItem['PRICES'] = [
                        $this->arParams['PRICES'] => $arPrices[$arItem['ID']]
                    ];
                }

                $arResult['ITEMS'][$arItem['ID']] = $this->getSysParam('skeletonItem');
            }

            $this->mergeArraysProperties($arResult['ITEMS'][$arItem['ID']], $arItem, 'item');
        }

        $this->arResultRaw = $arResult;
    }

    /**
     * Подгрузить данные об торговых предложениях инфоблока в $this->arResultRaw
     * Вызывается строго после loadItems
     */
    private function loadOffers(): void
    {
        // Узнаем ID инфоблока с торговыми предложениями
        $arDataIblockOffers = (new Query(CatalogIblockTable::getEntity()))->setSelect(['IBLOCK_ID', 'SKU_PROPERTY_ID'])
            ->setFilter(["PRODUCT_IBLOCK_ID" => $this->arParams['IBLOCK_ID']])
            ->exec()
            ->fetch();

        if (!empty($arDataIblockOffers['IBLOCK_ID']) && !empty($this->arResultRaw)) {

            $arProperties = [];
            $properties = CIBlockProperty::GetList(Array("sort" => "asc", "name" => "asc"), Array("ACTIVE" => "Y", "IBLOCK_ID" => $arDataIblockOffers['IBLOCK_ID']));
            while ($propFields = $properties->GetNext()) {
                $arProperties[$propFields['CODE']] = $propFields;
            }
            $this->setSysParam('propertiesOffers', $arProperties);
            unset($arProperties);
            unset($properties);

            $arFilter = [
                "IBLOCK_ID" => $arDataIblockOffers['IBLOCK_ID'],
                "ACTIVE" => "Y"
            ];

            if ($this->getSysParam('isDetailPage')) {
                $arFilter['PROPERTY_CML2_LINK'] = $this->getSysParam('idDetailPage');
            }

            $arSelect = [
                'PROPERTY_CML2_LINK'
            ];

            $dbResult = \CIBlockElement::GetList([], $arFilter, false, [], array_merge($arSelect, $this->getSysParam('arSelectOffers')));
            $dbResultIds = \CIBlockElement::GetList([], $arFilter, false, [], ['ID']);

            // Получаем все id'ы у торговых предложений
            $idsList = [];
            while ($arItem = $dbResultIds->Fetch()) {
                $idsList[] = $arItem['ID'];
            };

            unset($dbResultIds);

            $arPrices = [];

            // Получаем цены
            if (isset($this->arParams['PRICES'])) {
                $allProductPrices = PriceTable::getList([
                    "select" => ["*"],
                    "filter" => [
                        "=PRODUCT_ID" => $idsList,
                        "EXTRA_ID" => $this->arParams['PRICES']
                    ],
                    "order" => ["CATALOG_GROUP_ID" => "ASC"]
                ])->fetchAll();

                if (!empty($allProductPrices)) {
                    foreach ($allProductPrices as $arPrice) {
                        $arPrices[$arPrice['PRODUCT_ID']] = [
                            'PRICE' => $arPrice['PRICE'],
                            'CURRENCY' => $arPrice['CURRENCY'],
                            'PRICE_SCALE' => $arPrice['PRICE_SCALE']
                        ];
                    }
                }
            }

            while ($arItem = $dbResult->Fetch()) {

                // Выбираем только связанные с элементами каталога торговые предложения
                if (!empty($arItem['PROPERTY_CML2_LINK_VALUE'])) {

                    if (array_key_exists($arItem['ID'], $arPrices)) {
                        $arItem['PRICES'] = [
                            $this->arParams['PRICES'] => $arPrices[$arItem['ID']]
                        ];
                    }

                    if (array_key_exists($arItem['PROPERTY_CML2_LINK_VALUE'], $this->arResultRaw['ITEMS'])) {
                        if (!array_key_exists('OFFERS', $this->arResultRaw['ITEMS'][$arItem['PROPERTY_CML2_LINK_VALUE']])) {
                            $this->arResultRaw['ITEMS'][$arItem['PROPERTY_CML2_LINK_VALUE']]['OFFERS'] = [];
                        }

                        if (!empty($this->arResultRaw['ITEMS'][$arItem['PROPERTY_CML2_LINK_VALUE']]['OFFERS'][$arItem['ID']])) {
                            $this->mergeArraysProperties($this->arResultRaw['ITEMS'][$arItem['PROPERTY_CML2_LINK_VALUE']]['OFFERS'][$arItem['ID']], $arItem, 'offers');
                        } else {
                            $this->arResultRaw['ITEMS'][$arItem['PROPERTY_CML2_LINK_VALUE']]['OFFERS'][$arItem['ID']] = [];
                            $this->mergeArraysProperties($this->arResultRaw['ITEMS'][$arItem['PROPERTY_CML2_LINK_VALUE']]['OFFERS'][$arItem['ID']], $arItem, 'offers');
                        }
                    }
                }
            }

            unset($dbResult);
        }
    }

    /**
     * Сливает данные $arItem с уже существующими данными $arItem
     * Во время сливания отделяет стандартные свойства инфоблока от пользовательских свойств, начинающихся с PROPERTY_*
     *
     * Метод необходим из-за получения данных через GetList не в виде отдельных элементов при каждой итерации
     * при наличии множественных свойств, а в виде несвязанной кучи, которую нужно сливать с предыдущими результатами
     *
     * @param &$arItem - уже существующий элемент
     * @param &$arItemPlus - дополнительные данные того же элемента, которые вольются в существующий
     * @param $context - сливать элементы в рамках контекста:
     *  - offers - $arItem и $arItemPlus являются торговыми предложениями
     *  - item - $arItem и $arItemPlus являются элементами
     *
     * Контекст необходим чтобы проверить - является ли свойство множественным или нет, если да, то будет записываться
     * в values, если нет - в value
     */
    private function mergeArraysProperties(array &$arItem, array &$arItemPlus, string $context)
    {

        foreach ($arItemPlus as $k => $v) {

            if (strrpos($k, "PROPERTY_") === false) {
                // Обычное свойство
                $arItem[$k] = empty($v) ? null : $v;
            } else {
                preg_match('/^PROPERTY_(.*)_(ENUM_ID|VALUE|VALUE_ID)$/', $k, $matches);

                // Пользовательское свойство
                $property = $matches[1];
                $type = $matches[2];

                switch ($type) {
                    case 'VALUE':

                        if (!empty($arItemPlus['PROPERTY_' . $property . '_VALUE_ID'])) {
                            if ($context == 'item') {
                                if ($this->getSysParam('properties')[$property]['MULTIPLE'] == "N") {
                                    $arItem['PROPERTIES'][$property]['VALUE'] = $v;
                                    continue;
                                }
                            } else if ($context == 'offers') {
                                if ($this->getSysParam('propertiesOffers')[$property]['MULTIPLE'] == "N") {
                                    $arItem['PROPERTIES'][$property]['VALUE'] = $v;
                                    continue;
                                }
                            }

                            $arItem['PROPERTIES'][$property]['VALUES'][$arItemPlus['PROPERTY_' . $property . '_VALUE_ID']] = $v;
                        } else {
                            $arItem['PROPERTIES'][$property]['VALUES'] = $v;
                        }
                        break;
                }
            }
        }

    }


    /**
     * Установить значение в регистр
     *
     * @param $key
     * @param $value
     */
    private function setSysParam(string $key, $value): void
    {
        $this->registerSystemParams[$key] = $value;
    }

    /**
     * Получить значение из регистра
     *
     * @param $key
     * @return mixed|null
     */
    private function getSysParam(string $key)
    {
        return array_key_exists($key, $this->registerSystemParams) ? $this->registerSystemParams[$key] : null;
    }

    /**
     * Проверить есть ли значение в регистре
     *
     * @param $key
     * @return bool
     */
    private function hasSysParam(string $key): bool
    {
        return array_key_exists($key, $this->registerSystemParams);
    }
}