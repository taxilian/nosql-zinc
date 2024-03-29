<?php

/**
 * Sopha - A PHP 5.x Interface to CouchDB
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://prematureoptimization.org/sopha/license/new-bsd
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @package    Sopha
 * @subpackage Db
 * @license    http://prematureoptimization.org/sopha/license/new-bsd
 */

class Sopha_Db
{
    const COUCH_PORT = 5984;

    protected $_lastEtag = "";
    protected $_db_uri;

    /**
     * Create a new DB connector
     *
     * @param string  $dbname Database name
     * @param string  $host   Database host - defaults to localhost
     * @param integer $port   Database port - defaults to 5984
     */
    public function __construct($dbname, $host = 'localhost', $port = self::COUCH_PORT)
    {
        $this->_db_uri = self::makeDbUrl($dbname, $host, $port);
    }

    /**
     * Get info about the current database
     *
     * @return unknown
     */
    public function getInfo()
    {
        $response = Sopha_Http_Request::get($this->_db_uri);

        if (! $response->isSuccess()) {
            switch($response->getStatus()) {
                case 404:
                    throw new Sopha_Db_Exception("Database does not exist");
                    break;

                default:
                    throw new Sopha_Db_Exception("Unexpected response from server: " .
                        "{$response->getStatus()} {$response->getMessage()}", $response>getStatus());
                    break;
            }
        }
        $this->_lastEtag = trim($response->getHeader("Etag"), '"');

        return $response->getDocument();
    }

    /**
     * getLastEtag returns the Etag for the last couchdb request made from this object
     *
     * @access public
     * @return void
     */
    public function getLastEtag()
    {
        return $this->_lastEtag;
    }

    /**
     * Get all documents from DB
     *
     * @param  string  $startKey   Key to start from
     * @param  integer $limit      Limit result set size
     * @param  boolean $descending Descending order
     * @return array
     */
    public function getAllDocs($startKey = null, $limit = null, $descending = false)
    {
        $request = new Sopha_Http_Request($this->_db_uri . '_all_docs');

        if ($startKey !== null) $request->addQueryParam('startkey', $startKey);
        if ($limit !== null) $request->addQueryParam('limit', $limit);
        if ($descending) $request->addQueryParam('descending', 'true');

        $response = $request->send();

        if (! $response->isSuccess()) {
            switch($response->getStatus()) {
                case 404:
                    throw new Sopha_Db_Exception("Database does not exist");
                    break;

                default:
                    throw new Sopha_Db_Exception("Unexpected response from server: " .
                        "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                    break;
            }
        }

        $this->_lastEtag = trim($response->getHeader("Etag"), '"');
        $doc = $response->getDocument();
        return $doc['rows'];
    }

    /**
     * Document CRUD methods
     */

    /**
     * Create a new document and save it in DB. Will return the new Document object
     *
     * @param  mixed  $data
     * @param  string $doc
     * @return Sopha_Document
     */
    public function create($data, $doc = null)
    {

        $url = $this->_db_uri;
        if ($doc) {
            if (substr($doc, 0, 4) != "http") {
                $doc = self::encodeDocPath($doc);
                $docURL = $url . urlencode($doc);
            } else {
                $docURL = $doc;
            }

            $response = Sopha_Http_Request::put($docURL, Sopha_Json::encode($data));
        } else {
            $response = Sopha_Http_Request::post($url, Sopha_Json::encode($data));
        }

        switch ($response->getStatus()) {
            case 201:
                $responseData = $response->getDocument();
                $url .= urlencode($responseData['id']);

                $data['_id'] = $responseData['id'];
                $data['_rev'] = $responseData['rev'];

                $this->_lastEtag = trim($response->getHeader("Etag"), '"');
                return new Sopha_Document($data, $url, $this);
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Retrieve a document from the DB
     *
     * @param  string  $doc
     * @param  string  $class Class to return - must extend Sopha_Document
     * @param  string  $rev
     * @param  boolean $full
     * @return Sopha_Document|boolean
     */
    public function fetch($doc, $class = 'Sopha_Document', $rev = null, $full = false)
    {
        $url = $this->_db_uri . self::encodeDocPath($doc);
        $request = new Sopha_Http_Request($url);
        if ($rev !== null) $request->addQueryParam('rev', $rev);
        if ($full) $request->addQueryParam('full', 'true');

        $response = $request->send();

        switch($response->getStatus()) {
            case 200:
                $this->_lastEtag = trim($response->getHeader("Etag"), '"');
                $obj = new $class($response->getDocument(), $url, $this);
                if (! $obj instanceof Sopha_Document) {
                    throw new Sopha_Db_Exception("Class $class is expected to extend Sopha_Document");
                }
                return $obj;
                break;

            case 404:
                return false;
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Update an existing document.
     *
     * The document must have a _id field, or the url has to be specified in
     * $doc. Also, the document must have a _rev field. Will return the doc's
     * new revision.
     *
     * @param  mixed   $data
     * @param  string  $url
     * @return string  New revision
     */
    public function update($data, $url = null)
    {
        // Convert object to array if needed, and get revision and URL
        if ($data instanceof Sopha_Document) {
            if (! $url) $url = $data->getUrl();
            $data = $data->toArray(true);

        } elseif (is_array($data)) {
            if (! $url && isset($data['_id'])) $url = $this->_db_uri . $data['_id'];

        } else {
            throw new Sopha_Db_Exception("Data is expected to be either an array or a Sopha_Document object");
        }

        // Make sure we have a URL and a revision
        if (! $url) {
            throw new Sopha_Db_Exception("Unable to update a document without a known URL");
        }

        if (! isset($data['_rev'])) {
            throw new Sopha_Db_Exception("Unable to update a document without a known revision");
        }

        $response = Sopha_Http_Request::put($url, Sopha_Json::encode($data));

        switch ($response->getStatus()) {
            case 201:
                $this->_lastEtag = trim($response->getHeader("Etag"), '"');
                $responseData = $response->getDocument();
                return $responseData['rev'];
                break;

            case 409:
                throw new Sopha_Db_Exception("Cannot save updated document: revision conflict", 409);
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Delete a doc from DB
     *
     * @param  string $doc Document ID
     * @param  string $rev Revision
     * @return boolean
     */
    public function delete($doc, $rev)
    {
        $url = $this->_db_uri . self::encodeDocPath($doc);
        $request = new Sopha_Http_Request($url, 'DELETE');
        $request->addQueryParam('rev', $rev);

        $response = $request->send();

        switch ($response->getStatus()) {
            case 200:
                return true;
                break;

            case 404:
                return false;
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Call a view function of a deisgn document
     *
     * @param  string $designDoc design document name
     * @param  string $view      view function name
     * @param  array  $params    parameters to pass to the view
     * @param  mixed  $returnDoc return document type
     * @return Sopha_View_Result
     */
    public function getView($designDoc, $view, array $params = array(), $returnDoc = null)
    {
        $url = $this->_db_uri . '_design/' . urlencode($designDoc) . '/_view/' . urlencode($view);
        $request = new Sopha_Http_Request($url);
        foreach($params as $k => $v) {
            $request->addQueryParam($k, Sopha_Json::encode($v));
        }

        $response = $request->send();

        switch($response->getStatus()) {
            case 200:
                if (! $returnDoc) $returnDoc = Sopha_View_Result::RETURN_ARRAY;
                $this->_lastEtag = trim($response->getHeader("Etag"), '"');
                return new Sopha_View_Result($response->getDocument(), $returnDoc, $this);
                break;

            case 404:
                throw new Sopha_Db_Exception("View document '$designDoc/$view' does not exist",
                    $response->getStatus());
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Call a list function of a design document on a given view and return the result as a string
     *
     * @param  string $designDoc design document name
     * @param  string $list      list function name
     * @param  string $view      view to display in the list
     * @param  array  $params    parameters to pass to the view
     * @return array
     */
    public function getList($designDoc, $list, $view, array $params = array()) {
        $url = $this->_db_uri . "_design/" . urlencode($designDoc) . "/_list/" . urlencode($list) . "/" . urlencode($view);
        $request = new Sopha_Http_Request($url);
        foreach($params as $k => $v) {
            $request->addQueryParam($k, $v);
        }

        $response = $request->send();

        switch($response->getStatus()) {
            case 200:
                $this->_lastEtag = trim($response->getHeader("Etag"), '"');
                return $response->getBody();
                break;

            case 404:
                throw new Sopha_Db_Exception("list function '$designDoc/$list' does not exist", $response->getStatus());
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Call a show function of a design document and return the result as a string
     *
     * @param  string $designDoc design document name
     * @param  string $show      show function name
     * @param  string $id        document id to load in the show
     * @param  array  $params    parameters to pass to the show
     * @return array
     */
    public function getShow($designDoc, $show, $id, array $params = array()) {
        $url = $this->_db_uri . "_design/" . urlencode($designDoc) . "/_show/" . urlencode($show) . "/" . urlencode($id);
        $request = new Sopha_Http_Request($url);
        foreach($params as $k => $v) {
            $request->addQueryParam($k, $v);
        }

        $response = $request->send();

        switch($response->getStatus()) {
            case 200:
                $this->_lastEtag = trim($response->getHeader("Etag"), '"');
                return $response->getDocument();
                break;

            case 404:
                throw new Sopha_Db_Exception("Show function '$designDoc/$show' does not exist", $response->getStatus());
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Call an ad-hoc view function
     *
     * @param  array  $view   View function code to call
     * @param  array  $params Parameters to pass to the view
     * @param  mixed  $return_doc Type of document to return
     * @return Sopha_View_Result
     */
    public function adHocView($view, array $params = array(), $return_doc = null)
    {
        $url = $this->_db_uri . '_temp_view';
        $data = Sopha_Json::encode($view);
        $request = new Sopha_Http_Request($url, Sopha_Http_Request::POST, $data);

        foreach($params as $k => $v) {
            $request->addQueryParam($k, Sopha_Json::encode($v));
        }

        $response = $request->send();

        switch($response->getStatus()) {
            case 200:
                if (! $return_doc) $return_doc = Sopha_View_Result::RETURN_ARRAY;
                $this->_lastEtag = trim($response->getHeader("Etag"), '"');
                return new Sopha_View_Result($response->getDocument(), $return_doc, $this);
                break;

            default:
                throw new Sopha_Db_Exception("Unexpected response from server: " .
                    "{$response->getStatus()} {$response->getMessage()}", $response->getStatus());
                break;
        }
    }

    /**
     * Get the URL for this DB
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->_db_uri;
    }

    /**
     * Static DB manipulation functions
     */

    /**
     * Create a database on a host and return it as an object
     *
     * @param  string  $dbname
     * @param  string  $host
     * @param  integer $port
     * @return Sopha_Db
     */
    static public function createDb($dbname, $host = 'localhost', $port = self::COUCH_PORT)
    {
        $uri = self::makeDbUrl($dbname, $host, $port);
        $response = Sopha_Http_Request::put($uri);

        switch($response->getStatus()) {
            case 201: // Expected
                return new Sopha_Db($dbname, $host, $port);
                break;

            case 412: // DB already exists
                throw new Sopha_Db_Exception("Database '$dbname' already exists", 412);
                break;

            default: // Unexpected
                throw new Sopha_Db_Exception("Unexpected response from server: {$response->getStatus()}",
                    $response->getStatus());
                break;
        }
    }

    /**
     * Delete a database from a host
     *
     * @param  string  $dbname
     * @param  string  $host
     * @param  integer $port
     * @return boolean True if successful
     */
    static public function deleteDb($dbname, $host = 'localhost', $port = self::COUCH_PORT)
    {
        $url = self::makeDbUrl($dbname, $host, $port);
        $response = Sopha_Http_Request::delete($url);

        switch($response->getStatus()) {
            case 200: // Expected
                return true;
                break;

            case 404: // DB does not exists
                throw new Sopha_Db_Exception("Database '$dbname' does not exist", 404);
                break;

            default: // Unexpected
                throw new Sopha_Db_Exception("Unexpected response from server: {$response->getStatus()}",
                    $response->getStatus());
                break;
        }
    }

    /**
     * Get list of all databases on a host
     *
     * @param  string  $host
     * @param  integer $port
     * @return array
     */
    static public function getAllDbs($host = 'localhost', $port = self::COUCH_PORT)
    {
        $url = self::makeUrl($host, $port, '_all_dbs');

        $response = Sopha_Http_Request::get($url);
        if (! $response->isSuccess()) {
            throw new Sopha_Db_Exception("Unexpected response from server: {$response->getStatus()}",
                $response->getStatus());
        }

        $this->_lastEtag = trim($response->getHeader("Etag"), '"');
        return $response->getDocument();
    }

    /**
     * Validate parts and create a generic URL
     *
     * @param  string $host
     * @param  string $port
     * @param  string $path
     * @return string
     */
    static protected function makeUrl($host, $port, $path)
    {
        // Validate host
        if (! preg_match('/^(?:(?:[a-zA-Z0-9][a-zA-Z0-9\-]{0,62}\.)*[a-zA-Z0-9][a-zA-Z0-9\-]{0,62}){1,254}$/', $host)) {
            throw new Sopha_Exception("Invalid host name: '$host'");
        }

        // Validate port
        $port = (integer) $port;
        if ($port < 0x1 || $port > 0xffff) {
            throw new Sopha_Exception("Invalid db port: '$port'");
        }

        // TODO: Validate Path ?

        return 'http://' . $host . ':' . $port . '/' . $path;
    }

    /**
     * A specific case of makeUrl used to create DB base URLs
     *
     * @param  string  $dbname
     * @param  string  $host
     * @param  integer $port
     * @return string
     */
    static protected function makeDbUrl($dbname, $host, $port)
    {
        // Validate dbname
        if (! preg_match('|^[a-z][a-z0-9_\$\(\)+\-/]*$|', $dbname)) {
            throw new Sopha_Exception("Invalid db name: '$dbname'");
        }

        $dbname = str_replace('/', '%2F', $dbname) . '/';
        return self::makeUrl($host, $port, $dbname);
    }

    /**
     * Encode a document path into a CouchDB-complient path
     *
     * If $path is a string, will return a URL-encoded version of the same
     * string. If $path is an array, will return a string made of the URL
     * encoded members of the array, joied by litteral (non-encoded) '/'. This
     * is useful for accessing design documents and attachments.
     *
     * @param  array|string $path
     * @return string
     */
    static protected function encodeDocPath($path)
    {
        if (is_array($path)) {
            foreach($path as $k => $v) {
                $path[$k] = urlencode($v);
            }
            return implode('/', $path);

        } else {
            return urlencode($path);

        }
    }
}
