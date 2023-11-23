<?php

require_once('db.php');
require_once('../model/Response.php');

//connect to database
try {
  $writeDB = DB::connectWriteDB();
}

catch(PDOException $ex) {
  //log the connection error
  error_log("Database Connection Error: ".$ex, 0);
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("Database connection error");
  $response->send();
  exit();
}

//check if the sessionid has been passed into the url
if (array_key_exists("sessionid", $_GET)) {
  //get session id from query string
  $sessionid = $_GET['sessionid'];

  //check to see if sessions id in query string is not empty and is numeric
  if($sessionid == '' || !is_numeric($sessionid)) {
    //error response
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    ($sessionid == '' ? $response->addMessage("Session ID cannot be blank") : false);
    (!is_numeric($sessionid) ? $response->addMessage("Session ID must be numeric") : false);
    $response->send();
    exit();
  }

  //check if access token is provided in the HTTP Authorization header and value is longer than 0 chars
  if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1)
  {
    //error response
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access token is missing from the header") : false);
    (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit();
  }

  //get access token from authorisation header
  $accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

  //delete request to delete a session/logout
  if($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    //query the database to check token details
    try {
      //db query to delete session where access token is equal to the one provided
      $query = $writeDB->prepare('DELETE FROM tbl_sessions WHERE id = :sessionid AND accesstoken = :accesstoken');
      $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
      $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
      $query->execute();

      //get row count
      $rowCount = $query->rowCount();

      //if a row is not found
      if($rowCount === 0) {
        //error response for unsuccessful logout
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Failed to log out of this session using access token provided");
        $response->send();
        exit();
      }

      //create response data which contains the session id that has been logged out
      $returnData = array();
      $returnData['session_id'] = intval($sessionid);

      //successful logout response
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage("Logged out");
      $response->setData($returnData);
      $response->send();
      exit();
    }
    catch(PDOException $ex) {
      //error response
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("There was an issue logging out - please try again");
      $response->send();
      exit();
    }
  }

  //PATCH request method to renew access token
  elseif($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    //check content type is json in request
    if($_SERVER['CONTENT_TYPE'] !== 'application/json') {
      //error response
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage("Content Type header not set to JSON");
      $response->send();
      exit();
    }

    //get PATCH request body as the patch data will be JSON format
    $rawPatchdata = file_get_contents('php://input');

    //decode the raw patch data
    if(!$jsonData = json_decode($rawPatchdata)) {
      //error resoibse
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage("Request body is not valid JSON");
      $response->send();
      exit();
    }

    //check patch request contains access token
    if(!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1)  {
      //error response
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      (!isset($jsonData->refresh_token) ? $response->addMessage("Refresh Token not supplied") : false);
      (strlen($jsonData->refresh_token) < 1 ? $response->addMessage("Refresh Token cannot be blank") : false);
      $response->send();
      exit();
    }

    //query the db to check token details
    try {
      //store refresh token in variable
      $refreshtoken = $jsonData->refresh_token;

      //db query to retrieve user details from access and refresh token
      $query = $writeDB->prepare('SELECT tbl_sessions.id AS sessionid, tbl_sessions.userid AS userid, accesstoken,
        refreshtoken, useractive, loginattempts, accesstokenexpiry, refreshtokenexpiry FROM tbl_sessions, tbl_users
        WHERE tbl_users.id = tbl_sessions.userid AND tbl_sessions.id = :sessionid AND tbl_sessions.accesstoken = :accesstoken
        AND tbl_sessions.refreshtoken = :refreshtoken');
      $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
      $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
      $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
      $query->execute();

      //get row count
      $rowCount = $query->rowCount();

      //if no rows are found
      if($rowCount === 0) {
        //unsuccessful access token refresh attempt
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Access Token or Refresh Token is incorrect for session id");
        $response->send();
        exit();
      }

      //get returned row
      $row = $query->fetch(PDO::FETCH_ASSOC);

      //save returned details into variables
      $returned_sessionid = $row['sessionid'];
      $returned_userid = $row['userid'];
      $returned_accesstoken = $row['accesstoken'];
      $returned_refreshtoken = $row['refreshtoken'];
      $returned_useractive = $row['useractive'];
      $returned_loginattempts = $row['loginattempts'];
      $returned_accesstokenexpiry = $row['accesstokenexpiry'];
      $returned_refreshtokenexpiry = $row['refreshtokenexpiry'];

      //check if account is active
      if($returned_useractive != 'Y') {
        //error response
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account is not active");
        $response->send();
        exit();
      }

      //check if account is locked out
      if($returned_loginattempts >= 3) {
        //error response
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account is currently locked out");
        $response->send();
        exit();
      }

      //check if refresh token has expired
      if(strtotime($returned_refreshtokenexpiry) < time()) {
        //error response
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Refresh token has expired - please log in again");
        $response->send();
        exit();
      }

      //generate access token - use 24 random bytes and encode this as base64. Add .time to append the time to the token for a unique token
      $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

      //generate refresh token - use 24 random bytes and encode this as base64. Add .time to append the time to the token for a unique token
      $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

      //set access token and refresh token expiry in seconds
      $access_token_expiry_seconds = 1200; //20 mins
      $refresh_token_expiry_seconds = 1209600; //14 days

      //query to update the current session in the sessions table and set the token and refresh token as well as their expiry dates and times
      $query = $writeDB->prepare('UPDATE tbl_sessions SET accesstoken = :accesstoken, accesstokenexpiry = date_add(NOW(),
      INTERVAL :accesstokenexpiryseconds SECOND), refreshtoken = :refreshtoken, refreshtokenexpiry = date_add(NOW(),
      INTERVAL :refreshtokenexpiryseconds SECOND) WHERE id = :sessionid AND userid = :userid
      AND accesstoken = :returnedaccesstoken AND refreshtoken = :returnedrefreshtoken');

      //bind the user id
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      //bind the session id
      $query->bindParam(':sessionid', $returned_sessionid, PDO::PARAM_INT);
      //bind the access token
      $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
      //bind the access token expiry date
      $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
      //bind the refresh token
      $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
      //bind the refresh token expiry date
      $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
      //bind the old access token for where clause as user could have multiple sessions
      $query->bindParam(':returnedaccesstoken', $returned_accesstoken, PDO::PARAM_STR);
      //bind the old refresh token for where clause as user could have multiple sessions
      $query->bindParam(':returnedrefreshtoken', $returned_refreshtoken, PDO::PARAM_STR);
      //run the query
      $query->execute();

      //get count of rows updated
      $rowCount = $query->rowCount();

      //if no rows are found
      if($rowCount === 0) {
        //error response
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Access token could not be refreshed - please log in again");
        $response->send();
        exit();
      }

      //response data array which contains session id, access token and refresh token
      $returnData = array();
      $returnData['session_id'] = $returned_sessionid;
      $returnData['access_token'] = $accesstoken;
      $returnData['access_token_expiry'] = $access_token_expiry_seconds;
      $returnData['refresh_token'] = $refreshtoken;
      $returnData['refresh_token_expiry'] = $refresh_token_expiry_seconds;

      //successful response
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage("Token refreshed");
      $response->setData($returnData);
      $response->send();
      exit();
    }
    catch(PDOException $ex) {
      //error response
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("There was an issue refreshing access token - please log in again");
      $response->send();
      exit();
    }

  }
  //error when not DELETE or PATCH request
  else {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit();
  }
}
//handle creating a new session
elseif(empty($_GET)) {
  //check to make sure the request is POST only
  if($_SERVER['REQUEST_METHOD'] !== 'POST') {

    //error response for non post requests
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit();
  }

  //delays login by 1 second - slows down any brute force attacks
  sleep(1);

  //check content type header is json
  if($_SERVER['CONTENT_TYPE'] !== 'application/json') {
    //error message for non json response
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Content Type header not set to JSON");
    $response->send();
    exit();
  }

  //get POST request body - will be in json format
  $rawPostData = file_get_contents('php://input');

  //decode the raw post data
  if(!$jsonData = json_decode($rawPostData)) {
    //unsuccessful response
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Request body is not valid JSON");
    $response->send();
    exit();
  }

  //check post request contains username and password in body as they are mandatory
  if(!isset($jsonData->username) || !isset($jsonData->password)) {
    //error resonse - using turnary operators to inform user if username and/or password is not supplied
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    (!isset($jsonData->username) ? $response->addMessage("Username not supplied") : false);
    (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);
    $response->send();
    exit();
  }

  //check that username and password are not empty and not greater than 255 characters
  if(strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255) {
    //error resonse using turnary operators to inform user of any issues
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    (strlen($jsonData->username) < 1 ? $response->addMessage("Username cannot be blank") : false);
    (strlen($jsonData->username) > 255 ? $response->addMessage("Username must be less than 255 characters") : false);
    (strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
    (strlen($jsonData->password) > 255 ? $response->addMessage("Password must be less than 255 characters") : false);
    $response->send();
    exit();
  }

  //query the database to check user details
  try {
    //set variables for username and password
    $username = $jsonData->username;
    $password = $jsonData->password;

    //db query
    $query = $writeDB->prepare('SELECT id, fullName, username, password, userActive, loginAttempts FROM tbl_users WHERE username = :username');
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    //get row count
    $rowCount = $query->rowCount();

    //if no user is found
    if($rowCount === 0) {
      //for unsuccessful login attempt
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage("Username or password is incorrect");
      $response->send();
      exit();
    }

    //get the first row returned
    $row = $query->fetch(PDO::FETCH_ASSOC);

    //create variables for all returned data
    $returned_id = $row['id'];
    $returned_fullname = $row['fullName'];
    $returned_username = $row['username'];
    $returned_password = $row['password'];
    $returned_useractive = $row['userActive'];
    $returned_loginattempts = $row['loginAttempts'];

    //check if account is active
    if($returned_useractive !== 'Y') {
      //error response for inactive user
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage("User account is not active");
      $response->send();
      exit();
    }

    //check if account is locked out - has exceeded the amount of login attempts allowed
    if($returned_loginattempts >= 3) {
      //error response for a locked account
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage("User account is currently locked out");
      $response->send();
      exit();
    }

    //check if password is the same using the hash
    if(!password_verify($password, $returned_password)) {
      //create query to add 1 to the login attempts
      $query = $writeDB->prepare('UPDATE tbl_users SET loginAttempts = loginAttempts+1 WHERE id = :id');
      //bind the user id
      $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
      //execute the query
    	$query->execute();

      //error response
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage("Username or password is incorrect");
      $response->send();
      exit();
    }

    //generate access token - use 24 random bytes and encode this as base64. Add .time to append the time to the token for a unique token
    $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

    //generate refresh token - use 24 random bytes and encode this as base64. Add .time to append the time to the token for a unique token
    $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

    //set access token and refresh token expiry in seconds
    $access_token_expiry_seconds = 1200; //20 minutes
    $refresh_token_expiry_seconds = 1209600; //14 days
  }
  catch(PDOException $ex) {
    //error response - no errror logging as error logs cant hold usernames + passwords in plain text
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue logging in - please try again");
    $response->send();
    exit();
  }
  //include a roll back on database
  try {
    //start transaction as two queries should run
    $writeDB->beginTransaction();
    //reset login attempts to 0 after successful login
    $query = $writeDB->prepare('UPDATE tbl_users SET loginAttempts = 0 WHERE id = :id');
    //bind the user id
    $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
    //execute the query
    $query->execute();

    //query to insert new session into sessions table and set the token and refresh token
    $query = $writeDB->prepare('INSERT INTO tbl_sessions (userid, accesstoken, accesstokenexpiry, refreshtoken, refreshtokenexpiry) VALUES (:userid, :accesstoken, date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), :refreshtoken, date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND))');
    //bind the user id
    $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
    //bind the access token
    $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
    //bind the access token expiry date
    $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
    //bind the refresh token
    $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
    //bind the refresh token expiry date
    $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
    //execute the query
    $query->execute();

    //get last session id to return the session id in the json
    $lastSessionID = $writeDB->lastInsertId();

    //commit new row and updates
    $writeDB->commit();

    //create response data array which contains the access token and refresh tokens
    $returnData = array();
    $returnData['session_id'] = intval($lastSessionID);
    $returnData['access_token'] = $accesstoken;
    $returnData['access_token_expires_in'] = $access_token_expiry_seconds;
    $returnData['refresh_token'] = $refreshtoken;
    $returnData['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

    //successful response
    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->setData($returnData);
    $response->send();
    exit();
  }
  catch(PDOException $ex) {
    //roll back update/insert if error
    $writeDB->rollBack();
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue logging in - please try again");
    $response->send();
    exit();
  }
}
//return error if endpoint is not found
else {
  $response = new Response();
  $response->setHttpStatusCode(404);
  $response->setSuccess(false);
  $response->addMessage("Endpoint not found");
  $response->send();
  exit();
}
