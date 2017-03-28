<?php

    $options = getopt('h',['help','dir:', 'file:', 'ref:','xedition']);

    if(isset($options['h']) || isset($options['help'])){
        print("Скрипт генерации текстовой версии словарей.\n");
        print("Использование: php.exe builder.php --dir --file [--ref --xedition]");
        print("\n\n");
        print("--dir                     Путь к директории с файлами карточек или,
                                         если необходимо сгенерировать прошлую версию по тэгу репозитория,
                                         - путь к директории .git (находится
                                         внутри директории с карточками).\n");
        print("--file                    Имя файла, в который необходимо положить результат.\n");

        print("--ref                     Тэг или коммит, на момент которых нужно взять версию
                                         (необязательный параметр, если отсутствует, будет взята последняя версия).\n");
        print("--xedition                Указание на то, что необходимо сгенерировать полную редакторскую версию.\n");
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
    ksort($entries);

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
                    if(is_file("{$path}/{$entry}")){
                        $code = explode('.',$entry)[0];
                        $entries[$code] =  trim(file_get_contents_utf("{$path}/{$entry}"));
                    }
                    else{
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

    function file_get_contents_utf($filename){
	    $buf = file_get_contents($filename);

	    if      (substr($buf, 0, 3) == "\xEF\xBB\xBF")          return substr($buf,3);
	    else if (substr($buf, 0, 4) == "\xFF\xFE\x00\x00")      return iconv('UTF-32LE', 'UTF-8',substr($buf, 4));
	    else if (substr($buf, 0, 4) == "\x00\x00\xFE\xFF")      return iconv('UTF-32BE', 'UTF-8',substr($buf, 4));
	    else if (substr($buf, 0, 2) == "\xFE\xFF")              return iconv('UTF-16BE', 'UTF-8',substr($buf, 2));
	    else if (substr($buf, 0, 2) == "\xFF\xFE")              return iconv('UTF-16LE', 'UTF-8',substr($buf, 2));
	    else                                                    return $buf;
	}