<?php
include "../model.class.php";
include "../mysql.synth.php";
include_once("../GenericList.class.php");

Model::Initialize(".:./models:./storemodels", new MysqlSynthesizer("localhost", "root", "", "test"));

$product_model = new Model("Product");

$product1 = $product_model->Create();
$product1->set('Name', 'Universal Product');
$product1->set('otherName', 'Standard Product');
//print_r($product1);

$categoryModel = new Model("Category");
$strawberryCategory = $categoryModel->Create();
$strawberryCategory->set("Name", "Strawberry");

$clothingCategory = $categoryModel->Create();
$clothingCategory->set("Name", "Clothing");

//$product1->set("Category", $strawberryCategory); // this has to be a list

$product2 = $product_model->Create();
$product2->set("Name", "Glorious Product");
$product2->set("otherName", "Bastard");
//print_r($product2);

$a = new GenericList("ModelObject<Product>", array($product1, $product2));

$ManufacturerModel = new Model("Manufacturer");
$manufacturer1 = $ManufacturerModel->Create();
$manufacturer1->set("Name", "Glorious Product Manufacturing Facility");
$manufacturer1->set("Description", "Glory upon glory");

$product1->set("Manufacturer", $manufacturer1);

$categoryList = $categoryModel->CreateList();
$categoryList->append($strawberryCategory);
$categoryList->append($clothingCategory);

//$product1->set("Categories", $categoryList);
$product1->set("Categories", $categoryList);

$product2 = Model::Type("Product")->Create();
$product3 = Model::Type("Product")->Create();
$product4 = Model::Type("Product")->Create();

$manufacturer2 = Model::Type("Manufacturer")->Create();
$manufacturer3 = Model::Type("Manufacturer")->Create();

$category1 = Model::Type("Category")->Create();

// Trying out the Create parameter feature
$category2 = Model::Type("Category")->Create("Shite");

//$form_model->InitializeStorage(); 