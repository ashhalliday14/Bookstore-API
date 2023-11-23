<?php

  class TaskException extends Exception { }

  class Book {
    //variables for all data needed for books
    private $_id;
    private $_title;
    private $_description;
    private $_author;
    private $_publicationYear;
    private $_price;
    private $_completed;

    //construct method
    public function __construct($id, $title, $description, $author, $publicationYear, $price, $completed){
      //call all the set methods for the values passed into the method
      $this->setID($id);
      $this->setTitle($title);
      $this->setDescription($description);
      $this->setAuthor($author);
      $this->setPublicationYear($publicationYear);
      $this->setPrice($price);
      $this->setCompleted($completed);
    }

    //get id
    public function getID(){
      return $this->_id;
    }

    //get the title
    public function getTitle(){
      return $this->_title;
    }

    //get description
    public function getDescription(){
      return $this->_description;
    }

    //get the author
    public function getAuthor(){
      return $this->_author;
    }

    //get the publication year
    public function getPublicationYear(){
      return $this->_publicationYear;
    }

    //get the price
    public function getPrice(){
      return $this->_price;
    }

    //get the completed status
    public function getCompleted(){
      return $this->_completed;
    }

    //set the ID
    public function setID($id){
      //check the value coming in is between possible ID values, and ID cannot be overrided once set
      if(($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)) {
        throw new TaskException("Book ID error");
      }
      //if id is valid, then set to value of $id
      $this->_id = $id;
    }

    //set the title
    public function setTitle($title){
      //check title is correct length for db
      if(strlen($title) < 0 || strlen($title) > 255){
        throw new TaskException("Book title error");
      }
      //if title is valid, then set the value
      $this->_title = $title;
    }

    //set the description
    public function setDescription($description){
      //check description is not null and size is correct for DB
      if(($description !== null) && (strlen($description) > 16777215)){
        throw new TaskException("Book description error");
      }
      //if description is valid, then set the value
      $this->_description = $description;
    }

    //set the author
    public function setAuthor($author){
      //check author is correct length for db
      if(strlen($author) < 0 || strlen($author) > 255){
        throw new TaskException("Book author error");
      }
      //if author is valid, then set the value
      $this->_author = $author;
    }

    //set the publication year
    public function setPublicationYear($publicationYear){
      //check if year is not null
      if(($publicationYear !== null) && date_format(date_create_from_format('Y', $publicationYear), 'Y') != $publicationYear){
        throw new TaskException("Book publication year date error");
      }
      //if publication year is valid, set the value
      $this->_publicationYear = $publicationYear;
    }

    //set the price
    public function setPrice($price){
      //check if price is valid
      if(($price !== null) && (!is_numeric($price) || $price < 0)){
        throw new TaskException("Book price error");
      }
      //if price is valid, set the value
      $this->_price = $price;
    }

    //set the completed status
    public function setCompleted($completed){
      //check for valid characters used - Y/N
      if(strtoupper($completed) !== 'Y' && strtoupper($completed) !== 'N'){
        throw new TaskException("Book completed must be Y or N");
      }
      //if completed is valid, set the value
      $this->_completed = $completed;
    }

    //convert format into array for JSON
    public function returnBookAsArray(){
      $book = array(); //create a book array
      //set the data in the array
      $book['id'] = $this->getID();
      $book['title'] = $this->getTitle();
      $book['description'] = $this->getDescription();
      $book['author'] = $this->getAuthor();
      $book['publicationYear'] = $this->getPublicationYear();
      $book['price'] = $this->getPrice();
      $book['completed'] = $this->getCompleted();
      return $book;
    }
  }

 ?>
