<?php

namespace Odb;

class SqlBuilder
{
    /** @var SqlOperator */
    protected $op;
    protected $prefix;
    protected $table;
    protected $sql;
    protected $params = [];

    protected $sqlSlice = [
        'distinct' => '', 'columns' => '', 'table' => '', 'join' => '', 'where' => '', 'group_by' => '',
        'having' => '', 'order_by' => '', 'limit' => '', 'update' => ''
    ];
    protected $paramSlice = [
        'allow' => [], 'join' => [], 'where' => [], 'having' => [], 'update' => []
    ];

    public static function new(string $tablePrefix = '')
    {
        return new static($tablePrefix);
    }

    /**
     * SqlBuilder constructor.
     * @param string $tablePrefix
     */
    public function __construct(string $tablePrefix = '')
    {
        $this->prefix = $tablePrefix;
    }

    public function setOperator(SqlOperator $op)
    {
        $this->op = $op;
        return $this;
    }

    /**
     * usage: table('user') || table('user as u') || table('user u');
     * @param string $table
     * @return $this
     */
    public function table(string $table)
    {
        $table = trim($table);
        $foffset = strpos($table, ' ');
        if ($foffset === false) {
            $tableName = $table;
            $alias = '';

        } else {
            $tableName = substr($table, 0, $foffset);

            $soffset = strrpos($table, ' ');
            $alias = ' as `' . $this->prefix . substr($table, $soffset+1) . '`';
        }
        $this->table = $this->prefix . $tableName;
        $this->sqlSlice['table'] = ' `' . $this->table . '`' . $alias;
        return $this;
    }

    /**
     * usage: distinct()
     * @return $this
     */
    public function distinct()
    {
        $this->sqlSlice['distinct'] = ' distinct';
        return $this;
    }

    /**
     * usage: select(['id', 'age']) || select('id', 'age') || select('gradescore as score', 'sum(user.score) num')
     * @param $columns
     * @return $this
     */
    public function select($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        if ($columns === []) {
            $this->sqlSlice['columns'] = ' * ';
            return $this;
        }
        $columnSql = '';
        foreach ($columns as $column) {
            // 处理可能带有函数的列名
            $func = '@';
            $table = '';
            $arr = explode('(', $column);
            if (count($arr) === 2) {
                $func = $arr[0] . '(@)';
                $column = $arr[1];
            }
            // 处理可能带有表名的列名
            $arr = explode('.', $column);
            if (count($arr) === 2) {
                $table = '`' . $this->prefix . trim($arr[0]) . '`.';
                $column = $arr[1];
            }
            $column = trim($column, ' )');
            $foffset = strpos($column, ' ');
            if ($foffset === false) { // 获取列名
                $realcolumn = $column;
                $alias = '';
            } else {
                $realcolumn = rtrim(substr($column, 0, $foffset), ' )');
                $soffset = strrpos($column, ' ');
                $alias = ' as `' . substr($column, $soffset+1) . '`';
            }
            if ($realcolumn !== '*') { // *号不添加``
                $realcolumn = '`' . $realcolumn . '`';
            }
            $column = $table . $realcolumn;
            $column = str_replace('@', $column, $func);
            $columnSql .= $column . $alias . ',';
        }
        $this->sqlSlice['columns'] = ' '.rtrim($columnSql, ',').' ';
        return $this;
    }

    /**
     * usage: where('id', '=', 2) || where([['id', '=', 2], ['age', '>', 24]]) || where('id > ?', [45])
     * || where(function($query){$query->where('age', '>', 24)}) || where('id', 2)
     * @param array ...$where
     * @return $this
     */
    public function where(...$where)
    {
        $this->_where('and', ...$where);
        return $this;
    }

    /**
     * usage: orWhere('id', '=', 2) || orWhere([['id', '=', 2], ['age', '>', 24]]) || orWhere('id > ?', [45])
     * || orWhere(function($query){$query->where('age', '>', 24)}) || orWhere('id', 2)
     * @param array ...$where
     * @return $this
     */
    public function orWhere(...$where)
    {
        $this->_where('or', ...$where);
        return $this;
    }

    /**
     * usage: whereRaw('test_sup | 2 = ?', [6])
     * @param string $whereSql
     * @param array $whereParams
     * @return $this
     */
    public function whereRaw(string $whereSql, array $whereParams)
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = ' where ' . $whereSql;
        } else {
            if ($whereSql && substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' .  $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $whereParams);
        return $this;
    }

    /**
     * usage: whereNull('name')
     * @param string $column
     * @return $this
     */
    public function whereNull(string $column)
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

    /**
     * usage: whereNotNull('name')
     * @param string $column
     * @return $this
     */
    public function whereNotNull(string $column)
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

    /**
     * usage: whereBetween('age', [22, 26])
     * @param string $column
     * @param array $between
     * @return $this
     */
    public function whereBetween(string $column, array $between)
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

    /**
     * usage: whereNotBetween('age', [0, 18])
     * @param string $column
     * @param array $between
     * @return $this
     */
    public function whereNotBetween(string $column, array $between)
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

    /**
     * usage: whereIn('id', [1,3,5,6])
     * @param string $column
     * @param array $in
     * @return $this
     */
    public function whereIn(string $column, array $in)
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

    /**
     * usage: whereNotIn('id', [2,3])
     * @param string $column
     * @param array $in
     * @return $this
     */
    public function whereNotIn(string $column, array $in)
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

    /**
     * usage: whereColumn('id', '>', 'parent_id')
     * @param $where
     * @return $this
     */
    public function whereColumn($where)
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

    /**
     * usage: table('user as u')->join('article as a', 'u.id', '=', 'a.uid') ||
     * join('role', 'test_user.role_id=test_role.id and test_role.status>?', [1])
     * @param array ...$args
     * @return $this
     */
    public function join(...$args)
    {
        $this->_join('inner join ', ...$args);
        return $this;
    }

    /**
     * usage: same as join
     * @param array ...$args
     * @return $this
     */
    public function leftJoin(...$args)
    {
        $this->_join('left join ', ...$args);
        return $this;
    }

    /**
     * usage: same as join
     * @param array ...$args
     * @return $this
     */
    public function rightJoin(...$args)
    {
        $this->_join('right join ', ...$args);
        return $this;
    }

    /**
     * usage: orderBy('id', 'desc')
     * @param string $column
     * @param string $order
     * @return $this
     */
    public function orderBy(string $column, string $order)
    {
        $column = $this->column($column);
        if ($this->sqlSlice['order_by'] === '') {
            $this->sqlSlice['order_by'] = ' order by ' . $column . ' ' . $order;
        } else {
            $this->sqlSlice['order_by'] .= ',' .  $column . ' ' . $order;
        }
        return $this;
    }

    /**
     * usage: groupBy('username') || groupBy(['username', 'age']) || groupBy('username', 'age')
     * @param $column
     * @return $this
     */
    public function groupBy($column)
    {
        $columns = is_array($column) ? $column : func_get_args();
        $columnStr = '';
        foreach ($columns as $value) {
            $columnStr .= $this->column($value) . ',';
        }
        $columnStr = rtrim($columnStr, ',');
        $this->sqlSlice['group_by'] = ' group by ' . $columnStr . ' ';
        return $this;
    }

    /**
     * usage: having('num', '>', 2) || having('num > ?', [2])
     * @return $this
     */
    public function having(...$params)
    {
        $havingSql = ' having ';
        if (isset($params[2])) { // Simple parameters
            $column = $this->column($params[0]);
            $havingSql .= "{$column} {$params[1]} ?";
            $this->paramSlice['having'][] = $params[2];
        } else { // Native sql
            $havingSql .= $params[0];
            $this->paramSlice['having'] = $params[1];
        }
        $this->sqlSlice['having'] = $havingSql;
        return $this;
    }

    /**
     * usage: limit(3) || limit(2,3)
     * @param int $limit
     * @param int $length
     * @return $this
     */
    public function limit(int $limit, int $length = 0)
    {
        $this->sqlSlice['limit'] = ' limit ' . intval($limit);
        if ($length) $this->sqlSlice['limit'] .= ',' . intval($length);
        return $this;
    }

    /**
     * used for insert,  usage: allow('name', 'age') || allow(['name', 'age'])
     * @param $columns
     * @return $this
     */
    public function allow($columns)
    {
        $allows = is_array($columns) ? $columns : func_get_args();
        foreach ($allows as $a) {
            $this->paramSlice['allow'][$a] = '';
        }
        return $this;
    }

    /**
     * @return $this
     */
    public function buildQuery()
    {
        $columns = $this->sqlSlice['columns'] ? $this->sqlSlice['columns'] : ' * ';
        $this->sql = 'select' . $this->sqlSlice['distinct'] . $columns . 'from'.
            $this->sqlSlice['table'] .' '. $this->sqlSlice['join'] . $this->sqlSlice['where'] .
            $this->sqlSlice['group_by'] . $this->sqlSlice['having'] . $this->sqlSlice['order_by'] .
            $this->sqlSlice['limit'];
        $this->params = array_merge($this->paramSlice['join'], $this->paramSlice['where'], $this->paramSlice['having']);
//            $this->sql = rtrim($this->sql);
        $this->clean();
        return $this;
    }

    /**
     * @param array $insert
     * @return $this
     */
    public function buildInsert(array $insert)
    {
        $columns = '(';
        $values = '';
        $allow = $this->paramSlice['allow'];
        $insertVal = [];
        $filter = ($allow !== []) ? true : false;
        if (isset($insert[0]) && is_array($insert[0])) {
            $time = 0;
            foreach ($insert as $val) {
                $values .= '(';
                foreach ($val as $k => $v) {
                    if (!$filter || isset($allow[$k])) {
                        if ($time == 0) $columns .= '`' . $k . '`,';
                        $values .= '?,';
                        $insertVal[] = $v;
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
                if (!$filter || isset($allow[$key])) {
                    $columns .= '`' . $key . '`,';
                    $values .= '?,';
                    $insertVal[] = $val;
                }
            }
            $values = rtrim($values, ',') . ')';
            $columns = rtrim($columns, ',') . ')';
        }
        $this->sql = 'insert into ' . $this->sqlSlice['table'] . ' ' . $columns . ' values ' . $values;
        $this->params = $insertVal;
        $this->clean();
        return $this;
    }

    protected function _buildUpdate()
    {
        $where = $this->sqlSlice['where'] ?: 'where 1=2';
        $this->sql = 'update ' . $this->sqlSlice['table'] . ' ' . $this->sqlSlice['update'] . ' ' . $where;
        $this->params = array_merge($this->paramSlice['update'], $this->paramSlice['where']);
        $this->clean();
    }

    /**
     * @param array $update
     * @return $this
     */
    public function buildUpdate(array $update)
    {
        $updateSql = 'set ';
        $allow = $this->paramSlice['allow'];
        $filter = ($allow !== []) ? true : false;
        foreach ($update as $key => $val) {
            if (!$filter || isset($allow[$key])) {
                $updateSql .= '`' . $key . '`=?,';
                $this->paramSlice['update'][] = $val;
            }
        }
        $this->sqlSlice['update'] = rtrim($updateSql, ',');
        $this->_buildUpdate();
        return $this;
    }

    /**
     * @param $increment
     * @return $this
     */
    public function buildIncrement($increment)
    {
        $updateSql = 'set ';
        if (is_array($increment)) {
            foreach ($increment as $val) {
                $field = '`' . $val[0] . '`';
                $updateSql .= $field . '=' . $field . '+?,';
                $this->paramSlice['update'][] = $val[1];
            }
            $this->sqlSlice['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $incr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . $args[0] . '`';
            $this->sqlSlice['update'] = $updateSql . $field . '=' . $field . '+?';
            $this->paramSlice['update'][] = $incr;
        }
        $this->_buildUpdate();
        return $this;
    }

    /**
     * @param $decrement
     * @return $this
     */
    public function buildDecrement($decrement)
    {
        $updateSql = 'set ';
        if (is_array($decrement)) {
            foreach ($decrement as $val) {
                $field = '`' . $val[0] . '`';
                $updateSql .= $field . '=' . $field . '-?,';
                $this->paramSlice['update'][] = $val[1];
            }
            $this->sqlSlice['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $decr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . $args[0] . '`';
            $this->sqlSlice['update'] = $updateSql . $field . '=' . $field . '-?';
            $this->paramSlice['update'][] = $decr;
        }
        $this->_buildUpdate();
        return $this;
    }

    /**
     * @return $this
     */
    public function buildDelete()
    {
        $where = $this->sqlSlice['where'] ?: 'where 1=2';
        $this->sql = 'delete from ' . $this->sqlSlice['table'] . ' ' . $where;
        $this->params = $this->paramSlice['where'];
        $this->clean();
        return $this;
    }

    public function getOperator()
    {
        return $this->op;
    }

    /**
     * usage: insert(['id' => 2, 'name' => 'jack']) || insert([['name' => 'jack'], ['name' => 'linda']])
     * @param array $insert
     * @return int
     * @throws \Exception
     */
    public function insert(array $insert) : int
    {
        if (!$insert) return 0;
        if (isset($insert[0]) && $insert[0] == []) return $this;
        $this->buildInsert($insert);
        return $this->op->prepare($this->sql)->execute($this->params)->rowCount();
    }

    /**
     * usage: insertGetId(['id' => 2, 'name' => 'jack'])
     * @param array $insert
     * @return int
     * @throws \Exception
     */
    public function insertGetId(array $insert)
    {
        if (!$insert) return 0;
        $this->buildInsert($insert);
        return $this->op->prepare($this->sql)->execute($this->params)->lastInsertId();
    }

    /**
     * usage: update(['id' => 2, 'name' => 'jack'])
     * @param array $update
     * @return int
     * @throws \Exception
     */
    public function update(array $update) : int
    {
        if (!$update) return 0;
        $this->buildUpdate($update);
        return $this->op->prepare($this->sql)->execute($this->params)->rowCount();
    }

    /**
     * usage: increment('score') || increment('score', 2) || increment([['score', 1], ['level', 9]]);
     * @param $increment
     * @return int
     * @throws \Exception
     */
    public function increment($increment) : int
    {
        $this->buildIncrement($increment);
        return $this->op->prepare($this->sql)->execute($this->params)->rowCount();
    }

    /**
     * usage: increment('score') || increment('score', 2) || increment([['score', 1], ['level', 9]]);
     * @param $decrement
     * @return int
     * @throws \Exception
     */
    public function decrement($decrement) : int
    {
        $this->buildDecrement($decrement);
        return $this->op->prepare($this->sql)->execute($this->params)->rowCount();
    }

    public function delete() : int
    {
        $this->buildDelete();
        return $this->op->prepare($this->sql)->execute($this->params)->rowCount();
    }

    public function getSql() : string
    {
        return $this->sql;
    }

    public function getParams() : array
    {
        return $this->params;
    }

    public function getRSql()
    {
        if (!$this->sql) return '';
        $arr = explode('?', $this->sql);
        $sql = '';
        foreach ($arr as $k => $v) {
            $sql .= $v . ($this->params[$k] ?? '');
        }
        if (!$sql) $sql = $arr[0];
        return $sql;
    }

    public function get() : array
    {
        $this->buildQuery();
        return $this->op->prepare($this->sql)->execute($this->params)->get();
    }

    public function first() : ?array
    {
        $this->buildQuery();
        return $this->op->prepare($this->sql)->execute($this->params)->first();
    }

    public function pluck(string $col, string $key = '') : array
    {
        $columns[] = $col;
        ($key !== '') && ($columns[] = $key);
        $this->select($columns)->buildQuery();
        return $this->op->prepare($this->sql)->execute($this->params)->pluck($col, $key);
    }

    public function value(string $column) : ?string
    {
        $this->select($column)->buildQuery();
        return $this->op->prepare($this->sql)->execute($this->params)->value($column);
    }

    public function max(string $column) : int
    {
        $res = $this->select('max('.$column.') as num')->first();
        return $res['num'] ?: 0;
    }

    public function min(string $column) : int
    {
        $res = $this->select('min('.$column.') as num')->first();
        return $res['num'] ?: 0;
    }

    public function sum(string $column) : int
    {
        $res = $this->select('sum('.$column.') as num')->first();
        return $res['num'] ?: 0;
    }

    public function count() : int
    {
        $res = $this->select('count(*) as num')->first();
        return $res['num'];
    }

    public function avg(string $column) : int
    {
        $res = $this->select('avg('.$column.') as num')->first();
        return $res['num'] ?: 0;
    }

    public function clean()
    {
        foreach ($this->sqlSlice as $sk => $sv) {
            $this->sqlSlice[$sk] = '';
        }
        foreach ($this->paramSlice as $pk => $pv) {
            $this->paramSlice[$pk] = [];
        }
    }

    protected function _join($joinSql, ...$args) : void
    {
        $args[0] = trim($args[0]);
        $foffset = strpos($args[0], ' ');
        if ($foffset === false) {
            $tableName = $args[0];
            $alias = '';
        } else {
            $tableName = substr($args[0], 0, $foffset);
            $soffset = strrpos($args[0], ' ');
            $alias = ' as `' . $this->prefix.substr($args[0], $soffset+1) . '`';
        }
        $joinSql .= '`' . $this->prefix . $tableName . '`' . $alias;
        if (isset($args[3])) { // Simple parameters
            $offset1 = strpos($args[1], '.');
            $offset2 = strpos($args[3], '.');

            if ($offset1 !== false) {
                $join1 = '`' . $this->prefix . trim(substr($args[1],0, $offset1)) . '`.`' . trim(substr($args[1], $offset1+1)) . '`';
            } else {
                $join1 = '`' . trim($args[1]) . '`';
            }
            if ($offset2 !== false) {
                $join2 = '`' . $this->prefix . trim(substr($args[3],0, $offset2)) . '`.`' . trim(substr($args[3], $offset2+1)) . '`';
            } else {
                $join2 = '`' . $args[3] . '`';
            }
            $joinSql .= " on {$join1}{$args[2]}{$join2}";
        } else { // Native sql
            $joinSql .= " on $args[1]";
            $this->paramSlice['join'] = array_merge($this->paramSlice['join'], $args[2]);
        }
        $this->sqlSlice['join'] .= ' ' . $joinSql . ' ';
    }

    protected function orGroupBegin()
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where (';
        } else {
            $this->sqlSlice['where'] .= ' or (';
        }
        return $this;
    }

    protected function andGroupBegin()
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where (';
        } else {
            $this->sqlSlice['where'] .= ' and (';
        }
        return $this;
    }

    protected function groupEnd() : void
    {
        $this->sqlSlice['where'] .= ')';
    }

    protected function _where($relation, ...$where) : void
    {
        if (empty($where[0])) return;
        $whereSql = '';
        $whereParams = [];
        if (is_array($where[0])) { // Two-dimensional array
            $whereSql .= '(';
            foreach ($where[0] as $val) {
                $column = $this->column($val[0]);
                if (isset($val[2])) {
                    $whereSql .= $column.' '.$val[1].' ? and ';
                    $whereParams[] = $val[2];
                } else {
                    $whereSql .= $column.' = ? and ';
                    $whereParams[] = $val[1];
                }
            }
            $whereSql = substr($whereSql, 0, -5) . ')';
        } elseif (!is_string($where[0]) && is_callable($where[0])) { // Closure,此处不能是函数名
            if ($relation === 'and') {
                $where[0]($this->andGroupBegin());
            } else {
                $where[0]($this->orGroupBegin());
            }
            $this->groupEnd();
        } else {
            if (isset($where[2])) {
                $column = $this->column($where[0]);
                $whereSql .= $column.' '.$where[1].' ?';
                $whereParams[] = $where[2];
            } else {
                $column = $this->column($where[0]);
                $whereSql .= $column.' = ?';
                $whereParams[] = $where[1];
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

    protected function column(string $column) : string
    {
        $offset = strpos($column, '.');
        $table = '';
        if ($offset !== false) {
            $table = '`' . $this->prefix . rtrim(substr($column, 0, $offset)) . '`.';
            $column = ltrim(substr($column, $offset+1));
        }
        return $table . '`' . $column . '`';
    }

}
