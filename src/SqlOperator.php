<?php
/**
 * Created by PhpStorm.
 * User: chentairen
 * Date: 2019/5/21
 * Time: 下午3:24
 */

namespace Odb;


use Enum\RgtEnum;
use Yaf\Registry;

class SqlOperator
{
    /** @var \PDO[] */
    protected static $instances = [];
    protected static $prefixs = [];
    protected static $conf;
    
    /** @var \PDO */
    protected $pdo;
    protected $prefix;
    /** @var \PDOStatement */
    protected $statement;
    protected $sql;

    /**
     * @param string $configPath
     * @throws \Exception
     */
    public static function loadConfig(string $configPath)
    {
        if (self::$conf === null) {
            self::$conf = include $configPath;
            if (!is_array(self::$conf)) {
                throw new \Exception("db config error");
            }
        }
    }

    /**
     * @param string $connect
     * @return \PDO
     * @throws \Exception
     */
    public static function getConn(string $connect) : \PDO
    {
        if (!isset(self::$instances[$connect])) {
            if (!isset(self::$conf[$connect])) throw new \Exception($connect.'没有数据库连接配置');
            $conf = self::$conf[$connect];
            $dsn = $conf['driver'] . ":host={$conf['host']};port={$conf['port']};dbname={$conf['dbname']};charset={$conf['charset']}";
            $conf['params'][\PDO::ATTR_PERSISTENT] = $conf['pconnect'] ? true : false;
            $conf['params'][\PDO::ATTR_TIMEOUT] = $conf['time_out'] ? $conf['time_out'] : 3;
            $conf['params'][\PDO::ATTR_ERRMODE] = $conf['throw_exception'] ? \PDO::ERRMODE_EXCEPTION : \PDO::ERRMODE_SILENT;
            self::$instances[$connect] = new \PDO($dsn, $conf['username'], $conf['password'], $conf['params']);
            self::$prefixs[$connect] = $conf['prefix'];
        }
        return self::$instances[$connect];
    }

    public function __construct($connect)
    {
        $this->pdo = self::getConn($connect);
        $this->prefix = self::$prefixs[$connect];
    }

    /**
     * @param string $table
     * @return SqlBuilder
     */
    public function table(string $table)
    {
        return (new SqlBuilder($this->prefix))->setOperator($this)->table($table);
    }

    public function beginTrans()
    {
        $this->pdo->beginTransaction();
    }

    public function rollBack()
    {
        $this->pdo->rollBack();
    }

    public function commit()
    {
        $this->pdo->commit();
    }

    public function inTrans()
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @param $sql
     * @return $this
     */
    public function query($sql)
    {
        $this->sql = $sql;
        $this->statement = $this->pdo->query($sql);
        return $this;
    }

    /**
     * @param $sql
     * @return int
     */
    public function exec($sql)
    {
        return $this->pdo->exec($sql);
    }

    /**
     * @param $sql
     * @return SqlOperator
     * @throws \Exception
     */
    public function prepare($sql)
    {
        try {
            $this->sql = $sql;
            $this->statement = $this->pdo->prepare($sql);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage() . ' sql: ' . $sql);
        }

        return $this;
    }

    /**
     * @param $params
     * @return SqlOperator
     * @throws \Exception
     */
    public function execute($params)
    {
        try {
            $this->statement->execute($params);
        } catch (\Exception $e) {
            $sql = $this->getRSql($this->sql, $params);
            throw new \Exception($e->getMessage() . ' sql: ' . $sql);
        }

        return $this;
    }

    /**
     * @return int
     */
    public function rowCount() : int
    {
        return $this->statement->rowCount();
    }

    /**
     * @return string
     */
    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * @return array
     */
    public function get() : array
    {
        return $this->statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array|null
     */
    public function first() : ?array
    {
        $res = $this->statement->fetch(\PDO::FETCH_ASSOC);
//        $this->pdoStatement->closeCursor(); // 非mysql时尽量打开
        return $res ? $res : null;
    }

    /**
     * @param string $column
     * @return mixed|null
     */
    public function value(string $column)
    {
        $res = $this->first();
        if (!$res) return null;
        return $res[$column];
    }

    /**
     * @param string $col
     * @param string $key
     * @return array
     */
    public function pluck(string $col, string $key = '')
    {
        $res = $this->get();
        if ($res === []) return $res;
        $col = trim($col);
        $offset = strpos($col, '.');
        if ($offset !== false) {
            $col = ltrim(substr($col, $offset+1));
        }
        if ($key !== '') {
            $data = [];
            $key = trim($key);
            $offset = strpos($key, '.');
            if ($offset !== false) {
                $key = ltrim(substr($key, $offset+1));
            }
            foreach ($res as $val) {
                $data[$val[$key]] = $val[$col];
            }
            return $data;
        }
        return array_column($res, $col);
    }

    public function getRSql($sql, $params)
    {
        $arr = explode('?', $sql);
        $sql = '';
        foreach ($arr as $k => $v) {
            $sql .= $v . ($params[$k] ?? '');
        }
        if (!$sql) $sql = $arr[0];
        return $sql;
    }

    public function getDBVersion() : ?string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    public function setAttribute(int $attributeName, int $attributeVal) : void
    {
        $this->pdo->setAttribute($attributeName, $attributeVal);
    }
}