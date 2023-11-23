<?php

require_once('db.php');
require_once('../model/Book.php');
require_once('../model/Response.php');

//connect to database
try{
  //access static methods in the DB class
  $writeDB = DB::connectWriteDB();
  $readDB = DB::connectReadDB();
}
catch(PDOException $ex){
  //store error in the log file
  error_log("Connection error - ".$ex, 0);
  //if connection fails, output the 500 error
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("Database connection error");
  $response->send();
  exit();
}

//***************************************************************************************************
//start of authentication script
//check to see if access token is provided in the HTTP Authorization header and that the value is longer than 0 chars
if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1)
{
  //error response
  $response = new Response();
  $response->setHttpStatusCode(401);
  $response->setSuccess(false);
  (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access token is missing from the header") : false);
  (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
  $response->send();
  exit();
}

//get access token from authorisation header and store in variable
$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

//query the database to check token details
try {
  //db query to check access token is equal to the one provided
  $query = $writeDB->prepare('SELECT userid, accesstokenexpiry, useractive, loginattempts
    FROM tbl_sessions, tbl_users WHERE tbl_sessions.userid = tbl_users.id AND accesstoken = :accesstoken');
  //bind access token parameter
  $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
  //execute the query
  $query->execute();

  //get row count
  $rowCount = $query->rowCount();

  //if no row is found
  if($rowCount === 0) {
    //error response
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("Invalid access token");
    $response->send();
    exit();
  }

  //get returned row
  $row = $query->fetch(PDO::FETCH_ASSOC);

  //save returned details into variables
  $returned_userid = $row['userid'];
  $returned_accesstokenexpiry = $row['accesstokenexpiry'];
  $returned_useractive = $row['useractive'];
  $returned_loginattempts = $row['loginattempts'];

  //check if account is active
  if($returned_useractive != 'Y') {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("User account is not active");
    $response->send();
    exit();
  }

  //check if account is locked out
  if($returned_loginattempts >= 3) {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("User account is currently locked out");
    $response->send();
    exit();
  }

  //check if access token has expired
  if(strtotime($returned_accesstokenexpiry) < time()) {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("Access token has expired");
    $response->send();
    exit();
  }
}
catch(PDOException $ex) {
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("There was an issue authenticating - please try again");
  $response->send();
  exit();
}
//end of authentication script
//***************************************************************************************************

//check if a book exists
if(array_key_exists("bookid",$_GET)){
  $bookid = $_GET['bookid']; //within get array, get the book id

  $asXML = false;

  //check for xml output
  if(array_key_exists("asxml", $_GET)){
    $asXML = true;
  }

  //check if book id is blank or non numeric
  if($bookid == '' || !is_numeric($bookid)){
    $response = new Response();
    $response->setHttpStatusCode(400); //client error - incorrect data provided by client
    $response->setSuccess(false);
    $response->addMessage("Book ID cannot be blank or must be numeric");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit();
  }

  //check type of request method - cannot use POST method here as this data already exists
  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    //get the book from the DB
    try{
      //sql query to identify the correct book
      $query = $readDB->prepare('SELECT id, title, description, author, publication_year, price,
        completed FROM tbl_books WHERE id = :bookid AND userid = :userid');
      $query->bindParam(':bookid', $bookid, PDO::PARAM_INT); //bind the value of book id to the query
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT); //bind the value of userid id to the query
      $query->execute(); //execute the query

      //check the row count
      $rowCount = $query->rowCount();

      //if there are no rows, send a response to the client
      if($rowCount === 0){
        $response = new Response();
        $response->setHttpStatusCode(404); //data not found
        $response->setSuccess(false);
        $response->addMessage("Book not found");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //if data does exist, retrieve the data
      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        //from the values in the DB, create a book using the Book model
        $book = new Book($row['id'], $row['title'], $row['description'], $row['author'],
        $row['publication_year'], $row['price'], $row['completed']);

        //store book in the books array
        $bookArray[] = $book->returnBookAsArray();
      }

      //create return data array
      $returnData = array();
      //add books and the amount of rows returned to the return data array
      $returnData['rows_returned'] = $rowCount;
      $returnData['books'] = $bookArray;

      //create a success response for client
      $response = new Response();
      $response->setHttpStatusCode(200); //200 is a success code
      $response->setSuccess(true);
      $response->toCache(true); //cache this response if client tries to return same book multiple times in short timeframe
      $response->setData($returnData); //return data array contains list of books and row count
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(TaskException $ex){
      //create a server error response to client
      $response = new Response();
      $response->setHttpStatusCode(500); //500 is a server error code
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage); //get the correct error message from Book model
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit;
    }
    catch(PDOException $ex){
      //store error in the log file
      error_log("Database query error - ".$ex, 0);
      //if connection fails, output the 500 error
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to get book");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
  }
  elseif($_SERVER['REQUEST_METHOD'] === 'DELETE'){
    try{
      //query to delete a row
      $query = $writeDB->prepare('DELETE FROM tbl_books WHERE id = :bookid AND userid = :userid');
      $query->bindParam(':bookid', $bookid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      //rowcount of the number of rows deleted
      $rowCount = $query->rowCount();

      if($rowCount === 0){
        //unsuccessful return response as no rows deleted
        $response = new Response();
        $response->setHttpStatusCode(404); //book not found
        $response->setSuccess(false);
        $response->addMessage("Book not found");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //response for a successful deletion of a book
      $response = new Response();
      $response->setHttpStatusCode(200); //ok status code
      $response->setSuccess(true);
      $response->addMessage("Book deleted");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(PDOException $ex){
      //server error response
      $response = new Response();
      $response->setHttpStatusCode(500); //server error
      $response->setSuccess(false);
      $response->addMessage("Failed to delete book");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
  }
  elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){
    try{
      //check if data is not json
      if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Content Type header not set to JSON");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //get the json data passed in from the body
      $rawPatchData = file_get_contents('php://input');

      //check it is json data that has been passed in
      if(!$jsonData = json_decode($rawPatchData)){
        //send error message
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Request body is not valid JSON");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //create variables for updated data
      $title_updated = false;
      $description_updated = false;
      $author_updated = false;
      $publication_year_updated = false;
      $price_updated = false;
      $completed_updated = false;

      //create an empty string to append to when values are passed in
      $queryFields = "";

      //if the title has been passed into the update
      if(isset($jsonData->title)){
        //set title updated to true as title will be updated
        $title_updated = true;
        //append this to the query fields variable
        $queryFields .= "title = :title, ";
      }

      //if the description has been passed into the update
      if(isset($jsonData->description)){
        //set descripton updated to true as description will be updated
        $description_updated = true;
        //append this to the query fields variable
        $queryFields .= "description = :description, ";
      }

      //if the author has been passed into the update
      if(isset($jsonData->author)){
        //set author updated to true as author will be updated
        $author_updated = true;
        //append this to the query fields variable
        $queryFields .= "author = :author, ";
      }

      //if the publication_year has been passed into the update
      if(isset($jsonData->publication_year)){
        //set publication_year updated to true as publication_year will be updated
        $publication_year_updated = true;
        //append this to the query fields variable
        $queryFields .= "publication_year = :publication_year, ";
      }

      //if the price has been passed into the update
      if(isset($jsonData->price)){
        //set price updated to true as price will be updated
        $price_updated = true;
        //append this to the query fields variable
        $queryFields .= "price = :price, ";
      }

      //if the completed has been passed into the update
      if(isset($jsonData->completed)){
        //set completed updated to true as completed will be updated
        $completed_updated = true;
        //append this to the query fields variable
        $queryFields .= "completed = :completed, ";
      }

      //remove the last comma from the query fields string for the sql query to run successfully
      $queryFields = rtrim($queryFields, ", ");

      //check that some data has been provided in the patch request
      if($title_updated === false && $description_updated === false && $author_updated === false && $publication_year_updated === false && $price_updated === false && $completed_updated === false){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("No book fields have been provided");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //retrieve the existing task from the url and execute sql
      $query = $writeDB->prepare('SELECT id, title, description, author, publication_year, price,
        completed FROM tbl_books WHERE id = :bookid AND userid = :userid');
      $query->bindParam(':bookid', $bookid, PDO::PARAM_INT); //bind the value of book id to the query
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT); //bind the value of userid id to the query
      $query->execute(); //execute the query

      //get a row COUNT
      $rowCount = $query->rowCount();

      //check row count is not 0
      if($rowCount === 0){
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("No book found to update");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //if a row has been found
      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $book = new Book($row['id'], $row['title'], $row['description'], $row['author'], $row['publication_year'], $row['price'], $row['completed']);
      }

      //create query to update the book in database
      $queryString = "UPDATE tbl_books SET ".$queryFields." WHERE id = :bookid AND userid = :userid";
      $query = $writeDB->prepare($queryString);

      //check if title has been updated and set to new variable
      if($title_updated === true){
        $book->setTitle($jsonData->title);
        $up_title = $book->getTitle();
        $query->bindParam(':title', $up_title, PDO::PARAM_STR);
      }

      //check if description has been updated and set to new variable
      if($description_updated === true) {
        $book->setDescription($jsonData->description);
        $up_description = $book->getDescription();
        $query->bindParam(':description', $up_description, PDO::PARAM_STR);
      }

      //check if author has been updated and set to new variable
      if($author_updated === true){
        $book->setAuthor($jsonData->author);
        $up_author = $book->getAuthor();
        $query->bindParam(':author', $up_author, PDO::PARAM_STR);
      }

      //check if publication_year has been updated and set to new variable
      if($publication_year_updated === true){
        $book->setPublicationYear($jsonData->publication_year);
        $up_publication_year = $book->getPublicationYear();
        $query->bindParam(':publication_year', $up_publication_year, PDO::PARAM_STR);
      }

      //check if price has been updated and set to new variable
      if($price_updated === true){
        $book->setPrice($jsonData->price);
        $up_price = $book->getPrice();
        $query->bindParam(':price', $up_price, PDO::PARAM_STR);
      }

      //check if completed has been updated and set to new variable
      if($completed_updated === true){
        $book->setCompleted($jsonData->completed);
        $up_completed = $book->getCompleted();
        $query->bindParam(':completed', $up_completed, PDO::PARAM_STR);
      }

      //bind the book id and user id parameter
      $query->bindParam(':bookid', $bookid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      //check if it has successfully updated the row
      $rowCount = $query->rowCount();

      //if row count is 0 then there has been an error
      if($rowCount === 0){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Book not updated");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //retrieve the updated row out of the database and return to the client
      $query = $writeDB->prepare('SELECT * FROM tbl_books WHERE id = :bookid AND userid = :userid');
      $query->bindParam(':bookid', $bookid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      //check the row count
      $rowCount = $query->rowCount();

      //check row count is not 0
      if($rowCount === 0){
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("No book found after update");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //create a book array
      $bookArray = array();

      //create a new book
      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $book = new Book($row['id'], $row['title'], $row['description'], $row['author'], $row['publication_year'], $row['price'], $row['completed']);
        $bookArray[] = $book->returnBookAsArray();
      }

      //create return data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['books'] = $bookArray;

      //create successful response
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage("Book updated");
      $response->setData($returnData);
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(TaskException $ex){
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(PDOException $ex){
      error_log("Database query error - ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500); //server error response
      $response->setSuccess(false);
      $response->addMessage("Failed to update book - check your data for errors");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
  }
  else{
    //standard error Response
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit();
  }
}

//checks for the completed status of the book
elseif(array_key_exists("completed", $_GET)){
  $completed = $_GET['completed']; //get the value of completed within the query

  $asXML = false;

  //check for xml output
  if(array_key_exists("asxml", $_GET)){
    $asXML = true;
  }

  //check if completed is not set to Y or N
  if($completed !== 'Y' && $completed !== 'N'){
    //send an error response
    $response = new Response();
    $response->setHttpStatusCode(400); //client error - incorrect data provided by client
    $response->setSuccess(false);
    $response->addMessage("Completed filter must be Y or N");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit();
  }
  //check to see if the response is a GET response
  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    //retieve all tasks with a completed status of Y or N
    try{
      //sql query for completed or incompleted tasks
      $query = $readDB->prepare('SELECT id, title, description, author, publication_year,
        price, completed FROM tbl_books WHERE completed = :completed AND userid = :userid');
      //bind the parameter of completed status + userid
      $query->bindParam(':completed', $completed, PDO::PARAM_STR);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_STR);
      //execute the query
      $query->execute();

      //check the row count
      $rowCount = $query->rowCount();

      //create a book array
      $bookArray = array();

      //use a while to filter through any results
      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $book = new Book($row['id'], $row['title'], $row['description'], $row['author'], $row['publication_year'], $row['price'], $row['completed']);
        //append book to the array
        $bookArray[] = $book->returnBookAsArray();
      }

      //return data array
      $returndata = array();
      //show how many rows have been returned
      $returnData['rows_returned'] = $rowCount;
      //show how many books have been returned
      $returnData['books'] = $bookArray;

      //send a success response
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch (TaskException $ex){
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch (PDOException $ex){
      error_log("Database query error - ".$ex, 0); //store error in log. Use 0 to store error in log for php
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to get books");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
  }
  else{
    //send an error response
    $response = new Response();
    $response->setHttpStatusCode(405); //not valid, can only return completed/incompleted books
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit();
  }
}
//this section will handle pagination for the books
elseif(array_key_exists("page", $_GET)){
  //only a get request can be used
  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    //get the page number
    $page = $_GET['page'];

    $asXML = false;

    //check for xml output
    if(array_key_exists("asxml", $_GET)){
      $asXML = true;
    }

    //check if page value is a valid value
    if($page == '' || !is_numeric($page)){
      //send an error response as value is not valid
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage("Page number cannot be blank and must be numeric");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }

    //set a limit of books per page to 20
    $limitPerPage = 20;

    try{
      //query to get a count of books in the database
      $query = $readDB->prepare('SELECT COUNT(id) as totalNoOfBooks FROM tbl_books WHERE userid = :userid');
      //bind user id parameter
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $row = $query->fetch(PDO::FETCH_ASSOC);

      //check the value returned is an int
      $booksCount = intval($row['totalNoOfBooks']);

      //check how many pages is needed for the amount of books
      $numOfPages = ceil($booksCount/$limitPerPage); //use ceil to round val up if decimal

      //if no books are present, set pages to 1 to still display one page
      if($numOfPages == 0){
        $numOfPages == 1;
      }

      //check if page number is 0 or greater than the total number of pages
      if($page > $numOfPages || $page == 0){
        //send an error response
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("Page not found");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //if page = 1, then start at 0 for 0 and above, otherwise -1 from the page and * by 20
      $offset = ($page == 1 ? 0 : ($limitPerPage*($page-1)));

      //query the database limiting the query to 20 results and starting at the correct row
      $query = $readDB->prepare('SELECT * FROM tbl_books WHERE userid = :userid LIMIT :pglimit offset :offset');
      //bind the parameters to the query
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->bindParam(':pglimit', $limitPerPage, PDO::PARAM_INT);
      $query->bindParam(':offset', $offset, PDO::PARAM_INT);
      $query->execute();

      //get the row count
      $rowCount = $query->rowCount();
      //create a book array
      $bookArray = array();

      //iterate through each row to create a book
      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $book = new Book($row['id'], $row['title'], $row['description'], $row['author'], $row['publication_year'], $row['price'], $row['completed']);
        //append book to the array
        $bookArray[] = $book->returnBookAsArray();
      }

      //create the return data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['total_rows'] = $booksCount;
      $returnData['total_pages'] = $numOfPages;
      //if passed in page is < total num of pages then allow next page will be true, otherwise allow next page will be false
      ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
      //if passed in page is < 1 then allow previous page will be true, otherwise allow previous page will be false
      ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);
      $returnData['books'] = $bookArray;

      //successful response
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(TaskException $ex){
      $response = new Response();
      $response->setHttpStatusCode(500); //server error response
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(PDOException $ex){
      error_log("Database query error - ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500); //server error response
      $response->setSuccess(false);
      $response->addMessage("Failed to get books");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
  }
  //if request is not GET, output an error response
  else{
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit();
  }
}
//check to see if the $_GET is empty
elseif(empty($_GET)){
  //request can only be get or post
  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    try{
      //selecting all books from the db
      $query = $readDB->prepare('SELECT * FROM tbl_books WHERE userid = :userid');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      //create a row count for num of rows
      $rowCount = $query->rowCount();

      //create a book array
      $bookArray = array();

      //use a while to fiter through any results
      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $book = new Book($row['id'], $row['title'], $row['description'], $row['author'], $row['publication_year'], $row['price'], $row['completed']);

        //append book to the book array
        $bookArray[] = $book->returnBookAsArray();
      }

      //create a return data array
      $returnData = array();
      //return the row count
      $returnData['rows_returned'] = $rowCount;
      //return the book array
      $returnData['books'] = $bookArray;

      //successful response
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(TaskException $ex){
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(PDOException $ex){
      error_log("Database query error - ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to get books");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
  }
  elseif($_SERVER['REQUEST_METHOD'] === 'POST'){
    try{
      $asXML = false;

      //check for xml output
      if(array_key_exists("asxml", $_POST)){
        $asXML = true;
      }

      //check content type is set to json
      if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
        //error Response
        $response = new Response();
        $response->setHttpStatusCode(400); //client error - incorrect data provided by client
        $response->setSuccess(false);
        $response->addMessage("Content type header is not set to JSON");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //get data passed in in the request body using php input
      $rawPOSTData = file_get_contents('php://input'); //file_get_contents reads contents of a file

      //ensure input is valid json . Use ! to check the if the data is not valid
      if(!$jsonData = json_decode($rawPOSTData)){
        //error Response
        $response = new Response();
        $response->setHttpStatusCode(400); //client error - incorrect data provided by client
        $response->setSuccess(false);
        $response->addMessage("Request body is not valid JSON");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //check if user has not entered title and completed status as these are mandatory fields
      if(!isset($jsonData->title) || !isset($jsonData->completed)){
        //error Response
        $response = new Response();
        $response->setHttpStatusCode(400); //client error - incorrect data provided by client
        $response->setSuccess(false);
        //check if title has not been entered, if not then send the title error message
        (!isset($jsonData->title) ? $response->addMessage("Title field is mandatory and must be provided") : false);
        //check if completed status has not been entered, if not then send the completed error message
        (!isset($jsonData->completed) ? $response->addMessage("Completed field is mandatory and must be provided") : false);
        $response->send();
        exit();
      }

      //create a new book - if data is not required, then use the turnary operator to see if the data exists or not
      $newBook = new Book(null, $jsonData->title, (isset($jsonData->description) ? $jsonData->description : null),
      (isset($jsonData->author) ? $jsonData->author : null), (isset($jsonData->publication_year) ? $jsonData->publication_year : null),
      (isset($jsonData->price) ? $jsonData->price : null), $jsonData->completed);

      //store values in variables
      $title = $newBook->getTitle();
      $description = $newBook->getDescription();
      $author = $newBook->getAuthor();
      $publicationYear = $newBook->getPublicationYear();
      $price = $newBook->getPrice();
      $completed = $newBook->getCompleted();

      //query to insert data into the books table
      $query = $writeDB->prepare('INSERT INTO tbl_books (title, description, author, publication_year, price, completed, userid)
      VALUES (:title, :description, :author, :publication_year, :price, :completed, :userid)');
      $query->bindParam(':title', $title, PDO::PARAM_STR);
      $query->bindParam(':description', $description, PDO::PARAM_STR);
      $query->bindParam(':author', $author, PDO::PARAM_STR);
      $query->bindParam(':publication_year', $publicationYear, PDO::PARAM_STR);
      $query->bindParam(':price', $price, PDO::PARAM_STR);
      $query->bindParam(':completed', $completed, PDO::PARAM_STR);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      //get a row count to check how many rows inserted
      $rowCount = $query->rowCount();

      //check if the insert query has failed
      if($rowCount === 0){
        //error Response
        $response = new Response();
        $response->setHttpStatusCode(500); //server error
        $response->setSuccess(false);
        $response->addMessage("Failed to create a new book");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //fetch the inserted row from the database
      $lastTaskID = $writeDB->lastInsertId(); //will only receive the last id inserted by this user as only available for this session

      //select the last record that has been inserted
      $query = $writeDB->prepare('SELECT id, title, description, author, publication_year,
        price, completed FROM tbl_books WHERE id = :bookid AND userid = :userid');
      $query->bindParam(':bookid', $lastTaskID, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      //get the row count
      $rowCount = $query->rowCount();

      //if the row cannot be found, produce error response
      if($rowCount === 0){
        //error Response
        $response = new Response();
        $response->setHttpStatusCode(500); //server error
        $response->setSuccess(false);
        $response->addMessage("Failed to retrieve book after creation");
        $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
        exit();
      }

      //create a book array
      $bookArray = array();

      //if row has been found, get the row and send success response
      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $book = new Book($row['id'], $row['title'], $row['description'], $row['author'], $row['publication_year'], $row['price'], $row['completed']);

        //append book to bookArray
        $bookArray[] = $book->returnBookAsArray();
      }

      //create the return data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['books'] = $bookArray;

      //create a successful response
      $response = new Response();
      $response->setHttpStatusCode(201); //successful creation code
      $response->setSuccess(true);
      $response->addMessage("Book created");
      $response->setData($returnData);
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(TaskException $ex){
      $response = new Response();
      $response->setHttpStatusCode(400); //client error - incorrect data provided by client
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
    catch(PDOException $ex){
      error_log("Database query error - ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to insert book into database - check submitted data for errors");
      $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
      exit();
    }
  }
  else{
    //send error response if request is delete
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
    exit();
  }
}
else{
  //send an error response that the endpoint was not found
  $response = new Response();
  $response->setHttpStatusCode(404);
  $response->setSuccess(false);
  $response->addMessage("Endpoint not found");
  $asXML === true ? $response->sendXML() : $response->send(); //turnary operator to send JSON or XML depending on parameters set
  exit();
}

 ?>
