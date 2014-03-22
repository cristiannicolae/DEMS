<?php
class CacheQueue
{
	private $data; // stored as Name => Model object

	public function __construct()
	{
		$this->data = array();
	}
		
	public function GetCached($key)
	{
		if(array_key_exists($key, $this->data))
			return $this->data[$key];
		
		return false;
	}
	
	public function Cache($value, $key)
	{
		// TODO: Implement queue mechanism	
		$this->data[$key] = $value;
	}
	
	public function Remove($key)
	{
		unset($this->data[$key]);
	}
	
	public function RemoveValue($value)
	{
		$key = array_search($value, $this->data);
		unset($this->data[$key]);
	}
	
	public function GetData()
	{
		return $data;
	}
}
