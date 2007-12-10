<?php
    /**
     * @class DBPostgreSQL
     * @author ioseph (ioseph@postgresql.kr)
     * @brief MySQL DBMS를 이용하기 위한 class
     * @version 0.1
     *
     * postgresql handling class
     **/

    class DBPostgresql extends DB {

        /**
         * @brief PostgreSQL DB에 접속하기 위한 정보
         **/
        var $hostname = '127.0.0.1'; ///< hostname
        var $userid   = NULL; ///< user id
        var $password   = NULL; ///< password
        var $database = NULL; ///< database
        var $prefix   = 'xe'; ///< 제로보드에서 사용할 테이블들의 prefix  (한 DB에서 여러개의 제로보드 설치 가능)

        /**
         * @brief postgresql에서 사용될 column type
         *
         * column_type은 schema/query xml에서 공통 선언된 type을 이용하기 때문에
         * 각 DBMS에 맞게 replace 해주어야 한다
         **/
        var $column_type = array(
            'bignumber' => 'bigint',
            'number' => 'integer',
            'varchar' => 'varchar',
            'char' => 'char',
            'text' => 'text',
            'bigtext' => 'text',
            'date' => 'varchar(14)',
        );

        /**
         * @brief constructor
         **/
        function DBPostgresql() {
            $this->_setDBInfo();
            $this->_connect();
        }

        /**
         * @brief 설치 가능 여부를 return
         **/
        function isSupported() {
            if(!function_exists('pg_connect')) return false;
            return true;
        }

        /**
         * @brief DB정보 설정 및 connect/ close
         **/
        function _setDBInfo() {
            $db_info = Context::getDBInfo();
            $this->hostname = $db_info->db_hostname;
            $this->userid   = $db_info->db_userid;
            $this->password   = $db_info->db_password;
            $this->database = $db_info->db_database;
            $this->prefix = $db_info->db_table_prefix;
            if(!substr($this->prefix,-1)!='_') $this->prefix .= '_';
        }

        /**
         * @brief DB 접속
         **/
        function _connect() {
			// pg용 connection string 
			$conn_string = "";

            // db 정보가 없으면 무시
            if(!$this->hostname || !$this->userid || !$this->database) return;

			// connection string 만들기
			$conn_string .= ($this->hostname) ? " host=$this->hostname" : "";
			$conn_string .= ($this->userid) ? " user=$this->userid" : "";
			$conn_string .= ($this->password) ? " password=$this->password" : "";
			$conn_string .= ($this->database) ? " dbname=$this->database" : "";
			$conn_string .= ($this->port) ? " port=$this->port" : "";

            // 접속시도  
            $this->fd = @pg_connect($conn_string);
            if(pg_connection_status($this->fd) != PGSQL_CONNECTION_OK) {
                $this->setError(-1, "CONNECTION FAILURE");
                return;
            }

            // 접속체크
            $this->is_connected = true;

            // utf8임을 지정
            //$this ->_query('set client_encoding to uhc');
        }

        /**
         * @brief DB접속 해제
         **/
        function close() {
            if(!$this->isConnected()) return;
            @pg_close($this->fd);
        }

        /**
         * @brief 쿼리에서 입력되는 문자열 변수들의 quotation 조절
         **/
        function addQuotes($string) {
            if(get_magic_quotes_gpc()) $string = stripslashes(str_replace("\\","\\\\",$string));
            if(!is_numeric($string)) $string = @pg_escape_string($string);
            return $string;
        }

        /**
         * @brief 트랜잭션 시작
         **/
        function begin() {
            if(!$this->isConnected() || $this->transaction_started == false) return;
            if($this->_query($this->fd, 'BEGIN'))
            $this->transaction_started = true;
        }

        /**
         * @brief 롤백
         **/
        function rollback() {
            if(!$this->isConnected() || $this->transaction_started == false) return;
            if($this->_query($this->fd, 'ROLLBACK'))
            $this->transaction_started = false;
        }

        /**
         * @brief 커밋
         **/
        function commit() {
            if(!$this->isConnected() || $this->transaction_started == false) return;
            if($this->_query($this->fd, 'COMMIT'))
            $this->transaction_started = false;
        }

        /**
         * @brief : 쿼리문의 실행 및 결과의 fetch 처리
         *
         * query : query문 실행하고 result return\n
         * fetch : reutrn 된 값이 없으면 NULL\n
         *         rows이면 array object\n
         *         row이면 object\n
         *         return\n
         **/
        function _query($query) {
            if(!$this->isConnected()) return;

            // 쿼리 시작을 알림
            $this->actStart($query);

            // 쿼리 문 실행
            $result = @pg_query($this->fd, $query);

            // 오류 체크
            if(!$result) $this->setError(1, pg_last_error($this->fd));

            // 쿼리 실행 종료를 알림
            $this->actFinish();

            // 결과 리턴
            return $result;
        }

        /**
         * @brief 결과를 fetch
         **/
        function _fetch($result) {
            if(!$this->isConnected() || $this->isError() || !$result) return;
            while($tmp = pg_fetch_object($result)) {
                $output[] = $tmp;
            }
            if(count($output)==1) return $output[0];
            return $output;
        }

        /**
         * @brief 1씩 증가되는 sequence값을 return (postgresql의 auto_increment는 sequence테이블에서만 사용)
         **/
        function getNextSequence() {
            $query = sprintf("select nextval('%ssequence') as seq", $this->prefix);
            $result = $this->_query($query);
            $tmp = $this->_fetch($result);
            return $tmp->seq;
        }

        /**
         * @brief 테이블 기생성 여부 return
         **/
        function isTableExists($target_name) {
            if($target_name == "sequence") return true;
            $query = sprintf("SELECT tablename FROM pg_tables WHERE tablename = '%s%s' AND schemaname = current_schema()", $this->prefix, $this->addQuotes($target_name));

            $result = $this->_query($query);
            $tmp = $this->_fetch($result);
            if(!$tmp) return false;
            return true;
        }

        /**
         * @brief 특정 테이블에 특정 column 추가
         **/
        function addColumn($table_name, $column_name, $type='number', $size='', $default = '', $notnull=false) {
            $type = $this->column_type[$type];
            if(strtoupper($type)=='INTEGER' || strtoupper($type)=='BIGINT') $size = '';

            $query = sprintf("alter table %s%s add %s ", $this->prefix, $table_name, $column_name);
            if($size) $query .= sprintf(" %s(%s) ", $type, $size);
            else $query .= sprintf(" %s ", $type);
            if($default) $query .= sprintf(" default '%s' ", $default);
            if($notnull) $query .= " not null ";

            $this->_query($query);
        }


        /**
         * @brief 특정 테이블의 column의 정보를 return
         **/
        function isColumnExists($table_name, $column_name) {
            $query = sprintf("select column_name from information_schema.columns where table_schema = current_schema() and table_name = '%s%s' and column_name = '%s'", $this->prefix, $this->addQuotes($table_name), strtolower($column_name));
            $result = $this->_query($query);
            if($this->isError()) return;
            $output = $this->_fetch($result);
            if($output) return true;
            return false;
        }

        /**
         * @brief 특정 테이블에 특정 인덱스 추가
         * $target_columns = array(col1, col2)
         * $is_unique? unique : none
         **/
        function addIndex($table_name, $index_name, $target_columns, $is_unique = false) {
            if(!is_array($target_columns)) $target_columns = array($target_columns);

            if(strpos($table_name,$this->prefix)===false) $table_name = $this->prefix.$table_name;

            // index_name의 경우 앞에 table이름을 붙여줘서 중복을 피함
            $index_name = $table_name.$index_name;

            $query = sprintf("create %s index %s on %s (%s);", $is_unique?'unique':'', $index_name, $table_name, implode(',',$target_columns));
            $this->_query($query);
        }

        /**
         * @brief 특정 테이블의 index 정보를 return
         **/
        function isIndexExists($table_name, $index_name) {
            if(strpos($table_name,$this->prefix)===false) $table_name = $this->prefix.$table_name;

            // index_name의 경우 앞에 table이름을 붙여줘서 중복을 피함
            $index_name = $table_name.$index_name;

            //$query = sprintf("show indexes from %s%s where key_name = '%s' ", $this->prefix, $table_name, $index_name);
            $query = sprintf("select indexname from pg_indexes where schemaname = current_schema() and tablename = '%s' and indexname = '%s'", $table_name, strtolower($index_name));
            $result = $this->_query($query);
            if($this->isError()) return;
            $output = $this->_fetch($result);

            if($output) return true;
            return false;
        }

        /**
         * @brief xml 을 받아서 테이블을 생성
         **/
        function createTableByXml($xml_doc) {
            return $this->_createTable($xml_doc);
        }

        /**
         * @brief xml 을 받아서 테이블을 생성
         **/
        function createTableByXmlFile($file_name) {
            if(!file_exists($file_name)) return;
            // xml 파일을 읽음
            $buff = FileHandler::readFile($file_name);
            return $this->_createTable($buff);
        }

        /**
         * @brief schema xml을 이용하여 create table query생성
         *
         * type : number, varchar, text, char, date, \n
         * opt : notnull, default, size\n
         * index : primary key, index, unique\n
         **/
        function _createTable($xml_doc) {
            // xml parsing
            $oXml = new XmlParser();
            $xml_obj = $oXml->parse($xml_doc);

            // 테이블 생성 schema 작성
            $table_name = $xml_obj->table->attrs->name;

			if($table_name == 'sequence') {
                $query = sprintf('create sequence %s', $this->prefix.$table_name);
				return $this->_query($query);
			}

            if($this->isTableExists($table_name)) return;
            $table_name = $this->prefix.$table_name;

            if(!is_array($xml_obj->table->column)) $columns[] = $xml_obj->table->column;
            else $columns = $xml_obj->table->column;

            foreach($columns as $column) {
                $name = $column->attrs->name;
                $type = $column->attrs->type;
                $size = $column->attrs->size;
                $notnull = $column->attrs->notnull;
                $primary_key = $column->attrs->primary_key;
                $index = $column->attrs->index;
                $unique = $column->attrs->unique;
                $default = $column->attrs->default;
                $auto_increment = $column->attrs->auto_increment;

				if($type == "bignumber" || $type == "number") $size = 0;

                $column_schema[] = sprintf('%s %s%s %s %s',
                    $name,
                    $this->column_type[$type],
                    $size?'('.$size.')':'',
                    $default?"default '".$default."'":'',
                    $notnull?'not null':''
                );

                if($primary_key) $primary_list[] = $name;
                else if($unique) $unique_list[$unique][] = $name;
                else if($index) $index_list[$index][] = $name;
            }

            if(count($primary_list)) {
                $column_schema[] = sprintf("primary key (%s)", implode($primary_list,','));
            }

            if(count($unique_list)) {
                foreach($unique_list as $key => $val) {
                    $column_schema[] = sprintf("unique (%s)", implode($val,','));
                }
            }

            
            $schema = sprintf('create table %s (%s%s);', $this->addQuotes($table_name), "\n", implode($column_schema,",\n"));

            $output = $this->_query($schema);

            if(count($index_list)) {
                foreach($index_list as $key => $val) {
                    if(!$this->isIndexExists($table_name, $key)) $this->addIndex($table_name, $key, $val);
                }
            }

            if(!$output) return false;

        }

        /**
         * @brief 조건문 작성하여 return
         **/
        function getCondition($output) {
            if(!$output->conditions) return;

            foreach($output->conditions as $key => $val) {
                $sub_condition = '';
                foreach($val['condition'] as $k =>$v) {
                    if(!$v['value']) continue;

                    $name = $v['column'];
                    $operation = $v['operation'];
                    $value = $v['value'];
                    $type = $this->getColumnType($output->column_type,$name);
                    $pipe = $v['pipe'];

                    $value = $this->getConditionValue($name, $value, $operation, $type, $output->column_type);
                    if(!$value) $value = $v['value'];
                    $str = $this->getConditionPart($name, $value, $operation);
                    if($sub_condition) $sub_condition .= ' '.$pipe.' ';
                    $sub_condition .=  $str;
                }
                if($sub_condition) {
                    if($condition && $val['pipe']) $condition .= ' '.$val['pipe'].' ';
                    $condition .= '('.$sub_condition.')';
                }
            }

            if($condition) $condition = ' where '.$condition;
            return $condition;
        }

        /**
         * @brief insertAct 처리
         **/
        function _executeInsertAct($output) {
            // 테이블 정리
            foreach($output->tables as $key => $val) {
                $table_list[] = $this->prefix.$key;
            }

            // 컬럼 정리 
            foreach($output->columns as $key => $val) {
                $name = $val['name'];
                $value = $val['value'];
                if($output->column_type[$name]!='number') {
                    $value = "'".$this->addQuotes($value)."'";
                    if(!$value) $value = 'null';
                } elseif(!$value || is_numeric($value)) $value = (int)$value;

                $column_list[] = $name;
                $value_list[] = $value;
            }

            $query = sprintf("insert into %s (%s) values (%s);", implode(',',$table_list), implode(',',$column_list), implode(',', $value_list));
            return $this->_query($query);
        }

        /**
         * @brief updateAct 처리
         **/
        function _executeUpdateAct($output) {
            // 테이블 정리
            foreach($output->tables as $key => $val) {
                $table_list[] = $this->prefix.$key.' as '.$val;
            }

            // 컬럼 정리 
            foreach($output->columns as $key => $val) {
                if(!isset($val['value'])) continue;
                $name = $val['name'];
                $value = $val['value'];
                if(strpos($name,'.')!==false&&strpos($value,'.')!==false) $column_list[] = $name.' = '.$value;
                else {
                    if($output->column_type[$name]!='number') $value = "'".$this->addQuotes($value)."'";
                    elseif(!$value || is_numeric($value)) $value = (int)$value;

                    $column_list[] = sprintf("%s = %s", $name, $value);
                }
            }

            // 조건절 정리
            $condition = $this->getCondition($output);

            $query = sprintf("update %s set %s %s", implode(',',$table_list), implode(',',$column_list), $condition);

            return $this->_query($query);
        }

        /**
         * @brief deleteAct 처리
         **/
        function _executeDeleteAct($output) {
            // 테이블 정리
            foreach($output->tables as $key => $val) {
                $table_list[] = $this->prefix.$key;
            }

            // 조건절 정리
            $condition = $this->getCondition($output);

            $query = sprintf("delete from %s %s", implode(',',$table_list), $condition);

            return $this->_query($query);
        }

        /**
         * @brief selectAct 처리
         *
         * select의 경우 특정 페이지의 목록을 가져오는 것을 편하게 하기 위해\n
         * navigation이라는 method를 제공
         **/
        function _executeSelectAct($output) {
            // 테이블 정리
            $table_list = array();
            foreach($output->tables as $key => $val) {
                $table_list[] = $this->prefix.$key .' as '.$val;
            }

            if(!$output->columns) {
                $columns = '*';
            } else {
                $column_list = array();
                foreach($output->columns as $key => $val) {
                    $name = $val['name'];
                    $alias = $val['alias'];
                    if(substr($name,-1) == '*') {
                        $column_list[] = $name;
                    } elseif(strpos($name,'.')===false && strpos($name,'(')===false) {
                        if($alias) $column_list[] = sprintf('%s as %s', $name, $alias);
                        else $column_list[] = sprintf('%s',$name);
                    } else {
                        if($alias) $column_list[] = sprintf('%s as %s', $name, $alias);
                        else $column_list[] = sprintf('%s',$name);
                    }
                }
                $columns = implode(',',$column_list);
            }

            $condition = $this->getCondition($output);

            if($output->list_count && $output->page) return $this->_getNavigationData($table_list, $columns, $condition, $output);

            // list_order, update_order 로 정렬시에 인덱스 사용을 위해 condition에 쿼리 추가
            if($output->order) {
                $conditions = $this->getConditionList($output);
                if(!in_array('list_order', $conditions) && !in_array('update_order', $conditions)) {
                    foreach($output->order as $key => $val) {
                        $col = $val[0];
                        if(!in_array($col, array('list_order','update_order'))) continue;
                        if($condition) $condition .= sprintf(' and %s < 2100000000 ', $col);
                        else $condition = sprintf(' where %s < 2100000000 ', $col);
                    }
                }
            }

            $query = sprintf("select %s from %s %s", $columns, implode(',',$table_list), $condition);

            if(count($output->groups)) $query .= sprintf(' group by %s', implode(',',$output->groups));

            if($output->order) {
                foreach($output->order as $key => $val) {
                    $index_list[] = sprintf('%s %s', $val[0], $val[1]);
                }
                if(count($index_list)) $query .= ' order by '.implode(',',$index_list);
            }

            $result = $this->_query($query);
            if($this->isError()) return;
            $data = $this->_fetch($result);

            $buff = new Object();
            $buff->data = $data;
            return $buff;
        }

        /**
         * @brief query xml에 navigation 정보가 있을 경우 페이징 관련 작업을 처리한다
         *
         * 그닥 좋지는 않은 구조이지만 편리하다.. -_-;
         **/
        function _getNavigationData($table_list, $columns, $condition, $output) {
            require_once('./classes/page/PageHandler.class.php');

            // 전체 개수를 구함
            $count_query = sprintf("select count(*) as count from %s %s", implode(',',$table_list), $condition);
            $result = $this->_query($count_query);
            $count_output = $this->_fetch($result);
            $total_count = (int)$count_output->count;

            $list_count = $output->list_count['value'];
            if(!$list_count) $list_count = 20;
            $page_count = $output->page_count['value'];
            if(!$page_count) $page_count = 10;
            $page = $output->page['value'];
            if(!$page) $page = 1;

            // 전체 페이지를 구함
            if($total_count) $total_page = (int)( ($total_count-1) / $list_count) + 1;
            else $total_page = 1;

            // 페이지 변수를 체크
            if($page > $total_page) $page = $total_page;
            $start_count = ($page-1)*$list_count;

            // list_order, update_order 로 정렬시에 인덱스 사용을 위해 condition에 쿼리 추가
            if($output->order) {
                $conditions = $this->getConditionList($output);
                if(!in_array('list_order', $conditions) && !in_array('update_order', $conditions)) {
                    foreach($output->order as $key => $val) {
                        $col = $val[0];
                        if(!in_array($col, array('list_order','update_order'))) continue;
                        if($condition) $condition .= sprintf(' and %s < 2100000000 ', $col);
                        else $condition = sprintf(' where %s < 2100000000 ', $col);
                    }
                }
            }

            $query = sprintf("select %s from %s %s", $columns, implode(',',$table_list), $condition);

            if(count($output->groups)) $query .= sprintf(' group by %s', implode(',',$output->groups));

            if(count($output->order)) {
                foreach($output->order as $key => $val) {
                    $index_list[] = sprintf('%s %s', $val[0], $val[1]);
                }
                if(count($index_list)) $query .= ' order by '.implode(',',$index_list);
            }

            $query = sprintf('%s offset %d limit %d', $query, $start_count, $list_count);

            $result = $this->_query($query);
            if($this->isError()) {
                $buff = new Object();
                $buff->total_count = 0;
                $buff->total_page = 0;
                $buff->page = 1;
                $buff->data = array();

                $buff->page_navigation = new PageHandler($total_count, $total_page, $page, $page_count);
                return $buff;
            }

            $virtual_no = $total_count - ($page-1)*$list_count;
            while($tmp = pg_fetch_object($result)) {
                $data[$virtual_no--] = $tmp;
            }

            $buff = new Object();
            $buff->total_count = $total_count;
            $buff->total_page = $total_page;
            $buff->page = $page;
            $buff->data = $data;

            $buff->page_navigation = new PageHandler($total_count, $total_page, $page, $page_count);
            return $buff;
        }
    }
?>
