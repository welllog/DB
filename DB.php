<?php

namespace orinfy;

class DB
{
    private static $instance;
    // 数据库链接配置
    private $conf;
    // pdo多链接
    private $linkPool = [];
    // 当前PDO连接
    private $link;

    private $connect;
    private $pdoStatement;
    private $sqlOrig = [
        'distinct' => '',
        'field' => '',
        'table' => '',
        'join' => '',
        'where' => '',
        'group_by' => '',
        'having' => '',
        'order_by' => '',
        'limit' => '',
        'update' => '',
        'insert' => '',
        'delete' => ''
    ];
    private $sqlTemp = [
        'distinct' => '',
        'field' => '',
        'table' => '',
        'join' => '',
        'where' => '',
        'group_by' => '',
        'having' => '',
        'order_by' => '',
        'limit' => '',
        'update' => '',
        'insert' => '',
        'delete' => '',
    ];
    private $paramsOrig = [
        'field' => [],
        'join' => [],
        'where' => [],
        'having' => [],
        'insert' => [],
        'update' => []
    ];
    private $paramsTemp = [
        'field' => [],
        'join' => [],
        'where' => [],
        'having' => [],
        'insert' => [],
        'update' => []
    ];
    private $table;
    private $sql;
    private $params = [];

    /** 不容许直接调用构造函数 */
    private function __construct() {}

    /** 不容许深度复制 */
    private function __clone() {}

    /** 反序列化时恢复连接 */
    public  function __wakeup() {
        self::$instance = $this;
    }

    /** 需要在单例切换的时候做清理工作 */
    public function __destruct() {
        //清理工作
    }

    /**------------------------数据库链接方法------------------**/
    public static function i() : DB
    {
        // 判断是否支持pdo
        if (!class_exists(\PDO::class)) throw new \Exception('没有pdo扩展');
        // 当前实例是否存在
        if (null === self::$instance) {
            self::$instance = new self();
        }
        // 设置默认链接
        self::$instance->connect();
        return self::$instance;
    }

    public function connect(string $connect = 'default') : void
    {
        // 判断是否载入配置文件------(应根据实际更改，最好为绝对路劲)
        if (null === $this->conf) {
            $this->conf = include 'DBConf.php';
        }
        $conf = $this->conf[$connect];
        // 传入链接是否存在,存在不重复创建
        if (!isset($this->linkPool[$connect])) {
            $dsn = $conf['driver'] . ":host={$conf['host']};port={$conf['port']};dbname={$conf['dbname']};charset={$conf['charset']}";
            $conf['params'][\PDO::ATTR_PERSISTENT] = $conf['pconnect'] ? true : false;
            $conf['params'][\PDO::ATTR_TIMEOUT] = $conf['time_out'] ? $conf['time_out'] : 3;
            $this->linkPool[$connect] = new \PDO($dsn, $conf['username'], $conf['password'], $conf['params']);
            
        }
        // 传递当前链接
        $this->link = &$this->linkPool[$connect];
        $this->connect = $connect;
    }

    public function getVersion() : ?string
    {
        return $this->link->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    public function getPdo() : \PDO
    {
        return $this->link;
    }

    public function close(string $connect = '') : void
    {
        if ($connect === '') { // 关闭全部链接，还需要外部销毁本对象及PDO对象的引用变量，才能释放
            $this->linkPool = [];
            self::$instance = null;
        } else {
            $this->linkPool[$connect] = null;
        }
    }

    /**------------------------------crud方法-------------------**/
    /**
     * DB::i()->table('users as u')->leftJoin('levels as l', 'u.level', '=', 'l.id')
     * ->where([['id', '>', 4], ['id', '<', 10]])->where('level', '>', 3)
     * ->orWhere(function($query){$query->whereNull('status')->whereIn('level', [1, 3])})
     * ->select('u.id', 'u.name', 'l.level_name')
     * ->groupBy('u.p_uid')->orderBy('u.id', desc)->limit(2, 3)->get();
     *
     * DB::i()->table('users')->insert([['name' => 'lisi'],['name' => 'zs']]);
     * DB::i()->table('users')->insert(['name' => 'lisi', 'age' => 24]);
     * DB::i()->table('users')->insertGetId(['name' => 'lisi', 'age' => 24]);
     *
     * DB::i()->table('users')->where('id', '=', 1)->update(['name' => 'zhangsan']);
     * DB::i()->table('users')->where('id', '=', 1)->decrement('scores');
     * DB::i()->table('users')->where('id', '=', 1)->decrement('scores', 1);
     * DB::i()->table('users')->where('id', '=', 1)->decrement(['scores', 1], ['rest',-2]);
     *
     * DB::i()->table('users')->where('id', '=', 1)->delete();
     */
    /**
     * 设定语句表名，支持as设置别名(即别名设置必须加as)  table('users') table('users as u')
     * @param string $table
     * @return DB
     */
    public function table(string $table) : DB
    {
        // 不使用explode来切割，多个空格不方便处理
        $offset = stripos($table, ' as ');
        if ($offset === false) {
            $tableName = trim($table, ' `');
            $alias = '';
        } else {
            $tableName = trim(substr($table, 0, $offset), ' `');
            $alias = ' as `' . trim(substr($table, $offset + 4), ' `') . '`';
        }
        $this->table = $this->conf[$this->connect]['prefix'] . $tableName;
        $this->sqlTemp['table'] = '`' . $this->table . '`' . $alias;
        return $this;
    }

    /**
     * 返回不重复的数据     distinct()
     * @return DB
     */
    public function ditinct() : DB
    {
        $this->sqlTemp['distinct'] = 'distinct';
        return $this;
    }

    /**
     * 查询字段,支持数组跟多参数传参，支持as设置别名   select('id', 'name', 'age')  select(['id', 'name', 'age'])
     * @param $fields
     * @return DB
     */
    public function select($fields) : DB
    {
        $fields = is_array($fields) ? $fields : func_get_args();
        $fieldSql = '';
        foreach ($fields as $val) {
            $offset = stripos($val, ' as ');
            if ($offset === false) {
                $field = $val;
                $alias = '';
            } else {
                $field = trim(substr($val, 0, $offset));
                $alias = trim(substr($val, $offset + 4));
                if (strpos($alias, '`') === false) $alias = '`' . $alias . '`';
                $alias = ' as ' . $alias;
            }
            if ($field !== '*' && strpbrk($field, '.`(') === false) $field = '`' . $field . '`';
            $fieldSql .= $field . $alias . ',';
        }
        $this->sqlTemp['field'] = rtrim($fieldSql, ',');
        return $this;
    }



    /**
     * 查询条件，使用方法[传参]where('id', '>', 1)    [二维数组]where([['id', '>', 1], ['id', '<', 2]])
     * [闭包]where(function($query){$query->whereNull('name');})  [原生sql]where('`id`=? and `status`>?', [1, 2])
     * @param $where
     * @return DB
     */
    public function where($where) : DB
    {
        if (empty($where)) return $this;
        $whereSql = '';
        $whereParams = [];
        if (is_array($where)) { // 二维数组
            $whereSql .= '(';
            foreach ($where as $val) {
                if (strpbrk($val[0], '.`(') === false) $val[0] = '`' . $val[0] . '`';
                $whereSql .= $val[0].$val[1].'? and ';
                $whereParams[] = $val[2];
            }
            $whereSql = substr($whereSql, 0, -5) . ')';
        } elseif (is_callable($where)) { // 闭包分组
            $where($this->andGroupBegin());
            $this->groupEnd();
        } else {
            $params = func_get_args();
            if (count($params) === 3) { // 传参
                if (strpbrk($params[0], '.`(') === false) $params[0] = '`' . $params[0] . '`';
                $whereSql .= $params[0].$params[1].'?';
                $whereParams[] = $params[2];
            } else {  // 原生sql
                $whereSql = $params[0];
                $whereParams = $params[1];
            }
        }
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if ($whereSql && substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        $this->paramsTemp['where'] = array_merge($this->paramsTemp['where'], $whereParams);
        return $this;

    }

    /**
     * 用法同where
     * @param $where
     * @return DB
     */
    public function orWhere($where) : DB
    {
        if (empty($where)) return $this;
        $whereSql = '';
        $whereParams = [];
        if (is_array($where)) { // 二维数组
            $whereSql .= '(';
            foreach ($where as $val) {
                if (strpbrk($val[0], '.`(') === false) $val[0] = '`' . $val[0] . '`';
                $whereSql .= $val[0].$val[1].'? and ';
                $whereParams[] = $val[2];
            }
            $whereSql = substr($whereSql, 0, -5) . ')';
        } elseif (is_callable($where)) { // 闭包分组
            $where($this->orGroupBegin());
            $this->groupEnd();
        } else {
            $params = func_num_args();
            if (count($params) === 3) { // 传参
                if (strpbrk($params[0], '.`(') === false) $params[0] = '`' . $params[0] . '`';
                $whereSql .= $params[0].$params[1].'?';
                $whereParams[] = $params[2];
            } else {  // 原生sql
                $whereSql = $params[0];
                $whereParams = $params[1];
            }
        }
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if ($whereSql && substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' or ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        $this->paramsTemp['where'] = array_merge($this->paramsTemp['where'], $whereParams);
        return $this;
    }

    /**
     * @param string $field    whereNull('update_time')
     * @return DB
     */
    public function whereNull(string $field) : DB
    {
        if (strpbrk($field,'.`(') === false) $field = '`' . $field . '`';
        $whereSql = $field . ' is null';
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        return $this;
    }

    /**
     * @param string $field   whereNotNull('update_time')
     * @return DB
     */
    public function whereNotNull(string $field) : DB
    {
        if (strpbrk($field,'.`(') === false) $field = '`' . $field . '`';
        $whereSql = $field . ' is not null';
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        return $this;
    }

    /**
     * @param string $field   whereBetween('id', [1, 9])
     * @param array $between
     * @return DB
     */
    public function whereBetween(string $field, array $between) : DB
    {
        if (strpbrk($field, '.`(') === false) $field = '`' . $field . '`';
        $whereSql = $field . " between ? and ?";
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        $this->paramsTemp['where'] = array_merge($this->paramsTemp['where'], $between);
        return $this;
    }

    /**
     * @param string $field   whereNotBetween('id', [2, 5])
     * @param array $between
     * @return DB
     */
    public function whereNotBetween(string $field, array $between) : DB
    {
        if (strpbrk($field, '.`(') === false) $field = '`' . $field . '`';
        $whereSql = $field . " not between ? and ?";
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        $this->paramsTemp['where'] = array_merge($this->paramsTemp['where'], $between);
        return $this;
    }

    /**
     * @param string $field   whereIn('id', [2, 3, 4])
     * @param array $in
     * @return DB
     */
    public function whereIn(string $field, array $in) : DB
    {
        if (strpbrk($field, '.`(') === false) $field = '`' . $field . '`';
        $place_holders = implode(',', array_fill(0, count($in), '?'));
        $whereSql = $field . " in ({$place_holders})";
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        $this->paramsTemp['where'] = array_merge($this->paramsTemp['where'], $in);
        return $this;
    }

    /**
     * @param string $field  whereNotIn('id', [4, 5, 1])
     * @param array $in
     * @return DB
     */
    public function whereNotIn(string $field, array $in) : DB
    {
        if (strpbrk($field, '.`(') === false) $field = '`' . $field . '`';
        $place_holders = implode(',', array_fill(0, count($in), '?'));
        $whereSql = $field . " not in ({$place_holders})";
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        $this->paramsTemp['where'] = array_merge($this->paramsTemp['where'], $in);
        return $this;
    }

    /**
     * 两列之间的条件查询，支持传参，二维数组
     * whereColumn('level', '>', 'scores')  where([['level', '>', 'scores'], ['scores', '<', 'p.scores']])
     * @param $where
     * @return DB
     */
    public function whereColumn($where) : DB
    {
        if (empty($where)) return $this;
        $whereSql = '';
        if (is_array($where)) { // 二维数组
            $whereSql .= '(';
            foreach ($where as $val) {
                if (strpbrk($val[0], '.`(+-*/%') === false) $val[0] = '`' . $val[0] . '`';
                if (strpbrk($val[2], '.`(+-*/%') === false) $val[2] = '`' . $val[2] . '`';
                $whereSql .= $val[0].$val[1].$val[2].' and';
            }
            $whereSql = substr($whereSql, 0, -4) . ')';
        } else { // 传参
            $params = func_get_args();
            if (strpbrk($params[0], '.`(+-*/%') === false) $params[0] = '`' . $params[0] . '`';
            if (strpbrk($params[2], '.`(+-*/%') === false) $params[2] = '`' . $params[2] . '`';
            $whereSql .= $params[0].$params[1].$params[2];
        }
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlTemp['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlTemp['where'] .= $whereSql;
        }
        return $this;
    }

    /**
     * 连接表,表名支持as别名语法,后续参数支持原生跟传参
     * join('users as u', 'a.uid', '=', 'u.id')->join('levels as l', 'l.id=u.level and l.status=?', [3])
     * @return DB
     */
    public function join() : DB
    {
        $argNum = func_num_args();
        $params = func_get_args();
        $joinSql = 'inner join ';
        $offset = stripos($params[0], ' as ');
        if ($offset === false) {
            $tableName = trim($params[0], ' `');
            $alias = '';
        } else {
            $tableName = trim(substr($params[0], 0, $offset), ' `');
            $alias = ' as `' . trim(substr($params[0], $offset + 4), ' `') . '`';
        }
        $joinSql .= '`' . $this->conf[$this->connect]['prefix'] . $tableName . '`' . $alias;
        if ($argNum === 4) { // 传参
            $joinSql .= " on {$params[1]}{$params[2]}{$params[3]}";
        } elseif ($argNum === 3) { // 原生sql
            $joinSql .= " on $params[1]";
            $this->paramsTemp['join'] = array_merge($this->paramsTemp['join'], $params[2]);
        }
        $this->sqlTemp['join'] .= ' ' . $joinSql;
        return $this;
    }

    /**
     * 同join
     * @return DB
     */
    public function leftJoin() : DB
    {
        $argNum = func_num_args();
        $params = func_get_args();
        $joinSql = 'left join ';
        $offset = stripos($params[0], ' as ');
        if ($offset === false) {
            $tableName = trim($params[0], ' `');
            $alias = '';
        } else {
            $tableName = trim(substr($params[0], 0, $offset), ' `');
            $alias = ' as `' . trim(substr($params[0], $offset + 4), ' `') . '`';
        }
        $joinSql .= '`' . $this->conf[$this->connect]['prefix'] . $tableName . '`' . $alias;
        if ($argNum === 4) { // 传参
            $joinSql .= " on {$params[1]}{$params[2]}{$params[3]}";
        } elseif ($argNum === 3) { // 原生sql
            $joinSql .= " on $params[1]";
            $this->paramsTemp['join'] = array_merge($this->paramsTemp['join'], $params[2]);
        }
        $this->sqlTemp['join'] .= ' ' . $joinSql;
        return $this;
    }

    /**
     * 同join
     * @return DB
     */
    public function rightJoin() : DB
    {
        $argNum = func_num_args();
        $params = func_get_args();
        $joinSql = 'right join ';
        $offset = stripos($params[0], ' as ');
        if ($offset === false) {
            $tableName = trim($params[0], ' `');
            $alias = '';
        } else {
            $tableName = trim(substr($params[0], 0, $offset), ' `');
            $alias = ' as `' . trim(substr($params[0], $offset + 4), ' `') . '`';
        }
        $joinSql .= '`' . $this->conf[$this->connect]['prefix'] . $tableName . '`' . $alias;
        if ($argNum === 4) { // 传参
            $joinSql .= " on {$params[1]}{$params[2]}{$params[3]}";
        } elseif ($argNum === 3) { // 原生sql
            $joinSql .= " on $params[1]";
            $this->paramsTemp['join'] = array_merge($this->paramsTemp['join'], $params[2]);
        }
        $this->sqlTemp['join'] .= ' ' . $joinSql;
        return $this;
    }

    /**
     * @param string $field  orderBy('id', 'desc')  orderBy('id', 'asc')
     * @param string $order
     * @return DB
     */
    public function orderBy(string $field, string $order) : DB
    {
        if (strpbrk($field, '.`(') === false) $field = '`' . $field . '`';
        if ($this->sqlTemp['order_by'] === '') {
            $this->sqlTemp['order_by'] = 'order by ' . $field . ' ' . $order;
        } else {
            $this->sqlTemp['order_by'] .= ',' .  $field . ' ' . $order;
        }
        return $this;
    }

    /**
     * @param string $field  groupBy('p_uid')
     * @return DB
     */
    public function groupBy(string $field) : DB
    {
        if (strpbrk($field, '.`(') === false) $field = '`' . $field . '`';
        $this->sqlTemp['group_by'] = 'group by ' . $field;
        return $this;
    }

    /**
     * having('id', '>', 1) || having('`id`>?', [1])
     * @return DB
     */
    public function having() : DB
    {
        $argNum = func_num_args();
        $params = func_get_args();
        $havingSql = 'having ';
        if ($argNum === 3) { // 传参
            if (strpos($params[0], '`.(') === false) $params[0] = '`' . $params[0] . '`';
            $havingSql .= $params[0] . $params[1] . '?';
            $this->paramsTemp['having'] = $params[2];
        } elseif ($argNum === 2) { // 原生sql
            $havingSql .= $params[0];
            $this->paramsTemp['having'] = $params[1];
        }
        $this->sqlTemp['having'] = $havingSql;
        return $this;
    }

    /**
     * @param int $limit  limit(1, 5)
     * @param int $length
     * @return DB
     */
    public function limit(int $limit, int $length = 0) : DB
    {
        $this->sqlTemp['limit'] = 'limit ' . intval($limit);
        if ($length) $this->sqlTemp['limit'] .= ',' . intval($length);
        return $this;
    }

    /**
     * 参数为一维数组或二维数组， 返回受影响的行数
     * @param array $insert  insert(['scores' => 4, 'name' => 'lisi'])  insert([['name' => 'as'], ['name' => 'lisi']])
     * @return int
     * @throws \Exception
     */
    public function insert(array $insert) : int
    {
        $fields = '(';
        $values = '';
        if (isset($insert[0]) && is_array($insert[0])) { // 二维数组，批量插入
            $time = 0;
            foreach ($insert as $val) {
                $values .= '(';
                foreach ($val as $k => $v) {
                    if ($time == 0) $fields .= '`' . trim($k, ' `') . '`,';
                    $values .= '?,';
                    $this->paramsTemp['insert'][] = $v;
                }
                ++$time;
                $values = rtrim($values, ',') . '),';
            }
            $values = rtrim($values, ',');
            $fields = rtrim($fields, ',') . ')';
        } else {  // 一维数组
            $values .= '(';
            foreach ($insert as $key => $val) {
                $fields .= '`' . trim($key, ' `') . '`,';
                $values .= '?,';
                $this->paramsTemp['insert'][] = $val;
            }
            $values = rtrim($values, ',') . ')';
            $fields = rtrim($fields, ',') . ')';
        }
        $this->sqlTemp['insert'] = $fields . ' values ' . $values;
        $this->resolve('insert');
        return $this->_exec();
    }

    /**
     * 参数为一维数组，返回最后一条的id
     * @param array $insert
     * @return int
     * @throws \Exception
     */
    public function insertGetId(array $insert) : int
    {
        $fields = '(';
        $values = '(';
        foreach ($insert as $key => $val) {
            $fields .= '`' . trim($key, ' `') . '`,';
            $values .= '?,';
            $this->paramsTemp['insert'][] = $val;
        }
        $values = rtrim($values, ',') . ')';
        $fields = rtrim($fields, ',') . ')';
        $this->sqlTemp['insert'] = $fields . ' values ' . $values;
        $this->resolve('insert');
        $this->pdoStatement = $this->link->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        $this->throwError();
        return $this->link->lastInsertId();
    }

    /**
     * @param array $update  update(['name' => 'se'])
     * @return int
     * @throws \Exception
     */
    public function update(array $update) : int
    {
        $updateSql = 'set ';
        foreach ($update as $key => $val) {
            $updateSql .= '`' . trim($key, ' `') . '`=?,';
            $this->paramsTemp['update'][] = $val;
        }
        $this->sqlTemp['update'] = rtrim($updateSql, ',');
        $this->resolve('update');
        return $this->_exec();
    }

    /**
     * 自增
     * decrement('scores')    decrement('scores', 5)     decrement([['scores', 3], ['rest', -7]])
     * @param $increment
     * @return int
     * @throws \Exception
     */
    public function increment($increment) : int
    {
        $updateSql = 'set ';
        if (is_array($increment)) {  // 传参为二维数组
            foreach ($increment as $val) {
                $field = '`' . trim($val[0], ' `') . '`';
                $updateSql .= $field . '=' . $field . '+?,';
                $this->paramsTemp['update'][] = $val[1];
            }
            $this->sqlTemp['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $incr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . trim($args[0], ' `') . '`';
            $this->sqlTemp['update'] = $updateSql . $field . '=' . $field . '+?';
            $this->paramsTemp['update'][] = $incr;
        }
        $this->resolve('update');
        return $this->_exec();
    }

    /**
     * 自减
     * @param $decrement
     * @return int
     * @throws \Exception
     */
    public function decrement($decrement) : int
    {
        $updateSql = 'set ';
        if (is_array($decrement)) {  // 传参为二维数组
            foreach ($decrement as $val) {
                $field = '`' . trim($val[0], ' `') . '`';
                $updateSql .= $field . '=' . $field . '-?,';
                $this->paramsTemp['update'][] = $val[1];
            }
            $this->sqlTemp['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $decr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . trim($args[0], ' `') . '`';
            $this->sqlTemp['update'] = $updateSql . $field . '=' . $field . '-?';
            $this->paramsTemp['update'][] = $decr;
        }
        $this->resolve('update');
        return $this->_exec();
    }

    /**
     * @return int
     * @throws \Exception
     */
    public function delete() : int
    {
        $this->resolve('delete');
        return $this->_exec();
    }

    /**
     * 获取sql
     * @return string
     * @throws \Exception
     */
    public function getSql() : string
    {
        $this->resolve();
        return $this->sql;
    }

    /**
     * 获得结果，为二维数组或空数组
     * @return array
     * @throws \Exception
     */
    public function get() : array
    {
        $this->resolve();
        $this->pdoStatement = $this->link->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        $this->throwError();
        return $this->pdoStatement->fetchAll(\PDO::FETCH_ASSOC); // PDO::FETCH_OBJ
    }

    /**
     * 获取一行，一维数组或null
     * @return mixed
     * @throws \Exception
     */
    public function first() : ?array
    {
        $this->resolve();
        $this->pdoStatement = $this->link->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        $this->throwError();
        $res = $this->pdoStatement->fetch(\PDO::FETCH_ASSOC);
        $this->pdoStatement->closeCursor();
        return $res ? $res : null;

    }

    /**
     * 返回一列，返回一维数组或null
     * @param string $col
     * @return array|null
     * @throws \Exception
     */
    public function pluck(string $col) : ?array
    {
        $res = $this->select($col)->get();
        if ($res === []) return null;
        return array_column($res, $col);
    }

    /**
     * 获取某值，返回字符串或null
     * @param string $field
     * @return null|string
     * @throws \Exception
     */
    public function value(string $field) : ?string
    {
        $res = $this->select($field)->first();
        if ($res === null) return null;
        return $res[$field];
    }

    public function max(string $field) : int
    {
        if (strpbrk($field, '.`') === false)  $field = '`' . $field . '`';
        $res = $this->select('max('.$field.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function min(string $field) : int
    {
        if (strpbrk($field, '.`') === false)  $field = '`' . $field . '`';
        $res = $this->select('min('.$field.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function sum(string $field) : int
    {
        if (strpbrk($field, '.`') === false)  $field = '`' . $field . '`';
        $res = $this->select('sum('.$field.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function count() : int
    {
        $res = $this->select('count(*) as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function avg(string $field) : int
    {
        if (strpbrk($field, '.`') === false)  $field = '`' . $field . '`';
        $res = $this->select('avg('.$field.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function beginTrans()
    {
        $this->link->beginTransaction();
    }

    public function inTrans() : bool
    {
        return $this->link->inTransaction();
    }

    public function rollBack()
    {
        $this->link->rollBack();
    }

    public function commit()
    {
        $this->link->commit();
    }
    /**----------------------------原生sql方法----------------**/
    /**
     * DB::i()->prepare(原生sql)->execute(参数)->rowCount();
     * DB::i()->prepare(原生sql)->execute(参数)->lastInsertId();
     * DB::i()->prepare('update `users` set `status`=? where `id`>?')->execute([1,6])->rowCount();
     * DB::i()->prepare('insert into `users` (name,age) values (?,?)')->execute(['lisi',25])->lastInsertId();
     * DB::i()->prepare('select * from `users` where `id`>?')->execute([6])->fetchAll();
     * DB::i()->prepare('select * from `users` where `id`>?')->execute([6])->fetch();
     * @param string $sql
     * @return DB
     */
    public function prepare(string $sql) : DB
    {
        $this->pdoStatement = $this->link->prepare($sql);
        return $this;
    }

    public function execute(array $params = []) : DB
    {
        $this->pdoStatement->execute($params);
        $this->throwError();
        return $this;
    }

    public function rowCount() : int
    {
        return $this->pdoStatement->rowCount();
    }

    public function lastInsertId() : int
    {
        return $this->link->lastInsertId();
    }

    /**
     * DB::i()->exec('insert into `users` (name,age) values ('lisi',25)');
     * @param string $sql
     * @return int
     */
    public function exec(string $sql) : int
    {
        return $this->link->exec();
    }

    /**
     * DB::i()->query('select * from `users` where `id`>6')->fetchAll();
     * @param string $sql
     * @return DB
     * @throws \Exception
     */
    public function query(string $sql) : DB
    {
        $this->pdoStatement = $this->link->query();
        $this->throwError();
        return $this;
    }

    public function fetchAll() : array
    {
        return $this->pdoStatement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function fetch() : ?array
    {
        return $this->pdoStatement->fetch(\PDO::FETCH_ASSOC);
    }
    
    public function throwError() : void
    {
        $obj = empty($this->pdoStatement) ? $this->link : $this->pdoStatement;
        $errArr = $obj->errorInfo();
        if ($errArr[0] != '00000' && $errArr[0] !== '') {
            throw new \Exception('SQLSTATE: '.$errArr[0].';  SQL ERROR: '.$errArr[2]
                .';   ERROR SQL: '.$this->sql);
        }
    }

    /**---------------------------私有基础方法------------------**/

    private function _exec() : int
    {
        $this->pdoStatement = $this->link->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        $this->throwError();
        return $this->pdoStatement->rowCount();
    }

    private function resolve(string $option = 'select') : void
    {
        if (substr_count($this->sql, '?') !== count($this->params)) throw new \Exception('绑定参数错误:'.$this->sql);
        if ($option === 'select') {
            $fields = $this->sqlTemp['field'] ? $this->sqlTemp['field'] : '*';
            $this->sql = 'select ' . $this->sqlTemp['distinct'] . ' ' . $fields . ' from '.
                $this->sqlTemp['table'] . ' ' . $this->sqlTemp['join'] . ' ' . $this->sqlTemp['where'] .
                ' ' . $this->sqlTemp['group_by'] . ' ' . $this->sqlTemp['having'] . ' ' . $this->sqlTemp['order_by'] .
                ' ' . $this->sqlTemp['limit'];
            $this->params = array_merge($this->paramsTemp['field'], $this->paramsTemp['join'], $this->paramsTemp['where'],
                $this->paramsTemp['having']);
            $this->sql = rtrim($this->sql);
        } elseif ($option === 'update') {
            $this->sql = 'update ' . $this->sqlTemp['table'] . ' ' . $this->sqlTemp['update'] . ' ' . $this->sqlTemp['where'];
            $this->params = array_merge($this->paramsTemp['update'], $this->paramsTemp['where']);
        } elseif ($option === 'insert') {
            $this->sql = 'insert into ' . $this->sqlTemp['table'] . ' ' . $this->sqlTemp['insert'];
            $this->params = $this->paramsTemp['insert'];
        } elseif ($option === 'delete') {
            $this->sql = 'delete from ' . $this->sqlTemp['table'] . ' ' . $this->sqlTemp['where'];
            $this->params = $this->paramsTemp['where'];
        }
        $this->sqlTemp = $this->sqlOrig;
        $this->paramsTemp = $this->paramsOrig;
    }

    private function orGroupBegin() : DB
    {
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where (';
        } else {
            $this->sqlTemp['where'] .= ' or (';
        }
        return $this;
    }

    private function andGroupBegin() : DB
    {
        if ($this->sqlTemp['where'] === '') {
            $this->sqlTemp['where'] = 'where (';
        } else {
            $this->sqlTemp['where'] .= ' and (';
        }
        return $this;
    }

    private function groupEnd() : void
    {
        $this->sqlTemp['where'] .= ')';
    }

}
//$re = DB::i()->table('shop_accounts as a')->leftJoin('shop_accounts as b', 'a.uid', '=', 'b.p_uid')
//    ->select(['a.name', 'a.uid', 'b.name as pname'])
//    ->orderBy('a.id', 'desc')
//    ->limit(2, 3)
//    ->get();
//$re = DB::i()->table('touch_tem')->insert([['money_report_id' => 99, 'and' => 3], ['money_report_id' => 100, 'and' => 4]]);
//$re = DB::i()->table('touch_tem')->insert(['money_report_id' => 101, 'and' => 4]);
//$re = DB::i()->table('touch_tem')->insertGetId(['money_report_id' => 102, 'and' => 4]);
//$re = DB::i()->table('touch_tem')->where('id', '=', 90)->update(['money_report_id' => 103, 'and' => 7]);
//$re = DB::i()->table('touch_tem')->where('id', '=', 90)->increment([['money_report_id',2],['and', -1]]);
//$re = DB::i()->table('touch_tem')->where('id', '=', 90)->increment('money_report_id', -2);
//$re = DB::i()->table('touch_tem')->where('id', '=', 90)->decrement('money_report_id', 3);
//$re = DB::i()->table('touch_tem')->where('id', '=', 90)->delete();
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->max('money_report_id');
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->min('money_report_id');
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->sum('money_report_id');
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->avg('money_report_id');
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->count();
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->first();
//$re1 = DB::i()->table('touch_tem')->where('id', '>', 84)->first();
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->pluck('money_report_id');
//$re = DB::i()->table('touch_tem')->where('id', '>', 84)->value('money_report_id');
//DB::i()->beginTrans();
//$re = DB::i()->table('touch_tem')->where('id', '=', 84)->decrement('money_report_id', 3);
//$re = DB::i()->inTrans();
//DB::i()->rollBack();
//DB::i()->commit();

//var_dump($re);
//var_dump($re1);
//$re = DB::i()->table('areas')->where('id', '>', 1000)->where('id', '<', 1005)->get();
//return $re;
