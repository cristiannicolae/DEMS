<?php

interface LanguageTranslationTable
{
	public static function TypeFromModelToLanguage($typename);
	public static function TypeFromLanguageToModel($typename);
	public static function ValueFromModelToLanguage($value);
	public static function ValueFromLanguageToModel($value);
	
	public static function GetTypeClassName($typename);
}