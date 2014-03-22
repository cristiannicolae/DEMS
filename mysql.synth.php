<?php
// For now the synth path won't implement any interface
// but in the future it will require to implement an interface which the model class
// will use to access the synth's functionality (through polymorphism)

require_once("synth.interface.php");
require_once("storagetranslationtable.interface.php");
require_once("CacheQueue.class.php");

define("K_TYPE", "int(11)");

abstract class CheckModelTableResult
{
	const NonExistantTable = 0;
	const InvalidPrimaryKey = 1; // This refers to the fact that the table does not have a primary key or the primary key is not the first column of int type named ID
	const InvalidTableStructure = 2;
	const ValidTable = 4; // Table can be used for storage
}

class MysqlSynthesizer implements Synthesizer
{
	private $connection;
	private $tablePrefix;

	public function __construct($hostname, $username, $password, $database, $tablePrefix = "")
	{
		$this->connection = mysql_connect($hostname, $username, $password, true);
		mysql_select_db($database, $this->connection);
		$this->tablePrefix = $tablePrefix;
	}
	
	public function __destruct()
	{
		mysql_close($this->connection);
	}

	public function Initialize(Model $target, $typeStructure)
	{
		// Determine the model's table name
		$modelTableName = self::GetModelTable($target);
		
		// Check if it was already created in the database
		$result = self::CheckModelTable($target);
		
		if($result == CheckModelTableResult::NonExistantTable)
		{
			// Not created yet? What a bummer, let's create it then shall we
			if(!$this->CreateModelTable($target))
				throw new Exception("Could not initialize storage for model " . $target->Getname() . ". MySQL reports: ". mysql_error());
		}
		else if(($result & CheckModelTableResult::InvalidPrimaryKey == CheckModelTableResult::InvalidPrimaryKey) || ($result & CheckModelTableResult::InvalidTableStructure == CheckModelTableResult::InvalidTableStructure))
		{
			// Attempt to fix table or do something so that we have a valid table where we can store the data at the end of this routine.
			$oldTableName = $modelTableName . ".bak";
			$query = mysql_query("ALTER TABLE `" . $modelTableName . "` RENAME `" . $oldTableName . "`", $this->connection);
			while(!$query)
			{
				$oldTableName .= ".bak";
				$query = mysql_query("ALTER TABLE `" . $modelTableName . "` RENAME `" . $oldTableName . "`", $this->connection);
			} 
	
			if(!$this->CreateModelTable($target))
				throw new Exception("Could not initialize storage for model " . $target->Getname() . ". MySQL reports: ". mysql_error());
		}
	}
	
	private function CheckModelTable(Model $model)
	{
		$typeStructure = $model->GetTypeStructure();
		$modelTableName = $this->GetModelTable($model);
		
		$table_query = mysql_query("SHOW TABLES LIKE '" . $modelTableName ."'");
		
		if(mysql_num_rows($table_query) > 0)
		{
			// Seems to be created, now let's see if it actually has the same structure as the model
			$row = mysql_fetch_row($table_query);
		
			$table_name = $row[0];
		
			$structure_query = mysql_query("DESCRIBE " . $table_name);
		
			$column_counter = 0;
			$model_data_counter = 0;
				
			$indexed_model_data = array_values($typeStructure); // Easier for comparing the columns against the model structure
			$indexed_model_data_names = array_keys($typeStructure);
			while($structure_row = mysql_fetch_assoc($structure_query))
			{
				$result = 0;
				if($column_counter == 0)
				{
					// the first column should always be ID int auto_increment, no exceptions
					if($structure_row["Field"] != "ID" ||
					$structure_row["Type"] != K_TYPE ||
					$structure_row["Key"] != "PRI" ||
					$structure_row["Extra"] != "auto_increment")
					{
						// Our table has been meddled with, attempt to fix it
						$result |= CheckModelTableResult::InvalidPrimaryKey;
					}
					$column_counter++;
				}
				else
				{
					if(MySQLTranslationTable::IsBasicType($indexed_model_data[$model_data_counter]))
						$fieldType = MySQLTranslationTable::TypeFromModelToStorage($indexed_model_data[$model_data_counter]);
					else
						$fieldType = K_TYPE;
						
					if($structure_row["Field"] != $indexed_model_data_names[$model_data_counter] ||
					$structure_row["Type"] != $fieldType)
					{
						return $result | CheckModelTableResult::InvalidTableStructure;
					}
					$model_data_counter++;
		
				}
			}
			// Coming out the while statement, the table should be a simple projection of the model structure on the database
			return CheckModelTableResult::ValidTable;
		}
		
		return CheckModelTableResult::NonExistantTable;
	}
	
	private function GetModelReferenceName(Model $model)
	{
		return $model->GetName() . "ID";
	}
	
	private function CreateModelTable(Model $model)
	{
		$typeStructure = $model->GetTypeStructure();
		$modelTableName = $this->GetModelTable($model);
		
		// For each variable in the data class
		$table_structure_string = "";
		foreach($typeStructure as $key => $value)
		{
			if(MySQLTranslationTable::IsBasicType($value))
				$variable_storage_type = MySQLTranslationTable::TypeFromModelToStorage($value);
			else
			{
				if(strpos($value, "list of") !== false)
				{
					// Create the list table
					$referenceModelName = str_replace("list of ", "", $value);
					$referenceModel = Model::Type($referenceModelName); // TODO: treat the list of case dude, specifically get rid of list of from the $value
					$listTableName = $this->GetListTable($model, $referenceModel);
					
					$sql = mysql_query("SHOW TABLES LIKE '" . $listTableName . "'");
					
					if(mysql_num_rows($sql) == 0)
					{
						$create_command = "CREATE TABLE `" . $listTableName . "`(ID " . K_TYPE . " auto_increment, " . $this->GetModelReferenceName($model) . " " . K_TYPE . ", " . $this->GetModelReferenceName($referenceModel) . " " . K_TYPE . ", PRIMARY KEY(ID))";
						$sql = mysql_query($create_command);
						
						if(!$sql)
						{
							echo "OH! NO ERROR, HANDLE IT.";
						}
					}
					
					continue;
				}
				$variable_storage_type = K_TYPE;
			}
			$variable_storage_name = $key;
		
			$table_structure_string .= ", " . $variable_storage_name . " " . $variable_storage_type;
		}
		$create_command = "CREATE TABLE `" . $modelTableName ."` (ID " . K_TYPE . " auto_increment " . $table_structure_string . ", PRIMARY KEY(ID))";
		$create_query = mysql_query($create_command);
			
		if(!$create_query)
			return false;
		return true;
	}
	
	public function Create(ModelObject $mo, array &$data)
	{	
		$field_names = array_keys($data);
		$field_values = self::EscapeArray(array_values($data));
		$model = $mo->GetModel();
		
		$numFieldValues = count($field_values);
		for($i = 0; $i < $numFieldValues; $i++)
		{
			$fieldType = gettype($field_values[$i]);
			if($fieldType == "object")
				$field_values[$i] = ModelObjectIDCache::GetInstance()->GetID($field_values[$i]);
			else if($fieldType == "NULL")
				$field_values[$i] = "NULL";
			else if($fieldType == "string")
				$field_values[$i] = "'" . $field_values[$i] . "'";
		}
		// TODO: Remove "" from all values, only the appropriate values should have it
		$sql_command = "INSERT INTO `" . self::GetModelTable($model) . "`(" . implode(", ", $field_names) .") VALUES(" . implode(", ", $field_values ) . ")";
		
		$sql = mysql_query($sql_command);
		if(!$sql)
		{
			echo "Fizzled! " . mysql_error();
		}
		$modelID = mysql_insert_id();
		
		// Since we have this we should also try to insert the list type references if they are not null
		
		ModelObjectIDCache::GetInstance()->CacheID($modelID, $mo);
	}
	
	// Retrieves all the fields that should be treated as references by the MO and require further processing
	private function GetReferenceFields(Model $model)
	{
		$typeStructure = $model->GetTypeStructure();
		/*
		$query = mysql_query("DESCRIBE " . self::GetModelTable($model));
		
		$fieldNames = array(); // Contains all of the field names
		while($row = mysql_fetch_assoc($query))
		{
			if($row['Field'] == "ID")
				continue;
			
			$fieldNames[] = $row['Field'];
		}
		*/
		// Check which fields are reference fields and require additional processing/retrieval
		$referenceFields = array();
		foreach($typeStructure as $fieldName => $fieldType)
		{
			if(!MySQLTranslationTable::IsBasicType($fieldType))
			{
				$type = "single";
				if(strpos($fieldType, "list of") !== false)
					$type = "list";
				$referenceFields[$fieldName] =  $type;
			}
		}
		
		return $referenceFields;
	}
	
	private function RetrieveReferences(Model $model, &$row, array $referenceFields)
	{
		$referenceFields = array();
		foreach($referenceFields as $fieldName => $fieldType)
		{
			if($fieldType == "single")
			{
				$referenceModel = Model::Type($model->GetFieldType($fieldName));
				$query_command = "SELECT * FROM `" . $this->GetModelTable($referenceModel) . "` WHERE `ID`=" . $row[$fieldName];
				$sql = mysql_query($query_command);
				
				if(!$sql)
					echo "ERROR BIATCH, HANDLE IT";
				
				$row[$fieldName] = mysql_fetch_assoc($sql);
				
				continue;
			}
			$referenceType = $model->GetFieldType($fieldName);
			$referenceType = str_replace("list of ", "", $referenceType);
			$referenceModel = Model::Type($referenceType);
			$listTableName = $this->GetListTable($model, $referenceModel);
			$query_command = "SELECT * FROM `" . $this->GetModelTable($referenceModel) . "` A JOIN `" . $listTableName . "` B ON `B." . $this->GetModelReferenceName($model) . "`=`A.ID` WHERE `A.ID`=`" . $row['ID'];
			$query = mysql_query($query_command);
			if(!$query)
				echo "OH NO " . mysql_error();
			$row[$fieldName] = mysql_fetch_assoc($query);
			// Great so far; would've been great if I had a method to create the reference list tables and to know what columns they should have
		}
	}
	
	public function Retrieve(Model $model, array &$data)
	{		
		$field_names = array_keys($data);
		$field_values = self::EscapeArray(array_values($data));
		
		$sql_command = "SELECT * FROM " . self::GetModelTable($model);
		$where = array();
		
		$numFields = count($field_names);
		if($numFields > 0)
		{
			for($i = 0; $i < $numFields; $i++)
			{
				$where[] = "`". $field_names[$i] . "`=\"" . $field_values[$i] . "\"";
			}
			$sql_command .= " WHERE " . implode(" AND ", $where);
		}
		$query = mysql_query($sql_command);
		$result = array();
		$referenceFields = $this->GetReferenceFields($model);
		while($row = mysql_fetch_assoc($query))
		{
			$this->RetrieveReferences($row, $referenceFields);
			
			$result[] = $row;
		}
		
		if(count($result) == 1)
		{
			$modelID = $result[0]['ID'];
			unset($result[0]['ID']);
			
			$mo = new ModelObject($model, $result[0], true);
			ModelObjectIDCache::GetInstance()->CacheID($modelID, $mo);
			
			return $mo;
		}
		
		$objects = array();
		foreach($result as $row)
		{
			$modelID = $row['ID'];
			unset($row['ID']);
			
			$mo = new ModelObject($model, $row);
			ModelObjectIDCache::GetInstance()->CacheID($modelID, $mo);
			
			$objects[] = $mo;
		}
		
		$resultList = new GenericList("ModelObject<" . $model->GetName() . ">", $objects);
		
		return $resultList;
	}
	
	public function RetrieveByIdentifier(Model $model, array &$ids)
	{
		$id_values = self::EscapeArray(array_values($ids));
		$sql_command = "SELECT * FROM `" . self::GetModelTable($model) . "` WHERE `ID`=" . implode(" OR ID=", $id_values);

		$query = mysql_query($sql_command);
		$result = array();
		while($row = mysql_fetch_assoc($query))
			$result[] = $row;
		
		if(count($result) == 1)
		{
			$modelID = $result[0]['ID'];
			unset($result[0]['ID']);
				
			$mo = new ModelObject($model, $result[0], true);
			ModelObjectIDCache::GetInstance()->CacheID($modelID, $mo);
				
			return $mo;
		}
		
		$objects = array();
		foreach($result as $row)
		{
			$modelID = $row['ID'];
			unset($row['ID']);
				
			$mo = new ModelObject($model, $row);
			ModelObjectIDCache::GetInstance()->CacheID($modelID, $mo);
				
			$objects[] = $mo;
		}
		
		$resultList = new GenericList("ModelObject<" . $model->GetName() . ">", $objects);
		
		return $resultList;
	}
	
	public function Update(ModelObject $mo, array &$data)
	{
		$field_names = array_keys($data);
		$field_values = self::EscapeArray(array_values($data));
		
		$model = $mo->GetModel();
		$sql_command = "UPDATE " . self::GetModelTable($model) . " SET ";
		
		$updateFields = array();
		$numFields = count($field_names);
		if($numFields > 0)
		{
			for($i = 0; $i < $numFields; $i++)
			{
			$updateFields[] = "`". $field_names[$i] . "`=\"" . $field_values[$i] . "\"";
			}
			$sql_command .= implode(", ", $updateFields);
			
			$sql_command .= "WHERE `ID`=" . ModelObjectIDCache::GetInstance()->GetID($mo);
			
			mysql_query($sql_command);
		}
	}
	
	public function GetIdentifier(ModelObject $model)
	{
		return ModelObjectIDCache::GetInstance()->GetID($model);
	}
	
	public function Delete(ModelObject $mo)
	{
		$model = $mo->GetModel();
		mysql_query("DELETE FROM " . self::GetModelTable($model) . " WHERE ID=" . ModelObjectIDCache::GetInstance()->GetID($mo));
		ModelObjectIDCache::GetInstance()->RemoveID($mo);
	}
	
	public function DeleteByIdentifier(Model $model, array &$ids)
	{
		$id_values = self::EscapeArray(array_values($ids));
		mysql_query("DELETE FROM " . self::GetModelTable($model) . " WHERE ID=" . implode(" OR ID=", $id_values));
		
		//TODO: Remove old objects from ID cache
		foreach($ids as $id)
			ModelObjectIDCache::GetInstance()->RemoveObject($id);
	}
	
	// This function is used to make the table names more human friendly
	private function GetPlural($string)
	{
		// All of those lovely plural forming rules you used to dread in middle school
		$string_length = strlen($string);
		if(strtolower($string[$string_length - 1]) == 'y')
		{
			$string[$string_length - 1] = 'i';
			$string[$string_length] = 'e';
			$string[$string_length + 1] = 's';
		}
		else
			$string[$string_length] = 's';
		return $string;
	}
	
	private function GetModelTable($model)
	{
		return $this->tablePrefix.strtolower(self::GetPlural($model->GetName()));
	}
	
	private function GetListTable($model1, $model2)
	{		
		// Check if the other model has a "list" table already created and use that
		$name1 = $this->GetModelTable($model2) . $model1->GetName() . "list";
		$table_query = mysql_query("SHOW TABLES LIKE '" . $name1 ."'");
		
		if(mysql_num_rows($table_query) > 0)
			return $name1;
		
		return $this->GetModelTable($model1) . $model2->GetName() . "list"; 
	}
	
	private function EscapeArray($data)
	{
		$escaped = array();
		foreach($data as $value)
		{
			$valueType = gettype($value);
			if($valueType == "object") // No point in escaping non basic types
				continue;
			$value = mysql_real_escape_string($value);
			$escaped[] = $value;
		}
		return $escaped;
	}
}
// Static utility class which implements a type/value translation between the Storage and the Model
class MySQLTranslationTable implements StorageTranslationTable
{
	// Array which holds the actual translation
	// Model => Storage
	private static $data = array(
		"short text" => "varchar(255)",
		"text" => "text",
		"short integer" => "smallint",
		"integer" => "int",
		"long integer" => "bigint",
		"single float" => "float",
		"double float" => "double",
		"short data" => "tinyblob",
		"data" => "blob",
		"bool" => "tinyint(1)", // mysql actually translates the bool type to this
	);

	private function __construct() {} // Make this a static utility class

	public static function TypeFromModelToStorage($typename)
	{
		return self::$data[$typename];
	}
	
	public static function TypeFromStorageToModel($typename)
	{
		$key = array_search($typename, self::$data);
		if($key === FALSE)
			throw new Exception("No such existing Storage type : ".$typename);
		
		return $key;
	}
	
	public static function ValueFromModelToStorage($value)
	{
		return $value;
	}
	
	public static function ValueFromStorageToModel($value)
	{
		return $value;
	}
	
	public static function IsBasicType($type)
	{
		return isset(self::$data[$type]);
	}
}

class ModelObjectIDCache
{
	private $cache;
	private static $instance;
	
	private function __construct()
	{
		$this->cache = new CacheQueue();
	}
	
	public static function GetInstance()
	{
		if(!self::$instance)
			self::$instance = new ModelObjectIDCache();
		
		return self::$instance;
	}
	
	public function GetID($modelReference)
	{
		return $this->cache->GetCached(spl_object_hash($modelReference));
	}
	
	public function CacheID($ID, $modelReference)
	{
		$this->cache->Cache($ID, spl_object_hash($modelReference));
	}
	
	public function RemoveID($modelReference)
	{
		$this->cache->Remove(spl_object_hash($modelReference));
	}
	
	public function RemoveObject($object)
	{
		$this->cache->RemoveValue($object);
	}
}