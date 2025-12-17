<?php

// msi paths
//
// load msi db files from mscfb container;
// load msi db tables (see ms orca, wine source)
// parse msi source and destination directories
// 

// *************************************
// *
// * level 0 - MS container
// *
// *************************************

// lib 2
$container = isset($argv[1]) ? $argv[1] : 'setup.msi'; //'orca.msi';
require_once 'MSCFB.php';
$mscbf = new MSCFB($container, true);
if ($mscbf->error) die("\nfile not found `$container`"); else echo "loaded $container\n";


function fetStream($mscbf, $name) {
	$contents = false;
	$id = $mscbf->get_by_name($name, 1);
	if ($id >= 0) {
		$other_file = fopen('php://memory', 'w+b');
		$io = $mscbf->extract_stream($id, $other_file);
		if ($io === true) {
		  rewind($other_file);
		  $contents = stream_get_contents($other_file);
		} else {
		  print("mscbf: extract_stream error `$name`\n");
		}
		fclose($other_file); 
	} else {
		print("mscbf: `$name` not found in container\n");
	}
	return $contents;
}

// lib 2 test (OK)
//foreach ($file->DE as $k=>$v) {
//	echo $k, ' ', $v['type'], ' ', $v['sizeL'], ' [', $v['name']."]";
//	if (strlen($v['name']) > 0) {
//		
//		if ($v['type'] == 2 ) {
//			$contents = fetStream($file, $v['name16']);
//			file_put_contents("Setup2/".$v['name'], $contents);
//		} else {
//			print(" Skip stream");
//		}
//	} else {
//		print(" Skip name");
//	}
//	echo "\n";
//}
//die;


// *************************************
// *
// * level 2 - msi database
// *
// * msi internal db structure
// *
// *************************************


class MsiStringTable
{
    // Парсер строкового пула MSI (StringData + StringPool)
    public $strings = array();
    public $codepage = 0;
    
    public function loadFromMem($stringData, $dataSize, $stringPool, $poolSize, &$bytes_per_strref)
    {        
        $poolWords = unpack('v*', substr($stringPool, 0, 4)); // 1. Читаем заголовок StringPool (первые 4 байта)
	$bytes_per_strref = ($poolSize > 4) && ($poolWords[2] & 0x8000) ? MSIDatabase::LONG_STR_BYTES : 2 /*sizeof(USHORT)*/;
        $this->codepage = $poolWords[1] | (($poolWords[2] & 0x7FFF) << 16);        
        
        // 2. Парсим пул строк (начиная с позиции 4)
        $poolOffset = 4;
        $dataOffset = 0;
        $stringId = 1;
        
        while ($poolOffset + 3 < $poolSize) {
            
            $poolEntry = unpack('v*', substr($stringPool, $poolOffset, 4)); // Читаем 4 байта для каждой записи пула
            $poolOffset += 4;
            
            $len = $poolEntry[1];
            $refs = $poolEntry[2];

            if ($len === 0 && $refs === 0) { // Пропускаем пустые записи
                $stringId++;
                continue;
            }
            
            if ($len === 0) { // Обработка длинных строк (>64K)
                if ($poolOffset + 3 >= $poolSize) break;
                $nextEntry = unpack('v*', substr($stringPool, $poolOffset, 4));
                $poolOffset += 4;
                $len = $nextEntry[1] + ($nextEntry[2] << 16);
            }
            
            if ($dataOffset + $len <= $dataSize) {  // Извлекаем строку из StringData
                $this->strings[$stringId] = substr($stringData, $dataOffset, $len);
                $dataOffset += $len;
            }
            
            $stringId++;
        }

        return true;
    }   

    public function loadFromFiles($stringDataFile, $stringPoolFile, &$bytes_per_strref) {

        // 1. Загружаем StringPool
	$poolSize = filesize($stringPoolFile);
	if ($poolSize === false) return false;

        $stringPool = file_get_contents($stringPoolFile);
        if ($stringPool === false) return false;

        // 2. Загружаем StringData
	$dataSize = filesize($stringDataFile);
	if ($dataSize === false) return false;

        $stringData = file_get_contents($stringDataFile);
        if ($stringData === false) return false;

	return $this->loadFromMem($stringData, $dataSize, $stringPool, $poolSize, $bytes_per_strref);
    }

    public function getStringById($id)
    {
        if ($id == 0) return "";
        return isset($this->strings[$id]) ? $this->strings[$id] : null;
    }    

}


// Структура MSITABLE на PHP
class MSITABLE {
	public $data = array();
	public $data_persistent = array();
	public $row_count = 0;
	public $colinfo = array();
	public $col_count = 0;
	public $persistent = 0; // MSICONDITION_TRUE
	public $ref_count = 0;
	public $name = "";

	public function __construct($name) {
		$this->name = $name;
		$this->persistent = 1; // MSICONDITION_TRUE

		if ($name === "_Tables" || $name === "_Columns") {
			$this->persistent = 0; // MSICONDITION_NONE
		}
	}
}

// Получение информации о колонках таблицы MSI
class MSIDatabase {

    public $stringTable = null;
    public $bytes_per_strref = 0;

    //const MAX_STREAM_NAME_LEN		= 62;
    const LONG_STR_BYTES		= 3;
    //const INSTALLUILEVEL_MASK		= 0x0007;
    const MSI_NULL_INTEGER              = 0x80000000;

    const ERROR_SUCCESS			= 0;
    const ERROR_FUNCTION_FAILED		= 1;
    const ERROR_INVALID_PARAMETER	= 2;

    const MSI_DATASIZEMASK              = 0x00ff; 
    const MSITYPE_VALID                 = 0x0100;
    const MSITYPE_LOCALIZABLE           = 0x0200;
    const MSITYPE_STRING                = 0x0800;
    const MSITYPE_NULLABLE              = 0x1000;
    const MSITYPE_KEY                   = 0x2000;
    const MSITYPE_TEMPORARY             = 0x4000;
    const MSITYPE_UNKNOWN               = 0x8000;

    public $MSICOLUMNINFO = 
	array('tablename' => '','number' => 0,'colname' => '','type' => 0,'offset' => 0,'hash_table' => null
    );

    /* Информация о таблицах по умолчанию*/
    public $_Columns_cols = array(
        array('tablename' => '_Columns', 'number' => 1, 'colname' => 'Table',  'type' => self::MSITYPE_VALID | self::MSITYPE_STRING | self::MSITYPE_KEY | 64,'offset' => 0, 'hash_table' => null        ),
        array('tablename' => '_Columns', 'number' => 2, 'colname' => 'Number', 'type' => self::MSITYPE_VALID | self::MSITYPE_KEY | 2,                        'offset' => 2, 'hash_table' => null        ),
        array('tablename' => '_Columns', 'number' => 3, 'colname' => 'Name',   'type' => self::MSITYPE_VALID | self::MSITYPE_STRING | 64,                    'offset' => 4, 'hash_table' => null        ),
        array('tablename' => '_Columns', 'number' => 4, 'colname' => 'Type',   'type' => self::MSITYPE_VALID | 2,                                            'offset' => 6, 'hash_table' => null        )
    );
    
    private $_Tables_cols = array(
        array('tablename' => '_Tables','number' => 1,'colname' => 'Name','type' => self::MSITYPE_VALID | self::MSITYPE_STRING | self::MSITYPE_KEY | 64,'offset' => 0,'hash_table' => null)
    );

    static function GetTName($t) {
	$s = '';
	if ($t & self::MSITYPE_VALID) $s.='MSITYPE_VALID ';
	if ($t & self::MSITYPE_LOCALIZABLE) $s.='MSITYPE_LOCALIZABLE ';
	if ($t & self::MSITYPE_STRING) $s.='MSITYPE_STRING ';
	if ($t & self::MSITYPE_NULLABLE) $s.='MSITYPE_NULLABLE ';
	if ($t & self::MSITYPE_KEY) $s.='MSITYPE_KEY ';
	if ($t & self::MSITYPE_TEMPORARY) $s.='MSITYPE_TEMPORARY ';
	if ($t & self::MSITYPE_UNKNOWN) $s.='MSITYPE_UNKNOWN ';
	return $s;
    }    

    static function MSITYPE_IS_BINARY($type) { // Проверка, является ли колонка бинарной (MSITYPE_IS_BINARY)
        return (($type & ~(self::MSITYPE_NULLABLE)) == (self::MSITYPE_STRING | self::MSITYPE_VALID));
    }

    public static function bytes_per_column($col, $bytes_per_strref) {
        $type = $col['type'];
        
        if (self::MSITYPE_IS_BINARY($type)) return 2; // Для бинарных колонок всегда 2 байта
        if ($type & self::MSITYPE_STRING)   return $bytes_per_strref;

        $tsize = $type & self::MSI_DATASIZEMASK;
        if ($tsize <= 2) 		    return 2;
        if ($tsize != 4)                die("Invalid column size ".($type & 0xff)."\n");
        return 4;
    }
    
    private function table_calc_column_offsets($db, &$colinfo, $count) {
        for ($i = 0; $colinfo && $i < $count; $i++) {
            assert( $i + 1 == $colinfo[$i]['number'] );
            
            if ($i > 0) {
                $colinfo[$i]['offset'] = $colinfo[$i - 1]['offset'] + 
			$this->bytes_per_column($colinfo[$i - 1], self::LONG_STR_BYTES);
            } else {
                $colinfo[$i]['offset'] = 0;
            }
            //printf("column %d is [%s] with type %08x ofs %d\n",
            //       $colinfo[$i]['number'], $colinfo[$i]['colname'],
            //       $colinfo[$i]['type'], $colinfo[$i]['offset']);
        }
    }    

    private function get_defaulttablecolumns($db, $tableName, &$colinfo, &$sz) {
        $p = null;
        $n = 0;
        $i = 0;
	
        if ($tableName === "_Tables") {
            $p = $this->_Tables_cols;
            $n = 1;
        } elseif ($tableName === "_Columns") {
            $p = $db->_Columns_cols;
            $n = 4;
        } else {
            return self::ERROR_FUNCTION_FAILED;
        }
        
        for ($i = 0; $i < $n; $i++) {
            if ($colinfo && $i < $sz) {
                // Копируем информацию о колонке
		$colinfo[$i] = $p[$i];
                //$colinfo[$i] = array(
                //    'tablename' => $p[$i]['tablename'],
                //    'number' => $p[$i]['number'],
                //    'colname' => $p[$i]['colname'],
                //    'type' => $p[$i]['type'],
                //    'offset' => $p[$i]['offset'],
                //    'hash_table' => $p[$i]['hash_table']
                //);
            }
            
            if ($colinfo && $i >= $sz) {
                break;
            }
        }
	
        // Вычисляем смещения колонок
        $this->table_calc_column_offsets($db, $colinfo, $n);
	//print("get_defaulttablecolumns:n=$n\n");
        $sz = $n;
        
        return self::ERROR_SUCCESS;
    }


    /**
     * table_get_column_info - Получение информации о колонках таблицы
     * @param MSIDatabase $db База данных
     * @param string $name Имя таблицы
     * @param array &$pcols Ссылка на массив с информацией о колонках
     * @param int &$pcount Ссылка на количество колонок
     * @return int Код ошибки
     */
    public static function table_get_column_info($db, $name, &$pcols, &$pcount) {
        $r = 0;
        $column_count = 0;
        $columns = array();
        
        // Получаем количество колонок в таблице
	$dummy = 0;
        $column_count = 0;
        $r = $db->get_tablecolumns($db, $name, $dummy, $column_count);
        if ($r != self::ERROR_SUCCESS) {
	    printf("Error:get_tablecolumns\n");
            return $r;
        }
        
        $pcount = $column_count;

        if (!$column_count) {
	    printf("Error:!column_count\n");
            return self::ERROR_INVALID_PARAMETER;
        }
        
        // TRACE("table %s found\n", debugstr_w(name));
        
        // Создаем массив для колонок
        $columns = array_fill(0, $column_count, $db->MSICOLUMNINFO);
        
        $r = $db->get_tablecolumns($db, $name, $columns, $column_count);
        if ($r != self::ERROR_SUCCESS) {
            $columns = array();
            return self::ERROR_FUNCTION_FAILED;
        }
        
        $pcols = $columns;
        return $r;
    }



    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    /**
     * Вычисляет размер строки таблицы MSI
     * @param MSIDatabase $db База данных
     * @param array $cols Массив информации о колонках
     * @param int $count Количество колонок
     * @param int $bytes_per_strref Размер строковой ссылки (2 или 3 байта)
     * @return int Размер строки в байтах
     */
    public static function msi_table_get_row_size($db, $cols, $count, $bytes_per_strref) {
        if (!$count) 
            return 0;
       
        if ($bytes_per_strref != self::LONG_STR_BYTES) {
            $size = 0;
            for ($i = 0; $i < $count; $i++) {
                $size += self::bytes_per_column($cols[$i], $bytes_per_strref);
            }
            return $size;
        }
        
        $last_col = $cols[$count - 1];
        return $last_col['offset'] + self::bytes_per_column($last_col, $bytes_per_strref);
    }
    
   /**
     * read_stream_data - чтение данных из потока
     * @param string $stg Путь к директории/хранилищу
     * @param string $stname Имя потока
     * @param bool $table Флаг таблицы (для кодирования имени)
     * @param string &$pdata Ссылка для данных
     * @param int &$psz Ссылка для размера
     * @return int Код ошибки
     */
    public static function read_stream_data($stg, $stname, $table, &$pdata, &$psz) {

        $ret = self::ERROR_FUNCTION_FAILED;

	$encname = ($table ? '!' : '') .$stname;

	//echo "$encname.idb\n";
        //$pdata = $GLOBALS['cfb']->getStream( $encname );
	$pdata = fetStream($GLOBALS['mscbf'], $encname);
        $psz = strlen($pdata);
	if ($psz <= 0) {
		die("Can't load $encname from msi container\n");
	}
	return self::ERROR_SUCCESS;

        
        // TRACE("%s -> %s\n", debugstr_w(stname), debugstr_w(encname));
        
        // Открываем поток (в PHP - просто файл)
        $filename = $stg . '/' . $encname;
        if (!file_exists($filename)) {
            printf("open stream failed - empty table($filename)?\n");
            return $ret;
        }
        
        // Получаем размер файла
        $filesize = filesize($filename);
        if ($filesize === false) {
            printf("open stream failed!\n");
            return $ret;
        }
	echo $filesize."\n";
        
        // Проверяем размер (не более 4GB)
        if ($filesize > 0xFFFFFFFF) {
            printf("Too big!\n");
            return $ret;
        }
        
        $sz = $filesize;
        
        // Читаем данные
        $data = file_get_contents($filename);
        if ($data === false) {
            printf("read stream failed!\n");
            return $ret;
        }
        
        if (strlen($data) != $sz) {
            printf("read stream failed - size mismatch!\n");
            return $ret;
        }
        
        $pdata = $data;
        $psz = $sz;
        return self::ERROR_SUCCESS;
    }
    
    /**
     * read_table_from_storage - чтение таблицы из хранилища
     * @param MSIDatabase $db База данных
     * @param MSITable &$t Ссылка на объект таблицы
     * @param string $stg Путь к хранилищу
     * @return int Код ошибки
     */
    public static function read_table_from_storage($db, &$t, $stg, $do_associative_index = -1) {
        $rawdata = '';
        $rawsize = 0;
        
        ////printf("read_table_from_storage: %s\n", $t->name);
        
        // Получаем размер строки (заглушки)
        $row_size = self::msi_table_get_row_size($db, $t->colinfo, $t->col_count, $db->bytes_per_strref);
        $row_size_mem = self::msi_table_get_row_size($db, $t->colinfo, $t->col_count, self::LONG_STR_BYTES);
        
        // Пытаемся прочитать таблицу
        $result = self::read_stream_data($stg, $t->name, true, $rawdata, $rawsize);
        if ($result != self::ERROR_SUCCESS || empty($rawdata)) {
            printf("read_table_from_storage: Error:self::read_stream_data\n");
            return self::ERROR_SUCCESS;
        }
        
        // TRACE("Read %d bytes\n", $rawsize);
        
        // Проверяем размер таблицы
        if ($rawsize % $row_size != 0) {
            printf("read_table_from_storage: `{$t->name}` Table size is invalid %d/%d\n", $rawsize, $row_size);
	    die;
            return self::ERROR_FUNCTION_FAILED;
        }
        
        // Вычисляем количество строк
        if ($row_size > 0) {
            $t->row_count = (int)($rawsize / $row_size);
        } else {
            $t->row_count = 0;
        }
        
        if ($t->row_count > 0) {
            // Выделяем память для данных
            //$t->data = array_fill(0, $t->row_count, array());
	    $t->data = [];
            $t->data_persistent = array_fill(0, $t->row_count, true);
        }

	for ($j = 0; $j < $t->col_count; $j++) {
		$t->colinfo[$j]['m'] = self::bytes_per_column($t->colinfo[$j], self::LONG_STR_BYTES);

		$n = self::bytes_per_column($t->colinfo[$j], $db->bytes_per_strref);
                if ($n != 2 && $n != 3 && $n != 4) {
                    printf("oops - unknown column width %d\n", $n);
		    die;
                    return self::ERROR_FUNCTION_FAILED;
                }

		$t->colinfo[$j]['n'] = $n;
	}

        
        // Транспонируем данные
        // TRACE("Transposing data from %d rows\n", $t->row_count);
	$t->index = [];
        for ($i = 0; $i < $t->row_count; $i++) {
            $ofs = 0;
            $ofs_mem = 0;
            
            // Инициализируем строку данных
            // $t->data[$i] = array_fill(0, $row_size_mem, 0);
            
            for ($j = 0; $j < $t->col_count; $j++) {
                $m = $t->colinfo[$j]['m'];//self::bytes_per_column($t->colinfo[$j], self::LONG_STR_BYTES);
                $n = $t->colinfo[$j]['n'];//self::bytes_per_column($t->colinfo[$j], $db->bytes_per_strref);

		
                // Копируем данные с учетом разницы в размере
                if (($t->colinfo[$j]['type'] & self::MSITYPE_STRING) && $n < $m) {
                    //die(' 778');
                    for ($k = 0; $k < $m; $k++) {
                        if ($k < $n && isset($rawdata[$ofs * $t->row_count + $i * $n + $k])) {
                            $t->data[$i][$ofs_mem + $k] = ord($rawdata[$ofs * $t->row_count + $i * $n + $k]);
                        } else {
                            $t->data[$i][$ofs_mem + $k] = 0;
                        }
                    }
                } else {
                    for ($k = 0; $k < $n; $k++) {
                        if (isset($rawdata[$ofs * $t->row_count + $i * $n + $k])) {
                            $t->data[$i][$ofs_mem + $k] = ord($rawdata[$ofs * $t->row_count + $i * $n + $k]);
                        } else {
                            $t->data[$i][$ofs_mem + $k] = 0;
                        }
                    }
                }
                
                $ofs_mem += $m;
                $ofs += $n;
            }

	    if ($do_associative_index >= 0 ) {
		$key = $db->fetchColumn($t, $t->data, $i, $t->colinfo[$do_associative_index], $do_associative_index);
		$t->index[$key] = $t->data[$i];
	    }
        }
        
        return self::ERROR_SUCCESS;
    }


    // improove, for big tables, and increase mem usage;
    // prefetch columns actual data (strings)
    public function fetch_table($t, $do_associative_index = -1) {
        for ($i = 0; $i < $t->row_count; $i++) {
		for ($j = 0; $j < $t->col_count; $j++) {

			$key = $do_associative_index >= 0 ? 
				$this->fetchColumn($t, $t->data, $i, $t->colinfo[$do_associative_index], $do_associative_index) : 
				$i;

			$val = $this->fetchColumn($t, $t->data, $i, $t->colinfo[$j], $j);
			$t->fetched[$key][$j] = $val;

		}
        }
	$t->data = NULL;
	$t->index = NULL;
    }

    public function get_table($name, &$table_ret, $do_associative_index = -1) {
        $table = null;
        $r = 0;
        
        //printf("\tLooking for table: %s\n", $name);
        
        /* first, see if the table is cached */
        //$table = $this->find_cached_table($name);
        //if ($table) {
        //    $table_ret = $table;
        //    return self::ERROR_SUCCESS;
        //}
        
        /* nonexistent tables should be interpreted as empty tables */
        $table = new MSITABLE($name);
	
	//printf("\ttable_get_column_info\n");
        $r = $this->table_get_column_info($this, $name, $table->colinfo, $table->col_count);
        if ($r != self::ERROR_SUCCESS) {
            //$this->free_table($table);
	    printf("Error: table_get_column_info\n");
            return $r;
        }

	//printf("\tread_table_from_storage\n");        
        $r = $this->read_table_from_storage($this, $table, "Setup" /*$this->storage*/, $do_associative_index );
        if ($r != self::ERROR_SUCCESS) {
            //$this->free_table($table);
	    printf("Error: read_table_from_storage\n");
            return $r;
        }
        
        // Добавляем таблицу в кэш
        //$this->list_add_head($this->tables, $table);
        $table_ret = $table;

	if ($table->name != '_Columns') {
		$this->fetch_table($table_ret, $do_associative_index);
	}
        
        return self::ERROR_SUCCESS;
    }
    

    /**
     * Чтение целого числа из данных таблицы
     * @param array $data Данные таблицы (массив строк)
     * @param int $row Номер строки
     * @param int $col Смещение в байтах внутри строки
     * @param int $bytes Количество байт для чтения (1, 2, 3, 4)
     * @return int Прочитанное целое число
     */
    function read_table_int($data, $row, $col, $bytes) {
        $ret = 0;       
        for ($i = 0; $i < $bytes; $i++) {
            $ret += $data[$row][$col + $i] << $i * 8;
        }
        return $ret;
    }
    
    function msi_string_lookup($id) {
        return $this->stringTable->getStringById($id);
    }    
    
    //msi_string2id - поиск ID строки в таблице строк
    function msi_string2id($str, $len, &$id) {
	$id = array_search($str, $this->stringTable->strings);
        return $id === false ? self::ERROR_INVALID_PARAMETER : self::ERROR_SUCCESS;
    }
    
    /**
     * Основная функция - получение колонок таблицы
     * @param MSIDatabase $db База данных
     * @param string $szTableName Имя таблицы
     * @param array &$colinfo Массив для информации о колонках
     * @param int &$sz Размер/количество колонок
     * @return int Код ошибки
     */
    public static function get_tablecolumns($db, $szTableName, &$colinfo, &$sz) {
        $r = 0;
        $i = 0;
        $n = 0;
        $table_id = 0;
        $count = 0;
        $maxcount = $sz;
        $table = null;

	////printf("\nget_tablecolumns:%s\n", $szTableName);
        
        // Сначала проверяем таблицу по умолчанию
        $r = $db->get_defaulttablecolumns($db, $szTableName, $colinfo, $sz);
        if ($r == self::ERROR_SUCCESS && $sz > 0) {
	    //printf("get_tablecolumns:get_defaulttablecolumns: OK\n");
            return $r;
        }
        
        // Получаем таблицу _Columns
        $r = $db->get_table("_Columns", $table);
        if ($r != self::ERROR_SUCCESS) {
            printf("get_tablecolumns:couldn't load _Columns table\n");
            return self::ERROR_FUNCTION_FAILED;
        } else {
            //printf("_Columns loaded\n");		
	    $x = array_slice($table->data, 0,2);
	    //print_r($x);
	}


        
        // Конвертируем имя таблицы в ID из таблицы строк
        $r = $db->msi_string2id($szTableName, -1, $table_id);
        if ($r != self::ERROR_SUCCESS) {
            printf("get_tablecolumns:Couldn't find id for %s\n", $szTableName);
            return $r;
        }
        //printf("get_tablecolumns:Table `%s` id is %d, row count is %d, maxcount = $maxcount\n", $table->name, $table_id, $table->row_count);
        
        // Если maxcount не ноль, предполагаем что он точно соответствует этой таблице
        if ($colinfo!==NULL && $maxcount > 0) {
            $colinfo = array_fill(0, $maxcount, $db->MSICOLUMNINFO);
        }
        
        $count = isset($table->row_count) ? $table->row_count : 0;

        //print("\nget_tablecolumns:row_count:$count\n");
        //print("DATA name      : $table->name      \n");
	//print("DATA row_count : $table->row_count \n");
        //print("DATA col_count : $table->col_count \n");
        //print("DATA persistent: $table->persistent\n");
        //print("DATA ref_count : $table->ref_count \n");
	//print("DATA row_size  : ".$db->msi_table_get_row_size($db, $table->colinfo, $table->col_count, $db->bytes_per_strref)."      \n");

        //print("DATA colinfo   :"); 
	//$table->colinfo = $db->_Columns_cols;
	//print_r($table->colinfo);
	//print_r($table->data);

        for ($i = 0; $i < $count; $i++) {
	    $tid = $db->read_table_int($table->data, $i, 0, self::LONG_STR_BYTES);
            if ($tid != $table_id) {
                continue;
            }
	    //die('777');
            
            if ($colinfo!==NULL && $colinfo!==0) {
                $id = $db->read_table_int($table->data, $i, 
			isset($table->colinfo[2]['offset']) ? $table->colinfo[2]['offset'] : 0,
			self::LONG_STR_BYTES);
                    
                $col = $db->read_table_int($table->data, $i, 
			isset($table->colinfo[1]['offset']) ? $table->colinfo[1]['offset'] : 0,
			2) - (1 << 15);
                
                // Проверяем что номер колонки в пределах диапазона
                if ($col < 1 || $col > 64) {
                    printf($table->colinfo[1]['offset']." $i ".(1 << 15)." column %d out of range (maxcount: %d)\n", $col, $maxcount);
		    print_r($table->colinfo);
		    die('628');
                    continue;
                }

		if (!isset($colinfo[$col - 1])) {
			$colinfo[$col - 1] = [];
		}
                
                // Проверяем не была ли уже установлена эта колонка
                if (isset($colinfo[$col - 1]['number']) && $colinfo[$col - 1]['number'] > 0) {
                    printf("duplicate column %d\n", $col);
                    continue;
                }

                $colinfo[$col - 1]['tablename']  = $db->msi_string_lookup($table_id);
                $colinfo[$col - 1]['number']     = $col;
                $colinfo[$col - 1]['colname']    = $db->msi_string_lookup($id);
                $colinfo[$col - 1]['type']       = $db->read_table_int($table->data, $i, $table->colinfo[3]['offset'], 2) - (1 << 15);
                $colinfo[$col - 1]['offset']     = 0;
                $colinfo[$col - 1]['hash_table'] = null;

		////print('['.$colinfo[$col - 1]['colname']."] : ".self::GetTName($colinfo[$col - 1]['type'])."\n");
            }
            $n++;
        }
        
        ////printf("%s has %d columns\n", $szTableName, $n);
        
        //if ($colinfo!==NULL && $n != $maxcount) {
        //    printf("missing column in table %s\n", $szTableName);
        //    //$db->free_colinfo($colinfo, $maxcount);
        //    return self::ERROR_FUNCTION_FAILED;
        //}
        
        $db->table_calc_column_offsets($db, $colinfo, $n);
        $sz = $n;
        return self::ERROR_SUCCESS;
    }


	public function loadTestTable($fn) {
		$rows = explode("\r\n",file_get_contents("{$fn}"));
		$table = [];
		$table['header'] = explode("\t",$rows[0]);
		$table['type'] = explode("\t",$rows[1]);
		$table['keys'] = explode("\t",$rows[2]);

		$table['data'] = [];
		foreach ($rows as $k=>&$val) {
			if ($k < 3) continue;
			if ($val === '') continue;

			$table['data'][$val] = 1;
		}
		return $table;	
	}

	public function fetchColumn(&$table, $data, $k, &$col, $kc) {

		$type = $col['type'];
		
		//if ($this->MSITYPE_IS_BINARY($type)) { // not support propertly
		if (($type & ~(self::MSITYPE_NULLABLE)) == (self::MSITYPE_STRING | self::MSITYPE_VALID)) {
			if ($kc > 0) {
				//naive for .idt tests...
				$n = $this->bytes_per_column( $col, MSIDatabase::LONG_STR_BYTES ); 
				$ival = $this->read_table_int($data, $k, $table->colinfo[$kc-1]['offset'], $n);
				$str  = $this->msi_string_lookup($ival);
				return $str.".ibd";
			} else {
				//error
				return "[BLOB] $ival";
			}
		}

		//cache
		if (!($col['m'])) {
			print('cache');die;
			$col['m'] = $this->bytes_per_column( $col, MSIDatabase::LONG_STR_BYTES ); 
			//$n = $db->bytes_per_column( $col, MSIDatabase::LONG_STR_BYTES ); 
			$n = $col['m'];
			if ($n != 2 && $n != 3 && $n != 4) {
				printf("oops! what is %d bytes per column?\n", $n );
				die;
			} 
		}

		//$n = $this->bytes_per_column( $col, MSIDatabase::LONG_STR_BYTES ); 
		$n = $col['m'];

		$ival = $this->read_table_int($data, $k, $col['offset'], $n);

		if (!($type & MSIDatabase::MSITYPE_VALID)) {
			die("Invalid type!\n");
		}
		if ($type & MSIDatabase::MSITYPE_STRING)
		{
		    $str = $this->msi_string_lookup($ival);
		    return $str;
		    //printf($str."\t");
		}
		else
		{
		    if (($type & MSIDatabase::MSI_DATASIZEMASK) == 2) {
			//MSI_RecordSetInteger(rec, i, ival ? ival - (1<<15) : MSIDatabase::MSI_NULL_INTEGER);
			$val = $ival ? $ival - (1<<15) : MSIDatabase::MSI_NULL_INTEGER;
		    } else {
			//MSI_RecordSetInteger(rec, i, ival - (1u<<31));
			$val = $ival - (1<<31);
			$val = sprintf("%d", $val);
		    }		    
		    if ($ival == 0) $val = '';
		    return $val;
		    //printf("[%d][%d] %08x\t %d %u\n", $type & MSIDatabase::MSI_DATASIZEMASK, $ival, $val, $val, $val);
		}

	}

    public function testTable($tableName, $verbose = 0) {
	$table = NULL;
	echo "1";flush();
	$this->get_table($tableName, $table);
	echo "2";flush();
	if ($verbose) {
		print("DATA name      : $table->name      \n");
		print("DATA row_count : $table->row_count \n");
		print("DATA col_count : $table->col_count \n");
		print("DATA persistent: $table->persistent\n");
		print("DATA ref_count : $table->ref_count \n");
		print("DATA row_size  : ".$this->msi_table_get_row_size($this, $table->colinfo, $table->col_count, $this->bytes_per_strref)."      \n");
	}

	if ($table->col_count > 0) {
	    //print_r($table->colinfo);
	    $idx = $this->loadTestTable("db/".$tableName.".idt");
	    echo "3";flush();
	    foreach ($table->data as $k => $row) {
		    $rr = [];
		    foreach ($table->colinfo as $kc=>$col) {
			$rr[] = $this->fetchColumn($table, $table->data, $k, $col, $kc);
		    }
		    $rr = implode("\t",$rr);

		    if (!isset($idx['data'][$rr])) {
			    print("\n");
			    print_R($rr);
			    die('Fatal1');
		    }
		    //print("\r\n");
	    }
	} else {
		print_r($table->colinfo);
		echo "Error: " . $result . "\n";
	}
	echo " OK: $tableName\n";
    }
}

$stringTable = new MsiStringTable();
$bytes_per_strref = 0;

$stringData = fetStream($mscbf, '!_StringData');
$dataSize = strlen($stringData);
$stringPool = fetStream($mscbf, '!_StringPool');
$poolSize = strlen($stringPool);
$stringTable->loadFromMem($stringData, $dataSize, $stringPool, $poolSize, $bytes_per_strref);
echo "\nloaded !_StringPool\n";

//if ($stringTable->loadFromFiles('setup/!_StringData', 'setup/!_StringPool', $bytes_per_strref)) {
if ($stringTable->loadFromMem($stringData, $dataSize, $stringPool, $poolSize, $bytes_per_strref)) {
    echo " Code Page  : " . $stringTable->codepage . "\n";
    echo " Total rows : " . count($stringTable->strings) . "\n";
    echo " Ref size   : " . $bytes_per_strref . "\n";    
} else {
    echo "Error parsing `!_StringPool`\n";
    die;
}
echo "\n";

// tests
$db = new MSIDatabase();
$db->bytes_per_strref = $bytes_per_strref;
$db->stringTable = $stringTable;

// these tables:
//   EventMapping Environment Dialog CustomAction ControlEvent ControlCondition Control AdvtExecuteSequence AdminUISequence AdminExecuteSequence 
//   Media LaunchCondition InstallUISequence InstallExecuteSequence
//   Binary Upgrade UIText TextStyle Shortcut RadioButton _Validation Feature FeatureComponents CreateFolder Directory Component File MsiFileHash Registry
//
// tested: OK

$doTests = 0;
if ($doTests) {
	print ("Testing tables:\n");
	$tests = explode(' ',"EventMapping Environment Dialog CustomAction ControlEvent ControlCondition Control AdvtExecuteSequence AdminUISequence AdminExecuteSequence Media LaunchCondition InstallUISequence InstallExecuteSequence Binary Upgrade UIText TextStyle Shortcut RadioButton _Validation Feature FeatureComponents CreateFolder Directory Component File MsiFileHash Registry");
	foreach ($tests as $k=>&$val) {
		$db->testTable($val);
	}
}





// *************************************
// *
// * level 3 - Install
// *
// * msi Components
// *
// *************************************

	//$directoryTable = LoadTable('db/Directory.idt'); //'TARGETDIR' => array('TARGETDIR', 'TARGETDIR', 'SourceDir:TARGETDIR'),
	//$directoryTable['data']['TARGETDIR'][1] = 'TARGETDIR';
	//$directoryTable['data']['TARGETDIR'][2] = 'TARGETDIR:SourceDir';
	//$componentTable = LoadTable('db/Component.idt');
	//$fileTable = LoadTable('db/File.idt');	

$directoryTable = NULL;
$db->get_table("Directory", $directoryTable, 0);
print ("loaded Directory.idb #".($directoryTable->row_count)."\n");
$componentTable = NULL;
$db->get_table("Component", $componentTable, 0);
print ("loaded Component.idb #".($componentTable->row_count)."\n");
$fileTable = NULL;
$db->get_table("File", $fileTable, 0);
print ("loaded File.idb #".($fileTable->row_count)."\n\n");

$pathCache = array();
function getNameWithLongPair($name) {
    $pairParts = explode('|', $name, 2);
    return count($pairParts) > 1 ? $pairParts[1] : $pairParts[0];
}

function parseDefaultDir($defaultDir) {
    $parts = explode(':', $defaultDir, 2);
    $targetPart = $parts[0];
    $sourcePart = count($parts) === 2 ? $parts[1] : $parts[0];
    return array(
//        'target' => getNameWithLongPair($targetPart),
//        'source' => getNameWithLongPair($sourcePart)
        getNameWithLongPair($targetPart), //'target' 0
        getNameWithLongPair($sourcePart)  //'source' 1

    );
}

function formatPath($dir, $pth) {
    if ($pth !== '.' && $pth !== '') {
	$dir =	$dir !== '' ? 
		$dir . '\\' . $pth : 
		$pth;
    }
    return $dir;
}

function getDirectoryPath($db, $t, $dirKey, &$dbindex, $depth = 0) {
    global $pathCache;
    //if ($depth > 255) return array('src' => '', 'dst' => '');
    if ($depth > 255) return array('', ''); //src, dst
    if (isset($pathCache[$dirKey])) return $pathCache[$dirKey];
    
    if (!isset($dbindex[$dirKey]) || !is_array($dbindex[$dirKey]) || count($dbindex[$dirKey]) < 3) {
        die("Fatal2:[$dirKey]");
        //return array('src' => '', 'dst' => '');
        return array('','');
    }
    
    $row = $dbindex[$dirKey];
    //$parentKey = $db->fetchColumn($t, $dbindex, $dirKey, $t->colinfo[1], 1);//$row[1];
    //$defaultDir = $db->fetchColumn($t, $dbindex, $dirKey, $t->colinfo[2], 2);//$row[2];
    $parentKey = $row[1];
    $defaultDir = $row[2];

    
    $isRootDirectory = ($parentKey === null || $parentKey === '' || $parentKey === $dirKey);
    $dirNames = parseDefaultDir($defaultDir);
    
    if ($isRootDirectory) {
        $result = array(
            $dirNames[1/*'source'*/] !== '.' ? '['.$dirNames[1/*'source'*/].']' : '', //src
            $dirNames[0/*'target'*/] !== '.' ? '['.$dirNames[0/*'target'*/].']' : ''  //dst
        );
        $pathCache[$dirKey] = $result;
        return $result;
    }
    
    $parentPath = getDirectoryPath($db, $t, $parentKey, $dbindex, $depth + 1);

    //$srcPath = formatPath($parentPath['src'], $dirNames['source']); // source path
    //$dstPath = formatPath($parentPath['dst'], $dirNames['target']); // target path
    $srcPath = formatPath($parentPath[0], $dirNames[1]/*$dirNames['source']*/); // source path
    $dstPath = formatPath($parentPath[1], $dirNames[0]/*$dirNames['target']*/); // target path
    
    //$result = array('src' => $srcPath, 'dst' => $dstPath);
    $result = array($srcPath, $dstPath); // src, dst
    $pathCache[$dirKey] = $result;
    return $result;
}

// Основной вызов - заполняем кеш
$progress = 1;
foreach ($directoryTable->fetched as $dirKey => $row) {
	if ($progress % 500 == 0) {
		echo "\x0dPreparing data: ".$progress ." of ". $directoryTable->row_count."    ";
	}
    getDirectoryPath($db, $directoryTable, $dirKey, $directoryTable->fetched);
    $progress++;
}
echo "Dirs loaded\n";

const msidbComponentAttributesRegistryKeyPath =  4;
const msidbComponentAttributesODBCDataSource  = 32;

foreach ($componentTable->fetched as $key=>$raw) {
	$row = [];
	//$row[2] = $db->fetchColumn($componentTable, $componentTable->index, $key, $componentTable->colinfo[2], 2);
	//$row[5] = $db->fetchColumn($componentTable, $componentTable->index, $key, $componentTable->colinfo[5], 5);
	$row = $raw;
	if (!isset($pathCache[$row[2]])) {
		die("Fatal3:".$row[2]."\n");
	}

	if (strlen($row[5])) {
		$attr = (int)$row[5];
		if ($attr & msidbComponentAttributesRegistryKeyPath == 0 && $attr & msidbComponentAttributesODBCDataSource == 0) {
			if (!isset($fileTable['data'][$row[5]])) {
				die("Fatal4:".$row[5]."\n");
			}
		}
	}
}
echo "Components Checked OK\n\n";

foreach ($fileTable->fetched as $key=>$raw) {
	$row = [];
	//$row[1] = $db->fetchColumn($fileTable, $fileTable->index, $key, $fileTable->colinfo[1], 1);
	//$row[2] = $db->fetchColumn($fileTable, $fileTable->index, $key, $fileTable->colinfo[2], 2);
	$row = $raw;
	if (strlen($row[1])) {
		if (!isset($row[1])) {
			die("Fatal5:".$row[1]."\n");
		}
		$comp = $componentTable->fetched[$row[1]];
		//print_r($comp);
		//$comp = $db->fetchColumn($componentTable, $componentTable->index, $row[1], $componentTable->colinfo[2], 2);
		$file_pth = $pathCache[$comp[2]];
		$file_nam = getNameWithLongPair($row[2]);
		//echo $file_pth['src'].'\\'.$file_nam. " -> ".$file_pth['dst'].'\\'.$file_nam."\n";
		echo $file_pth[0].'\\'.$file_nam. " -> ".$file_pth[1].'\\'.$file_nam."\n"; //src, dst
	}
}


die;
/*

//$colinfo = array();
$sz = 0;

$tableName = "File";
printf("Begin:\n");
//$result = MSIDatabase::get_tablecolumns($db, $tableName, $colinfo, $sz);

$table = NULL;
$db->get_table($tableName, $table);
print("DATA name      : $table->name      \n");
print("DATA row_count : $table->row_count \n");
print("DATA col_count : $table->col_count \n");
print("DATA persistent: $table->persistent\n");
print("DATA ref_count : $table->ref_count \n");
print("DATA row_size  : ".$db->msi_table_get_row_size($db, $table->colinfo, $table->col_count, $db->bytes_per_strref)."      \n");

function LoadTable($fn) {
	$rows = explode("\r\n",file_get_contents("{$fn}"));
	$table = [];
	$table['header'] = explode("\t",$rows[0]);
	$table['type'] = explode("\t",$rows[1]);
	$table['keys'] = explode("\t",$rows[2]);

	$table['data'] = [];
	foreach ($rows as $k=>&$val) {
		if ($k < 3) continue;
		if ($val === '') continue;

		$table['data'][$val] = 1;
	}
	return $table;	
}


//if ($result == MSIDatabase::ERROR_SUCCESS) {
if ($table->col_count > 0) {
    echo "Columns count: " . $sz . "\n";
    print_r($table->colinfo);
    $idx = LoadTable("db/".$tableName.".idt");
    foreach ($table->data as $k => $row) {
	    $rr = [];
	    foreach ($table->colinfo as $kc=>$col) {
		$type = $col['type'];

		if ($db->MSITYPE_IS_BINARY($type)) { // not support propertly
			if ($kc > 0) {
				//naive for .idt tests...
				$n = $db->bytes_per_column( $col, MSIDatabase::LONG_STR_BYTES ); 
				$ival = $db->read_table_int($table->data, $k, $table->colinfo[$kc-1]['offset'], $n);
				$str  = $db->msi_string_lookup($GLOBALS['stringTable'], $ival);
				$rr[] = $str.".ibd";
			} else {
				//error
				$rr[] = "[BLOB] $ival";
			}
			//printf('[BLOB]'."\t");
			continue;
		}

		//cache
		if (!isset($col['hash_table'])){
			$col['hash_table'] = $db->bytes_per_column( $col, MSIDatabase::LONG_STR_BYTES ); 
			//$n = $db->bytes_per_column( $col, MSIDatabase::LONG_STR_BYTES ); 
			if ($n != 2 && $n != 3 && $n != 4) {
				printf("oops! what is %d bytes per column?\n", $n );
				die;
			} 
		}

		$n = $col['hash_table'];
	        $ival = $db->read_table_int($table->data, $k, $col['offset'], $n);

		if (!($type & MSIDatabase::MSITYPE_VALID)) {
			printf("Invalid type!\n");
			die;
		}
		if ($type & MSIDatabase::MSITYPE_STRING)
		{
		    $str = $db->msi_string_lookup($GLOBALS['stringTable'], $ival);
		    $rr[] = $str;
		    //printf($str."\t");
		}
		else
		{
		    if (($type & MSIDatabase::MSI_DATASIZEMASK) == 2) {
			//MSI_RecordSetInteger(rec, i, ival ? ival - (1<<15) : MSIDatabase::MSI_NULL_INTEGER);
			$val = $ival ? $ival - (1<<15) : MSIDatabase::MSI_NULL_INTEGER;
		    } else {
			//MSI_RecordSetInteger(rec, i, ival - (1u<<31));
		    	$val = $ival - (1<<31);
			$val = sprintf("%d", $val);
		    }		    
		    if ($ival == 0) $val = '';
		    $rr[] = $val;
		    //printf("[%d][%d] %08x\t %d %u\n", $type & MSIDatabase::MSI_DATASIZEMASK, $ival, $val, $val, $val);
		}
	    }
            $rr = implode("\t",$rr);

	    if (!isset($idx['data'][$rr])) {
		    print("\n");
		    print_R($rr);
		    die('Fatal7');
	    }
	    //print("\r\n");
    }
} else {
    echo "Ошибка: " . $result . "\n";
}

print "allOk";

*/

?>