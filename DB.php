<?php

namespace orinfy;

class DB
{
    private static $instance;
    private $conf;
    private $links;
    private $currentLink;
    private $connect;

    private $pdoStatement;
    private $table;
    private $sql;
    private $params = [];
    private $lastInsertId;

    private $sqlSlice = [
        'distinct' => '', 'columns' => '', 'table' => '', 'join' => '', 'where' => '', 'group_by' => '',
        'having' => '', 'order_by' => '', 'limit' => '', 'update' => '', 'insert' => '', 'delete' => ''
    ];
    private $paramSlice = [
        'allow' => [], 'join' => [], 'where' => [], 'having' => [], 'insert' => [], 'update' => []
    ];

    private $sqlSliceInit = [
        'distinct' => '', 'columns' => '', 'table' => '', 'join' => '', 'where' => '', 'group_by' => '',
        'having' => '', 'order_by' => '', 'limit' => '', 'update' => '', 'insert' => '', 'delete' => ''
    ];
    private $paramSliceInit = [
        'allow' => [], 'join' => [], 'where' => [], 'having' => [], 'insert' => [], 'update' => []
    ];

    private function __construct() {}
    private function __clone() {}

    public  function __wakeup() {
        self::$instance = $this;
    }

    public static function i() : DB
    {
        if (!class_exists(\PDO::class)) throw new \Exception('没有pdo扩展');
        if (null === self::$instance) {
            self::$instance = new self();
        }
        self::$instance->connect();
        return self::$instance;
    }

    public function connect(string $connect = 'default') : void
    {
        if (null === $this->conf) {
            $this->conf = include __DIR__ . '/DBConf.php';
        }
        $conf = $this->conf[$connect];
        if (!isset($this->links[$connect])) {
            $dsn = $conf['driver'] . ":host={$conf['host']};port={$conf['port']};dbname={$conf['dbname']};charset={$conf['charset']}";
            $conf['params'][\PDO::ATTR_PERSISTENT] = $conf['pconnect'] ? true : false;
            $conf['params'][\PDO::ATTR_TIMEOUT] = $conf['time_out'] ? $conf['time_out'] : 3;
            $conf['params'][\PDO::ATTR_ERRMODE] = $conf['throw_exception'] ? \PDO::ERRMODE_EXCEPTION : \PDO::ERRMODE_SILENT;
            $this->links[$connect] = new \PDO($dsn, $conf['username'], $conf['password'], $conf['params']);

        }
        $this->currentLink = &$this->links[$connect];
        $this->connect = $connect;
    }

    public function getDBVersion() : ?string
    {
        return $this->currentLink->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    public function setAttribute(int $attributeName, int $attributeVal) : void
    {
        $this->currentLink->setAttribute($attributeName, $attributeVal);
    }

    public function getPdo() : \PDO
    {
        return $this->currentLink;
    }

    public function close(string $connect = '') : void
    {
        if ($connect === '') { // close all links
            $this->links = [];
            self::$instance = null;
        } else {
            $this->links[$connect] = null;
        }
    }

    public function table(string $table) : DB
    {
        $offset = stripos($table, ' as ');
        if ($offset === false) {
            $tableName = trim($table, ' `');
            $alias = '';
        } else {
            $tableName = trim(substr($table, 0, $offset), ' `');
            $alias = trim(substr($table, $offset + 4), ' `');
            $alias = ' as `' . $this->conf[$this->connect]['prefix'] . $alias . '`';
        }
        $this->table = $this->conf[$this->connect]['prefix'] . $tableName;
        $this->sqlSlice['table'] = ' `' . $this->table . '`' . $alias;
        return $this;
    }

    public function ditinct() : DB
    {
        $this->sqlSlice['distinct'] = ' distinct';
        return $this;
    }

    public function select($columns) : DB
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        if ($columns === []) {
            $this->sqlSlice['columns'] = ' * ';
            return $this;
        }
        $columnSql = '';
        foreach ($columns as $val) {
            $offset = stripos($val, ' as ');
            if ($offset === false) {
                $column = $val;
                $alias = '';
            } else {
                $column = substr($val, 0, $offset);
                $alias = ' as `' . trim(substr($val, $offset + 4), ' `') . '`';
            }
            $func = 'pla';
            $table = '';
            $arr = explode('(', $column);
            if (count($arr) === 2) {
                $func = $arr[0] . '(pla)';
                $column = $arr[1];
            }
            $arr = explode('.', $column);
            if (count($arr) === 2) {
                $table = '`' . $this->conf[$this->connect]['prefix'] . trim($arr[0], ' `') . '`.';
                $column = $arr[1];
            }
            $column = $table . '`' . trim($column, ' `)') . '`';
            $column = str_replace('pla', $column, $func);
            $columnSql .= $column . $alias . ',';
        }
        $this->sqlSlice['columns'] = ' '.rtrim($columnSql, ',').' ';
        return $this;
    }

    public function where(...$where) : DB
    {
        $this->_where($where, 'and');
        return $this;
    }

    public function orWhere(...$where) : DB
    {
        $this->_where($where, 'or');
        return $this;
    }

    public function whereNull(string $column) : DB
    {
        $column = $this->column($column);
        $whereSql = $column . ' is null';
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        return $this;
    }

    public function whereNotNull(string $column) : DB
    {
        $column = $this->column($column);
        $whereSql = $column . ' is not null';
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        return $this;
    }

    public function whereBetween(string $column, array $between) : DB
    {
        $column = $this->column($column);
        $whereSql = $column . " between ? and ?";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $between);
        return $this;
    }

    public function whereNotBetween(string $column, array $between) : DB
    {
        $column = $this->column($column);
        $whereSql = $column . " not between ? and ?";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $between);
        return $this;
    }

    public function whereIn(string $column, array $in) : DB
    {
        $column = $this->column($column);
        $place_holders = implode(',', array_fill(0, count($in), '?'));
        $whereSql = $column . " in ({$place_holders})";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $in);
        return $this;
    }

    public function whereNotIn(string $column, array $in) : DB
    {
        $column = $this->column($column);
        $place_holders = implode(',', array_fill(0, count($in), '?'));
        $whereSql = $column . " not in ({$place_holders})";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $in);
        return $this;
    }

    public function whereColumn($where) : DB
    {
        if (empty($where)) return $this;
        $whereSql = '';
        if (is_array($where)) { // Two-dimensional array
            $whereSql .= '(';
            foreach ($where as $val) {
                $column1 = $this->column($val[0]);
                $column2 = $this->column($val[2]);
                $whereSql .= $column1.' '.$val[1].' '.$column2.' and';
            }
            $whereSql = substr($whereSql, 0, -4) . ')';
        } else { // Simple parameters
            $params = func_get_args();
            $column1 = $this->column($params[0]);
            $column2 = $this->column($params[2]);
            $whereSql .= $column1.' '.$params[1].' '.$column2;
        }
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        return $this;
    }

    public function join(...$ags) : DB
    {
        $this->_join($ags, 'inner join ');
        return $this;
    }

    public function leftJoin(...$ags) : DB
    {
        $this->_join($ags, 'left join ');
        return $this;
    }

    public function rightJoin(...$ags) : DB
    {
        $this->_join($ags, 'right join ');
        return $this;
    }

    public function orderBy(string $column, string $order) : DB
    {
        $column = $this->column($column);
        if ($this->sqlSlice['order_by'] === '') {
            $this->sqlSlice['order_by'] = ' order by ' . $column . ' ' . $order;
        } else {
            $this->sqlSlice['order_by'] .= ',' .  $column . ' ' . $order;
        }
        return $this;
    }

    public function groupBy(string $column) : DB
    {
        $column = $this->column($column);
        $this->sqlSlice['group_by'] = ' group by ' . $column . ' ';
        return $this;
    }

    public function having() : DB
    {
        $argNum = func_num_args();
        $params = func_get_args();
        $havingSql = ' having ';
        if ($argNum === 3) { // Simple parameters
            $column = $this->column($params[0]);
            $havingSql .= "{$column} {$params[1]} ?";
            $this->paramSlice['having'][] = $params[2];
        } elseif ($argNum === 2) { // Native sql
            $havingSql .= $params[0];
            $this->paramSlice['having'] = $params[1];
        }
        $this->sqlSlice['having'] = $havingSql;
        return $this;
    }

    public function limit(int $limit, int $length = 0) : DB
    {
        $this->sqlSlice['limit'] = ' limit ' . intval($limit);
        if ($length) $this->sqlSlice['limit'] .= ',' . intval($length);
        return $this;
    }

    public function allow($columns) : DB
    {
        $this->paramSlice['allow'] = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    public function insert(array $insert) : int
    {
        $columns = '(';
        $values = '';
        $allow = $this->paramSlice['allow'];
        $filter = ($allow !== []) ? true : false;
        if (isset($insert[0]) && is_array($insert[0])) {
            $time = 0;
            foreach ($insert as $val) {
                $values .= '(';
                foreach ($val as $k => $v) {
                    if (!$filter || in_array($k, $allow)) {
                        if ($time == 0) $columns .= '`' . trim($k, ' `') . '`,';
                        $values .= '?,';
                        $this->paramSlice['insert'][] = $v;
                    }
                }
                ++$time;
                $values = rtrim($values, ',') . '),';
            }
            $values = rtrim($values, ',');
            $columns = rtrim($columns, ',') . ')';
        } else {
            $values .= '(';
            foreach ($insert as $key => $val) {
                if (!$filter || in_array($key, $allow)) {
                    $columns .= '`' . trim($key, ' `') . '`,';
                    $values .= '?,';
                    $this->paramSlice['insert'][] = $val;
                }
            }
            $values = rtrim($values, ',') . ')';
            $columns = rtrim($columns, ',') . ')';
        }
        $this->sqlSlice['insert'] = $columns . ' values ' . $values;
        $this->resolve('insert');
        return $this->_exec();
    }

    public function insertGetId(array $insert) : int
    {
        $columns = '(';
        $values = '(';
        $allow = $this->paramSlice['allow'];
        $filter = ($allow !== []) ? true : false;
        foreach ($insert as $key => $val) {
            if (!$filter || in_array($key, $allow)) {
                $columns .= '`' . trim($key, ' `') . '`,';
                $values .= '?,';
                $this->paramSlice['insert'][] = $val;
            }
        }
        $values = rtrim($values, ',') . ')';
        $columns = rtrim($columns, ',') . ')';
        $this->sqlSlice['insert'] = $columns . ' values ' . $values;
        $this->resolve('insert');
        $this->pdoStatement = $this->currentLink->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        return $this->currentLink->lastInsertId();
    }

    public function update(array $update) : int
    {
        $updateSql = 'set ';
        $allow = $this->paramSlice['allow'];
        $filter = ($allow !== []) ? true : false;
        foreach ($update as $key => $val) {
            if (!$filter || in_array($key, $allow)) {
                $updateSql .= '`' . trim($key, ' `') . '`=?,';
                $this->paramSlice['update'][] = $val;
            }
        }
        $this->sqlSlice['update'] = rtrim($updateSql, ',');
        $this->resolve('update');
        return $this->_exec();
    }

    public function increment($increment) : int
    {
        $updateSql = 'set ';
        if (is_array($increment)) {
            foreach ($increment as $val) {
                $field = '`' . trim($val[0], ' `') . '`';
                $updateSql .= $field . '=' . $field . '+?,';
                $this->paramSlice['update'][] = $val[1];
            }
            $this->sqlSlice['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $incr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . trim($args[0], ' `') . '`';
            $this->sqlSlice['update'] = $updateSql . $field . '=' . $field . '+?';
            $this->paramSlice['update'][] = $incr;
        }
        $this->resolve('update');
        return $this->_exec();
    }

    public function decrement($decrement) : int
    {
        $updateSql = 'set ';
        if (is_array($decrement)) {
            foreach ($decrement as $val) {
                $field = '`' . trim($val[0], ' `') . '`';
                $updateSql .= $field . '=' . $field . '-?,';
                $this->paramSlice['update'][] = $val[1];
            }
            $this->sqlSlice['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $decr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . trim($args[0], ' `') . '`';
            $this->sqlSlice['update'] = $updateSql . $field . '=' . $field . '-?';
            $this->paramSlice['update'][] = $decr;
        }
        $this->resolve('update');
        return $this->_exec();
    }

    public function delete() : int
    {
        $this->resolve('delete');
        return $this->_exec();
    }

    public function getSql() : string
    {
        if ($this->sql === null) {
            $this->resolve();
        }
        return $this->sql;
    }

    public function get() : array
    {
        $this->resolve();
        $this->pdoStatement = $this->currentLink->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        return $this->pdoStatement->fetchAll(\PDO::FETCH_ASSOC); // PDO::FETCH_OBJ
    }

    public function first() : ?array
    {
        $this->resolve();
        $this->pdoStatement = $this->currentLink->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        $res = $this->pdoStatement->fetch(\PDO::FETCH_ASSOC);
        $this->pdoStatement->closeCursor();
        return $res ? $res : null;
    }

    public function pluck(string $col, string $key = '') : array
    {
        $columns[] = $col;
        ($key !== '') && ($columns[] = $key);
        $res = $this->select($columns)->get();
        if ($res === []) return $res;
        if (count($columns) === 2) {
            $data = [];
            foreach ($res as $val) {
                $data[$val[$key]] = $val[$col];
            }
            return $data;
        }
        return array_column($res, $col);
    }

    public function value(string $column) : ?string
    {
        $res = $this->select($column)->first();
        if ($res === null) return null;
        return $res[$column];
    }

    public function max(string $column) : int
    {
        $res = $this->select('max('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function min(string $column) : int
    {
        $res = $this->select('min('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function sum(string $column) : int
    {
        $res = $this->select('sum('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function count() : int
    {
        $res = $this->select('count(*) as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function avg(string $column) : int
    {
        $res = $this->select('avg('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function beginTrans()
    {
        $this->currentLink->beginTransaction();
    }

    public function inTrans() : bool
    {
        return $this->currentLink->inTransaction();
    }

    public function rollBack()
    {
        $this->currentLink->rollBack();
    }

    public function commit()
    {
        $this->currentLink->commit();
    }

    public function prepare(string $sql) : DB
    {
        $this->pdoStatement = $this->currentLink->prepare($sql);
        return $this;
    }

    public function execute(array $params = []) : DB
    {
        $this->pdoStatement->execute($params);
        return $this;
    }

    public function rowCount() : int
    {
        return $this->pdoStatement->rowCount();
    }

    public function lastInsertId() : int
    {
        return $this->currentLink->lastInsertId();
    }

    public function exec(string $sql) : int
    {
        return $this->currentLink->exec($sql);
    }

    public function query(string $sql) : DB
    {
        $this->pdoStatement = $this->currentLink->query($sql);
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

    public function throwError() : ?string
    {
        $obj = empty($this->pdoStatement) ? $this->currentLink : $this->pdoStatement;
        $errArr = $obj->errorInfo();
        if ($errArr[0] != '00000' && $errArr[0] !== '') {
            return 'SQLSTATE: ' . $errArr[0] . '; SQL ERROR: ' . $errArr[2] . '; ERROR SQL: ' . $this->sql;
        }
    }

    private function _exec() : int
    {
        $this->pdoStatement = $this->currentLink->prepare($this->sql);
        $this->pdoStatement->execute($this->params);
        return $this->pdoStatement->rowCount();
    }

    private function resolve(string $option = 'select') : void
    {
        if (substr_count($this->sql, '?') !== count($this->params)) throw new \Exception('绑定参数错误:'.$this->sql);
        if ($option === 'select') {
            $columns = $this->sqlSlice['columns'] ? $this->sqlSlice['columns'] : ' * ';
            $this->sql = 'select' . $this->sqlSlice['distinct'] . $columns . 'from'.
                $this->sqlSlice['table'] . $this->sqlSlice['join'] . $this->sqlSlice['where'] .
                $this->sqlSlice['group_by'] . $this->sqlSlice['having'] . $this->sqlSlice['order_by'] .
                $this->sqlSlice['limit'];
            $this->params = array_merge($this->paramSlice['join'], $this->paramSlice['where'], $this->paramSlice['having']);
            $this->sql = rtrim($this->sql);
        } elseif ($option === 'update') {
            $where = $this->sqlSlice['where'] ?: 'where 1=2';
            $this->sql = 'update ' . $this->sqlSlice['table'] . ' ' . $this->sqlSlice['update'] . ' ' . $where;
            $this->params = array_merge($this->paramSlice['update'], $this->paramSlice['where']);
        } elseif ($option === 'insert') {
            $this->sql = 'insert into ' . $this->sqlSlice['table'] . ' ' . $this->sqlSlice['insert'];
            $this->params = $this->paramSlice['insert'];
        } elseif ($option === 'delete') {
            $where = $this->sqlSlice['where'] ?: 'where 1=2';
            $this->sql = 'delete from ' . $this->sqlSlice['table'] . ' ' . $where;
            $this->params = $this->paramSlice['where'];
        }
        $this->sqlSlice = $this->sqlSliceInit;
        $this->paramSlice = $this->paramSliceInit;
    }

    private function orGroupBegin() : DB
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where (';
        } else {
            $this->sqlSlice['where'] .= ' or (';
        }
        return $this;
    }

    private function andGroupBegin() : DB
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where (';
        } else {
            $this->sqlSlice['where'] .= ' and (';
        }
        return $this;
    }

    private function groupEnd() : void
    {
        $this->sqlSlice['where'] .= ')';
    }

    private function _join(...$args) : void
    {
        $joinSql = $args[1];
        $args = $args[0];
        $offset = stripos($args[0], ' as ');
        if ($offset === false) {
            $tableName = trim($args[0], ' `');
            $alias = '';
        } else {
            $tableName = trim(substr($args[0], 0, $offset), ' `');
            $alias = $this->conf[$this->connect]['prefix'].trim(substr($args[0], $offset + 4), ' `');
            $alias = ' as `' . $alias . '`';
        }
        $joinSql .= '`' . $this->conf[$this->connect]['prefix'] . $tableName . '`' . $alias;
        $argNum = count($args);
        if ($argNum === 4) { // Simple parameters
            $arr1 = explode('.', $args[1]);
            $arr2 = explode('.', $args[3]);
            if (count($arr1) === 2) {
                $table1 = trim($arr1[0], ' `');
                $join1 = '`' . $this->conf[$this->connect]['prefix'] . $table1 . '`.' . $arr1[1];
            } else {
                $join1 = $arr1[0];
                if (strpos($arr1[0], '`') === false) $join1 = '`' . $join1 . '`';
            }
            if (count($arr2) === 2) {
                $table2 = trim($arr2[0], ' `');
                $join2 = '`' . $this->conf[$this->connect]['prefix'] . $table2 . '`.' . $arr2[1];
            } else {
                $join2 = $arr2[0];
                if (strpos($arr1[0], '`') === false) $join2 = '`' . $join2 . '`';
            }
            $joinSql .= " on {$join1}{$args[2]}{$join2}";
        } elseif ($argNum === 3) { // Native sql
            $joinSql .= " on $args[1]";
            $this->paramSlice['join'] = array_merge($this->paramSlice['join'], $args[2]);
        }
        $this->sqlSlice['join'] .= ' ' . $joinSql . ' ';
    }

    private function _where(...$where) : void
    {
        $relation = $where[1];
        $where = $where[0];
        if (empty($where[0])) return;
        $whereSql = '';
        $whereParams = [];
        if (is_array($where[0])) { // Two-dimensional array
            $whereSql .= '(';
            foreach ($where[0] as $val) {
                $column = $this->column($val[0]);
                $whereSql .= $column.' '.$val[1].' ? and ';
                $whereParams[] = $val[2];
            }
            $whereSql = substr($whereSql, 0, -5) . ')';
        } elseif (is_callable($where[0])) { // Closure
            if ($relation === 'and') {
                $where[0]($this->andGroupBegin());
            } else {
                $where[0]($this->orGroupBegin());
            }
            $this->groupEnd();
        } else {
            $agsNum = count($where);
            if ($agsNum === 3) { // Simple parameters
                $column = $this->column($where[0]);
                $whereSql .= $column.' '.$where[1].' ?';
                $whereParams[] = $where[2];
            } elseif ($agsNum === 2) {  // Native sql
                $whereSql = $where[0];
                $whereParams = $where[1];
            }
        }
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = ' where ' . $whereSql;
        } else {
            if ($whereSql && substr($this->sqlSlice['where'], -1) !== '(') $whereSql = " {$relation} {$whereSql}";
            $this->sqlSlice['where'] .= $whereSql . ' ';
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $whereParams);
    }

    private function column(string $column) : string
    {
        $arr = explode('.', $column);
        $table = '';
        if (count($arr) === 2) {
            $table = trim($arr[0], ' `');
            $table = '`' . $this->conf[$this->connect]['prefix'] . $table . '`.';
            $column = $arr[1];
        }
        return $table . '`' . trim($column, ' `') . '`';
    }

}