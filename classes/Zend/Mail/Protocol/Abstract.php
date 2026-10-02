<?php

/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Mail
 * @subpackage Protocol
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Abstract.php 22602 2010-07-16 22:37:31Z freak $
 */


/**
 * @see Zend_Validate
 */
require_once 'Zend/Validate.php';


/**
 * @see Zend_Validate_Hostname
 */
require_once 'Zend/Validate/Hostname.php';


/**
 * Zend_Mail_Protocol_Abstract
 *
 * Provides low-level methods for concrete adapters to communicate with a remote mail server and track requests and responses.
 *
 * @category   Zend
 * @package    Zend_Mail
 * @subpackage Protocol
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Abstract.php 22602 2010-07-16 22:37:31Z freak $
 * @todo Implement proxy settings
 */
abstract class Zend_Mail_Protocol_Abstract
{
    /**
     * Mail default EOL string
     */
    const EOL = "\r\n";


    /**
     * Default timeout in seconds for initiating session
     */
    const TIMEOUT_CONNECTION = 30;

    /**
     * Wall-clock budget in seconds for one whole connection -- from connect()
     * through every command and reply until disconnect -- or null for the
     * library's own behaviour, where each reply may take as long as its RFC 2821
     * timeout (up to 300s per command and 600s after DATA).
     *
     * Those RFC timeouts are right for an MTA and wrong for a web request: one
     * slow SMTP host blocks the calling request for minutes. Set it with the
     * 'timeout' config key (see Zend_Mail_Protocol_Smtp) or setTimeout(). Once
     * the budget is spent, the next read or write throws
     * Zend_Mail_Protocol_Exception instead of blocking.
     *
     * @var float|null
     */
    protected $_timeout = null;

    /**
     * microtime(true) at which the current connection's budget runs out, or null
     * @var float|null
     */
    protected $_deadline = null;

    /**
     * Maximum of the transaction log
     * @var integer
     */
    protected $_maximumLog = 64;


    /**
     * Hostname or IP address of remote server
     * @var string
     */
    protected $_host;


    /**
     * Port number of connection
     * @var integer
     */
    protected $_port;


    /**
     * Instance of Zend_Validate to check hostnames
     * @var Zend_Validate
     */
    protected $_validHost;


    /**
     * Socket connection resource
     * @var resource
     */
    protected $_socket;


    /**
     * Last request sent to server
     * @var string
     */
    protected $_request;


    /**
     * Array of server responses to last request
     * @var array
     */
    protected $_response;


    /**
     * String template for parsing server responses using sscanf (default: 3 digit code and response string)
     * @var resource
     * @deprecated Since 1.10.3
     */
    protected $_template = '%d%s';


    /**
     * Log of mail requests and server responses for a session
     * @var array
     */
    private $_log = array();


    /**
     * Constructor.
     *
     * @param  string  $host OPTIONAL Hostname of remote connection (default: 127.0.0.1)
     * @param  integer $port OPTIONAL Port number (default: null)
     * @throws Zend_Mail_Protocol_Exception
     * @return void
     */
    public function __construct($host = '127.0.0.1', $port = null)
    {
        $this->_validHost = new Zend_Validate();
        $this->_validHost->addValidator(new Zend_Validate_Hostname(Zend_Validate_Hostname::ALLOW_ALL));

        if (!$this->_validHost->isValid($host)) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception(join(', ', $this->_validHost->getMessages()));
        }

        $this->_host = $host;
        $this->_port = $port;
    }


    /**
     * Class destructor to cleanup open resources
     *
     * @return void
     */
    public function __destruct()
    {
        $this->_disconnect();
    }

    /**
     * Bound the whole connection to a wall-clock budget (see $_timeout).
     *
     * @param  float|null $seconds Budget in seconds; null or <= 0 restores the
     *                             library's per-command RFC timeouts
     * @return void
     */
    public function setTimeout($seconds)
    {
        $this->_timeout = ($seconds > 0) ? (float) $seconds : null;
    }


    /**
     * Get the wall-clock budget for a whole connection, or null if unbounded
     *
     * @return float|null
     */
    public function getTimeout()
    {
        return $this->_timeout;
    }


    /**
     * Clamp a per-operation timeout to what is left of the connection budget.
     *
     * @param  float|null $timeout The timeout the caller would otherwise use
     * @throws Zend_Mail_Protocol_Exception when the budget is already spent
     * @return float|null
     */
    protected function _boundedTimeout($timeout = null)
    {
        if ($this->_deadline === null) {
            return $timeout;
        }
        $remaining = $this->_deadline - microtime(true);
        if ($remaining <= 0) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception($this->_host . ' has timed out');
        }
        return ($timeout === null) ? $remaining : min($timeout, $remaining);
    }


    /**
     * stream_set_timeout() with a fractional number of seconds
     *
     * @param  float $seconds
     * @return boolean
     */
    protected function _setStreamTimeout($seconds)
    {
        $whole = (int) floor($seconds);
        return stream_set_timeout($this->_socket, $whole, (int) (($seconds - $whole) * 1000000));
    }


    /**
     * Set the maximum log size 
     * 
     * @param integer $maximumLog Maximum log size
     * @return void
     */
    public function setMaximumLog($maximumLog)
    {
        $this->_maximumLog = (int) $maximumLog;
    }
    
    
    /**
     * Get the maximum log size 
     * 
     * @return int the maximum log size
     */
    public function getMaximumLog()
    {
        return $this->_maximumLog;
    }
    

    /**
     * Create a connection to the remote host
     *
     * Concrete adapters for this class will implement their own unique connect scripts, using the _connect() method to create the socket resource.
     */
    abstract public function connect();


    /**
     * Retrieve the last client request
     *
     * @return string
     */
    public function getRequest()
    {
        return $this->_request;
    }


    /**
     * Retrieve the last server response
     *
     * @return array
     */
    public function getResponse()
    {
        return $this->_response;
    }


    /**
     * Retrieve the transaction log
     *
     * @return string
     */
    public function getLog()
    {
        return implode('', $this->_log);
    }


    /**
     * Reset the transaction log
     *
     * @return void
     */
    public function resetLog()
    {
        $this->_log = array();
    }

    /**
     * Add the transaction log
     *
     * @param  string new transaction
     * @return void
     */
    protected function _addLog($value)
    {
        if ($this->_maximumLog >= 0 && count($this->_log) >= $this->_maximumLog) {
            array_shift($this->_log);
        }

        $this->_log[] = $value;
    }

    /**
     * Connect to the server using the supplied transport and target
     *
     * An example $remote string may be 'tcp://mail.example.com:25' or 'ssh://hostname.com:2222'
     *
     * @param  string $remote Remote
     * @throws Zend_Mail_Protocol_Exception
     * @return boolean
     */
    protected function _connect($remote)
    {
        $errorNum = 0;
        $errorStr = '';

        // Start the connection's wall-clock budget, if one is set
        $this->_deadline = null;
        $connectTimeout = self::TIMEOUT_CONNECTION;
        if ($this->_timeout !== null) {
            $this->_deadline = microtime(true) + $this->_timeout;
            $connectTimeout = min($connectTimeout, $this->_timeout);
        }

        // open connection
        $this->_socket = @stream_socket_client($remote, $errorNum, $errorStr, $connectTimeout);

        if ($this->_socket === false) {
            if ($errorNum == 0) {
                $errorStr = 'Could not open socket';
            }
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception($errorStr);
        }

        if (($result = $this->_setStreamTimeout($this->_boundedTimeout(self::TIMEOUT_CONNECTION))) === false) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception('Could not set stream timeout');
        }

        return $result;
    }


    /**
     * Disconnect from remote host and free resource
     *
     * @return void
     */
    protected function _disconnect()
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
    }


    /**
     * Send the given request followed by a LINEEND to the server.
     *
     * @param  string $request
     * @throws Zend_Mail_Protocol_Exception
     * @return integer|boolean Number of bytes written to remote host
     */
    protected function _send($request)
    {
        if (!is_resource($this->_socket)) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception('No connection has been established to ' . $this->_host);
        }

        $this->_request = $request;

        // A blocked write waits on the stream timeout too, so it gets the
        // same budget as a read
        if ($this->_deadline !== null) {
            $this->_setStreamTimeout($this->_boundedTimeout());
        }

        $result = fwrite($this->_socket, $request . self::EOL);

        // Save request to internal log
        $this->_addLog($request . self::EOL);

        if ($result === false) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception('Could not send request to ' . $this->_host);
        }

        return $result;
    }


    /**
     * Get a line from the stream.
     *
     * @var    integer $timeout Per-request timeout value if applicable
     * @throws Zend_Mail_Protocol_Exception
     * @return string
     */
    protected function _receive($timeout = null)
    {
        if (!is_resource($this->_socket)) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception('No connection has been established to ' . $this->_host);
        }

        // Adapters may wish to supply per-commend timeouts according to appropriate RFC,
        // clamped to what is left of the connection's budget if it has one
        $timeout = $this->_boundedTimeout($timeout);
        if ($timeout !== null) {
           $this->_setStreamTimeout($timeout);
        }

        // Retrieve response
        $reponse = fgets($this->_socket, 1024);

        // Save request to internal log
        $this->_addLog($reponse);

        // Check meta data to ensure connection is still valid
        $info = stream_get_meta_data($this->_socket);

        if (!empty($info['timed_out'])) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception($this->_host . ' has timed out');
        }

        if ($reponse === false) {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception('Could not read from ' . $this->_host);
        }

        return $reponse;
    }


    /**
     * Parse server response for successful codes
     *
     * Read the response from the stream and check for expected return code.
     * Throws a Zend_Mail_Protocol_Exception if an unexpected code is returned.
     *
     * @param  string|array $code One or more codes that indicate a successful response
     * @throws Zend_Mail_Protocol_Exception
     * @return string Last line of response string
     */
    protected function _expect($code, $timeout = null)
    {
        $this->_response = array();
        $cmd  = '';
        $more = '';
        $msg  = '';
        $errMsg = '';

        if (!is_array($code)) {
            $code = array($code);
        }

        do {
            $this->_response[] = $result = $this->_receive($timeout);
            list($cmd, $more, $msg) = preg_split('/([\s-]+)/', $result, 2, PREG_SPLIT_DELIM_CAPTURE);

            if ($errMsg !== '') {
                $errMsg .= ' ' . $msg;
            } elseif ($cmd === null || !in_array($cmd, $code)) {
                $errMsg =  $msg;
            }

        } while (strpos($more, '-') === 0); // The '-' message prefix indicates an information string instead of a response string.

        if ($errMsg !== '') {
            /**
             * @see Zend_Mail_Protocol_Exception
             */
            require_once 'Zend/Mail/Protocol/Exception.php';
            throw new Zend_Mail_Protocol_Exception($errMsg);
        }

        return $msg;
    }
}
