<?if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

/**
 * @var string $componentPath
 * @var string $componentName
 * @var array $arCurrentValues
 * */

use \Bitrix\Main\ {
    Loader,
    Localization\Loc,
    Entity\Query
};

use \Bitrix\{
    Iblock\IblockTable,
    Catalog\CatalogIblockTable
};

if(
    !Loader::includeModule("iblock") ||
    !Loader::includeModule("catalog")
) {
    throw new \Exception('Не загружены модули необходимые для работы компонента');
}

// типы инфоблоков
$arIBlockType = CIBlockParameters::GetIBlockTypes();

// инфоблоки выбранного типа
$arIBlock = [];
$iblockFilter = !empty($arCurrentValues['IBLOCK_TYPE'])
    ? ['TYPE' => $arCurrentValues['IBLOCK_TYPE'], 'ACTIVE' => 'Y']
    : ['ACTIVE' => 'Y'];

$rsIBlock = CIBlock::GetList(['SORT' => 'ASC'], $iblockFilter);
while ($arr = $rsIBlock->Fetch()) {
    $arIBlock[$arr['ID']] = '['.$arr['ID'].'] '.$arr['NAME'];
}
unset($arr, $rsIBlock, $iblockFilter);



$propertyFilter = !empty($arCurrentValues['IBLOCK_ID'])
    ? ['IBLOCK_ID' => $arCurrentValues['IBLOCK_ID'], 'ACTIVE' => 'Y']
    : [];

$rsProperty = CIBlockProperty::GetList(['SORT' => 'ASC'], $propertyFilter);
while ($arr = $rsProperty->Fetch()) {
    $arProperty[$arr['CODE']] = '['.$arr['CODE'].'] '.$arr['NAME'];
}
unset($arr, $rsProperty, $propertyFilter);

// Свойства торговых предложений
$arPropertyOffers = [];

// Узнаем типы цен
$arTypePrices = [];
$dbPriceType = CCatalogGroup::GetList(
    array("SORT" => "ASC"),
    array()
);

while ($arPriceType = $dbPriceType->Fetch())
{
    $arTypePrices[] = $arPriceType['NAME'];
}


// Узнаем есть ли торговые предложения
if(!empty($arCurrentValues['IBLOCK_ID']))
{
    $queryHasOffers = new Query(CatalogIblockTable::getEntity());

    $resQueryHasOffers = $queryHasOffers
        ->setSelect(['IBLOCK_ID', 'SKU_PROPERTY_ID'])
        ->setFilter(["PRODUCT_IBLOCK_ID" => $arCurrentValues['IBLOCK_ID']])
        ->exec()
        ->fetch();

    if(
        !empty($resQueryHasOffers['SKU_PROPERTY_ID']) &&
        !empty($resQueryHasOffers['IBLOCK_ID'])
    )
    {
        $iblockIdOffers = $resQueryHasOffers['IBLOCK_ID'];

        // Получаем доступные свойства торговых предложений
        $propertyFilter = !empty($arCurrentValues['IBLOCK_ID'])
            ? ['IBLOCK_ID' => $iblockIdOffers, 'ACTIVE' => 'Y']
            : [];

        $rsProperty = CIBlockProperty::GetList(['SORT' => 'ASC'], $propertyFilter);
        while ($arr = $rsProperty->Fetch()) {
            $arPropertyOffers[$arr['CODE']] = '['.$arr['CODE'].'] '.$arr['NAME'];
        }

        unset($rsProperty);
    }
}



$arComponentParameters = [
    "GROUPS" => [
        "SETTINGS" => [
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_TAB_SETTINGS'),
            "SORT" => 1,
        ],
        "SOURCE" => [
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_TAB_SOURCE'),
            "SORT" => 2,
        ],
        "OFFERS" => [
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_TAB_OFFERS'),
            "SORT" => 3,
        ],
        'SORT_SETTINGS' => [
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_TAB_SORT'),
            "SORT" => 4,
        ],
        'OTHER_SETTINGS' => [
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_TAB_OTHER_SETTINGS'),
            "SORT" => 100,
        ]
    ],
    "PARAMETERS" => [

        // Выбор инфоблока
        "IBLOCK_TYPE" => [
            "PARENT" => "SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_IBLOCK_TYPE'),
            "TYPE" => "LIST",
            "ADDITIONAL_VALUES" => "Y",
            "VALUES" => $arIBlockType,
            "REFRESH" => "Y"
        ],
        "IBLOCK_ID" => [
            "PARENT" => "SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_IBLOCK_ID'),
            "TYPE" => "LIST",
            "ADDITIONAL_VALUES" => "Y",
            "VALUES" => $arIBlock,
            "REFRESH" => "Y"
        ],


        // Источник данных
        "FIELD_CODE" => CIBlockParameters::GetFieldCode(
            Loc::getMessage('NOVA_COMPONENT_PROPERTY_FIELD_CODE'),
            "SOURCE"
        ),
        "PROPERTIES" => [
            "PARENT" => "SOURCE",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_PROPERTIES'),
            "TYPE" => "LIST",
            "MULTIPLE" => "Y",
            "ADDITIONAL_VALUES" => "Y",
            "VALUES" => $arProperty
        ],
        "PRICES" => [
            "PARENT" => "SOURCE",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_PRICES'),
            "TYPE" => "LIST",
            "VALUES" => $arTypePrices
        ],
        "DETAIL_URL" => [
            "PARENT" => "SOURCE",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_DETAIL_URL'),
            "TYPE" => "STRING"
        ],


        // Торговые предложения
        "FIELD_OFFERS" => CIBlockParameters::GetFieldCode(
            Loc::getMessage('NOVA_COMPONENT_PROPERTY_FIELD_OFFERS_CODE'),
            "OFFERS"
        ),
        "PROPERTIES_OFFERS" => [
            "PARENT" => "OFFERS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_PROPERTIES_OFFERS_CODE'),
            "TYPE" => "LIST",
            "MULTIPLE" => "Y",
            "ADDITIONAL_VALUES" => "Y",
            "REFRESH" => "Y",
            "VALUES" => $arPropertyOffers
        ],


        // Иные настройки
        "COUNT_ELEMENTS_ON_PAGE" => [
            "PARENT" => "OTHER_SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_COUNT_ELEMENTS_ON_PAGE'),
            "TYPE" => "STRING",
            "MULTIPLE" => "N",
            "DEFAULT" => 20,
            "COLS" => 5
        ],
        "NAME_FILTER" => [
            "PARENT" => "OTHER_SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_NAME_FILTER'),
            "TYPE" => "STRING",
            "MULTIPLE" => "N",
            "DEFAULT" => 'arFilterNova',
            "COLS" => 25
        ],


        // Настройки сортировки
        "ELEMENT_FIELD_SORT" => [
            "PARENT" => "SORT_SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_ELEMENT_FIELD_SORT'),
            "TYPE" => "STRING",
            "MULTIPLE" => "N",
            "COLS" => 5
        ],
        "ELEMENT_TYPE_SORT" => [
            "PARENT" => "SORT_SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_ELEMENT_TYPE_SORT'),
            "TYPE" => "LIST",
            "MULTIPLE" => "N",
            "VALUES" => [
                'ASC' => 'По возрастанию',
                'DESC' => 'По убыванию'
            ],
            "DEFAULT" => 'ASC',
            "COLS" => 5
        ],
        "ELEMENT_FIELD_SORT_2" => [
            "PARENT" => "SORT_SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_ELEMENT_FIELD_SORT_2'),
            "TYPE" => "STRING",
            "MULTIPLE" => "N",
            "COLS" => 5
        ],
        "ELEMENT_TYPE_SORT_2" => [
            "PARENT" => "SORT_SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_ELEMENT_TYPE_SORT_2'),
            "TYPE" => "LIST",
            "MULTIPLE" => "N",
            "VALUES" => [
                'ASC' => 'По возрастанию',
                'DESC' => 'По убыванию'
            ],
            "DEFAULT" => 'ASC',
            "COLS" => 5
        ],


        // Иные настройки
        "NAME_FILTER" => [
            "PARENT" => "OTHER_SETTINGS",
            "NAME" => Loc::getMessage('NOVA_COMPONENT_PROPERTY_NAME_FILTER'),
            "TYPE" => "STRING",
            "MULTIPLE" => "N",
            "DEFAULT" => 'arFilterNova',
            "COLS" => 25
        ],


        'CACHE_TIME' => ['DEFAULT' => 3600],
    ]
];

// Добавляем параметры постраничной навигации
CIBlockParameters::AddPagerSettings(
    $arComponentParameters,
    false, //$pager_title
    true, //$bDescNumbering
    true, //$bShowAllParam
    true, //$bBaseLink
    true//$bBaseLinkEnabled
);

// Не показываем поля торговых предложений если их нет
if(empty($arPropertyOffers))
{
    unset($arComponentParameters['PARAMETERS']['FIELD_OFFERS']);

    // Не показываем свойства торговых предложений если их нет
    unset($arComponentParameters['PARAMETERS']['PROPERTIES_OFFERS']);

    // Убираем вообще вкладку  - источник данных торговых предложений
    unset($arComponentParameters['GROUPS']['OFFERS']);
}

if(empty($arTypePrices))
{
    unset($arComponentParameters['PARAMETERS']['PRICES']);
}