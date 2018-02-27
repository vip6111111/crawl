<?php
namespace crawl\library\db;

use Exception;
use PDO;
use PDOException;

/**
 * PDO 数据库类.
 *
 * @author
 */
class MysqlPDO
{
    /*
     * 最后执行的一条SQL语句
     */
    public $lastSql;

    /**
     * 受影响行数.
     */
    public $num_rows;

    public $log = true;

    /**
     * 数据表前缀
     */
    protected $tablePrefix = '';

    protected $pdoException;

    /**
     * 执行SQL语句.
     */
    private $_arrSql;

    /**
     * 数据库配置.
     */
    private $_config;

    /**
     * 表链接.
     */
    private static $conn = null;

    /**
     * @var \PDOStatement
     */
    private $_stmt;


    /**
     * MysqlPDO constructor.
     * @param array $config
     */
    public function __construct(array $config)
    {
        if (!class_exists('PDO')) {
            throw new PDOException('PHP环境未安装PDO函数库！');
        }
        $this->_config = $config;
        if (!is_array($config)) {
            throw new PDOException('Adapter parameters must be in an array !');
        }
    }

    /**
     * 析构函数.
     */
    public function __destruct()
    {
        self::$conn = null;
    }

    /**
     * 按表字段调整适合的字段.
     *
     * @param $table
     * @param $rows 输入的表字段
     *
     * @return array
     */
    private function __prepera_format($table, $rows)
    {
        $columns = $this->getTableInfo($table);
        $newcol = array();
        foreach ($columns as $col) {
            $newcol[$col['Field']] = $col['Field'];
        }

        return array_intersect_key($rows, $newcol);
    }

    /**
     * 对特殊字符进行过滤.
     *
     * @param $value
     *
     * @return float|int|string
     */
    private function __val_escape($value)
    {
        if (null === $value) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (float) $value;
        }
        if (@get_magic_quotes_gpc()) {
            $value = stripslashes($value);
        }

        return $this->getConn()->quote($value);
    }

    /**
     * 从数据表中查找一条记录.
     *
     * @param string $table 数据表
     * @param string|array $conditions 查找条件，数组array("字段名"=>"查找值")或字符串，请注意在使用字符串时将需要开发者自行使用escape来对输入值进行过滤
     * @param null $sort       排序，等同于“ORDER BY ”
     * @param null $fields     返回的字段范围，默认为返回全部字段的值
     * @return bool|mixed
     * @throws Exception
     */
    public function find($table, $conditions = null, $sort = null, $fields = null)
    {
        if ($record = $this->findAll($table, $conditions, $sort, $fields, 1)) {
            return array_pop($record);
        }

        return false;
    }

    /**
     * 从数据表中查找记录.
     *
     * @param $table
     * @param string|array $conditions 数组array("字段名"=>"查找值")或字符串，请注意在使用字符串时将需要开发者自行使用escape来对输入值进行过滤
     * @param null $sort       排序，等同于“ORDER BY ”
     * @param null $fields     返回的字段范围，默认为返回全部字段的值
     * @param null $limit      如果limit值只有一个数字，则是指代从0条记录开始
     * @param null $offset     offset
     * @throws Exception
     * @return bool
     */
    public function findAll($table, $conditions = null, $sort = null, $fields = null, $limit = null, $offset = null)
    {
        $where = '';
        $fields = empty($fields) ? '*' : $fields;
        $params = array();
        if (is_array($conditions)) {
            $join = array();
            foreach ($conditions as $key => $condition) {
                $join[] = "`{$key}` = :{$key}";
                $params[":{$key}"] = $condition;
            }
            $where = 'WHERE '.implode(' AND ', $join);
        } else {
            if (null !== $conditions) {
                $where = 'WHERE '.$conditions;
            }
        }
        if (null !== $sort) {
            $sort = "ORDER BY {$sort}";
        }
        $table = $this->getTableName($table);
        $sql = "SELECT {$fields} FROM {$table} {$where} {$sort}";
        if (null !== $limit) {
            $sql = $this->setLimit($sql, $limit, $offset);
        }

        return $this->getArray($sql, $params);
    }

    /**
     * 过滤转义字符.
     *
     * @param $value 需要进行过滤的值
     *
     * @return float|int
     */
    public function escape($value)
    {
        return $this->__val_escape($value);
    }

    /**
     * 在数据表中新增一行数据.
     * @param string $table
     * @param array $row 数组形式，数组的键是数据表中的字段名，键对应的值是需要新增的数据
     * @return bool|mixed
     * @throws Exception
     */
    public function insert($table, $row)
    {
        if (!is_array($row)) {
            return false;
        }

        $row = $this->__prepera_format($table, $row);
        if (empty($row)) {
            return false;
        }

        $colArr = [];
        $valArr = [];
        foreach ($row as $key => $value) {
            $colArr[] = $key;
            $valArr[] = ":{$key}";
            $params[":{$key}"] = $value;
        }

        $col = '(`'.implode('`,`', $colArr).'`)';
        $val = implode(',', $valArr);

        $table = $this->getTableName($table);
        $sql = "INSERT INTO $table {$col} VALUES ({$val})";

        if (false !== $this->exec($sql, $params)) { // 获取当前新增的ID
            if ($newInsertId = $this->lastInsertId()) {
                return $newInsertId;
            }
        }

        return false;
    }


    /**
     * 批量insert
     * @param string $table
     * @param array $rows 二维数组、一维数组
     * @return bool
     * @throws Exception
     */
    public function insertAll($table, $rows)
    {
        $sql = $this->createInsert($table, $rows);

        return $this->exec($sql);
    }

    /**
     * 根据数组（支持一维二维）,生成insert SQL语句.
     *
     * @param $table
     * @param array $data
     *
     * @return string
     */
    public function createInsert($table, array $data)
    {
        $table = $this->getTableName($table);
        $sql = 'INSERT INTO '.$table;
        $flag = false; // 是否是二维数组

        $fields = [];
        $values = [];
        foreach ($data as $key => $val) {
            if (is_array($val)) { // 二维数组
                $flag = true;
                $fields = array_keys($val);
                $values[] = "('".implode("','", array_map('addslashes', $val))."')";
            } else { // 一维数组
                $values[] = $this->escape($val);
                $fields[] = $key;
            }
        }

        $sql .= ' (`'.implode('`,`', $fields).'`) VALUES ';
        if ($flag) { // 二维数组
            $sql .= implode(',', $values).';';
        } else { // 一维数组
            $sql .= "('".implode("','", $values)."');";
        }

        return $sql;
    }

    /**
     * 按条件删除记录
     * @param string $table
     * @param array|string $conditions 查找条件，此参数的格式用法与find/findAll的查找条件参数是相同的
     * @return bool
     * @throws Exception
     */
    public function delete($table, $conditions)
    {
        $where = '';
        if (is_array($conditions)) {
            $join = array();
            $params = array();
            foreach ($conditions as $key => $condition) {
                $join[] = "`{$key}` = :{$key}";
                $params[":{$key}"] = $condition;
            }
            $where = 'WHERE ( '.implode(' AND ', $join).')';
        } else {
            if (null !== $conditions) {
                $where = 'WHERE ( '.$conditions.')';
            }
        }
        $table = $this->getTableName($table);
        $sql = "DELETE FROM {$table} {$where}";

        return $this->exec($sql, $params);
    }

    /**
     * 按字段值查找一条记录.
     * @param  string $table
     * @param  string $field 字符串，对应数据表中的字段名
     * @param  string $value 字符串，对应的值
     * @return bool|mixed
     * @throws Exception
     */
    public function findBy($table, $field, $value)
    {
        return $this->find($table, array(
            $field => $value,
        ));
    }

    /**
     * 返回最后执行的SQL语句供分析.
     *
     * @return mixed
     */
    public function getSqlList()
    {
        return $this->_arrSql;
    }

    /**
     * 返回最后执行的SQL语句供分析.
     *
     * @return mixed
     */
    public function getLastSql()
    {
        return array_pop($this->_arrSql);
    }

    /**
     * 返回上次执行update,create,delete,exec的影响行数.
     *
     * @return mixed
     */
    public function affectedRows()
    {
        return $this->num_rows;
    }

    /**
     * 计算符合条件的记录数量.
     *
     * @param $table
     * @param null $conditions 查找条件，数组array("字段名"=>"查找值")或字符串， 请注意在使用字符串时将需要开发者自行使用escape来对输入值进行过滤
     *
     * @return int
     */
    public function count($table, $conditions = null)
    {
        $where = '';
        if (is_array($conditions)) {
            $join = array();
            foreach ($conditions as $key => $condition) {
                $condition = $this->escape($condition);
                $join[] = "`{$key}` = {$condition}";
            }
            $where = 'WHERE '.implode(' AND ', $join);
        } else {
            if (null !== $conditions) {
                $where = 'WHERE '.$conditions;
            }
        }
        $table = $this->getTableName($table);
        $sql = "SELECT COUNT(*) AS SP_COUNTER FROM {$table} {$where}";
        $result = $this->getArray($sql);

        return (int) $result[0]['SP_COUNTER'];
    }


    /**
     * 修改数据，该函数将根据参数中设置的条件而更新表中数据.
     * @param string $table
     * @param array|string $conditions
     * @param $row
     * @return bool
     * @throws Exception
     */
    public function update($table, $conditions, $row)
    {
        $where = '';
        $row = $this->__prepera_format($table, $row);
        if (empty($row)) {
            return false;
        }
        $params = array();
        if (is_array($conditions)) {
            $join = array();

            foreach ($conditions as $key => $condition) {
                $join[] = "`{$key}` = :{$key}";
                $params[":{$key}"] = $condition;
            }
            $where = 'WHERE '.implode(' AND ', $join);
        } else {
            if (null !== $conditions) {
                $where = 'WHERE '.$conditions;
            }
        }
        foreach ($row as $key => $value) {
            $vals[] = "`{$key}` = :set_{$key}";
            $params[":set_{$key}"] = $value;
        }
        $values = implode(', ', $vals);
        $table = $this->getTableName($table);
        $sql = "UPDATE  {$table} SET {$values} {$where}";
        $resultUpdate = $this->exec($sql, $params);
        return (true === $resultUpdate) ? $this->_stmt->rowCount() : false;
    }


    /**
     *  按字段值修改一条记录.
     * @param string $table
     * @param array|string $conditions 查找条件，此参数的格式用法与find/findAll的查找条件参数是相同的
     * @param string $field 数据表中的需要修改的字段名
     * @param string $value  数据表中的需要修改新值
     * @return bool
     * @throws Exception
     */
    public function updateField($table, $conditions, $field, $value)
    {
        return $this->update($table, $conditions, array(
            $field => $value,
        ));
    }


    /**
     * 按给定的数据表的主键删除记录.
     * @param string $table  数据表
     * @param string|int $pk 数据表主键的值
     * @return bool
     * @throws Exception
     */
    public function deleteByPk($table, $pk)
    {
        $table = $this->getTableName($table);

        return $this->delete($table, array(
            'id' => $pk,
        ));
    }

    /**
     * 返回当前插入记录的主键ID.
     *
     * @return mixed
     */
    public function lastInsertId()
    {
        return $this->getConn()->lastInsertId();
    }

    /**
     * 格式化带limit的SQL语句.
     *
     * @param string $sql
     * @param int    $limit
     * @param int    $offset
     *
     * @throws Exception
     *
     * @return string
     */
    public function setLimit($sql, $limit, $offset = 0)
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            throw new Exception("LIMIT argument limit=$limit is not valid");
        }

        $offset = (int) $offset;
        if ($offset < 0) {
            throw new Exception("LIMIT argument offset=$offset is not valid");
        }

        $sql .= " LIMIT $limit";
        if ($offset > 0) {
            $sql .= " OFFSET $offset";
        }

        return $sql;
    }

    /**
     * 执行一个SQL语句.
     *
     * @param string $sql    需要执行的SQL语句
     * @param array   $params 绑定参数
     * @throws Exception
     * @return bool
     */
    public function exec($sql, $params = array())
    {
        try {
            if (!$this->_stmt = $this->getConn()->prepare($sql)) {
                $pdoError = $this->getConn()->errorInfo();
                throw new Exception('[execution error]: '.$pdoError[2]."{$sql}");
            }
            if (!empty($params)) {
                foreach ($params as $key => $val) {
                    $sql = str_replace($key, "'{$val}'", $sql);
                    $this->_stmt->bindValue($key, $val);
                }
            }
            $this->_arrSql[] = $sql;
            $this->_stmt->execute();

            return true;
        } catch (PDOException $e) {
            if (true === $this->log) {
                error_log('DATABASE ::'.print_r($e, true));
            }
            $this->pdoException = $e;

            return false;
        } catch (Exception $e) {
            if (true === $this->log) {
                error_log('DATABASE ::'.print_r($e, true));
            }
            $this->pdoException = $e;

            return false;
        }

        return false;
    }

    /**
     * 获取数据表结构.
     * @param $table 表名称
     * @return bool
     */
    public function getTableInfo($table)
    {
        $table = $this->getTableName($table);
        $tableInfo = $this->getArray("DESCRIBE {$table}");
        if (empty($tableInfo)) {
            throw new PDOException('The'.$table.'not exists');
        }
        return $tableInfo;
    }

    /**
     * 获取表名，处理表前缀
     * @param string $name
     * @return string
     */
    public function getTableName($name)
    {
        if (false !== strpos($name, '{{')) {
            $prefix = $this->_config['tablePrefix'];
            $name = preg_replace_callback('#\{\{(.*)\}\}#', function ($match) use ($prefix) {
                return '`'.$prefix.$match[1].'`';
            }, $name);

        }

        return $name;
    }

    /**
     * getConn 取得PDO对象
     */
    public function getConn()
    {
        if (null === self::$conn) {
            $this->conn();
        }

        return self::$conn;
    }

    /**
     * 数据库连接.
     */
    private function conn()
    {
        if (!isset($this->_config['host'])) {
            throw new PDOException('HOTS不能为空');
        }
        if (!isset($this->_config['user'])) {
            throw new PDOException('用户名不能为空');
        }
        if (!isset($this->_config['password'])) {
            throw new PDOException('密码不能为空');
        }
        if (!isset($this->_config['tablePrefix'])) {
            throw new PDOException('tablePrefix 表前缀不存在');
        }
        if (!isset($this->_config['charset'])) {
            $this->_config['charset'] = 'utf8';
        }
        $this->tablePrefix = $this->_config['tablePrefix'];
        try {
            self::$conn = new PDO(
                $this->_config['host'],
                $this->_config['user'],
                $this->_config['password'],
                array(
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->_config['charset']}",
                )
            );
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage());
        }
    }

    /**
     * 按SQL语句获取记录结果，返回数组.
     *
     * @param string sql 执行的SQL语句
     * @param array params 参数绑定
     * @param mixed $sql
     * @param mixed $params
     *
     * @return bool
     */
    private function getArray($sql, $params = array())
    {
        try {
            $this->exec($sql, $params);

            return $this->_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (true === $this->log) {
                error_log('DATABASE WRAPPER::'.print_r($e, true));
            }
            $this->pdoException = $e;

            return false;
        } catch (Exception $e) {
            if (true === $this->log) {
                error_log('DATABASE WRAPPER::'.print_r($e, true));
            }
            $this->pdoException = $e;

            return false;
        }
    }
}
