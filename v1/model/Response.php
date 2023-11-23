<?php

//standard json response class
  class Response {

    //private variables to store data until ready to send response back
    private $_success;
    private $_httpStatusCode;
    private $_messages = array();
    private $_data;
    private $_toCache = false; //can cache response to a request - prevents any additional load on server
    private $_responseData = array();

    //set values to private variables
    public function setSuccess($success){
      $this->_success = $success; //setting value of success passed into the function to the instance variable of $_success
    }

    public function setHttpStatusCode($httpStatusCode){
      $this->_httpStatusCode = $httpStatusCode; //set value of httpStatusCode passed into the functon to the instance variable
    }

    public function addMessage($message){
      $this->_messages[] = $message; //adding to the message array with the value of $message
    }

    public function setData($data){
      $this->_data = $data; //set value of message passed into the function to the instance variable of $_data
    }

    public function toCache($toCache){
      $this->_toCache = $toCache; //set value of toCache passed into the function to the value of $_toCache
    }

    //send function provides the response back to the browser
    public function send(){  //no arguments as uses data in the instance of response data
      header('Content-type: application/json;charset=utf-8'); //tells client the type of data that will be returned

      //if the cache variable has been set to true
      if($this->_toCache == true){
        header('Cache-control: max-age=60'); //tells client to cache for a max of 60 seconds
      }
      else{
        header('Cache-control: no-cache, no-store'); //no cache and response cannot be stored at all on the client, will have to come back to server for a response
      }

      //if no data has been set to the instance variables
      if(($this->_success !== false && $this->_success !== true) || !is_numeric($this->_httpStatusCode)) { //checks if success is not set to false and not set to true, or status code is not numeric
        http_response_code(500); //server error code
        $this->_responseData['statusCode'] = 500; //allows end user to see the status error code
        $this->_responseData['success'] = false; //success will be false
        $this->addMessage("Response creation error"); //returns a message to the user
        $this->_responseData['messages'] = $this->_messages; //returns the messages
      }
      //successful response
      else{
        http_response_code($this->_httpStatusCode); //returns the variable with status code
        $this->_responseData['statusCode'] = $this->_httpStatusCode;
        $this->_responseData['success'] = $this->_success;
        $this->_responseData['messages'] = $this->_messages;
        $this->_responseData['data'] = $this->_data;
      }

      echo json_encode($this->_responseData); //echo out response data to the user
    }

    //return xml response
    function arrayToXML($array, $node, &$dom){
      foreach ($array as $key => $value) {
        if(preg_match("/^[0-9]/", $key)){
          $key = $key + 1;
          $key = "Book-{$key}";
        }

        $key = preg_replace("/[^a-z0-9_\-]+/i", '', $key);

        if($key===''){
          $key = '_';
        }

        $element = $dom->createElement($key);
        $node->appendChild($element);

        if(!is_array($value)){
          $element->appendChild($dom->createTextNode($value));
        }
        else{
          $this->arrayToXML($value, $element, $dom);
        }
      }
    }

    //send xml response to client
    public function sendXML(){
      header("Content-type: application/xml;charset=utf-8");

      if($this->_toCache === true){
        header("Cache-control: max-age-60");
      }
      else{
        header("Cache-control: no-store");
      }

      if(($this->_success !== true && $this->_success !== false) ||
        !is_numeric($this->_httpStatusCode)){
          $this->_responseData['success'] = "false";
          http_response_code(500);
          $this->_responseData['statusCode'] = 500;
          $this->addMessage("500 internal server error");
          $this->_responseData['messages'] = $this->_message;
        }
        else{
          $this->_success === true ? $this->_responseData['success'] = "true" : $this->_responseData['success'] = "false";
          http_response_code($this->_httpStatusCode);
          $this->_responseData['statusCode'] = $this->_httpStatusCode;
          $this->_responseData['messages'] = $this->_messages;
          $this->_responseData['data'] = $this->_data;
        }

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $root = $dom->createElement('Response');
        $dom->appendChild($root);

        $this->arrayToXML($this->_responseData, $root, $dom);

        echo $dom->saveXML();
    }
  }

 ?>
