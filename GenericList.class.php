<?php
/* Cristian Nicolae (c) 2014
 * Released under the BSD license
 */
require_once("parameterized.interface.php"); 

class GenericList extends ArrayObject implements Parameterized
{
	private $type;
	private $parameterTypes; // Array that holds the parameter types if the generic type is parameterized
	private $typeIsParameterized;

	public function __construct($type = "", array $input = [], $flags = 0, $iterator_class = "ArrayIterator")
	{
		if(($parameter = self::HasParameters($type)) == - 1)
			throw new InvalidArgumentException("The type argument contains invalid syntax.");
		else if($parameter != false)
		{
			$this->parameterTypes = $parameter;
			$this->typeIsParameterized = true;
		}
		
		if($this->typeIsParameterized)
		{
			$this->type = substr($type, 0, strpos($type, "<"));
		}
		else
			$this->type = $type;
			
		if(!self::IsValidType($this->type))
			throw new Exception("The type parameter entered in the constructor is not a valid PHP type (funny I know) or declared class.");

		if($this->typeIsParameterized)
			if(array_search("Parameterized", class_implements($this->type)) === false)
				throw new Exception("The parameter type specified in the constructor " . $this->type . " can not be used with parametrization as it does not implement the Parameterized interface.");
		
		if(count($input) > 0)
		{
			// Check type safety
			foreach($input as $key => $value)
			{
				if(!self::CheckType($value))
					throw new BadTypeException("A variable of uncompatible type was found in the input array by GenericList of " . $this->type . ". Please make sure that the input array only contains elements of type " . $this->type . ".");
			}
		}
		
		parent::__construct($input, $flags, $iterator_class);
	}
	
	// Wrappers for the ArrayObject stuff that respect the container's type safety	
	public function exchangeArray($input)
	{
		// probably check if the input array is a GenericList with the same type
		return parent::exchangeArray($input);
	}
	
	public function offsetSet($index, $value)
	{
		if(!self::CheckType($value))
			throw new BadTypeException("A value of incompatible type was passed to the apped function in GenericList of " . $this->type . ". Please make sure that the data passed to the GenericList's accessor/mutator methods is of type " . $this->type . ".");
		
		parent::offsetSet($index, $value);
	}

	private function CheckType($var)
	{
		$varType = gettype($var);
		if($varType == $this->type)
			return true;
		else if($varType == "object")
		{
			if(get_class($var) == $this->type)
			{
				if($this->typeIsParameterized)
				{
					if($var->ParameterTypes() == $this->parameterTypes)
						return true;
					return false;
				}
				return true;
			}
		}
		
		return false;
	}
	// Check if the type string provided in the constructor contains a <> parameter
	// Returns the parameter types array if the type string is valid (has the same number of < as >)
	// If invalid returns false
	// TODO: Syntax abuse prevention (no "," outside of <>)
	private function HasParameters($type)
	{
		$numOpen = $numClose = 0;
		$numChars = strlen($type);
		$parameterTypes = array();
		$parameter = "";
		for($i = 0; $i < $numChars; $i++)
		{
			if($type[$i] == ",")
			{
				$parameterTypes[] = trim(substr($parameter, 1));
				$parameter = "";
				continue;
			}
			if($type[$i] == '<')
				$numOpen++;
			else if($type[$i] == '>')
				$numClose++;
			if($numOpen > 0)
				$parameter .= $type[$i];
		}
		$parameterTypes[] = trim(substr($parameter, 1, strlen($parameter) - 2));
		
		if($numOpen - $numClose != 0) // This type has bad syntax
			return -1;
		else if($numOpen == 0) // There ain't no parameter in this type
			return false;

		return $parameterTypes;
	}
	
	public function ParameterTypes()
	{	
		return array($this->type . "<" . implode(",", $this->parameterTypes) . ">");
	}
	
	private function IsValidType($type)
	{
		return class_exists($type) || self::IsBaseType($type);
	}
	
	private function IsBaseType($type)
	{
		return $type == "boolean" || $type == "integer" || $type == "double" || $type == "float" ||
			$type == "string" || $type == "array" || $type == "object" || $type == "resource" ||
			$type == "NULL" || $type == "unknown";
	}
}

class BadTypeException extends Exception
{
	public function __construct($message)
	{
		parent::__construct($message);
	}
}
