<?php
class SophaModule extends ZoopModule
{
    const COUCH_PORT = 5984;
	private static $connections = array();
    
    protected $hasConfig = false;

	/**
	 * Returns a Sopha_Db object to the database called "$name"
	 *
	 * @param string $name
	 * @return Sopha_Db
	 */
	static function getConnection($name)
	{
		if(!isset(self::$connections[$name]))
			trigger_error("connection '$name' does not exist");
		return self::$connections[$name];
	}
	
	/**
	 * Returns a Sopha_Db object for the default database connection
	 *
	 * @return Sopha_Db
	 */
	static function getDefaultConnection()
	{
		return self::getConnection('default');
	}

    /**
     * Creates a new connection, saves it in the collection, and returns a the object
     *
     * @return Sopha_Db
     **/
    static function newConnection($name, $host = 'localhost', $port = self::COUCH_PORT, $dbName = null)
    {
        if ($dbName === null) $dbName = $name;
        if (isset(self::$connections[$name]))
            trigger_error("Connection '$name' already exists");

        self::$connections[$name] = new Sopha_Db($dbName, $host, $port);
        return self::getConnection($name);
    }
	
	protected function init()
	{
		$this->addClass('Sopha_Db');
		$this->addClass('Sopha_Document');
		$this->addClass('Sopha_Exception');
        $this->addClass('Sopha_Json');
        $this->addClass('Sopha_Db_Exception', $this->path . '/Db/Exception.php');
        $this->addClass('Sopha_Document_Exception', $this->path . '/Document/Exception.php');
        $this->addClass('Sopha_Document_Attachment', $this->path . '/Document/Attachment.php');
        $this->addClass('Sopha_Http_Exception', $this->path . '/Http/Exception.php');
        $this->addClass('Sopha_Http_Request', $this->path . '/Http/Request.php');
        $this->addClass('Sopha_Http_Response', $this->path . '/Http/Response.php');
        $this->addClass('Sopha_Json_Exception', $this->path . '/Json/Exception.php');
        $this->addClass('Sopha_Json_Encoder', $this->path . '/Json/Encoder.php');
        $this->addClass('Sopha_Json_Decoder', $this->path . '/Json/Decoder.php');
        $this->addClass('Sopha_View_Result', $this->path . '/View/Result.php');
        $this->addClass('Sopha_View_Result_Exception', $this->path . '/View/Result/Exception.php');
		//$this->depend('');
    }

    protected function configure()
    {
		$connections = $this->getConfig();
		if($connections)
		{
			foreach($connections as $name => $params)
            {
                if (!isset($params["host"]))
                {
                    $params["host"] = "localhost";
                }
                if (!isset($params["port"]))
                {
                    $params["port"] = $this->COUCH_PORT;
                }
                if (!isset($params["database"]))
                {
                    $params["database"] = $name;
                }
                self::newConnection($name, $params["host"], $params["port"], $params["database"]);
			}
		}
    }
}
