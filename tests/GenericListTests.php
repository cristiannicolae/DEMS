<?php
/* Copyright (c) 2014, Cristian Nicolae
 * See LICENSE for licensing terms
 * TL;DR: Licensed under the BSD License, you are allowed
 * to do whatever you want with this software and source code
 * as long as you keep the contents of the file LICENSE
 * in your program's source code and in your program's documentation
 * in the case that your program does not have a public source code.
 * The author's name must not be used for promotional purposes
 * without priorly contacting and receiving permission from the author.
 * NOTE: This is a summary of the licensing terms, written for the purpose of brevity.
 * Please read the file LICENSE in case you are curious about the actual licensing terms.
 *
 * Unit tests for the GenericList class
 */
 
 class MockParameterized implements Parameterized
 {
	private $internal_type;
	
	public function __construct($type)
	{
		$this->internal_type = $type;
	}
 
	public function ParameterTypes()
	{
		return array($this->internal_type);
	}
 }
 
class GenericListTest extends PHPUnit_Framework_TestCase
{
    private $list;
    
    public function setUp()
    {
        $list = new GenericList("R-Type");
    }
    
    public function testListAppend()
    {
		$object = new MockParameterized("R-Type");
		try
		{
			$list->append($object);
		}
		catch(Exception $e)
		{
			fail();
		}
		
    }
}
