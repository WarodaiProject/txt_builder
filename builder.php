<?php

    $options = getopt('h',['help','dir:', 'file:', 'ref:','sort:', 'xedition']);

    if(isset($options['h']) || isset($options['help'])){
        print("Скрипт генерации текстовой версии словарей.\n");
        print("Использование: php.exe builder.php --dir --file [--ref --xedition --sort]");
        print("\n\n");
        print(
            "--dir        Путь к директории с файлами карточек или,\n".
            "             если необходимо сгенерировать прошлую версию по тэгу репозитория,\n".
            "             - путь к директории .git (находится внутри директории с карточками).\n\n"
        );
        print(
            "--file       Имя файла, в который необходимо положить результат.\n\n"
        );

        print(
            "--sort       Способ сортировка карточек. Возможные значения: code - по коду,\n".
            "             kana - по написанию каной, kiriji - по порядку букв в русском алфавите,\n".
            "             header - по всему заголовку целиком. По умолчанию - code\n\n"
        );

        print(
            "--ref        Тэг или коммит, на момент которых нужно взять версию\n".
            "             (необязательный параметр, если отсутствует, будет взята последняя версия).\n\n"
        );
        
        print(
            "--xedition   Указание на то, что необходимо сгенерировать полную редакторскую версию.\n"
        );
        exit();
    }

    if(!isset($options['dir'])){
        die("Вы не указали путь к директории с файлами карточек или директории .git.\n");
    }
    if(!isset($options['file'])){
        die("Вы не указали имя файла, в который необходимо положить результат.\n");
    }

	$repoPath = $options['dir'];
    $outputFile = $options['file'];
    $xEdition = isset($options['xedition']);

    $ref = null;
    if(!empty($options['ref'])){
        $ref = $options['ref'];
    }

    $sort = 'code';
    if(!empty($options['sort'])){
        $sort = trim($options['sort']);

        if(!in_array($sort, ['code', 'kiriji', 'kana', 'header'])){
            die("Неверно указана сортировка. Возможные значения: code, kiriji, kana, header.\n");
        }
    }

	$entries = [];
	$isRepoFlag = false;

    if(preg_match('/.git$/',$repoPath)){
        $isRepoFlag = true;
    }

    if(!$isRepoFlag && !empty($ref)){
        print("Невозможно извлечь версию по тэгу или коммиту. Укажите путь к директории .git.\n");
        exit(0);
    }

    if($isRepoFlag){
        scanGitDir($repoPath,$entries,$ref);
    }
    else{
	    scanCorpDir($repoPath,$entries);
    }

    if($sort == 'code'){
        ksort($entries);
    }
    else{
        try{
            if($sort == 'kana'){
                uasort($entries, 'sortByKana');
            }
            if($sort == 'kiriji'){
                uasort($entries, 'sortByKiriji');
            }
            if($sort == 'header'){
                uasort($entries, 'sortByHeader');
            }
        }
        catch (Exception $e){
            die("Ошибка при сортировке: ".$e."\n");
        }     
    }


    $output = <<<EOD
*******************************************************************************************************************
This file is licensed under a Creative Commons Attribution-Noncommercial-No Derivative Works 3.0 Unported License *
License URL: http://creativecommons.org/licenses/by-nc-nd/3.0/                                                    *
*******************************************************************************************************************
EOD;

	foreach ($entries as $id=>$entry){
        $entry = preg_replace('/&lt;&lt;([^|]+)\|([^&]+)&gt;&gt;/u','<a href="#$1">$2</a>',$entry);

        if(!$xEdition){
            $entry = explode('※',$entry)[0];
            $entry = preg_replace('/ *\[\[[^]]+\]\]/','',$entry);
        }

        $entry = trim($entry);
        if(preg_match("/〔{$id}〕/u",$entry)){
            $output .= "\n\n{$entry}";
        }
        else{
            $output .= "\n\n{$id}\n{$entry}";
        }
	}

    file_put_contents($outputFile, "\xFF\xFE".iconv('UTF-8', 'UTF-16LE',$output));
    print("Done.\n");

	function scanCorpDir($path,&$entries){
	    if ($handle = opendir($path)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry{0} != ".") {
                    if(is_file("{$path}/{$entry}") && preg_match('/^[0-9A-]+\.txt$/', $entry)){
                        $code = explode('.',$entry)[0];
                        $entries[$code] =  trim(file_get_contents_utf("{$path}/{$entry}"));
                    }
                    elseif(preg_match('/^[0-9A-]+$/', $entry)) {
                        scanCorpDir("{$path}/{$entry}",$entries);
                    }
                }
            }
            closedir($handle);
        }
        else{
            print('Невозможно открыть '.$path.'. Процедура генерации прервана.');
            exit(0);
        }
    }

    function scanGitDir($repoPath,&$entries,$ref){
        if(empty($ref)){
            $ref = 'HEAD';
        }

        $e = [];
        exec("git --git-dir {$repoPath} ls-tree --name-only -r {$ref}", $e);

        foreach($e as $s){
            $_t = [];
            //print("Extracting {$s} - done\n");
            exec("git --git-dir {$repoPath} show {$ref}:{$s}",$_t);
            $code = explode('.',explode('/',$s)[1])[0];
            $entries[$code] =  join("\n",$_t);
        }
    }

    function sortByKana($a, $b){
        return sortCard($a, $b, 'kana');
    }

    function sortByKiriji($a, $b){
        return sortCard($a, $b, 'kiriji');
    }

    function sortByHeader($a, $b){
        $a = explode("\n", $a)[0];
        $b = explode("\n", $b)[0];

        return ($a < $b) ? -1 : 1;
    }

    function sortCard($a, $b, $field='kana'){
        $a = parseHeader(explode("\n", $a)[0])[$field][0];        
        $b = parseHeader(explode("\n", $b)[0])[$field][0];

        return ($a < $b) ? -1 : 1;
    }

    function parseHeader($headerString){
        //Структура заголовка статьи
        $header = [
            'kana'=>[],           //неразобранный массив написаний каной
            'hyouki'=>[],         //неразобранный массив написаний хё:ки
            'kiriji'=>[],         //неразобранный массив написаний киридзи
        ];
    
        //Разбираем заголовок с помощью вот такого регулярного выражения
        $headerReg = '/^ *(([\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{31f0}-\x{31ff}…A-Z.,･！ ]+)(【([^】]+)】)? ?\(([а-яА-ЯЁёйў*,…:\[\] \x{0306}-]+)\)) *(\[([^]]+)\])?(〔([^〕]+)〕)?/u';
    
        //Заполняем структуру данных заголовка статьи
        if(preg_match($headerReg,$headerString,$match)){
            $header['kana'] = normalizeKana(explode(",", $match[2]));
            $header['hyouki'] = (empty($match[4])) ? [] : normalizeHyouki(explode(",", $match[4]));
            $header['kiriji'] = normalizeKiriji(explode(",",$match[5]));    
        }
        else{
            //Заголовок не подошел под регулярное выражение.
            throw new Exception('Article has malformed header');
        }
    
        return $header;
    }

    function normalizeKana($kana){
        if(is_string($kana)){
            $kana = [$kana];
        }
        for($i=0;$i<count($kana);$i++){
            $kana[$i] = trim($kana[$i]);
            $kana[$i] = preg_replace('/([^A-Za-z])[IV]+$/','$1',$kana[$i]);
            $kana[$i] = str_replace(['…','!','.'],'',$kana[$i]);
        }
        return $kana;
    }
    
    function normalizeHyouki($hyouki){
        return normalizeKana($hyouki);
    }
    
    function normalizeKiriji($kiriji){
        if(is_string($kiriji)){
            $kiriji = [$kiriji];
        }
        for($i=0;$i<count($kiriji);$i++){
            $kiriji[$i] = trim($kiriji[$i]);
            $kiriji[$i] = str_replace(['-','…'], '',$kiriji[$i]);
            $kiriji[$i] = str_replace('ў','у',$kiriji[$i]);
            $kiriji[$i] = str_replace('й','и',$kiriji[$i]);
        }
        return $kiriji;
    }

    function file_get_contents_utf($filename){
	    $buf = file_get_contents($filename);

	    if      (substr($buf, 0, 3) == "\xEF\xBB\xBF")          return substr($buf,3);
	    else if (substr($buf, 0, 4) == "\xFF\xFE\x00\x00")      return iconv('UTF-32LE', 'UTF-8',substr($buf, 4));
	    else if (substr($buf, 0, 4) == "\x00\x00\xFE\xFF")      return iconv('UTF-32BE', 'UTF-8',substr($buf, 4));
	    else if (substr($buf, 0, 2) == "\xFE\xFF")              return iconv('UTF-16BE', 'UTF-8',substr($buf, 2));
	    else if (substr($buf, 0, 2) == "\xFF\xFE")              return iconv('UTF-16LE', 'UTF-8',substr($buf, 2));
	    else                                                    return $buf;
	}
