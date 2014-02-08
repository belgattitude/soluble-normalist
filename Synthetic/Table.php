<?php

namespace Soluble\Normalist\Synthetic;

use Soluble\Normalist\Synthetic\Exception;
use Soluble\Db\Sql\Select;

use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Where;
use Zend\Db\Sql\Predicate;
use Zend\Db\Sql\PreparableSqlInterface;

use ArrayObject;

class Table
{

    /**
     * Table name
     * @var string
     */
    protected $table;

    /**
     * Prefixed table name or table name if no prefix
     * @var string
     */
    protected $prefixed_table;

    /**
     *
     * @var Table\Relation
     */
    protected $relation;

    /**
     * Primary key of the table
     * @var string|integer
     */
    protected $primary_key;

    /**
     * Primary keys of the table in case there's a multiple column pk
     * @var string|integer
     */
    protected $primary_keys;

    /**
     * Table alias useful when using join
     * @var string
     */
    protected $table_alias;

    /**
     * @param TableManager
     */
    protected $tableManager;

    /**
     *
     * @var string
     */
    protected $tablePrefix;

    /**
     *
     * @var Zend\Db\Sql\Sql
     */
    protected $sql;

    /**
     *
     * @var array
     */
    protected $column_information;

    /**
     *
     * @param array|string $table table name
     * @param \Soluble\Normalist\Synthetic\TableManager $tableManager
     *
     * @throws Exception\InvalidArgumentException
     */
    public function __construct($table, TableManager $tableManager)
    {
        $this->tableManager = $tableManager;
        $this->sql = new Sql($tableManager->getDbAdapter());
        if (!is_string($table)) {
            throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": Table name must be a string");
        }

        $this->table = $table;
        $this->prefixed_table = $this->tableManager->getTablePrefix() . $table;
    }

    /**
     *
     * @return \Soluble\Normalist\Synthetic\TableSearch
     * @throws \Exception
     */
    public function search($table_alias = null)
    {
        return new TableSearch($this->select($table_alias), $this->tableManager);
    }

    /**
     * Return all records in the table
     *
     * @return array
     */
    public function all()
    {
        return $this->search()->toArray();
    }

    /**
     * Find a record
     *
     * @param integer|string|array $id
     *
     * @throws Exception\InvalidArgumentException when id is invalid
     * @throws Exception\PrimaryKeyNotFoundException
     *
     * @return Record|false
     */
    public function find($id)
    {
        $record = $this->findOneBy($this->getPrimaryKeyPredicate($id));
        return $record;
    }


    /**
     * Find a record by primary key, throw a NotFoundException if record does not exists
     *
     * @param integer|string|array $id
     *
     * @throws Exception\NotFoundException
     * @throws Exception\InvalidArgumentException when the id is not valid
     * @throws Exception\PrimaryKeyNotFoundException
     *
     * @return Record
     */
    public function findOrFail($id)
    {
        $record = $this->find($id);
        if ($record === false) {
            throw new Exception\NotFoundException(__CLASS__ . '::' . __METHOD__ . ": cannot find record '$id' in table '$this->table'");
        }
        return $record;
    }

    /**
     * Find a record by unique key
     *
     * @param  Where|\Closure|string|array|Predicate\PredicateInterface $predicate
     * @param  string $combination One of the OP_* constants from Predicate\PredicateSet
     *
     * @throws Exception\ColumnNotFoundException when a column in the predicate does not exists
     * @throws Exception\UnexpectedValueException when more than one record match the predicate
     * @throws Exception\InvalidArgumentException when the predicate is not correct / invalid column
     *
     * @return Record|false
     */
    public function findOneBy($predicate, $combination = Predicate\PredicateSet::OP_AND)
    {
        $select = $this->select($this->table);
        $select->where($predicate, $combination);
        try {
            $results = $select->execute()->toArray();
        } catch (\Exception $e) {

            $messages = array();
            $ex = $e;
            do {
                $messages[] = $ex->getMessage();
            } while ($ex = $ex->getPrevious());
            $message = join(', ', array_unique($messages));

            $lmsg = '[' . get_class($e) . '] ' . strtolower($message) . '(code:' . $e->getCode() . ')';

            if (strpos($lmsg, 'column not found') !== false ||
                    strpos($lmsg, 'unknown column') !== false) {
                //"SQLSTATE[42S22]: Column not found: 1054 Unknown column 'media_id' in 'where clause
                $rex = new Exception\ColumnNotFoundException($message);
                throw $rex;
            } else {

                $sql_string = $select->getSqlString($this->sql->getAdapter()->getPlatform());
                $iqex = new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": $message - $sql_string");
                throw $iqex;
            }
        }

        if (count($results) == 0)
            return false;
        if (count($results) > 1)
            throw new Exception\UnexpectedValueException(__CLASS__ . '::' . __METHOD__ . ": return more than one record");

        $record = $this->record($results[0]);
        $record->setState(Record::STATE_CLEAN);
        return $record;


    }

    /**
     * Find a record by unique key and trhow an exception id record cannot be found
     *
     * @param  Where|\Closure|string|array|Predicate\PredicateInterface $predicate
     * @param  string $combination One of the OP_* constants from Predicate\PredicateSet
     *
     * @throws Exception\NotFoundException when the record is not found
     * @throws Exception\ColumnNotFoundException when a column in the predicate does not exists
     * @throws Exception\UnexpectedValueException when more than one record match the predicate
     * @throws Exception\InvalidArgumentException when the predicate is not correct / invalid column
     *
     * @return Record
     */
    public function findOneByOrFail($predicate, $combination = Predicate\PredicateSet::OP_AND)
    {
        $record = $this->findOneBy($predicate, $combination);
        if ($record === false) {
            throw new Exception\NotFoundException(__CLASS__ . '::' . __METHOD__ . ": cannot findOneBy record in table '$this->table'");
        }
        return $record;
    }

    /**
     * Count the number of record in table
     *
     * @return int number of record in table
     */
    public function count()
    {
        return $this->countBy("1=1");
    }

    /**
     * Find a record by unique key
     *
     * @param  Where|\Closure|string|array|Predicate\PredicateInterface $predicate
     * @param  string $combination One of the OP_* constants from Predicate\PredicateSet
     *
     *
     * @return int number of record matching predicates
     */
    public function countBy($predicate, $combination = Predicate\PredicateSet::OP_AND)
    {

        $result = $this->select()
                        ->columns(array('count' => new Expression('count(*)')))
                        ->where($predicate, $combination)
                        ->execute()->toArray();
        $count = $result[0]['count'];
        return (int) $result[0]['count'];
    }

    /**
     * Test if a record exists
     *
     * @param integer|string|array $id
     *
     * @throws Exception\InvalidArgumentException when the id is invalid
     * @throws Exception\PrimaryKeyNotFoundException
     *
     * @return boolean
     */
    public function exists($id)
    {
        $result = $this->select()->where($this->getPrimaryKeyPredicate($id))
                        ->columns(array('count' => new Expression('count(*)')))
                        ->execute()->toArray();

        return ($result[0]['count'] > 0);
    }

    /**
     * Test if a record exists by a predicate
     *
     * @param  Where|\Closure|string|array|Predicate\PredicateInterface $predicate
     * @param  string $combination One of the OP_* constants from Predicate\PredicateSet
     *
     * @throws Exception\InvalidArgumentException when the predicate is not correct
     *
     * @return boolean
     */
    public function existsBy($predicate, $combination = Predicate\PredicateSet::OP_AND)
    {

        try {
            $select = $this->select()->where($predicate, $combination)
                    ->columns(array('count' => new Expression('count(*)')));
            $result = $select->execute()->toArray();
        } catch (\Exception $e) {
            throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": invaid usage ({$e->getMessage()})");
        }

        return ($result[0]['count'] > 0);
    }

    /**
     * Get a Soluble\Db\Select object
     *
     * @param string $table_alias useful when you want to join columns
     * @return Soluble\Db\Sql\Select
     */
    public function select($table_alias = null)
    {
        $prefixed_table = $this->getTableName($this->table);
        $select = new Select();
        $select->setDbAdapter($this->tableManager->getDbAdapter());
        if ($table_alias === null) {
            $table_spec = $this->table;
        } else {
            $table_spec = array($table_alias => $prefixed_table);
        }
        $select->from($table_spec);
        return $select;
    }

    /**
     * Delete a record by primary key value
     *
     * @param integer|string|array|Record $id primary key(s) or a Record object
     *
     * @throws Exception\InvalidArgumentException if $id is not valid
     * @throws Exception\LogicException if Record has already been deleted
     *
     * @return int the number of affected rows (maybe be greater than 1 with trigger or cascade)
     */
    public function delete($id)
    {
        if ($id instanceof Record) {
            $state = $id->getState();
            if ($state == Record::STATE_DELETED) {
                throw new Exception\LogicException("Record has already been deleted in database.");
            }
            if ($state == Record::STATE_NEW) {
                throw new Exception\LogicException("Record has not already been saved in database.");
            }
            $affected_rows = $this->deleteBy($this->getRecordPrimaryKeyPredicate($id));
            $id->setState(Record::STATE_DELETED);
        } else {
            $affected_rows = $this->deleteBy($this->getPrimaryKeyPredicate($id));
        }
        return $affected_rows;
    }

    /**
     * Delete a record by predicate
     *
     * @param  Where|\Closure|string|array|Predicate\PredicateInterface $predicate
     * @param  string $combination One of the OP_* constants from Predicate\PredicateSet
     *
     * @return boolean
     */
    public function deleteBy($predicate, $combination = Predicate\PredicateSet::OP_AND)
    {
        $delete = $this->sql->delete($this->prefixed_table)
                ->where($predicate);

        $statement = $this->sql->prepareStatementForSqlObject($delete);
        $result = $statement->execute();
        $affected = $result->getAffectedRows();
        return $affected;
    }

    /**
     * Delete a record or throw an Exception
     *
     * @param integer|string|array $id primary key value
     *
     * @throws Exception\InvalidArgumentException if $id is not valid
     * @throws Exception\NotFoundException if record does not exists
     *
     * @return Table
     */
    public function deleteOrFail($id)
    {
        $deleted = $this->delete($id);
        if ($deleted == 0) {
            throw new Exception\NotFoundException(__CLASS__ . '::' . __METHOD__ . ": cannot delete record '$id' in table '$this->table'");
        }
        return $this;
    }

    /**
     * Update data into table
     *
     * @param array|ArrayObject $data
     * @param  Where|\Closure|string|array|Predicate\PredicateInterface $predicate
     * @param  string $combination One of the OP_* constants from Predicate\PredicateSet
     *
     * @throws Exception\InvalidArgumentException
     * @throws Exception\ColumnNotFoundException when $data contains columns that does not exists in table
     * @throws Exception\ForeignKeyException when insertion failed because of an invalid foreign key
     * @throws Exception\DuplicateEntryException when insertion failed because of an invalid foreign key
     * @throws Exception\NotNullException when insertion failed because a column cannot be null
     * @throws Exception\RuntimeException when insertion failed for another reason
     *
     * @return int number of affected rows
     */

    public function update($data, $predicate, $combination=Predicate\PredicateSet::OP_AND)
    {
        $prefixed_table = $this->prefixed_table;

        if ($data instanceOf ArrayObject) {
            $d = (array) $data;
        } elseif (is_array($data)) {
            $d = $data;
        } else {
            throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": requires data to be array or an ArrayObject");
        }

        $this->checkDataColumns($d);


        $update = $this->sql->update($prefixed_table);
        $update->set($d);
        $update->where($predicate);

        $result = $this->executeStatement($update);

        $affectedRows =  $result->getAffectedRows();
        return $affectedRows;

    }

    /**
     * Insert data into table
     *
     * @param array|ArrayObject $data
     * @param boolean $validate_datatype ensure data are compatible with database columns datatypes
     *
     * @throws Exception\InvalidArgumentException when data is not an array or an ArrayObject
     * @throws Exception\ColumnNotFoundException when $data contains columns that does not exists in table
     * @throws Exception\ForeignKeyException when insertion failed because of an invalid foreign key
     * @throws Exception\DuplicateEntryException when insertion failed because of an invalid foreign key
     * @throws Exception\NotNullException when insertion failed because a column cannot be null
     * @throws Exception\RuntimeException when insertion failed for another reason
     *
     * @return \Soluble\Normalist\Synthetic\Record
     */
    public function insert($data, $validate_datatype=false)
    {
        $prefixed_table = $this->prefixed_table;

        if ($data instanceof \ArrayObject) {
            $d = (array) $data;
        } elseif (is_array($data)) {
            $d = $data;
        } else {
            $type = gettype($data);
            throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": expects data to be array or ArrayObject. Type receive '$type'");
        }

        $this->checkDataColumns($d);

        if ($validate_datatype) {
            // here put the code to check datatypes
        }


        $insert = $this->sql->insert($prefixed_table);
        $insert->values($d);

        $this->executeStatement($insert);

        $pks = $this->getPrimaryKeys($table_name);
        $nb_pks = count($pks);
        if ($nb_pks > 1) {
            // In multiple keys there should not be autoincrement value
            $id = array();
            foreach ($pks as $pk) {
                $id[$pk] = $d[$pk];
            }
        } elseif (array_key_exists($pks[0], $d)) {
            // not using autogenerated value
            //$id = $d[$this->getPrimaryKey()];
            $id = $d[$pks[0]];
        } else {
            $id = $this->tableManager->getDbAdapter()->getDriver()->getLastGeneratedValue();
        }
        $record = $this->findOrFail($id);
        return $record;
    }



    /**
     * Insert on duplicate key
     *
     * @param array|ArrayObject $data
     * @param array|null $duplicate_exclude
     * @param boolean $validate_datatype ensure data are compatible with database columns datatypes
     *
     * @throws Exception\ColumnNotFoundException
     * @throws Exception\ForeignKeyException when insertion failed because of an invalid foreign key
     * @throws Exception\DuplicateEntryException when insertion failed because of an invalid foreign key
     * @throws Exception\NotNullException when insertion failed because a column cannot be null
     *
     * @return Record
     */
    public function insertOnDuplicateKey($data, array $duplicate_exclude = array(), $validate_datatypes=false)
    {
        $platform = $this->tableManager->getDbAdapter()->platform;

        $primary = $this->getPrimaryKey();

        if ($data instanceOf ArrayObject) {
            $d = (array) $data;
        } else {
            $d = $data;
        }

        $this->checkDataColumns($d);
        $this->checkDataColumns(array_fill_keys($duplicate_exclude, null));

        if ($validate_datatypes) {
            $this->validateDatatypes($data);
        }

        $insert = $this->sql->insert($this->prefixed_table);
        $insert->values($d);

        $sql_string = $this->sql->getSqlStringForSqlObject($insert);
        $extras = array();
        $excluded_columns = array_merge($duplicate_exclude, array($primary));

        foreach ($d as $column => $value) {

            if (!in_array($column, $excluded_columns)) {
                $v = ($value === null) ? 'NULL' : $v = $platform->quoteValue($value);
                $extras[] = $platform->quoteIdentifier($column) . ' = ' . $v;
            }
        }
        $sql_string .= ' on duplicate key update ' . join(',', $extras);

        try {

            $this->executeStatement($sql_string);

        } catch (\Exception $e) {

            $messages = array();
            $ex = $e;
            do {
                $messages[] = $ex->getMessage();
            } while ($ex = $ex->getPrevious());
            $msg = join(', ', array_unique($messages));
            $message = __CLASS__ . '::' . __METHOD__ . ": failed, $msg [ $sql_string ]";
            throw new Exception\RuntimeException($message);
        }

        if (array_key_exists($primary, $d)) {
            // not using autogenerated value
            $pk_value = $d[$primary];
            $record = $this->find($pk_value);
        } else {

            $id = $this->tableManager->getDbAdapter()->getDriver()->getLastGeneratedValue();

            // This test is not made with id !== null, understand why before changing
            if ($id > 0) {
                $pk_value = $id;
                $record = $this->find($pk_value);
            } else {
                // if the id was not generated, we have to guess on which key
                // the duplicate has been fired
                $unique_keys = $this->tableManager->metadata()->getUniqueKeys($this->prefixed_table);
                $data_columns = array_keys($d);
                $found = false;
                // Uniques keys could be
                // array(
                //      'unique_idx' => array('categ', 'legacy_mapping'),
                //      'unique_idx_2' => array('test', 'test2')
                //      )
                //


                foreach ($unique_keys as $index_name => $unique_columns) {
                    //echo "On duplicate key\n\n $index_name \n";
                    $intersect = array_intersect($data_columns, $unique_columns);
                    if (count($intersect) == count($unique_columns)) {
                        // Try to see if we can find a record with the key
                        $conditions = array();
                        foreach ($intersect as $key) {
                            $conditions[$key] = $d[$key];
                        }
                        $record = $this->findOneBy($conditions);
                        if ($record) {
                            $found = true;
                            $pk_value = $record[$primary];
                            break;
                        }
                    }
                }

                // I cannot write a test case for that
                // It should never happen but in case :
                //@codeCoverageIgnoreStart
                if (!$found) {
                    throw new \Exception(__CLASS__ . '::' . __METHOD__ . ": after probing all unique keys in table '{$this->table}', cannot dertermine which one was fired when using on duplicate key.");
                }
                //@codeCoverageIgnoreEnd
            }
        }
        return $record;
    }


    /**
     * Return table relations
     *
     * @return array
     */
    public function getRelations()
    {
        return $this->tableManager->metadata()->getRelations($this->prefixed_table);
    }

    /**
     * Save a record in database
     *
     * @throws Exception\LogicException when record has already been deleted
     *
     * @param Record $record
     * @param boolean $validate_datatype if true will ensure record data correspond to column datatype
     *
     * @return Record freshly modified record (from database)
     */
    public function save(Record $record, $validate_datatype=true)
    {

        $state = $record->getState();
        if ($state == Record::STATE_DELETED) {
            throw new Exception\LogicException("Record has already been deleted in database.");
        }

        $data = $record->toArray();
        if ($validate_datatype) {
            $this->validateDatatypes($data);
        }

        if ($state == Record::STATE_NEW) {
            // Means insert
            $new_record = $this->insert($data);

        } elseif ($state == Record::STATE_CLEAN || $state == Record::STATE_DIRTY) {
            // Means update
            $predicate = $this->getRecordPrimaryKeyPredicate($record);
            $this->update($data, $predicate);
            $new_record = $this->findOneBy($predicate);
        } else {
             //@codeCoverageIgnoreStart

            throw new Exception\LogicException(__CLASS__ . '::' . __METHOD . ": Record is not on manageable state.");
             //@codeCoverageIgnoreEnd
        }
        $record->setData($new_record->toArray());
        unset($new_record);
        $record->setState(Record::STATE_CLEAN);
        return $record;
    }

    /**
     * Return a record object for this table
     * If $data is specified, the record will be filled with the
     * data present in the associative array
     *
     *
     * If $throwException is true, if any non existing column is found
     * an error will be thrown
     *
     * @throws Exception\ColumnNotFoundException if $ignore_invalid_columns is false and some columns does not exists in table
     *
     * @param array|ArrayObject $data associative array containing initial data
     * @param boolean $ignore_invalid_column if true will throw an exception if a column does not exists
     * @return Record
     */
    public function record($data = array(), $ignore_invalid_columns=true)
    {
        if (!$ignore_invalid_columns) {
            $this->checkDataColumns($data);
        }
        $record = new Record($data);
        $record->setState(Record::STATE_NEW);
        return $record;
    }


    /**
     * Return table relation reader
     *
     * @return Table\Relation
     */
    public function relation()
    {
        if ($this->relation === null) {
            $this->relation = new Table\Relation($this);
        }
        return $this->relation;
    }

    /**
     * Return table primary keys
     * @return array
     */
    public function getPrimaryKeys()
    {
        if (!$this->primary_keys) {
            $this->primary_keys = $this->tableManager->metadata()->getPrimaryKeys($this->prefixed_table);
        }
        return $this->primary_keys;
    }

    /**
     * Return primary key, if multiple primary keys found will
     * throw an exception
     * @throws Exception
     * @return int|string
     */
    public function getPrimaryKey()
    {
        if (!$this->primary_key) {
            $this->primary_key = $this->tableManager->metadata()->getPrimaryKey($this->prefixed_table);
        }
        return $this->primary_key;
    }

    /**
     * Return list of table columns
     * @return array
     */
    public function getColumnsInformation()
    {
        if ($this->column_information === null) {
            $this->column_information = $this->tableManager
                    ->metadata()
                    ->getColumnsInformation($this->prefixed_table);
        }
        return $this->column_information;
    }

    /**
     * Return the original table name
     *
     * @return string
     */
    public function getTableName()
    {
        return $this->table;
    }

    /**
     * Return the prefixed table
     *
     * @return string
     */
    public function getPrefixedTableName()
    {
        return $this->prefixed_table;
    }


    /**
     * Return underlying table manager
     *
     * @return TableManager
     */
    public function getTableManager()
    {
       return $this->tableManager;
    }

    /**
     * Execute a statement
     *
     * @todo move to driver if not will only support MySQL
     *
     * @throws Exception\ForeignKeyException when insertion failed because of an invalid foreign key
     * @throws Exception\DuplicateEntryException when insertion failed because of an invalid foreign key
     * @throws Exception\NotNullException when insertion failed because a column cannot be null
     * @throws Exception\RuntimeException when insertion failed for another reason
     *
     * @param string|PreparableSqlInterface $sqlObject
     * @return Zend\Db\Adapter\Driver\ResultInterface
     */
    protected function executeStatement($sqlObject)
    {
        if ($sqlObject instanceof PreparableSqlInterface) {
            $statement = $this->sql->prepareStatementForSqlObject($sqlObject);
        } elseif (is_string($sqlObject)) {
            $statement = $this->tableManager->getDbAdapter()->createStatement($sqlObject);
        } else {
             //@codeCoverageIgnoreStart
            throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ': expects sqlObject to be string or PreparableInterface');
             //@codeCoverageIgnoreEnd
        }
        try {
            $result = $statement->execute();
        } catch (\Exception $e) {

            // In ZF2, PDO_Mysql and MySQLi return different exception,
            // attempt to normalize by catching one exception instead
            // of RuntimeException and InvalidQueryException

            $messages = array();
            $ex = $e;
            do {
                $messages[] = $ex->getMessage();
            } while ($ex = $ex->getPrevious());
            $message = join(', ', array_unique($messages));

            $lmsg = __CLASS_ . '::' . __METHOD__ . ':' . strtolower($message) . '(code:' . $e->getCode() . ')';

            if (strpos($lmsg, 'cannot be null') !== false) {
                // Integrity constraint violation: 1048 Column 'non_null_column' cannot be null
                $rex = new Exception\NotNullException($message, $e->getCode(), $e);
                throw $rex;
            } elseif (strpos($lmsg, 'duplicate entry') !== false) {
                $rex = new Exception\DuplicateEntryException($message, $e->getCode(), $e);
                throw $rex;
            } elseif (strpos($lmsg, 'constraint violation') !== false ||
                    strpos($lmsg, 'foreign key') !== false) {
                $rex = new Exception\ForeignKeyException($message, $e->getCode(), $e);
                throw $rex;
            } else {
                if ($sqlObject instanceof PreparableSqlInterface) {
                    $sql_string = $sqlObject->getSqlString($this->sql->getAdapter()->getPlatform());
                } else {
                    $sql_string = $sqlObject;
                }
                $iqex = new Exception\RuntimeException($message . "[$sql_string]", $e->getCode(), $e);
                throw $iqex;
            }
        }
        return $result;
    }

    /**
     * Return primary key predicate
     *
     * @param integer|string|array $id
     *
     * @throws Exception\InvalidArgumentException
     * @throws Exception\PrimaryKeyNotFoundException
     * @return array predicate
     */
    protected function getPrimaryKeyPredicate($id)
    {
        try {
            $keys = $this->getPrimaryKeys();
        } catch (\Soluble\Db\Metadata\Exception\NoPrimaryKeyException $e) {
            throw new Exception\PrimaryKeyNotFoundException(__CLASS__ . '::' . __METHOD__ . ": cannot find any primary key (single or multiple) on table '{$this->table}'.");
        }
        if (count($keys) == 1) {
            $pk = $keys[0];
            if (!is_scalar($id)) {
                $type = gettype($id);
                throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": invalid primary key value. Table '{$this->table}' has a single primary key '$pk'. Argument must be scalar, '$type' given");
            }
            $predicate = array($pk => $id);
        } else {
            if (!is_array($id)) {
                $pks = join(',', $keys);
                $type = gettype($id);
                throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": invalid primary key value. Table '{$this->table}' has multiple primary keys '$pks'. Argument must be an array, '$type' given");
            }

            $matched_keys = array_diff($id, $keys);
            if (count($matched_keys) == count($keys)) {
                $predicate = $matched_keys;
            } else {
                $pks = join(',', $keys);
                $vals = join(',', $matched_keys);
                $type = gettype($id);
                throw new Exception\InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . ": incomplete primary key value. Table '{$this->table}' has multiple primary keys '$pks', values received '$vals'");
            }
        }
        return $predicate;
    }

    /**
     * Return primary key predicate on record
     *
     * @param Record $record
     *
     * @throws Exception\InvalidArgumentException
     * @throws Exception\PrimaryKeyNotFoundException
     * @return array predicate
     */
    protected function getRecordPrimaryKeyPredicate($record)
    {
        // Get table primary keys
        $primary_keys = $this->getPrimaryKeys();
        if (count($primary_keys) > 1) {
            $predicate = array();
            foreach($primary_keys as $column) {
                $pk_value = $record[$column];
                if ($pk_value !== null) {
                    $predicate[$column] = $pk_value;
                }
            }
        } else {
            if ($record->offsetExists($primary_keys[0])) {
                $predicate = $record->offsetGet($primary_keys[0]);
            }
        }

        return $this->getPrimaryKeyPredicate($predicate);
    }

    /**
     * Check if all columns exists in table
     *
     * @param array $data
     * @throws Exception\ColumnNotFoundException
     */
    protected function checkDataColumns(array $data)
    {
        $diff = array_diff_key($data, $this->getColumnsInformation());
        if (count($diff) > 0) {
            $msg = join(',', array_keys($diff));
            throw new Exception\ColumnNotFoundException(__CLASS__ . '::' . __METHOD__ . ": some specified columns '$msg' does not exists in table {$this->table}.");
        }
    }

    /**
     * Validate data with database column datatype
     *
     * @param array $data
     */
    protected function validateDatatypes(array $data)
    {


    }



}
