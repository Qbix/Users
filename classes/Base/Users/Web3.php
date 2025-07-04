<?php

/**
 * Autogenerated base class representing web3 rows
 * in the Users database.
 *
 * Don't change this file, since it can be overwritten.
 * Instead, change the Users_Web3.php file.
 *
 * @module Users
 */
/**
 * Base class representing 'Web3' rows in the 'Users' database
 * @class Base_Users_Web3
 * @extends Db_Row
 *
 * @param {array} [$fields=array()] The fields values to initialize table row as 
 * an associative array of $column => $value pairs
 * @param {string} [$fields.chainId] defaults to ""
 * @param {string} [$fields.contract] defaults to ""
 * @param {string} [$fields.methodName] defaults to ""
 * @param {string} [$fields.params] defaults to ""
 * @param {string} [$fields.fromAddress] defaults to ""
 * @param {string} [$fields.result] defaults to null
 * @param {string|Db_Expression} [$fields.insertedTime] defaults to new Db_Expression("CURRENT_TIMESTAMP")
 * @param {string|Db_Expression} [$fields.updatedTime] defaults to null
 */
abstract class Base_Users_Web3 extends Db_Row
{
	/**
	 * @property $chainId
	 * @type string
	 * @default ""
	 * 
	 */
	/**
	 * @property $contract
	 * @type string
	 * @default ""
	 * 
	 */
	/**
	 * @property $methodName
	 * @type string
	 * @default ""
	 * 
	 */
	/**
	 * @property $params
	 * @type string
	 * @default ""
	 * 
	 */
	/**
	 * @property $fromAddress
	 * @type string
	 * @default ""
	 * 
	 */
	/**
	 * @property $result
	 * @type string
	 * @default null
	 * 
	 */
	/**
	 * @property $insertedTime
	 * @type string|Db_Expression
	 * @default new Db_Expression("CURRENT_TIMESTAMP")
	 * 
	 */
	/**
	 * @property $updatedTime
	 * @type string|Db_Expression
	 * @default null
	 * 
	 */
	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		$this->setDb(self::db());
		$this->setTable(self::table());
		$this->setPrimaryKey(
			array (
			  0 => 'chainId',
			  1 => 'contract',
			  2 => 'methodName',
			  3 => 'params',
			  4 => 'fromAddress',
			)
		);
	}

	/**
	 * Connects to database
	 * @method db
	 * @static
	 * @return {Db_Interface} The database object
	 */
	static function db()
	{
		return Db::connect('Users');
	}

	/**
	 * Retrieve the table name to use in SQL statement
	 * @method table
	 * @static
	 * @param {boolean} [$with_db_name=true] Indicates wheather table name should contain the database name
	 * @param {string} [$alias=null] You can optionally provide an alias for the table to be used in queries
 	 * @return {string|Db_Expression} The table name as string optionally without database name if no table sharding
	 * was started or Db_Expression class with prefix and database name templates is table was sharded
	 */
	static function table($with_db_name = true, $alias = null)
	{
		if (class_exists('Q_Config') and Q_Config::get('Db', 'connections', 'Users', 'indexes', 'Web3', false)) {
			return new Db_Expression(($with_db_name ? '{{dbname}}.' : '').'{{prefix}}'.'web3');
		} else {
			$conn = Db::getConnection('Users');
  			$prefix = empty($conn['prefix']) ? '' : $conn['prefix'];
  			$table_name = $prefix . 'web3';
  			if (!$with_db_name)
  				return $table_name;
  			$db = Db::connect('Users');
			$alias = isset($alias) ? ' '.$alias : '';
  			return $db->dbName().'.'.$table_name.$alias;
		}
	}
	/**
	 * The connection name for the class
	 * @method connectionName
	 * @static
	 * @return {string} The name of the connection
	 */
	static function connectionName()
	{
		return 'Users';
	}

	/**
	 * Create SELECT query to the class table
	 * @method select
	 * @static
	 * @param {string|array} [$fields=null] The fields as strings, or array of alias=>field.
	 *   The default is to return all fields of the table.
	 * @param {string} [$alias=null] Table alias.
	 * @return {Db_Query_Mysql} The generated query
	 */
	static function select($fields=null, $alias = null)
	{
		if (!isset($fields)) {
			$fieldNames = array();
			$a = isset($alias) ? $alias.'.' : '';
			foreach (self::fieldNames() as $fn) {
				$fieldNames[] = $a .  $fn;
			}
			$fields = implode(',', $fieldNames);
		}
		$alias = isset($alias) ? ' '.$alias : '';
		$q = self::db()->select($fields, self::table(true, $alias));
		$q->className = 'Users_Web3';
		return $q;
	}

	/**
	 * Create UPDATE query to the class table
	 * @method update
	 * @static
	 * @param {string} [$alias=null] Table alias
	 * @return {Db_Query_Mysql} The generated query
	 */
	static function update($alias = null)
	{
		$alias = isset($alias) ? ' '.$alias : '';
		$q = self::db()->update(self::table(true, $alias));
		$q->className = 'Users_Web3';
		return $q;
	}

	/**
	 * Create DELETE query to the class table
	 * @method delete
	 * @static
	 * @param {string} [$table_using=null] If set, adds a USING clause with this table
	 * @param {string} [$alias=null] Table alias
	 * @return {Db_Query_Mysql} The generated query
	 */
	static function delete($table_using = null, $alias = null)
	{
		$alias = isset($alias) ? ' '.$alias : '';
		$q = self::db()->delete(self::table(true, $alias), $table_using);
		$q->className = 'Users_Web3';
		return $q;
	}

	/**
	 * Create INSERT query to the class table
	 * @method insert
	 * @static
	 * @param {array} [$fields=array()] The fields as an associative array of column => value pairs
	 * @param {string} [$alias=null] Table alias
	 * @return {Db_Query_Mysql} The generated query
	 */
	static function insert($fields = array(), $alias = null)
	{
		$alias = isset($alias) ? ' '.$alias : '';
		$q = self::db()->insert(self::table(true, $alias), $fields);
		$q->className = 'Users_Web3';
		return $q;
	}
	
	/**
	 * Inserts multiple rows into a single table, preparing the statement only once,
	 * and executes all the queries.
	 * @method insertManyAndExecute
	 * @static
	 * @param {array} [$rows=array()] The array of rows to insert. 
	 * (The field names for the prepared statement are taken from the first row.)
	 * You cannot use Db_Expression objects here, because the function binds all parameters with PDO.
	 * @param {array} [$options=array()]
	 *   An associative array of options, including:
	 *
	 * * "chunkSize" {integer} The number of rows to insert at a time. defaults to 20.<br>
	 * * "onDuplicateKeyUpdate" {array} You can put an array of fieldname => value pairs here,
	 * 		which will add an ON DUPLICATE KEY UPDATE clause to the query.
	 *
	 */
	static function insertManyAndExecute($rows = array(), $options = array())
	{
		self::db()->insertManyAndExecute(
			self::table(), $rows,
			array_merge($options, array('className' => 'Users_Web3'))
		);
	}
	
	/**
	 * Create raw query with begin clause
	 * You'll have to specify shards yourself when calling execute().
	 * @method begin
	 * @static
	 * @param {string} [$lockType=null] First parameter to pass to query->begin() function
	 * @param {string} [$transactionKey=null] Pass a transactionKey here to "resolve" a previously
	 *  executed that began a transaction with ->begin(). This is to guard against forgetting
	 *  to "resolve" a begin() query with a corresponding commit() or rollback() query
	 *  from code that knows about this transactionKey. Passing a transactionKey that doesn't
	 *  match the latest one on the transaction "stack" also generates an error.
	 *  Passing "*" here matches any transaction key that may have been on the top of the stack.
	 * @return {Db_Query_Mysql} The generated query
	 */
	static function begin($lockType = null, $transactionKey = null)
	{
		$q = self::db()->rawQuery('')->begin($lockType, $transactionKey);
		$q->className = 'Users_Web3';
		return $q;
	}
	
	/**
	 * Create raw query with commit clause
	 * You'll have to specify shards yourself when calling execute().
	 * @method commit
	 * @static
	 * @param {string} [$transactionKey=null] Pass a transactionKey here to "resolve" a previously
	 *  executed that began a transaction with ->begin(). This is to guard against forgetting
	 *  to "resolve" a begin() query with a corresponding commit() or rollback() query
	 *  from code that knows about this transactionKey. Passing a transactionKey that doesn't
	 *  match the latest one on the transaction "stack" also generates an error.
	 *  Passing "*" here matches any transaction key that may have been on the top of the stack.
	 * @return {Db_Query_Mysql} The generated query
	 */
	static function commit($transactionKey = null)
	{
		$q = self::db()->rawQuery('')->commit($transactionKey);
		$q->className = 'Users_Web3';
		return $q;
	}
	
	/**
	 * Create raw query with rollback clause
	 * @method rollback
	 * @static
	 * @param {array} $criteria Can be used to target the rollback to some shards.
	 *  Otherwise you'll have to specify shards yourself when calling execute().
	 * @return {Db_Query_Mysql} The generated query
	 */
	static function rollback()
	{
		$q = self::db()->rawQuery('')->rollback();
		$q->className = 'Users_Web3';
		return $q;
	}
	
	/**
	 * Method is called before setting the field and verifies if value is string of length within acceptable limit.
	 * Optionally accept numeric value which is converted to string
	 * @method beforeSet_chainId
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value is not string or is exceedingly long
	 */
	function beforeSet_chainId($value)
	{
		if (!isset($value)) {
			$value='';
		}
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('chainId', $value);
		}
		if (!is_string($value) and !is_numeric($value))
			throw new Exception('Must pass a string to '.$this->getTable().".chainId");
		if (strlen($value) > 10)
			throw new Exception('Exceedingly long value being assigned to '.$this->getTable().".chainId");
		return array('chainId', $value);			
	}

	/**
	 * Returns the maximum string length that can be assigned to the chainId field
	 * @return {integer}
	 */
	function maxSize_chainId()
	{

		return 10;			
	}

	/**
	 * Returns schema information for chainId column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_chainId()
	{

return array (
  0 => 
  array (
    0 => 'varchar',
    1 => '10',
    2 => '',
    3 => false,
  ),
  1 => false,
  2 => 'PRI',
  3 => NULL,
);			
	}

	/**
	 * Method is called before setting the field and verifies if value is string of length within acceptable limit.
	 * Optionally accept numeric value which is converted to string
	 * @method beforeSet_contract
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value is not string or is exceedingly long
	 */
	function beforeSet_contract($value)
	{
		if (!isset($value)) {
			$value='';
		}
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('contract', $value);
		}
		if (!is_string($value) and !is_numeric($value))
			throw new Exception('Must pass a string to '.$this->getTable().".contract");
		if (strlen($value) > 42)
			throw new Exception('Exceedingly long value being assigned to '.$this->getTable().".contract");
		return array('contract', $value);			
	}

	/**
	 * Returns the maximum string length that can be assigned to the contract field
	 * @return {integer}
	 */
	function maxSize_contract()
	{

		return 42;			
	}

	/**
	 * Returns schema information for contract column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_contract()
	{

return array (
  0 => 
  array (
    0 => 'varchar',
    1 => '42',
    2 => '',
    3 => false,
  ),
  1 => false,
  2 => 'PRI',
  3 => NULL,
);			
	}

	/**
	 * Method is called before setting the field and verifies if value is string of length within acceptable limit.
	 * Optionally accept numeric value which is converted to string
	 * @method beforeSet_methodName
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value is not string or is exceedingly long
	 */
	function beforeSet_methodName($value)
	{
		if (!isset($value)) {
			$value='';
		}
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('methodName', $value);
		}
		if (!is_string($value) and !is_numeric($value))
			throw new Exception('Must pass a string to '.$this->getTable().".methodName");
		if (strlen($value) > 63)
			throw new Exception('Exceedingly long value being assigned to '.$this->getTable().".methodName");
		return array('methodName', $value);			
	}

	/**
	 * Returns the maximum string length that can be assigned to the methodName field
	 * @return {integer}
	 */
	function maxSize_methodName()
	{

		return 63;			
	}

	/**
	 * Returns schema information for methodName column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_methodName()
	{

return array (
  0 => 
  array (
    0 => 'varchar',
    1 => '63',
    2 => '',
    3 => false,
  ),
  1 => false,
  2 => 'PRI',
  3 => '',
);			
	}

	/**
	 * Method is called before setting the field and verifies if value is string of length within acceptable limit.
	 * Optionally accept numeric value which is converted to string
	 * @method beforeSet_params
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value is not string or is exceedingly long
	 */
	function beforeSet_params($value)
	{
		if (!isset($value)) {
			$value='';
		}
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('params', $value);
		}
		if (!is_string($value) and !is_numeric($value))
			throw new Exception('Must pass a string to '.$this->getTable().".params");
		if (strlen($value) > 1023)
			throw new Exception('Exceedingly long value being assigned to '.$this->getTable().".params");
		return array('params', $value);			
	}

	/**
	 * Returns the maximum string length that can be assigned to the params field
	 * @return {integer}
	 */
	function maxSize_params()
	{

		return 1023;			
	}

	/**
	 * Returns schema information for params column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_params()
	{

return array (
  0 => 
  array (
    0 => 'varchar',
    1 => '1023',
    2 => '',
    3 => false,
  ),
  1 => false,
  2 => 'PRI',
  3 => '',
);			
	}

	/**
	 * Method is called before setting the field and verifies if value is string of length within acceptable limit.
	 * Optionally accept numeric value which is converted to string
	 * @method beforeSet_fromAddress
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value is not string or is exceedingly long
	 */
	function beforeSet_fromAddress($value)
	{
		if (!isset($value)) {
			$value='';
		}
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('fromAddress', $value);
		}
		if (!is_string($value) and !is_numeric($value))
			throw new Exception('Must pass a string to '.$this->getTable().".fromAddress");
		if (strlen($value) > 42)
			throw new Exception('Exceedingly long value being assigned to '.$this->getTable().".fromAddress");
		return array('fromAddress', $value);			
	}

	/**
	 * Returns the maximum string length that can be assigned to the fromAddress field
	 * @return {integer}
	 */
	function maxSize_fromAddress()
	{

		return 42;			
	}

	/**
	 * Returns schema information for fromAddress column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_fromAddress()
	{

return array (
  0 => 
  array (
    0 => 'varchar',
    1 => '42',
    2 => '',
    3 => false,
  ),
  1 => false,
  2 => 'PRI',
  3 => '',
);			
	}

	/**
	 * Method is called before setting the field and verifies if value is string of length within acceptable limit.
	 * Optionally accept numeric value which is converted to string
	 * @method beforeSet_result
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value is not string or is exceedingly long
	 */
	function beforeSet_result($value)
	{
		if (!isset($value)) {
			return array('result', $value);
		}
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('result', $value);
		}
		if (!is_string($value) and !is_numeric($value))
			throw new Exception('Must pass a string to '.$this->getTable().".result");
		if (strlen($value) > 65535)
			throw new Exception('Exceedingly long value being assigned to '.$this->getTable().".result");
		return array('result', $value);			
	}

	/**
	 * Returns the maximum string length that can be assigned to the result field
	 * @return {integer}
	 */
	function maxSize_result()
	{

		return 65535;			
	}

	/**
	 * Returns schema information for result column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_result()
	{

return array (
  0 => 
  array (
    0 => 'text',
    1 => 65535,
    2 => NULL,
    3 => NULL,
  ),
  1 => true,
  2 => '',
  3 => NULL,
);			
	}

	/**
	 * Method is called before setting the field and normalize the DateTime string
	 * @method beforeSet_insertedTime
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value does not represent valid DateTime
	 */
	function beforeSet_insertedTime($value)
	{
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('insertedTime', $value);
		}
		if ($value instanceof DateTime) {
			$value = $value->getTimestamp();
		}
		if (is_numeric($value)) {
			$newDateTime = new DateTime();
			$datetime = $newDateTime->setTimestamp($value);
		} else {
			$datetime = new DateTime($value);
		}
		$value = $datetime->format("Y-m-d H:i:s");
		return array('insertedTime', $value);			
	}

	/**
	 * Returns schema information for insertedTime column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_insertedTime()
	{

return array (
  0 => 
  array (
    0 => 'timestamp',
    1 => NULL,
    2 => NULL,
    3 => NULL,
  ),
  1 => false,
  2 => '',
  3 => 'CURRENT_TIMESTAMP',
);			
	}

	/**
	 * Method is called before setting the field and normalize the DateTime string
	 * @method beforeSet_updatedTime
	 * @param {string} $value
	 * @return {array} An array of field name and value
	 * @throws {Exception} An exception is thrown if $value does not represent valid DateTime
	 */
	function beforeSet_updatedTime($value)
	{
		if (!isset($value)) {
			return array('updatedTime', $value);
		}
		if ($value instanceof Db_Expression
               or $value instanceof Db_Range) {
			return array('updatedTime', $value);
		}
		if ($value instanceof DateTime) {
			$value = $value->getTimestamp();
		}
		if (is_numeric($value)) {
			$newDateTime = new DateTime();
			$datetime = $newDateTime->setTimestamp($value);
		} else {
			$datetime = new DateTime($value);
		}
		$value = $datetime->format("Y-m-d H:i:s");
		return array('updatedTime', $value);			
	}

	/**
	 * Returns schema information for updatedTime column
	 * @return {array} [[typeName, displayRange, modifiers, unsigned], isNull, key, default]
	 */
	static function column_updatedTime()
	{

return array (
  0 => 
  array (
    0 => 'timestamp',
    1 => NULL,
    2 => NULL,
    3 => NULL,
  ),
  1 => true,
  2 => '',
  3 => NULL,
);			
	}

	/**
	 * Check if mandatory fields are set and updates 'magic fields' with appropriate values
	 * @method beforeSave
	 * @param {array} $value The array of fields
	 * @return {array}
	 * @throws {Exception} If mandatory field is not set
	 */
	function beforeSave($value)
	{
		if (!$this->retrieved) {
			$table = $this->getTable();
			foreach (array('chainId','contract') as $name) {
				if (!isset($value[$name])) {
					throw new Exception("the field $table.$name needs a value, because it is NOT NULL, not auto_increment, and lacks a default value.");
				}
			}
		}						
		// convention: we'll have updatedTime = insertedTime if just created.
		$this->updatedTime = $value['updatedTime'] = new Db_Expression('CURRENT_TIMESTAMP');
		if (!isset($this->fields["chainId"]) and !isset($value["chainId"])) {
			$this->chainId = $value["chainId"] = "";
		}
		if (!isset($this->fields["contract"]) and !isset($value["contract"])) {
			$this->contract = $value["contract"] = "";
		}
		return $value;			
	}

	/**
	 * Retrieves field names for class table
	 * @method fieldNames
	 * @static
	 * @param {string} [$table_alias=null] If set, the alieas is added to each field
	 * @param {string} [$field_alias_prefix=null] If set, the method returns associative array of ('prefixed field' => 'field') pairs
	 * @return {array} An array of field names
	 */
	static function fieldNames($table_alias = null, $field_alias_prefix = null)
	{
		$field_names = array('chainId', 'contract', 'methodName', 'params', 'fromAddress', 'result', 'insertedTime', 'updatedTime');
		$result = $field_names;
		if (!empty($table_alias)) {
			$temp = array();
			foreach ($result as $field_name)
				$temp[] = $table_alias . '.' . $field_name;
			$result = $temp;
		} 
		if (!empty($field_alias_prefix)) {
			$temp = array();
			reset($field_names);
			foreach ($result as $field_name) {
				$temp[$field_alias_prefix . current($field_names)] = $field_name;
				next($field_names);
			}
			$result = $temp;
		}
		return $result;			
	}
};