<?php

namespace Odb;

class DB
{
    const DEFAULT_CONN = "default";

    /**
     * @param string $configPath
     * @throws \Exception
     */
    public static function loadConfig(string $configPath)
    {
        SqlOperator::loadConfig($configPath);
    }

    /**
     * @param string $connect
     * @return SqlOperator
     * @throws \Exception
     */
    public static function connect(string $connect = self::DEFAULT_CONN)
    {
        return new SqlOperator($connect);
    }

    /**
     * @param string $table
     * @param string $connect
     * @return SqlBuilder
     * @throws \Exception
     */
    public static function table(string $table, string $connect = self::DEFAULT_CONN)
    {
        return self::connect($connect)->table($table);
    }

    /**
     * @param string $connect
     * @throws \Exception
     */
    public static function beginTrans(string $connect = self::DEFAULT_CONN)
    {
        self::connect($connect)->beginTrans();
    }

    /**
     * @param string $connect
     * @throws \Exception
     */
    public static function rollBack(string $connect = self::DEFAULT_CONN)
    {
        self::connect($connect)->rollBack();
    }

    /**
     * @param string $connect
     * @throws \Exception
     */
    public static function commit(string $connect = self::DEFAULT_CONN)
    {
        self::connect($connect)->commit();
    }

    /**
     * @param string $connect
     * @return bool
     * @throws \Exception
     */
    public static function inTrans(string $connect = self::DEFAULT_CONN)
    {
        return self::connect($connect)->inTrans();
    }

    /**
     * @param string $sql
     * @param string $connect
     * @return SqlOperator
     * @throws \Exception
     */
    public static function prepare(string $sql, string $connect = self::DEFAULT_CONN)
    {
        return self::connect($connect)->prepare($sql);
    }

    /**
     * @param string $sql
     * @param string $connect
     * @return SqlOperator
     * @throws \Exception
     */
    public static function query(string $sql, string $connect = self::DEFAULT_CONN)
    {
        return self::connect($connect)->query($sql);
    }

    /**
     * @param string $sql
     * @param string $connect
     * @return int
     * @throws \Exception
     */
    public static function exec(string $sql, string $connect = self::DEFAULT_CONN)
    {
        return self::connect($connect)->exec($sql);
    }

    private function __construct() {}
    private function __clone() {}

}