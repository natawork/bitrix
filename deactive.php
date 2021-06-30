<?
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

$prefix = 'deactive';

// Путь к лог файлам
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

// Подключаем framework Битрикса
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('iblock');
CModule::IncludeModule("catalog");

function deactive_goods($PRODUCT_ID) {
	$el = new CIBlockElement;
	$arLoadProductArray = Array(
		"ACTIVE" => "N"
	);
	$el->Update($PRODUCT_ID,$arLoadProductArray);
}

$count_elements = 0;

//foreach($array_categories as $SECTION_ID) {
	$arFilter = array(
        "IBLOCK_ID" => $IBLOCK_ID,
        //"SECTION_ID" => $SECTION_ID,
        "ACTIVE" => "Y",
        "DATE_MODIFY_TO" => date($DB->DateFormatToPHP(CLang::GetDateFormat("FULL")), time()-(60*60*24*5))
    );
	$arSort = array ("TIMESTAMP_X" => "ASC");
	$resItems = CIBlockElement::GetList($arSort, $arFilter, false, false, Array());

	while ($ob = $resItems->GetNextElement()) {
		$arItem = $ob->GetFields();

        //echo"<pre>";print_r($arItem);echo"</pre>";

		$date_goods = $arItem['TIMESTAMP_X'];
		$PRODUCT_ID = $arItem['ID'];

        deactive_goods($PRODUCT_ID);

        $count_elements++;
/*		writelog ( $log_filename,
            "UPDATE element: \r\n"
            ."  ID: ".$arItem['ID']."\r\n"
            ."  NAME: ".$arItem['NAME']."\r\n"
            ."  SECTION: ".$arItem['IBLOCK_SECTION_ID']."\r\n"
            ."  CREATED DATE: ".$arItem['CREATED_DATE']."\r\n"
            ."  MODIFIED DATE: ".$arItem['TIMESTAMP_X']."\r\n"
            ."\r\n\r\n" );*/
	}
//}

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

// Статистика
writelog ( $log_filename, iconv("UTF-8", "windows-1251", "Обработано элементов: ".$count_elements."\r\n\r\n") );

writelog ( $log_filename, iconv("UTF-8", "windows-1251", "Старые лог-файлы удалены \r\n\r\n") );
writelog ( $log_filename, "END \r\n" );


?>