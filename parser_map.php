<?php
// Если запуск через браузер, перенаправляем на главную страницу сайта
// Через cron $_SERVER["REMOTE_ADDR"] == $_SERVER["SERVER_ADDR"]
if ( $_SERVER["REMOTE_ADDR"] != $_SERVER["SERVER_ADDR"] ) {
    header( 'Location: /' ); exit();
}

// Ограничение времени выполнения скрипта (секунды*минуты*часы)
set_time_limit(60*60*3);

// Вывод ошибок
//ini_set('error_reporting', E_ALL);
//ini_set('display_errors', true);
//ini_set('display_startup_errors', true);

require(dirname(__FILE__).'/settings.php');
require(dirname(__FILE__).'/functions.php');
require(dirname(__FILE__).'/phpQuery/phpQuery.php');

$prefix = 'map';
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
"BEGIN \r\n\r\n".iconv("UTF-8", "windows-1251", "Скрипт запущен через Cron")."\r\n"
."UNIQUE_ID => ". $_SERVER["UNIQUE_ID"]."\r\n"
."HTTP_ACCEPT => ".$_SERVER["HTTP_ACCEPT"]."\r\n"
."HTTP_COOKIE => ". $_SERVER["HTTP_COOKIE"]."\r\n"
."REMOTE_ADDR => ".$_SERVER["REMOTE_ADDR"]."\r\n"
."SERVER_ADDR => ".$_SERVER["SERVER_ADDR"]."\r\n"
."\r\n"
);

$arPages = array();

// Собираем страницы разделов каталога из меню
$content = get_web_page($parser_domain);
$doc = phpQuery::newDocument($content['content']);

$arSections = array();
foreach ( $doc->find('#offlajn-accordion-316-1-container dd dt') as $key => $value ) {
    $pq = pq($value);
    $arSections[] = $parser_domain.$pq->find('a')->attr('href');
}
unset($content);
unset($doc);

//echo"<pre>";print_r($arSections);echo"</pre>";

phpQuery::unloadDocuments(); //очистка документа
gc_collect_cycles(); // принудительный вызов встроенного сборщика мусора PHP

// Проходимся по станицам разделов и формируем постраничные ссылки разделов
foreach ( $arSections as $key => $page ) {
    // Проверяем наличие раздела сайта-донора в нашем списке разделов
    $idSection = 0;
    $v1 = strripos($page,'/')+1;
    $v2 = strripos($page,'.');
    $idSection = substr($page, $v1, $v2-$v1);

    if ( array_key_exists($idSection, $array_categories) ) {
        $arPages[] = $page;

        $content = get_web_page($page);
        $doc = phpQuery::newDocument($content['content']);

        // Количество страниц в разделе
        $pageCount_text = $doc->find('.orderby-displaynumber > .vm-pagination > span:last-child')->text();
        $pageCount = trim(substr(strrchr($pageCount_text, " "), 1));

        // Первая ссылка на страницу постраничной навигации
        $pageNav = $doc->find('.orderby-displaynumber > .vm-pagination > ul > li > a')[0]->attr('href');
        if ( substr($pageNav, 0, 1) == '/' ) {
            $pageNav = $parser_domain.$pageNav;
        }

        for ( $page=1; $page < $pageCount; $page++ ) {
            // Чтобы не прогружать все страницы сайта-донора, формируем их по шаблону исходя из первой ссылки и количества страниц
            $v1 = strripos($pageNav,',')+1;
            $v2 = strripos($pageNav,'.');
            $arPages[] = substr_replace($pageNav, ($page*100+1)."-".($page*100), $v1, $v2-$v1 );
        }

        phpQuery::unloadDocuments(); //очистка документа
        gc_collect_cycles(); // принудительный вызов встроенного сборщика мусора PHP
    }
}
// Удаляем старый файл со страницами
if ( file_exists( $file_sitemap ) ) {
    unlink( $file_sitemap );
}
// Записываем все страницы в файл для дальнейшей обработки
foreach ( $arPages as $key => $value ) {
    if (!empty($value)) file_put_contents( $file_sitemap, trim($value, " \n\r\t\v\0")."\n", FILE_APPEND );
}
//file_put_contents( $file_sitemap, $arPages );

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

writelog ( $log_filename, iconv("UTF-8", "windows-1251", "Количество найденных страниц: ".count($arPages)." \r\n\r\n" ) );
writelog ( $log_filename, iconv("UTF-8", "windows-1251", "Старые лог-файлы удалены \r\n\r\n") );
writelog ( $log_filename, "END \r\n" );
