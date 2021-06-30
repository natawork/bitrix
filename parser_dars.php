<?php
// Если запуск через браузер, перенаправляем на главную страницу сайта
// Через cron $_SERVER["REMOTE_ADDR"] == $_SERVER["SERVER_ADDR"]
if ( $_SERVER["REMOTE_ADDR"] != $_SERVER["SERVER_ADDR"] ) {
    header( 'Location: /' ); exit();
}

// Ограничение времени выполнения скрипта (секунды*минуты*часы)
set_time_limit(60*60*3);

// Вывод ошибок
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
ini_set('display_startup_errors', true);

include dirname(__FILE__).'/settings.php';
include dirname(__FILE__).'/functions.php';
require(dirname(__FILE__).'/phpQuery/phpQuery.php');

$prefix = 'parser';
$log_filename = $log_dirname.'/'.$prefix.'_log_'.date("Y-m-d_H-i-s").'.txt';

// Запрещаем одновременный запуск нескольких процессов
$block_started = dirname(__FILE__)."/started_".$prefix;
if ( !file_exists( $block_started ) ) {
    $fp = fopen($block_started, "w");
    $content = date("d.m.Y H:i:s")."\r\n"
        .$log_filename;
    fwrite($fp, $content);
    fclose($fp);
} else {
    // Если скрипт работает дольше $started_filetime, значит подвис или ошибка
    // Удаляем файл-блокировку
    $time = time() - filemtime($block_started); // сколько прошло времени (в сек.) от последнего изменения файла
    if ( is_file($block_started) && ( $time > $started_filetime ) ) {
        unlink($block_started);
    } else {
        exit();
    }
}


// Если файл лога с таким названием уже есть, удаляем его
if ( file_exists($log_filename) ) unlink($log_filename);

writelog ( $log_filename,
"BEGIN \r\n\r\nСкрипт запущен через Cron\r\n"
."UNIQUE_ID => ". $_SERVER["UNIQUE_ID"]."\r\n"
."HTTP_ACCEPT => ".$_SERVER["HTTP_ACCEPT"]."\r\n"
."HTTP_COOKIE => ". $_SERVER["HTTP_COOKIE"]."\r\n"
."REMOTE_ADDR => ".$_SERVER["REMOTE_ADDR"]."\r\n"
."SERVER_ADDR => ".$_SERVER["SERVER_ADDR"]."\r\n"
."\r\n"
);

// Подключаем framework Битрикса
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('iblock');
CModule::IncludeModule("catalog");

// Получаем все элементы инфоблока, создаем массив элементов с кодом поставщика
$arFilter = array( "IBLOCK_ID" => $IBLOCK_ID );
$rsItems = CIBlockElement::GetList( Array("SORT" => "ASC"), $arFilter, false, false, Array("ID", "PROPERTY_CODE_DARS") );
$arItems_CodeToID = array();
while ( $ob = $rsItems->GetNextElement() ) {
    $arFields = $ob->GetFields();
    if (!empty($arFields['PROPERTY_CODE_DARS_VALUE'])) {
        $arItems_CodeToID[$arFields['PROPERTY_CODE_DARS_VALUE']] = $arFields['ID'];
    }
}

// Получаем данные формы авторизации
$html = request($parser_domain);
$doc = phpQuery::newDocument($html);
$auth = array(
        'username' => $p_username,
        'password' => $p_password,
        'option' => $doc->find('form#cdlogin_form_login > input:hidden[name=option]')->attr('value'),
    	'task' => $doc->find('form#cdlogin_form_login > input:hidden[name=task]')->attr('value'),
    	'return' => $doc->find('form#cdlogin_form_login > input:hidden[name=return]')->attr('value'),
    	$doc->find('form#cdlogin_form_login > input:hidden[value=1]')->attr('name') => '1'
);
unset($html);
unset($doc);
phpQuery::unloadDocuments(); //очистка документа
gc_collect_cycles(); // принудительный вызов встроенного сборщика мусора PHP


// Записываем странцы для парсинга в массив (берем из файла $file_sitemap_parse)
$pages_all =  file( $file_sitemap_parse, FILE_IGNORE_NEW_LINES );
if ( empty($pages_all) ) {
    unlink( $file_sitemap_parse );
    copy( $file_sitemap, $file_sitemap_parse );
    $pages_all = file( $file_sitemap_parse, FILE_IGNORE_NEW_LINES );
}
// Достаем из массива $count_page первых страниц для парсинга
$arPages = array_slice( $pages_all, 0, $count_page);
// Остальные сохраняем для дальнейшей обработки
$arPages2 = array_slice( $pages_all, $count_page );
unset($pages_all);
// Записываем оставшиеся страницы в файл для дальнейшей обработки
//file_put_contents( $file_sitemap_parse, $arPages2 );
// Удаляем старый файл со страницами
if ( file_exists( $file_sitemap_parse ) ) {
    unlink( $file_sitemap_parse );
}
// Записываем все страницы в файл для дальнейшей обработки
foreach ( $arPages2 as $key => $value ) {
    if (!empty($value)) file_put_contents( $file_sitemap_parse, trim($value, " \n\r\t\v\0")."\n", FILE_APPEND );
}
unset($arPages2);



$count_elements = 0;
$count_elements_add = 0;
$count_elements_update = 0;

// Парсинг страниц донора - $arPages
if ( isAuth(request( $parser_domain, $auth )) ) {

    writelog ( $log_filename, "Authorization on the website OK\r\n\r\n" );

    foreach ( $arPages as $key => $currentPage ) {
        if ( !empty($currentPage) ) {

            $currentPage = trim($currentPage);
            writelog ( $log_filename, "PAGE # ".($key+1)." =======================\r\n    ".$currentPage."\r\n" );

            // Получаем из адреса страницы сайта-донора ID раздела
            $SECTION_ID = 0;
            $url_section = explode("/", $currentPage); // разбираем строку в массив элементов
            $section_temp = $url_section[5]; // раздел находится в 5 элементе
            $SECTION_ID = $array_categories[$section_temp];

            if ( in_array($SECTION_ID, $array_categories) ) {

                writelog ( $log_filename, "SECTION: ".$SECTION_ID . "\r\n" );

                $content = get_web_page($currentPage);
                $content = get_web_page($currentPage); // Из-за ошибки страничной навигации на сайте нужна повторная загрузка страницы

                if ( !empty($content['content']) ) {
                    writelog ( $log_filename, "Load page: OK\r\n" );
                    writelog ( $log_filename, "Load DOM ...\r\n" );

                    $doc = phpQuery::newDocument($content['content']);

                    writelog ( $log_filename, "Load DOM OK\r\n" );

                    //writelog ( $log_filename, "Element Quantity: ".count($list_tr)."\r\n\r\n" );

                    foreach ( $doc->find('table.product-list_custom > tr') as $key => $value ) {
                        $tr = pq($value);

                        $count_elements ++;
                        writelog ( $log_filename, "Element # ".$count_elements."\r\n" );
                        writelog ( $log_filename, "LINK: ".$currentPage."\r\n" );

                        // Название товара
                        $name_temp = $tr->find('td:eq(0) > a.product_name')->text();
                        if ( !empty($name_temp) ) {
                            $name = trim($name_temp, " \n\r\t\v\0.,-");
                            /*
                            } elseif ( $td0->find('span.product_name1',0) ) {
                                // Если ссылки нет, то товар "на заказ" в $tr->find(td, 3)->(span, 0)->plaintext;
                            */
                        } else {
                            writelog ( $log_filename, "NAME: NO\r\n    continue...\r\n\r\n" );
                            continue;
                        }
                        writelog ( $log_filename, "NAME: ".$name."\r\n" );

                        $brand_temp = $tr->find('td:eq(0) > span:eq(1)')->text();
                        $brand = trim($brand_temp);
                        writelog ( $log_filename, "BRAND: ".$brand."\r\n" );

                        $code_temp = $tr->find('td:eq(0) > span:eq(3)')->text();
                        $code_temp = explode(":", $code_temp);
                        $code_unic = ( !empty(trim($code_temp[1])) ) ? trim($code_temp[1]) : '';
                        if ( !empty($code_unic) ) {
                            $transParams = Array("replace_space"=>"-","replace_other"=>"-");
                            $trans = Cutil::translit($code_unic."-".$name, "ru", $transParams);
                            writelog ( $log_filename, "CODE DARS: ".$code_unic."\r\n" );
                        } else {
                            writelog ( $log_filename, "CODE DARS: NO\r\n    continue...\r\n\r\n" );
                            continue;
                        }

                        $article_temp = $tr->find('td:eq(0)')->html();
                        $article_temp = preg_replace('|<a[^>]+>(.*?)<\/a>|is', '', $article_temp);
                        $article_temp = preg_replace('|<span[^>]+>(.*?)<\/span>|is', '', $article_temp);
                        $article_temp = str_replace($code_unic, '', $article_temp);
                        $article = trim( pq($article_temp)->text() );
                        $article = trim( $article, " \n\r\t\v\0.," );

                        writelog ( $log_filename, "ARTICLE: ".$article."\r\n" );

                        $count_temp = explode(" ", $tr->find('td:eq(1)')->text());
                        if ( !empty($count_temp[0]) )
                            $count = preg_replace('~[^0-9]+~', '', $count_temp[0]);
                        if ( empty($count) )
                            $count = 0;
                        writelog ( $log_filename, "QUANTITY: ".$count."\r\n" );

                        $price_temp = trim($tr->find('td:eq(2) .cena1')->text());
                        $price_temp = explode(" ", $price_temp);
                        $price = (!empty($price_temp[1])?$price_temp[0]:0);
                        if ( !empty($price) ) {
                            writelog ( $log_filename, "PRICE: ".$price."\r\n" );
                        } else {
                            writelog ( $log_filename, "PRICE: NO\r\n    continue...\r\n\r\n" );
                            continue;
                        }

                        $picture_temp = $tr->find('td:eq(4) a.thumbnail img')->attr('src');
                        $picture = (!empty($picture_temp))?$parser_domain . $picture_temp:"";
                        writelog ( $log_filename, "PICTURE: ".$picture."\r\n" );

                        $PRODUCT_ID = $arItems_CodeToID[$code_unic];
                        writelog ( $log_filename, "ELEMENT ID in DB: ".$PRODUCT_ID."\r\n" );

                        // Пересчитываем цену
                        $new_price = intval( ( ($qpercent/100) + 1 ) * $price );

                        // Передаем данные в массиве
                        $arr_param = Array (
                            'IBLOCK_ID' => $IBLOCK_ID,
                            'ID' => $PRODUCT_ID,
                            'NAME' => $name,
                            'QUANTITY' => $count,
                            'PRICE_DARS' => $price,
                            'PRICE' => $new_price,
                            'SECTION_ID' => $SECTION_ID,
                            'CODE_DARS' => $code_unic,
                            'ARTICLE' => $article,
                            'PICTURE' => $picture,
                            'BRAND' => $brand
                        );
                        if ( $PRODUCT_ID ) {
                            $res_data = update_element( $arr_param );
                            writelog ( $log_filename, "UPDATE element: \r\n".$res_data."\r\n" );
                            $count_elements_update++;
                        } else {
                            //sleep(10);
                            $res_data = add_element( $arr_param );
                            writelog ( $log_filename, "ADD element: \r\n".$res_data."\r\n" );
                            $count_elements_add++;
                        }

    writelog ( $log_filename, "Обработано элементов: ".$count_elements." / ADD: ".$count_elements_add." / UPDATE: ".$count_elements_update."\r\n\r\n");

                            unset($arr_param);
                            //sleep(1);
                    } // foreach $tr

                } else {
                    writelog ( $log_filename,
                    "Load page: ERROR!\r\n"
                    .(!empty($html_line['errno'])?"errno: ".$html_line['errno']:"")
                    .(!empty($html_line['errmsg'])?"errmsg: ".$html_line['errmsg']:"")
                    ."\r\n\r\n" );
                    unset($html_line);
                }

            } // if $SECTION_ID
        } // if $currentPage
    } // foreach $arPages

}
unset($db_all_elements_keys);

// Удаляем файл-блокировку
if ( file_exists( $block_started ) ) {
    unlink( $block_started );
}

// Удаляем старые лог-файлы
if ( is_dir($log_dirname) && ( $dh = opendir($log_dirname) ) ) {
    while ( ( $file = readdir($dh) ) !== false ) {
        $filepath = $log_dirname ."/". $file;
        $time = time() - filemtime($filepath); // сколько прошло времени (в сек.) от последнего изменения файла
        if ( is_file($filepath) && ( $time > $log_lifetime ) ) {
            unlink($filepath);
        }
    }
    closedir($dh);
}

// Статистика                                                           dd.mm.yyyy hh:ii:ss -
writelog ( $log_filename, "Обработано элементов: ".$count_elements."\r\n                      Добавлено: ".$count_elements_add."\r\n                      Обновлено: ".$count_elements_update."\r\n\r\n");

writelog ( $log_filename, "Старые лог-файлы удалены \r\n\r\n");
writelog ( $log_filename, "END \r\n" );


// Добавление элемента в БД
function add_element( $arr_param ) {

    //global $IBLOCK_ID;
    $res_data = "";
//	$percent = $percent/100 + 1;
//	$price = intval( $percent * $arr_param['PRICE'] );

    // Создаем символьный код
    $name = $arr_param["NAME"];
    $arParams = Array("replace_space"=>"-","replace_other"=>"-");
    $trans = Cutil::translit($arr_param['CODE_DARS']."-".$name, "ru", $arParams);

    $res_data .= "    Символьный код: ".$trans."\r\n";

    $el = new CIBlockElement;

    // Добавляем элемент в каталог
    $arLoadProductArray = Array(
        "IBLOCK_ID"         => $arr_param['IBLOCK_ID'],
        "IBLOCK_SECTION_ID" => $arr_param['SECTION_ID'],
        "NAME"              => $arr_param['NAME'],
        "ACTIVE"            => "Y",
        "CODE"              => $trans,
        "PROPERTY_VALUES"   => Array(
            "CODE_DARS"     => $arr_param['CODE_DARS'],
            "CML2_ARTICLE"  => $arr_param['ARTICLE'],
            "MANUFACTURER"  => $arr_param['BRAND']
        )
    );
    if ( !empty($arr_param['PICTURE']) ) {
        $arLoadProductArray["DETAIL_PICTURE"] = CFile::MakeFileArray($arr_param['PICTURE']);
    }

    if ( $PRODUCT_ID = $el->Add( $arLoadProductArray ) ) {
        $res_data .= "    Элемент добавлен. ID: ".$PRODUCT_ID."\r\n";
                    /*."    Символьный код: ".$trans."\r\n";*/
    } else {
        //$res_data .= "    Элемнет не добавлен. ERROR! \r\n";
        $res_data .= "    Элемнет не добавлен. ERROR! ".$el->LAST_ERROR."\r\n";
    }

    if ( $PRODUCT_ID ) {

        // Добавляет параметры товара
        $arFields = array(
        	"ID" => $PRODUCT_ID,
    		"VAT_ID" => 1, //тип ндс
    		"VAT_INCLUDED" => "Y" //НДС входит в стоимость
    	);
        $el = new CCatalogProduct;
        if ( $el->Add( $arFields ) ) {
            $res_data .= "    Параметры товара добавлены.\r\n";
        } else {
            $res_data .= "    Параметры товара не добавлены. ERROR!\r\n";
            $res_data .= "    Элемнет не добавлен. ERROR! ".$el->LAST_ERROR."\r\n";
        }

        // Установление цены для товара
        $PRICE_TYPE_ID = 1;

        $arFields = Array(
    	    "PRODUCT_ID"       => $PRODUCT_ID,
    	    "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
    	    "PRICE"            => $arr_param['PRICE'],
    	    "CURRENCY"         => "RUB",
    	    "QUANTITY"         => $arr_param['QUANTITY'],
        );

        if ( CPrice::Add( $arFields ) ) {
            $res_data .= "    Цена товара добавлена. PRICE: ".$arr_param['PRICE']."\r\n";
        } else {
            $res_data .= "    Цена товара не добавлена. ERROR! PRICE: ".$arr_param['PRICE']."\r\n";
        }

        // Добавляем данные по остаткам товара
    	$arFields = Array(
    		"PRODUCT_ID" => $PRODUCT_ID,
    		"STORE_ID"   => 1,
    		"AMOUNT"     => $arr_param['QUANTITY']
        );

        $ID = CCatalogStoreProduct::UpdateFromForm( $arFields );

    	$arFields = Array(
            'QUANTITY' => $arr_param['QUANTITY'],
            "PURCHASING_PRICE" => $arr_param['PRICE_DARS'],
            "PURCHASING_CURRENCY" => "RUB",
        );
    	CCatalogProduct::Update( $PRODUCT_ID, $arFields );
    }

    return $res_data;
}

// Обновление элемента в БД
function update_element( $arr_param ) {
    global $log_filename;
    $res_data = "";
    $PRODUCT_ID = $arr_param['ID'];
//    $percent = $percent/100 + 1;
//    $price = intval( $percent * $arr_param['PRICE'] );

    // Создаем символьный код
    $name = $arr_param["NAME"];
    $arParams = array("replace_space"=>"-","replace_other"=>"-");
    $trans = Cutil::translit($arr_param['CODE_DARS']."-".$name, "ru", $arParams);

    $res_data .= "    Символьный код: ".$trans."\r\n";

    $el = new CIBlockElement;

    // Задаем параметры элемента для обновления
    $arLoadProductArray = Array(
        "NAME"     => $arr_param['NAME'],
        "ACTIVE"   => "Y",
        "CODE"     => $trans,
        "QUANTITY" => $arr_param['QUANTITY'],
    );

    // Если у текущего товара нет детальной картинки и если она есть у спарсенного товара, то загружаем ее
    $res = CIBlockElement::GetByID( $PRODUCT_ID );
    if ( $ar_res = $res->GetNext() ) {
        if ( empty($ar_res['DETAIL_PICTURE']) && !empty($arr_param['PICTURE']) ) {
            $arLoadProductArray["DETAIL_PICTURE"] = CFile::MakeFileArray($arr_param['PICTURE']);
        }
    }

    // Обновляем элемент в каталоге
    if ( $el->Update( $PRODUCT_ID, $arLoadProductArray ) ) {
        $res_data .= "    Элемент каталога обновлен. ID: ".$PRODUCT_ID."\r\n";
                    /*."    Символьный код: ".$trans."\r\n";*/
    } else {
        $res_data .= "    Элемент каталога не обновлен. ERROR! ".$el->LAST_ERROR."\r\n";
    }
writelog ( $log_filename, "CIBlockElement::Update\r\n" );

    // Обновляем свойства элемента
    CIBlockElement::SetPropertyValuesEx(
        $PRODUCT_ID, false,
        Array(
            "CODE_DARS"    => $arr_param['CODE_DARS'],
            "CML2_ARTICLE" => $arr_param['ARTICLE'],
            "MANUFACTURER" => $arr_param['BRAND']
        )
    );
writelog ( $log_filename, "CIBlockElement::SetPropertyValuesEx\r\n" );

    // Обновляем цену элемента
    $arFields = Array(
        "PRODUCT_ID" => $PRODUCT_ID,
        "PRICE" => $arr_param['PRICE'],
        "CURRENCY" => "RUB",
        "PURCHASING_PRICE" => $arr_param['PRICE_DARS'],
        "PURCHASING_CURRENCY" => "RUB",
    );
    $res = CPrice::GetList(
        Array(),
        Array(
            "PRODUCT_ID" => $PRODUCT_ID
        )
    );

    if ( $arr = $res->Fetch() ) {
        //CPrice::Update( $arr["ID"], $arFields );

        if ( CPrice::Update( $arr["ID"], $arFields ) ) {
            $res_data .= "    Цена товара обновлена. PRICE: ".$arr_param['PRICE']."\r\n";
        } else {
            $res_data .= "    Цена товара не обновлена. ERROR! PRICE: ".$arr_param['PRICE']."\r\n";
        }
writelog ( $log_filename, "CPrice::Update\r\n" );
    } else {
        //CPrice::Add( $arFields );

        if ( CPrice::Add( $arFields ) ) {
            $res_data .= "    Цена товара добавлена. PRICE: ".$arr_param['PRICE']."\r\n";
        } else {
            $res_data .= "    Цена товара не добавлена. ERROR! PRICE: ".$arr_param['PRICE']."\r\n";
        }
writelog ( $log_filename, "CPrice::Add\r\n" );
    }

    // Обновляем данные по количеству товара на складе
    $arFields = Array(
        "PRODUCT_ID" => $PRODUCT_ID,
        "STORE_ID"   => 1,
        "AMOUNT"     => $arr_param['QUANTITY']
    );

    $ID = CCatalogStoreProduct::UpdateFromForm( $arFields );
writelog ( $log_filename, "CCatalogStoreProduct::UpdateFromForm\r\n" );

    $arFields = Array(
        'QUANTITY' => $arr_param['QUANTITY'],
        "PURCHASING_PRICE" => $arr_param['PRICE_DARS'],
        "PURCHASING_CURRENCY" => "RUB",
    );
    CCatalogProduct::Update( $PRODUCT_ID, $arFields );
writelog ( $log_filename, "CCatalogProduct::Update\r\n" );

    return $res_data;
}


?>