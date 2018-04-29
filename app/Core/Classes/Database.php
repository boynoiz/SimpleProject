<?php
namespace App\Core\Classes;

use PDO;
use Exception;
use PDOException;

class Database
{
    // General
    /**
     * @var
     */
    protected $database_type;
    /**
     * @var
     */
    protected $charset;
    /**
     * @var
     */
    protected $database_name;
    // For MySQL, MariaDB, MSSQL, Sybase, PostgreSQL, Oracle
    /**
     * @var
     */
    protected $server;
    /**
     * @var null
     */
    protected $username;
    /**
     * @var null
     */
    protected $password;
    // For SQLite
    /**
     * @var
     */
    protected $database_file;
    // For MySQL or MariaDB with unix_socket
    /**
     * @var
     */
    protected $socket;
    // Optional
    /**
     * @var
     */
    protected $port;
    /**
     * @var mixed
     */
    protected $prefix;
    /**
     * @var array
     */
    protected $option = array();
    // Variable
    /**
     * @var array
     */
    protected $logs = array();
    /**
     * @var bool
     */
    protected $debug_mode = false;

    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var
     */
    protected $stmt;

    /**
     * Database constructor.
     * @param null $options
     * @throws Exception
     */
    public function __construct($options = null)
    {
        try {
            $commands = array();
            $dsn = '';
            if (is_array($options)) {
                foreach ($options as $option => $value) {
                    $this->$option = $value;
                }
            } else {
                return false;
            }
            if (
                isset($this->port) &&
                is_int($this->port * 1)
            ) {
                $port = $this->port;
            }
            $type = strtolower($this->database_type);
            $is_port = isset($port);
            if (isset($options['prefix'])) {
                $this->prefix = $options['prefix'];
            }
            switch ($type) {
                case 'mariadb':
                    $type = 'mysql';
                case 'mysql':
                    if ($this->socket) {
                        $dsn = $type . ':unix_socket=' . $this->socket . ';dbname=' . $this->database_name;
                    } else {
                        $dsn = $type . ':host=' . $this->server . ($is_port ? ';port=' . $port : '') . ';dbname=' . $this->database_name;
                    }
                    // Make MySQL using standard quoted identifier
                    $commands[] = 'SET SQL_MODE=ANSI_QUOTES';
                    break;
                case 'pgsql':
                    $dsn = $type . ':host=' . $this->server . ($is_port ? ';port=' . $port : '') . ';dbname=' . $this->database_name;
                    break;
                case 'sybase':
                    $dsn = 'dblib:host=' . $this->server . ($is_port ? ':' . $port : '') . ';dbname=' . $this->database_name;
                    break;
                case 'oracle':
                    $dbname = $this->server ?
                        '//' . $this->server . ($is_port ? ':' . $port : ':1521') . '/' . $this->database_name :
                        $this->database_name;
                    $dsn = 'oci:dbname=' . $dbname . ($this->charset ? ';charset=' . $this->charset : '');
                    break;
                case 'mssql':
                    $dsn = in_array('sqlsrv', PDO::getAvailableDrivers(), true) ?
                        'sqlsrv:server=' . $this->server . ($is_port ? ',' . $port : '') . ';database=' . $this->database_name :
                        'dblib:host=' . $this->server . ($is_port ? ':' . $port : '') . ';dbname=' . $this->database_name;
                    // Keep MSSQL QUOTED_IDENTIFIER is ON for standard quoting
                    $commands[] = 'SET QUOTED_IDENTIFIER ON';
                    break;
                case 'sqlite':
                    $dsn = $type . ':' . $this->database_file;
                    $this->username = null;
                    $this->password = null;
                    break;
            }
            if (in_array($type, array('mariadb', 'mysql', 'pgsql', 'sybase', 'mssql')) && $this->charset
            ) {
                $commands[] = "SET NAMES '" . $this->charset . "'";
            }
            $this->pdo = new PDO(
                $dsn,
                $this->username,
                $this->password,
                $this->option
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            foreach ($commands as $value) {
                $this->pdo->exec($value);
            }
        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param $query
     * @return bool|\PDOStatement
     */
    public function query($query)
    {
        if ($this->debug_mode) {
            echo $query;
            $this->debug_mode = false;
            return false;
        }
        $this->logs[] = $query;
        return $this->pdo->query($query);
    }

    /**
     * @param $query
     * @return bool|int
     */
    public function exec($query)
    {
        if ($this->debug_mode) {
            echo $query;
            $this->debug_mode = false;
            return false;
        }
        $this->logs[] = $query;
        return $this->pdo->exec($query);
    }

    /**
     * @param $string
     * @return string
     */
    public function quote($string)
    {
        return $this->pdo->quote($string);
    }

    /**
     * @param $table
     * @return string
     */
    protected function table_quote($table)
    {
        return '"' . $this->prefix . $table . '"';
    }

    /**
     * @param $string
     * @return string
     */
    protected function column_quote($string)
    {
        preg_match('/(\(JSON\)\s*|^#)?([a-zA-Z0-9_]*)\.([a-zA-Z0-9_]*)/', $string, $column_match);
        if (isset($column_match[2], $column_match[3])) {
            return '"' . $this->prefix . $column_match[2] . '"."' . $column_match[3] . '"';
        }
        return '"' . $string . '"';
    }

    /**
     * @param $columns
     * @return array|string
     */
    protected function column_push(&$columns)
    {
        if ($columns == '*') {
            return $columns;
        }
        if (is_string($columns)) {
            $columns = array($columns);
        }
        $stack = array();
        foreach ($columns as $key => $value) {
            if (is_array($value)) {
                $stack[] = $this->column_push($value);
            } else {
                preg_match('/([a-zA-Z0-9_\-\.]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $value, $match);
                if (isset($match[1], $match[2])) {
                    $stack[] = $this->column_quote($match[1]) . ' AS ' . $this->column_quote($match[2]);
                    $columns[$key] = $match[2];
                } else {
                    $stack[] = $this->column_quote($value);
                }
            }
        }
        return implode($stack, ',');
    }

    /**
     * @param $array
     * @return string
     */
    protected function array_quote($array)
    {
        $temp = array();
        foreach ($array as $value) {
            $temp[] = is_int($value) ? $value : $this->pdo->quote($value);
        }
        return implode($temp, ',');
    }

    /**
     * @param $data
     * @param $conjunctor
     * @param $outer_conjunctor
     * @return string
     */
    protected function inner_conjunct($data, $conjunctor, $outer_conjunctor)
    {
        $haystack = array();
        foreach ($data as $value) {
            $haystack[] = '(' . $this->data_implode($value, $conjunctor) . ')';
        }
        return implode($outer_conjunctor . ' ', $haystack);
    }

    /**
     * @param $column
     * @param $string
     * @return string
     */
    protected function fn_quote($column, $string)
    {
        return (strpos($column, '#') === 0 && preg_match('/^[A-Z0-9\_]*\([^)]*\)$/', $string)) ?
            $string :
            $this->quote($string);
    }

    /**
     * @param $data
     * @param $conjunctor
     * @param null $outer_conjunctor
     * @return string
     */
    protected function data_implode($data, $conjunctor, $outer_conjunctor = null)
    {
        $wheres = array();
        foreach ($data as $key => $value) {
            $type = gettype($value);
            if (
                preg_match("/^(AND|OR)(\s+#.*)?$/i", $key, $relation_match) &&
                $type == 'array'
            ) {
                $wheres[] = 0 !== count(array_diff_key($value, array_keys(array_keys($value)))) ?
                    '(' . $this->data_implode($value, ' ' . $relation_match[1]) . ')' :
                    '(' . $this->inner_conjunct($value, ' ' . $relation_match[1], $conjunctor) . ')';
            } else {
                preg_match('/(#?)([\w\.\-]+)(\[(\>|\>\=|\<|\<\=|\!|\<\>|\>\<|\!?~)\])?/i', $key, $match);
                $column = $this->column_quote($match[2]);
                if (isset($match[4])) {
                    $operator = $match[4];
                    if ($operator == '!') {
                        switch ($type) {
                            case 'NULL':
                                $wheres[] = $column . ' IS NOT NULL';
                                break;
                            case 'array':
                                $wheres[] = $column . ' NOT IN (' . $this->array_quote($value) . ')';
                                break;
                            case 'integer':
                            case 'double':
                                $wheres[] = $column . ' != ' . $value;
                                break;
                            case 'boolean':
                                $wheres[] = $column . ' != ' . ($value ? '1' : '0');
                                break;
                            case 'string':
                                $wheres[] = $column . ' != ' . $this->fn_quote($key, $value);
                                break;
                        }
                    }
                    if ($operator == '<>' || $operator == '><') {
                        if ($type == 'array') {
                            if ($operator == '><') {
                                $column .= ' NOT';
                            }
                            if (is_numeric($value[0]) && is_numeric($value[1])) {
                                $wheres[] = '(' . $column . ' BETWEEN ' . $value[0] . ' AND ' . $value[1] . ')';
                            } else {
                                $wheres[] = '(' . $column . ' BETWEEN ' . $this->quote($value[0]) . ' AND ' . $this->quote($value[1]) . ')';
                            }
                        }
                    }
                    if ($operator == '~' || $operator == '!~') {
                        if ($type != 'array') {
                            $value = array($value);
                        }
                        $like_clauses = array();
                        foreach ($value as $item) {
                            $item = strval($item);
                            $suffix = mb_substr($item, -1, 1);
                            if (preg_match('/^(?!(%|\[|_])).+(?<!(%|\]|_))$/', $item)) {
                                $item = '%' . $item . '%';
                            }
                            $like_clauses[] = $column . ($operator === '!~' ? ' NOT' : '') . ' LIKE ' . $this->fn_quote($key,
                                    $item);
                        }
                        $wheres[] = implode(' OR ', $like_clauses);
                    }
                    if (in_array($operator, array('>', '>=', '<', '<='))) {
                        if (is_numeric($value)) {
                            $wheres[] = $column . ' ' . $operator . ' ' . $value;
                        } elseif (strpos($key, '#') === 0) {
                            $wheres[] = $column . ' ' . $operator . ' ' . $this->fn_quote($key, $value);
                        } else {
                            $wheres[] = $column . ' ' . $operator . ' ' . $this->quote($value);
                        }
                    }
                } else {
                    switch ($type) {
                        case 'NULL':
                            $wheres[] = $column . ' IS NULL';
                            break;
                        case 'array':
                            $wheres[] = $column . ' IN (' . $this->array_quote($value) . ')';
                            break;
                        case 'integer':
                        case 'double':
                            $wheres[] = $column . ' = ' . $value;
                            break;
                        case 'boolean':
                            $wheres[] = $column . ' = ' . ($value ? '1' : '0');
                            break;
                        case 'string':
                            $wheres[] = $column . ' = ' . $this->fn_quote($key, $value);
                            break;
                    }
                }
            }
        }
        return implode($conjunctor . ' ', $wheres);
    }

    /**
     * @param $where
     * @return string
     */
    protected function where_clause($where)
    {
        $where_clause = '';
        if (is_array($where)) {
            $where_keys = array_keys($where);
            $where_AND = preg_grep("/^AND\s*#?$/i", $where_keys);
            $where_OR = preg_grep("/^OR\s*#?$/i", $where_keys);
            $single_condition = array_diff_key($where, array_flip(
                array('AND', 'OR', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'LIKE', 'MATCH')
            ));
            if ($single_condition != array()) {
                $condition = $this->data_implode($single_condition, '');
                if ($condition != '') {
                    $where_clause = ' WHERE ' . $condition;
                }
            }
            if (!empty($where_AND)) {
                $value = array_values($where_AND);
                $where_clause = ' WHERE ' . $this->data_implode($where[$value[0]], ' AND');
            }
            if (!empty($where_OR)) {
                $value = array_values($where_OR);
                $where_clause = ' WHERE ' . $this->data_implode($where[$value[0]], ' OR');
            }
            if (isset($where['MATCH'])) {
                $MATCH = $where['MATCH'];
                if (is_array($MATCH) && isset($MATCH['columns'], $MATCH['keyword'])) {
                    $where_clause .= ($where_clause != '' ? ' AND ' : ' WHERE ') . ' MATCH ("' . str_replace('.', '"."',
                            implode($MATCH['columns'],
                                '", "')) . '") AGAINST (' . $this->quote($MATCH['keyword']) . ')';
                }
            }
            if (isset($where['GROUP'])) {
                $where_clause .= ' GROUP BY ' . $this->column_quote($where['GROUP']);
                if (isset($where['HAVING'])) {
                    $where_clause .= ' HAVING ' . $this->data_implode($where['HAVING'], ' AND');
                }
            }
            if (isset($where['ORDER'])) {
                $ORDER = $where['ORDER'];
                if (is_array($ORDER)) {
                    $stack = array();
                    foreach ($ORDER as $column => $value) {
                        if (is_array($value)) {
                            $stack[] = 'FIELD(' . $this->column_quote($column) . ', ' . $this->array_quote($value) . ')';
                        } else {
                            if ($value === 'ASC' || $value === 'DESC') {
                                $stack[] = $this->column_quote($column) . ' ' . $value;
                            } else {
                                if (is_int($column)) {
                                    $stack[] = $this->column_quote($value);
                                }
                            }
                        }
                    }
                    $where_clause .= ' ORDER BY ' . implode($stack, ',');
                } else {
                    $where_clause .= ' ORDER BY ' . $this->column_quote($ORDER);
                }
            }
            if (isset($where['LIMIT'])) {
                $LIMIT = $where['LIMIT'];
                if (is_numeric($LIMIT)) {
                    $where_clause .= ' LIMIT ' . $LIMIT;
                }
                if (
                    is_array($LIMIT) &&
                    is_numeric($LIMIT[0]) &&
                    is_numeric($LIMIT[1])
                ) {
                    if ($this->database_type === 'pgsql') {
                        $where_clause .= ' OFFSET ' . $LIMIT[0] . ' LIMIT ' . $LIMIT[1];
                    } else {
                        $where_clause .= ' LIMIT ' . $LIMIT[0] . ',' . $LIMIT[1];
                    }
                }
            }
        } else {
            if ($where != null) {
                $where_clause .= ' ' . $where;
            }
        }
        return $where_clause;
    }

    /**
     * @param $table
     * @param $join
     * @param null $columns
     * @param null $where
     * @param null $column_fn
     * @return string
     */
    protected function select_context($table, $join, &$columns = null, $where = null, $column_fn = null)
    {
        preg_match('/([a-zA-Z0-9_\-]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $table, $table_match);
        if (isset($table_match[1], $table_match[2])) {
            $table = $this->table_quote($table_match[1]);
            $table_query = $this->table_quote($table_match[1]) . ' AS ' . $this->table_quote($table_match[2]);
        } else {
            $table = $this->table_quote($table);
            $table_query = $table;
        }
        $join_key = is_array($join) ? array_keys($join) : null;
        if (
            isset($join_key[0]) &&
            strpos($join_key[0], '[') === 0
        ) {
            $table_join = array();
            $join_array = array(
                '>' => 'LEFT',
                '<' => 'RIGHT',
                '<>' => 'FULL',
                '><' => 'INNER'
            );
            foreach ($join as $sub_table => $relation) {
                preg_match('/(\[(\<|\>|\>\<|\<\>)\])?([a-zA-Z0-9_\-]*)\s?(\(([a-zA-Z0-9_\-]*)\))?/', $sub_table,
                    $match);
                if ($match[2] != '' && $match[3] != '') {
                    if (is_string($relation)) {
                        $relation = 'USING ("' . $relation . '")';
                    }
                    if (is_array($relation)) {
                        // For ['column1', 'column2']
                        if (isset($relation[0])) {
                            $relation = 'USING ("' . implode($relation, '", "') . '")';
                        } else {
                            $joins = array();
                            foreach ($relation as $key => $value) {
                                $joins[] = (
                                    strpos($key, '.') > 0 ?
                                        // For ['tableB.column' => 'column']
                                        $this->column_quote($key) :
                                        // For ['column1' => 'column2']
                                        $table . '."' . $key . '"'
                                    ) .
                                    ' = ' .
                                    $this->table_quote(isset($match[5]) ? $match[5] : $match[3]) . '."' . $value . '"';
                            }
                            $relation = 'ON ' . implode($joins, ' AND ');
                        }
                    }
                    $table_name = $this->table_quote($match[3]) . ' ';
                    if (isset($match[5])) {
                        $table_name .= 'AS ' . $this->table_quote($match[5]) . ' ';
                    }
                    $table_join[] = $join_array[$match[2]] . ' JOIN ' . $table_name . $relation;
                }
            }
            $table_query .= ' ' . implode($table_join, ' ');
        } else {
            if (is_null($columns)) {
                if (is_null($where)) {
                    if (
                        is_array($join) &&
                        isset($column_fn)
                    ) {
                        $where = $join;
                        $columns = null;
                    } else {
                        $where = null;
                        $columns = $join;
                    }
                } else {
                    $where = $join;
                    $columns = null;
                }
            } else {
                $where = $columns;
                $columns = $join;
            }
        }
        if (isset($column_fn)) {
            if ($column_fn == 1) {
                $column = '1';
                if (is_null($where)) {
                    $where = $columns;
                }
            } else {
                if (empty($columns)) {
                    $columns = '*';
                    $where = $join;
                }
                $column = $column_fn . '(' . $this->column_push($columns) . ')';
            }
        } else {
            $column = $this->column_push($columns);
        }
        return 'SELECT ' . $column . ' FROM ' . $table_query . $this->where_clause($where);
    }

    /**
     * @param $index
     * @param $key
     * @param $value
     * @param $data
     * @param $stack
     */
    protected function data_map($index, $key, $value, $data, &$stack)
    {
        if (is_array($value)) {
            $sub_stack = array();
            foreach ($value as $sub_key => $sub_value) {
                if (is_array($sub_value)) {
                    $current_stack = $stack[$index][$key];
                    $this->data_map(false, $sub_key, $sub_value, $data, $current_stack);
                    $stack[$index][$key][$sub_key] = $current_stack[0][$sub_key];
                } else {
                    $this->data_map(false, preg_replace('/^[\w]*\./i', "", $sub_value), $sub_key, $data, $sub_stack);
                    $stack[$index][$key] = $sub_stack;
                }
            }
        } else {
            if ($index !== false) {
                $stack[$index][$value] = $data[$value];
            } else {
                if (preg_match('/[a-zA-Z0-9_\-\.]*\s*\(([a-zA-Z0-9_\-]*)\)/i', $key, $key_match)) {
                    $key = $key_match[1];
                }
                $stack[$key] = $data[$key];
            }
        }
    }

    /**
     * @param $table
     * @param $join
     * @param null $columns
     * @param null $where
     * @return array|bool
     */
    public function select($table, $join, $columns = null, $where = null)
    {
        $column = $where == null ? $join : $columns;
        $is_single_column = (is_string($column) && $column !== '*');

        $query = $this->query($this->select_context($table, $join, $columns, $where));
        $stack = array();
        $index = 0;
        if (!$query) {
            return false;
        }
        if ($columns === '*') {
            return $query->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($is_single_column) {
            return $query->fetchAll(PDO::FETCH_COLUMN);
        }
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            foreach ($columns as $key => $value) {
                if (is_array($value)) {
                    $this->data_map($index, $key, $value, $row, $stack);
                } else {
                    $this->data_map($index, $key, preg_replace('/^[\w]*\./i', "", $value), $row, $stack);
                }
            }
            $index++;
        }
        return $stack;
    }

    /**
     * @param $table
     * @param $datas
     * @return array|mixed
     */
    public function insert($table, $datas)
    {
        $lastId = array();
        // Check indexed or associative array
        if (!isset($datas[0])) {
            $datas = array($datas);
        }
        foreach ($datas as $data) {
            $values = array();
            $columns = array();
            foreach ($data as $key => $value) {
                $columns[] = preg_replace("/^(\(JSON\)\s*|#)/i", "", $key);
                switch (gettype($value)) {
                    case 'NULL':
                        $values[] = 'NULL';
                        break;
                    case 'array':
                        preg_match("/\(JSON\)\s*([\w]+)/i", $key, $column_match);
                        $values[] = isset($column_match[0]) ?
                            $this->quote(json_encode($value)) :
                            $this->quote(serialize($value));
                        break;
                    case 'boolean':
                        $values[] = ($value ? '1' : '0');
                        break;
                    case 'integer':
                    case 'double':
                    case 'string':
                        $values[] = $this->fn_quote($key, $value);
                        break;
                }
            }
            $this->exec('INSERT INTO ' . $this->table_quote($table) . ' (' . implode(', ',
                    $columns) . ') VALUES (' . implode($values, ', ') . ')');
            $lastId[] = $this->pdo->lastInsertId();
        }
        return count($lastId) > 1 ? $lastId : $lastId[0];
    }

    /**
     * @param $table
     * @param $data
     * @param null $where
     * @return bool|int
     */
    public function update($table, $data, $where = null)
    {
        $fields = array();
        foreach ($data as $key => $value) {
            preg_match('/([\w]+)(\[(\+|\-|\*|\/)\])?/i', $key, $match);
            if (isset($match[3])) {
                if (is_numeric($value)) {
                    $fields[] = $this->column_quote($match[1]) . ' = ' . $this->column_quote($match[1]) . ' ' . $match[3] . ' ' . $value;
                }
            } else {
                $column = $this->column_quote(preg_replace("/^(\(JSON\)\s*|#)/i", "", $key));
                switch (gettype($value)) {
                    case 'NULL':
                        $fields[] = $column . ' = NULL';
                        break;
                    case 'array':
                        preg_match("/\(JSON\)\s*([\w]+)/i", $key, $column_match);
                        $fields[] = $column . ' = ' . $this->quote(
                                isset($column_match[0]) ? json_encode($value) : serialize($value)
                            );
                        break;
                    case 'boolean':
                        $fields[] = $column . ' = ' . ($value ? '1' : '0');
                        break;
                    case 'integer':
                    case 'double':
                    case 'string':
                        $fields[] = $column . ' = ' . $this->fn_quote($key, $value);
                        break;
                }
            }
        }
        return $this->exec('UPDATE ' . $this->table_quote($table) . ' SET ' . implode(', ',
                $fields) . $this->where_clause($where));
    }

    /**
     * @param $table
     * @param $where
     * @return bool|int
     */
    public function delete($table, $where)
    {
        return $this->exec('DELETE FROM ' . $this->table_quote($table) . $this->where_clause($where));
    }

    /**
     * @param $table
     * @param $columns
     * @param null $search
     * @param null $replace
     * @param null $where
     * @return bool|int
     */
    public function replace($table, $columns, $search = null, $replace = null, $where = null)
    {
        if (is_array($columns)) {
            $replace_query = array();
            foreach ($columns as $column => $replacements) {
                foreach ($replacements as $replace_search => $replace_replacement) {
                    $replace_query[] = $column . ' = REPLACE(' . $this->column_quote($column) . ', ' . $this->quote($replace_search) . ', ' . $this->quote($replace_replacement) . ')';
                }
            }
            $replace_query = implode(', ', $replace_query);
            $where = $search;
        } else {
            if (is_array($search)) {
                $replace_query = array();
                foreach ($search as $replace_search => $replace_replacement) {
                    $replace_query[] = $columns . ' = REPLACE(' . $this->column_quote($columns) . ', ' . $this->quote($replace_search) . ', ' . $this->quote($replace_replacement) . ')';
                }
                $replace_query = implode(', ', $replace_query);
                $where = $replace;
            } else {
                $replace_query = $columns . ' = REPLACE(' . $this->column_quote($columns) . ', ' . $this->quote($search) . ', ' . $this->quote($replace) . ')';
            }
        }
        return $this->exec('UPDATE ' . $this->table_quote($table) . ' SET ' . $replace_query . $this->where_clause($where));
    }

    /**
     * @param $table
     * @param null $join
     * @param null $columns
     * @param null $where
     * @return bool|mixed
     */
    public function get($table, $join = null, $columns = null, $where = null)
    {
        $column = $where == null ? $join : $columns;
        $is_single_column = (is_string($column) && $column !== '*');
        $query = $this->query($this->select_context($table, $join, $columns, $where) . ' LIMIT 1');
        if ($query) {
            $data = $query->fetchAll(PDO::FETCH_ASSOC);
            if (isset($data[0])) {
                if ($is_single_column) {
                    return $data[0][preg_replace('/^[\w]*\./i', "", $column)];
                }

                if ($column === '*') {
                    return $data[0];
                }
                $stack = array();
                foreach ($columns as $key => $value) {
                    if (is_array($value)) {
                        $this->data_map(0, $key, $value, $data[0], $stack);
                    } else {
                        $this->data_map(0, $key, preg_replace('/^[\w]*\./i', "", $value), $data[0], $stack);
                    }
                }
                return $stack[0];
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * @param $table
     * @param $join
     * @param null $where
     * @return bool
     */
    public function has($table, $join, $where = null)
    {
        $column = null;
        $query = $this->query('SELECT EXISTS(' . $this->select_context($table, $join, $column, $where, 1) . ')');
        if ($query) {
            return $query->fetchColumn() === '1';
        } else {
            return false;
        }
    }

    /**
     * @param $table
     * @param null $join
     * @param null $column
     * @param null $where
     * @return bool|int
     */
    public function count($table, $join = null, $column = null, $where = null)
    {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'COUNT'));
        return $query ? 0 + $query->fetchColumn() : false;
    }

    /**
     * @param $table
     * @param $join
     * @param null $column
     * @param null $where
     * @return bool|int|string
     */
    public function max($table, $join, $column = null, $where = null)
    {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'MAX'));
        if ($query) {
            $max = $query->fetchColumn();
            return is_numeric($max) ? $max + 0 : $max;
        } else {
            return false;
        }
    }

    /**
     * @param $table
     * @param $join
     * @param null $column
     * @param null $where
     * @return bool|int|string
     */
    public function min($table, $join, $column = null, $where = null)
    {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'MIN'));
        if ($query) {
            $min = $query->fetchColumn();
            return is_numeric($min) ? $min + 0 : $min;
        } else {
            return false;
        }
    }

    /**
     * @param $table
     * @param $join
     * @param null $column
     * @param null $where
     * @return bool|int
     */
    public function avg($table, $join, $column = null, $where = null)
    {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'AVG'));
        return $query ? 0 + $query->fetchColumn() : false;
    }

    /**
     * @param $table
     * @param $join
     * @param null $column
     * @param null $where
     * @return bool|int
     */
    public function sum($table, $join, $column = null, $where = null)
    {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'SUM'));
        return $query ? 0 + $query->fetchColumn() : false;
    }

    /**
     * @param $actions
     * @return bool
     */
    public function action($actions)
    {
        if (is_callable($actions)) {
            $this->pdo->beginTransaction();
            $result = $actions($this);
            if ($result === false) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->commit();
            }
        } else {
            return false;
        }
    }

    /**
     * @return $this
     */
    public function debug()
    {
        $this->debug_mode = true;
        return $this;
    }

    /**
     * @return array
     */
    public function error()
    {
        return $this->pdo->errorInfo();
    }

    /**
     * @return mixed
     */
    public function last_query()
    {
        return end($this->logs);
    }

    /**
     * @return array
     */
    public function log()
    {
        return $this->logs;
    }

    /**
     * @return array
     */
    public function info()
    {
        $output = array(
            'server' => 'SERVER_INFO',
            'driver' => 'DRIVER_NAME',
            'client' => 'CLIENT_VERSION',
            'version' => 'SERVER_VERSION',
            'connection' => 'CONNECTION_STATUS'
        );
        foreach ($output as $key => $value) {
            $output[$key] = $this->pdo->getAttribute(constant('PDO::ATTR_' . $value));
        }
        return $output;
    }

    /**
     * @param $tableName
     * @param string $OrderBy
     * @return $this
     */
    public function findAll($tableName, $OrderBy = '')
    {
        $this->sql = "SELECT * FROM  $tableName $OrderBy";
        return $this;
    }

    /**
     * @param $tableName
     * @param $Condition
     * @param string $OrderBy
     * @return $this
     */
    public function findByCondition($tableName, $Condition, $OrderBy = '')
    {
        $this->sql = "SELECT * FROM $tableName WHERE $Condition $OrderBy";
        return $this;
    }

    /**
     * @param $select
     * @param $tableName
     * @param string $Condition
     * @param string $OrderBy
     * @return $this
     */
    public function select_nowhere($select, $tableName, $Condition = '', $OrderBy = '')
    {
        $this->sql = "SELECT $select FROM  $tableName $Condition $OrderBy";
        return $this;
    }

    /**
     * @param $select
     * @param string $tableName
     * @param string $Condition
     * @param string $OrderBy
     * @return $this
     */
    public function select_noall($select, $tableName = '', $Condition = '', $OrderBy = '')
    {
        $this->sql = "SELECT $select $tableName $Condition $OrderBy";
        return $this;
    }

    /**
     * @param $select
     * @return $this
     */
    public function non_select($select)
    {
        $this->sql = "$select";
        return $this;
    }

    //สำหรับ คิวรี่ค้นหาทั้งหมด

    /**
     * @param $tableName
     * @param $Condition
     * @return $this
     */
    public function alter($tableName, $Condition)
    {
        $this->sql = "ALTER TABLE {$tableName} $Condition";
        return $this;
    }

    //สำหรับ คิวรี่ค้นหาตามเงื่อนไข

    /**
     * @param $Condition
     * @return $this
     */
    public function select_stored($Condition)
    {
        $this->sql = "EXEC {$Condition}";
        return $this;
    }

    //สำหรับ select
    /**
     * @param $select
     * @param $tableName
     * @param $Condition
     * @param string $OrderBy
     * @return $this
     */
//    public function select($select, $tableName, $Condition, $OrderBy = '')
//    {
//        $this->sql = "SELECT $select FROM  $tableName WHERE $Condition $OrderBy";
//        return $this;
//    }

    //สำหรับ select ระบุ where เอง

    /**
     * @return mixed|null
     */
    public function executeFetch()
    {
        $conn = $this->pdo();

        if (!empty($conn)) {
            //$rs = $conn->query($this->sql);
            $rs = $conn->prepare($this->sql);
            $rs->execute();
            if (!empty($rs)) {
                $row = $rs->fetch();
                return $row;
            }
        }
        return null;
    }

    //สำหรับ select ระบุทุกอย่าง เอง

    /**
     * @return int|null
     */
    public function executeNumRow()
    {
        $conn = $this->pdo();

        if (!empty($conn)) {
            //$rs = $conn->query($this->sql);
            $rs = $conn->prepare($this->sql);
            $rs->execute();
            if (!empty($rs)) {
                $row = $rs->rowCount();
                return $row;
            }
        }
        return null;
    }

    //สำหรับระบุทุกอย่าง เอง

    /**
     * @param $query
     * @return null
     */
    public function select_query($query)
    {
        if (!empty($this->pdo)) {
            $this->stmt = $this->pdo->prepare($query);
        }
        return null;
    }

    //สำหรับ insert
    /**
     * @param $tableName
     * @param $fieldName
     * @param $value
     * @return $this
     */
//    public function insert($tableName, $fieldName, $value)
//    {
//        $this->sql = "INSERT INTO {$tableName} ({$fieldName}) VALUES ({$value})";
//        return $this;
//    }

    //สำหรับ update
    /**
     * @param $tableName
     * @param $fieldNameSet
     * @param $Condition
     * @return $this
     */
//    public function update($tableName, $fieldNameSet, $Condition)
//    {
//        $this->sql = "UPDATE {$tableName} SET {$fieldNameSet} WHERE $Condition";
//        return $this;
//    }

    //สำหรับ delete
    /**
     * @param $tableName
     * @param $Condition
     * @return $this
     */
//    public function delete($tableName, $Condition)
//    {
//        $this->sql = "DELETE FROM {$tableName} WHERE $Condition";
//        return $this;
//    }

    //สำหรับ alter

    /**
     * @param $query
     * @return null
     */
    public function insert_query($query)
    {
        $conn = $this->pdo();
        if (!empty($conn)) {
            $this->stmt = $conn->prepare($query);
        }
        return null;
    }

    //สำหรับ stored-procedure

    /**
     * @param $query
     * @return null
     */
    public function update_query($query)
    {
        $conn = $this->pdo();
        if (!empty($conn)) {
            $this->stmt = $conn->prepare($query);
        }
        return null;
    }

    //สำหรับ ประมวลผลทั้งหมด

    /**
     * @param $query
     * @return null
     */
    public function delete_query($query)
    {
        $conn = $this->pdo();
        if (!empty($conn)) {
            $this->stmt = $conn->prepare($query);
        }
        return null;
    }

    //สำหรับ ประมวลผลแสดงแถวเดียว

    /**
     * @param $query
     * @return null
     */
    public function alter_query($query)
    {
        $conn = $this->pdo();
        if (!empty($conn)) {
            $this->stmt = $conn->prepare($query);
        }
        return null;
    }

    //สำหรับ ประมวลผลนับแถว

    /**
     * @param $param
     * @param $value
     * @param null $type
     */
    public function bind($param, $value, $type = null)
    {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
    }

    ///////////////////////////////////// version ใหม่ ///////////////////////////////////////////////////////////////////


    /**
     * @return null|\PDOStatement
     */
    public function execute_all()
    {
        //$rs = $this->stmt->execute();
        //$rs = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
        $rs = $this->execute();
        return $rs;
    }

    /**
     * @return null|\PDOStatement
     */
    public function execute()
    {
        $conn = $this->pdo();

        if (!empty($conn)) {
            $rs = $conn->prepare($this->sql);
            $rs->execute();
            return $rs;
            //return $conn->query($this->sql);
        }
        return null;
    }

    /**
     * @return mixed
     */
    public function execute_single()
    {
        $this->stmt->execute();
        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);
        return $row;

    }

    /**
     * @return mixed
     */
    public function execute_count()
    {
        $row = $this->stmt->rowCount();
        return $row;
    }

    /**
     * @return string
     */
    public function lastInsertId()
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * @return bool
     */
    public function beginTransaction()
    {
        return $this->pdo()->beginTransaction();
    }

    /**
     * @return bool
     */
    public function endTransaction()
    {
        return $this->pdo()->commit();
    }

    /**
     * @return bool
     */
    public function cancelTransaction()
    {
        return $this->pdo()->rollBack();
    }

    /**
     * @return mixed
     */
    public function debugDumpParams()
    {
        return $this->stmt->debugDumpParams();
    }
}
