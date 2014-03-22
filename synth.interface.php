<?php
require_once("model.class.php");

interface Synthesizer
{
	public function Initialize(Model $target, $data);
	public function Create(ModelObject $model, array &$data);
	public function Retrieve(Model $model, array &$data);
	public function RetrieveByIdentifier(Model $model, array &$ids);
	public function Update(ModelObject $model, array &$data);
	public function Delete(ModelObject $model);
	public function DeleteByIdentifier(Model $model, array &$ids);
	public function GetIdentifier(ModelObject $model);
}