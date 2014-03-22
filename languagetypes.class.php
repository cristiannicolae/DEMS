<?php
/* Intermediate Model Language Type Wrappers and Type Translation Table (c) 2013 Cristian Nicolae
 * For licensing crap read COPYING
 */

require_once("languagetranslationtable.interface.php");

interface Type
{
	public static function Validate($var);
}

class ShortText implements Type
{
	public static function Validate($var)
	{
		if(is_string($var))
		{
			if(strlen($var) <= 255)
				return true;
		}
		
		return false;
	}
}

class Text implements Type
{
	public static function Validate($var)
	{
		return is_string($var);
	}
}

class Data implements Type
{
	public static function Validate($value)
	{
		return is_string($value);
	}
}

class ShortData implements Type
{
	public static function Validate($value)
	{
		return is_string($value) && (strlen($value) < 65536);
	}
}

class Bool implements Type
{
	public static function Validate($var)
	{
		return is_bool($var);
	}
}

class PHPTranslationTable implements LanguageTranslationTable
{
	// Array which holds the actual translation
	// Model => Storage
	private static $data = array(
		"short text" => "string",
		"text" => "string",
		"short integer" => "integer",
		"integer" => "integer",
		"long integer" => "integer",
		"single float" => "float",
		"double float" => "double",
		"short data" => "string",
		"data" => "string",
		"bool" => "boolean",
	);

	private function __construct() {} // Make this a static utility class

	public static function TypeFromModelToLanguage($typename)
	{
		return $data[$typename];
	}
	
	public static function TypeFromLanguageToModel($typename)
	{
		$key = array_search($typename, self::$data);
		if($key === FALSE)
			throw new Exception("No such existing Storage type : ".$typename);
		
		return $key;
	}
	
	public static function ValueFromModelToLanguage($value)
	{
		return $value;
	}
	
	public static function ValueFromLanguageToModel($value)
	{
		return $value;
	}
	
	public static function GetTypeClassName($typename)
	{
		return str_replace(" ", "", ucwords($typename));
	}
}
