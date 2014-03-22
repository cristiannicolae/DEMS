<?php
/* Intermediate Model Class (c) 2013 Cristian Nicolae
 * For licensing crap read COPYING
 * Dynamically translates your model files into PHP objects
 * which you can comfortably use in your PHP code
 */

define("MODEL_CACHE_SIZE", "20"); // Defines how many of the most used models are to be kept preloaded
define("PERSIST_ON_DESTRUCT", false);

require_once("languagetypes.class.php"); // Contains translation and validation classes for the PHP language
require_once("parameterized.interface.php");
require_once("GenericList.class.php");
require_once("CacheQueue.class.php");

// This declaration is required to make the script compatible with versions of PHP prior to 5.2 (mainly 5.1)
if ( !function_exists( 'spl_object_hash' ) ) {
    function spl_object_hash( $object )
    {
        ob_start();
        var_dump( $object );
        preg_match( '[#(\d+)]', ob_get_clean(), $match );
        return $match[1];
    }
}

// TODO: Let the user have the option to disable storage structure integrity checking and deal with it himself.

class Model
{
	use TypeValidityChecker;

	private static $currentSynth; // the current data storage synthesizer for the models
	
	private $name; // what the model is named after
	private $typeStructure; // contains the type structure of the model
	private $storageInitialized; // specifies if the model has initialized its storage
	
	private function __construct($modelName = "")
	{
		/*
		if(strlen($modelName) > 0)
		{
			$model = self::LoadModel($modelName);
			$this->name = $model->GetName();
			$this->typeStructure = $model->typeStructure;
		} */
		$this->storageInitialized = false;
	}
	
	// Must be called to initialize internal variables before models can be used
	public static function Initialize($modelPath, Synthesizer $currentSynth)
	{
		self::$currentSynth = $currentSynth;
		
		ModelList::GetInstance()->ScanPath($modelPath);
	}
	
	// Optional function, called to initialize the model's persistance (called anyway on first operation)
	public function InitializeStorage()
	{
		self::$currentSynth->Initialize($this, $this->typeStructure);
		$this->storageInitialized = true;
	}
	
	public static function Type($modelName)
	{
		return self::LoadModel($modelName);
	}
	
	// Returns a constructed Model object that is loaded from a Model File
	private static function LoadModel($modelName)
	{
		// Check to see if the required static variables where intiliazed prior to attempting to create this model object
		if(!isset(self::$currentSynth))
			throw new Exception("Model class variables are not initialized. Please call Model::Initialize(modelPath, currentSynth) before attempting to construct a model.");
			
		// Check if we already preloaded this Model in the caching array
		if(($model = ModelCache::GetInstance()->GetCachedModel($modelName)) !== false)
		{
			return $model;
		}
		else
		{
			if(false === ($modelFilePath = ModelList::GetInstance()->GetModelPath($modelName)))
				throw new Exception("No model file " . $modelName . " was found in the Model Path.");
		
			$model = self::ParseModelFile($modelFilePath);

			ModelCache::GetInstance()->CacheModel(clone $model);

			return $model;
		}
	}
	
	private static function ParseModelFile($location)
	{
		$model = new Model("");
		$accepted_types = "(list of (\w+)|short text|text|short integer|integer|long integer|single float|double float|short data|data|bool|time|date|time interval|bool)"; // used in the regular expression for detecting the type of a variable
	
		$guts = file_get_contents($location);
		$lines = explode("\n", $guts);

		// First line must always be Model %modelName%:
		if(($modelName = self::GetModelName($lines[0])) === FALSE)
			throw new Exception("Invalid model file. First line must start with Model %ModelName%:");
		
		$model->name = $modelName; // set the name of the Model
			
		// Expect to have properties on the next lines
		$i = 1;
		$num_lines = count($lines);
		while($i < $num_lines)
		{
			// Skip over white lines
			if(preg_match("/^\s*$/", $lines[$i]) == 1)
			{
				$i++;
				continue;
			}

			if(preg_match("/^\s*" . $accepted_types . "\s+(\w*)\s*$/" , $lines[$i], $matches) == 0)
			{
				// Test if to see if the line doesn't contain invalid syntax
				if(preg_match("/^\s*(\w*)\s+(\w*)\s*$/", $lines[$i], $matches) == 0)
					throw new Exception("Invalid syntax on line " . ($i + 1) . " in file " . $location);
				// If it's not a basic type, it might be a model file.

				if(($modelPath = ModelList::GetInstance()->GetModelPath($matches[1])) === false)
					throw new Exception("Invalid syntax on line " . ($i + 1) . " in file " . $location . ". Reference " . $matches[2] . " points to an undeclared model " . $matches[1] . "." );
					
				$variable_name = $matches[2];
				$variable_type_name = $matches[1];
			}
			else
			{
				if(strpos($matches[1], "list of") !== false) // if it's a list
				{
					// Do a type check on the list
					$listType = $matches[2];
					
					if(($modelPath = ModelList::GetInstance()->GetModelPath($listType)) === false)
						throw new Exception("Invalid syntax on line " . ($i + 1) . " in file " . $location . " . No such type \"" . $listType . "\"");
				}
				$variable_name = $matches[3];
				$variable_type_name = $matches[1];
			}

			$model->typeStructure[$variable_name] = $variable_type_name;
			
			$i++;
		}

		return $model;
	}
	
	public static function GetModelName($firstLine)
	{
		// First line in a model file must always be Model %modelName%:
		if(preg_match("/^Model\s*(\w*):\s*$/", $firstLine, $matches) == 0)
			return false;
		
		return $matches[1]; // set the name of the Model
	}
	// Creates a ModelObject
	// Contains a variable number of parameters, each corresponding to the fields of a model
	// If no arguments are provided, the ModelObject will be initialized with NULL values
	// If the user provides more arguments than there are model fields, these additional arguments will be discarded
	public function Create()
	{
		$numArgs = func_num_args();
		$argList = func_get_args();
	
		$objectData = array();
		
		$argsIterator = 0; // iterator for the arguments provided by the user
		foreach($this->typeStructure as $name => $type)
		{
			if($argsIterator < $numArgs)
			{
				self::IsTypeValid($name, $argList[$argsIterator], $this);
				$objectData[$name] = $argList[$argsIterator]; // TODO: Check type safety
			}
			else
				$objectData[$name] = NULL;
				
			++$argsIterator;
		}

		$result = new ModelObject($this, $objectData);
		
		// TODO: Check the persistance type constant, also have one for immediate creation in storage
		
		return $result;
	}
	
	public function CreateList()
	{
		return new GenericList("ModelObject<" . $this->name . ">");
	}
	
	public function Retrieve()
	{
		$numArgs = func_num_args();
		$argList = func_get_args();

		$paramData = array();
		
		$argsIterator = 0; // iterator for the arguments provided by the user
		foreach($this->typeStructure as $name => $type)
		{
			if($argsIterator < $numArgs)
			{
				self::IsTypeValid($name, $argList[$argsIterator], $this);
				$paramData[$name] = $argList[$argsIterator]; // TODO: Check type safety
			}
				
			++$argsIterator;
		}
		
		if(!$this->IsStorageInitialized())
			$this->InitializeStorage();
			
		return self::$currentSynth->Retrieve($this, $paramData);
	}
	
	public function RetrieveByIdentifier($id)
	{
		$numArgs = func_num_args();
		$argList = func_get_args();
		
		if(!$this->IsStorageInitialized())
			$this->InitializeStorage();
		
		return self::$currentSynth->RetrieveByIdentifier($this, $argList);
	}
	
	public function DeleteByIdentifier($id)
	{
		$numArgs = func_num_args();
		$argList = func_get_args();
		
		if(!$this->IsStorageInitialized())
			$this->InitializeStorage();
		
		return self::$currentSynth->DeleteByIdentifier($this, $argList);
	}
	
	public function GetFieldType($name)
	{
		return $this->typeStructure[$name];
	}
	
	public function GetName()
	{
		return $this->name;
	}
	
	public function IsStorageInitialized()
	{
		return $this->storageInitialized;
	}
	
	public static function GetCurrentSynth()
	{
		return self::$currentSynth;
	}
	
	public static function SetCurrentSynth(Synthesizer $synth)
	{
		self::$currentSynth = $synth;
		
		// Run through all the cached models and de-initialize them so we can check for integrity (because you never know)
		$cachedModels = ModelCache::GetInstance()->GetCachedModels();
		foreach($cachedModels as $model)
			$model->storageInitialized = false;
		
		// TODO: You will have to reset the persistence/change flags of the ModelObjects that were retrieved from this synth
		// Rethinking if this method is good might be necessary
	}
	
	public function GetTypeStructure()
	{
		return $this->typeStructure;
	}
}

class ModelObject implements Parameterized
{
	use TypeValidityChecker; // type validity checker used to check the validity of a value in the set method

	private $model; // The model type of the Object
	private $data; // The data contained by this Object
	
	private $persisted; // Determines if the object was freshly created or was retrieved
	private $changed; // Determines if the object was altered since the last retrieval

	public function __construct($model, $data, $persisted = false)
	{
		$this->model = $model;
		$this->data = $data;
		
		$this->persisted = $persisted;
		$this->changed = false;
	}
	
	public function __destruct()
	{
		if(PERSIST_ON_DESTRUCT)
			self::Persist();
	}
	
	public function Persist()
	{
		if(!$this->persisted)
		{
			if(!$this->model->IsStorageInitialized())
				$this->model->InitializeStorage();
			Model::GetCurrentSynth()->Create($this, $this->data);
		}
		else if($this->changed)
		{
			if(!$this->model->IsStorageInitialized())
				$this->model->InitializeStorage();
			
			Model::GetCurrentSynth()->Update($this, $this->data);
		}
		
		$persisted = true;
	}
	
	public function Delete()
	{
		if($this->persisted)
			self::$currentSynth->Delete($mo);
	}
	
	public function GetModel()
	{
		return $this->model;
	}
	
	public function get($name)
	{
		return $this->data[$name];
	}
	/*
	public function getType($name)
	{
		return $this->data[$name]["type"];
	} */
	public function set($name, $value)
	{
		self::IsTypeValid($name, $value, $this->model); // checks if the value provided by the user is valid, no need for ifs as it throws an exception if the value isn't valid
		$this->data[$name] = $value;
		$this->changed = true;
	}
	
	public function ParameterTypes()
	{
		return array($this->model->GetName());
	}
	
	public function IsChanged()
	{
		return $this->changed;
	}
	
	public function IsPersisted()
	{
		return $this->persisted;
	}
	
	public function GetData()
	{
		return $this->data;
	}
	
	public function GetIdentifier()
	{
		return Model::GetCurrentSynth()->GetIdentifier($this);
	}
}

trait TypeValidityChecker
{
	function IsTypeValid($name, $value, $model)
	{
		$valueType = gettype($value);
		if($valueType != "object")
		{	
			$fieldType = $model->GetFieldType($name);
			if(PHPTranslationTable::IsBasicType($fieldType))
			{
				if(!call_user_func(PHPTranslationTable::GetTypeClassName($fieldType)."::Validate", $value))
					throw new Exception("Invalid type. This field expects a ". $fieldType . " value for this field.");
				return;
			}
			if($valueType != "NULL") // A null can also be passed if the reference shouldn't point to any object
				throw new Exception("Reference type expected for " . $name . "");
			return;
		}
		$valueClass = get_class($value);
		if($valueClass == "ModelObject")
		{
			if($value->GetModel()->GetName() != $model->GetFieldType($name))
				throw new Exception("Invalid type for ModelObject reference.");
			return;
		}
		else if($valueClass == "GenericList")
		{
			// Check if the field we are trying to set is a list of type first of all
			$fieldType = $model->GetFieldType($name);
				
			if(strpos($fieldType, "list of") !== false)
			{			
				if($value->ParameterTypes() != array("ModelObject<" . trim(substr($this->model->GetFieldType($name), 8)) .">"))
					throw new Exception("Invalid type of GenericList for List of reference.");
				return;
			}
				
			throw new Exception("Passed list object in non list of reference field.");
		}
			
		throw new Exception("Invalid value type exception.");
	}
}

// Keeps a list of all the model files found in the path
class ModelList
{
	public $data; // stored as Name => Path
	private $modelDirectories; // an exploded version of the model path string

	private static $instance;
	private function __construct() { $this->data = array(); } // singleton
	
	public static function GetInstance()
	{
		if(!self::$instance)
			self::$instance = new ModelList();

		return self::$instance;
	}
	
	public function AddModel($name, $path)
	{
		$key = array_search($name, $this->data);
		if($key !== FALSE) // really there's a type that already is called this, so just warn the user
		{
			trigger_error("The type " . $name . " is already defined in " . $this->data[$name] , E_USER_WARNING);
			return;
		}
		$this->data[$name] = $path;
	}
	
	public function ScanPath($pathString)
	{
		$modelDirectories = explode(";", $pathString);
		foreach($modelDirectories as $directory)
		{
			if ($handle = opendir($directory)) {
				$modelFilePath = "";
				while (false !== ($entry = readdir($handle))) {
					if ($entry != "." && $entry != ".." && preg_match("/.model$/", $entry) == 1) {
						// Found a model file, let's add it to the list
						// First we'll read the first line
						$filePath = $directory ."/". $entry;
						$fileHandle = fopen($filePath, "rb");
						
						if(!$fileHandle) {
							throw new Exception("Couldn't open model file " . $filePath);
						}
						
						if(($line = fgets($fileHandle)) === false)
						{
							// Seems we hit an empty model file, let's ignore it for now
						
							fclose($fileHandle);
							continue;
						}
						
						$modelName = Model::GetModelName($line);
						
						fclose($fileHandle);
						
						self::AddModel($modelName, $directory ."/". $entry);
					}
				}
				closedir($handle);
			}
		}
	}
	
	public function GetModelPath($modelName)
	{
		if(array_key_exists($modelName, $this->data))
			return $this->data[$modelName];
		
		return false;
	}
}

// Keeps the data from the most used models in a priority queue
class ModelCache
{
	private static $instance;
	private $queue;
	private function __construct() { $this->queue = new CacheQueue();}
	
	public static function GetInstance()
	{
		if(!self::$instance)
			self::$instance = new ModelCache();
		
		return self::$instance;
	}
	
	public function GetCachedModel($modelName)
	{
		return $this->queue->GetCached($modelName);
	}
	
	public function CacheModel($model)
	{
		$this->queue->Cache($model, $model->GetName());
	}
	
	public function GetCachedModels()
	{
		return $this->queue->GetData();
	}
}
