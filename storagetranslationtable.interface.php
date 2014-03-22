<?php

interface StorageTranslationTable
{
	public static function TypeFromModelToStorage($typename);
	public static function TypeFromStorageToModel($typename);
	public static function ValueFromModelToStorage($value);
	public static function ValueFromStorageToModel($value);
}