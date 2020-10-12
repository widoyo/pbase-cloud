<?php

/*
    http://php.net/manual/en/function.session-start.php
    by: linblow at hotmail dot fr
    
    Use the static method getInstance to get the object.
*/

// namespace App;

class Session
{
    const SESSION_STARTED = TRUE;
    const SESSION_NOT_STARTED = FALSE;

    // app key
    private $appKey = "";
    
    // The state of the session
    private $sessionState = self::SESSION_NOT_STARTED;
    
    // THE only instance of the class
    private static $instance;
    
    
    private function __construct() 
    {
        $this->appKey = isset($_ENV['APP_KEY']) ? $_ENV['APP_KEY'] : base64_encode('IAmTheBoneOfMySwordSteelIsMyBodyAndFireIsMyBlood');
    }
    
    
    /**
    *    Returns THE instance of 'Session'.
    *    The session is automatically initialized if it wasn't.
    *    
    *    @return    object
    **/
    
    public static function getInstance()
    {
        if ( !isset(self::$instance))
        {
            self::$instance = new self;
        }
        
        self::$instance->startSession();
        
        return self::$instance;
    }
    
    
    /**
    *    (Re)starts the session.
    *    
    *    @return    bool    TRUE if the session has been initialized, else FALSE.
    **/
    
    public function startSession()
    {
        if ( $this->sessionState == self::SESSION_NOT_STARTED )
        {
            $this->sessionState = session_status() == PHP_SESSION_NONE ? session_start() : self::SESSION_STARTED;
        }
        
        return $this->sessionState;
    }
    
    
    /**
    *    Stores datas in the session.
    *    Example: $instance->foo = 'bar';
    *    
    *    @param    name    Name of the datas.
    *    @param    value    Your datas.
    *    @return    void
    **/
    
    public function __set( $name , $value )
    {
        $_SESSION[$this->appKey .'_'. $name] = $value;
    }
    
    
    /**
    *    Gets datas from the session.
    *    Example: echo $instance->foo;
    *    
    *    @param    name    Name of the datas to get.
    *    @return    mixed    Datas stored in session.
    **/
    
    public function __get( $name )
    {
        if ( isset($_SESSION[$this->appKey .'_'. $name]))
        {
            return $_SESSION[$this->appKey .'_'. $name];
        }
    }
    
    
    public function __isset( $name )
    {
        return isset($_SESSION[$this->appKey .'_'. $name]);
    }
    
    
    public function __unset( $name )
    {
        unset( $_SESSION[$this->appKey .'_'. $name] );
    }
    
    
    /**
    *    Destroys the current session.
    *    
    *    @return    bool    TRUE is session has been deleted, else FALSE.
    **/
    
    public function destroy()
    {
        if ( $this->sessionState == self::SESSION_STARTED )
        {
            $this->sessionState = !session_destroy();
            unset( $_SESSION );
            
            return !$this->sessionState;
        }
        
        return FALSE;
    }
}
