<?php

require_once('db.php');
require_once('../model/Response.php');

//connect to database
try{
  $writeDB = DB::connectWriteDB();
}

$asXML = false;

//check for xml output
if(array_key_exists("asxml", $_GET)){
  $asXML = true;
}

catch(PDOException $ex){
  error_log("Connection error: ".$ex, 0);
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("Database connection error");
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit();
}

//check http request method - only allow post to create new users
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  $response = new Response();
  $response->setHttpStatusCode(405);
  $response->setSuccess(false);
  $response->addMessage("Request method not allowed");
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit();
}

//check if content type is not json
if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
  $response = new Response();
  $response->setHttpStatusCode(400);
  $response->setSuccess(false);
  $response->addMessage("Content Type header not set to JSON");
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit();
}

//get posted json data and store in variable
$rawPostData = file_get_contents('php://input');

//check for valid json
if(!$jsonData = json_decode($rawPostData)){
  $response = new Response();
  $response->setHttpStatusCode(400);
  $response->setSuccess(false);
  $response->addMessage("Request body is not valid JSON");
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit();
}

//check mandatory fields are present
if(!isset($jsonData->fullName) || !isset($jsonData->username) || !isset($jsonData->password)){
  $response = new Response();
  $response->setHttpStatusCode(400);
  $response->setSuccess(false);
  //turnary operator to send message if full name is not supplied
  (!isset($jsonData->fullName) ? $response->addMessage("Full name not supplied") : false);
  //turnary operator to send message if username is not supplied
  (!isset($jsonData->username) ? $response->addMessage("Username not supplied") : false);
  //turnary operator to send message if password is not supplied
  (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit();
}

//check data supplied is valid - not blank string and not greater than 255
if(strlen($jsonData->fullName) < 1 || strlen($jsonData->fullName) > 255 || strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255){
  $response = new Response();
  $response->setHttpStatusCode(400);
  $response->setSuccess(false);
  (strlen($jsonData->fullName) < 1 ? $response->addMessage("Full name cannot be blank") : false);
  (strlen($jsonData->fullName) > 255 ? $response->addMessage("Full name cannot be greater than 255 characters") : false);
  (strlen($jsonData->username) < 1 ? $response->addMessage("Username cannot be blank") : false);
  (strlen($jsonData->username) > 255 ? $response->addMessage("Username cannot be greater than 255 characters") : false);
  (strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
  (strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot be greater than 255 characters") : false);
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit();
}

//tidy up the data - remove any spaces etc
$fullname = trim($jsonData->fullName);
$username = trim($jsonData->username);
$password = $jsonData->password;

//ensure username is not currently in use by someone else
try {
  //create db query
  $query = $writeDB->prepare('SELECT id from tbl_users where username = :username');
  $query->bindParam(':username', $username, PDO::PARAM_STR);
  $query->execute();

  //get row count
  $rowCount = $query->rowCount();

  if($rowCount !== 0) {
    //response for username already exists
    $response = new Response();
    $response->setHttpStatusCode(409);
    $response->setSuccess(false);
    $response->addMessage("Username already exists");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit;
  }

  //hash the password to store in the DB as plain text
  $hashed_password = password_hash($password, PASSWORD_DEFAULT);

  //create db query to create user
  $query = $writeDB->prepare('INSERT INTO tbl_users (fullName, username, password) VALUES (:fullname, :username, :password)');
  $query->bindParam(':fullname', $fullname, PDO::PARAM_STR);
  $query->bindParam(':username', $username, PDO::PARAM_STR);
  $query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
  $query->execute();

  //get row count
  $rowCount = $query->rowCount();

  if($rowCount === 0) {
    //set up response for error
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an error creating the user account - please try again");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit;
  }

  //get last user id so can return the user id in the json
  $lastUserID = $writeDB->lastInsertId();

  //response data array which contains user details
  $returnData = array();
  $returnData['id'] = $lastUserID;
  $returnData['fullName'] = $fullname;
  $returnData['username'] = $username;

  //success response
  $response = new Response();
  $response->setHttpStatusCode(201);
  $response->setSuccess(true);
  $response->addMessage("User created");
  $response->setData($returnData);
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit;
}
catch(PDOException $ex) {
  errro_log("Database query error: ".$ex, 0);
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("There was an issue creating a user account - please try again");
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit;
}

 ?>
