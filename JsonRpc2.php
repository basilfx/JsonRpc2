<?php 
/**
 * JSON-RPC 2.0 class for PHP5.3. Does not require cURL-lib and uses closures
 * to associate callbacks. Also provides a ProxyObject to simplify requests
 * to the server, as if it were local implementations
 * 
 * Fully supports JSON-RPC2.0, including batch requests and notifications. 
 * Requests can be made stateful by enabling cookies. They are automatically
 * captured from the webserver. It is extended with JSON-RPC-over-HTTP.
 * 
 * @author Bas Stottelaar <basstottelaar {at} gmail {dot} com>
 * @license GPLv3
 * @version 1.3
 * @see https://www.github.com/basilfx/jsonrpc2
 */

namespace JsonRpc2;

/**
 * The main class. Executes all requests.
 */
class Client {
	/**
	 * @var int Number of seconds before a timeout happens
	 */
	public static  $__HTTP_TIMEOUT = 30;
	
	/**
	 * @var array Extra HTTP headers
	 */
	public static  $__HTTP_EXTRA_HEADERS = array(
		"X-Application: JsonRpc2-for-PHP5.3",
		"Connection: close"
	);
	
	/**
	 * @var string JSON-RPC version
	 */
	public static  $__JSONRPC_VERSION = "2.0";
	
	/**
	 * @var Closure Callback for outgoing data, debugging purpose
	 */
	public static $__DEBUG_SEND_DATA = null;
	
	/**
	 * @var Closure Callback for incoming data, debugging purpose
	 */
	public static $__DEBUG_RECEIVE_DATA = null;
	
	/**
	 * @var string Endpoint URL
	 */
	private $_endpointUrl = null;
	
	/**
	 * @var array jar of cookie data
	 */
	private $_cookieJar = array();
	
	/**
	 * @var bool enable or disable cookies
	 */
	private $_useCookies = true;
	
	/**
	 * @var array list of requests
	 */
	private $_batch = array();
	
	/**
	 * Initialize a new Client object with a given endpoint URL. 
	 * 
	 * Cookies can be enabled to make requests stateful.
	 * 
	 * @param string $endpointUrl
	 * @param bool $useCookies
	 */
	public function __construct($endpointUrl, $useCookies = true) {
		if (parse_url($endpointUrl) === false)
			throw new \InvalidArgumentException("Malformed endpoint given");
		
		$this->_endpointUrl = $endpointUrl;
		$this->_useCookies = $useCookies;
	}
	
	/**
	 * Add a request to the schedule list
	 * @param ClientRequest $request request to schedule
	 */
	public function scheduleRequest(ClientRequest $request) {
		$this->_batch[] = $request;
	}
	
	/**
	 * Perform a batch request.
	 * 
	 * If no array of requests is given, it will execute the scheduled 
	 * requests. Scheduled requests will alway be cleared, even if an
	 * array of requests is given.
	 * 
	 * @param array $requests batch of requests
	 */
	public function batchRequest(array $requests = null) {
		// If we do not specify any requests, then execute scheduled batch
		if ($requests === null) 
			$requests = $this->_batch;
		
		// Clear the scheduled requests, even if we do not execute them
		$this->_batch = array();
		
		// Check if we have to execute something
		if (count($requests) == 0) return;
		
		$mappings = array();
		$structures = array();
		
		// Map all requests by Id
		foreach ($requests as $request) {
			if (!($request instanceof ClientRequest))
				throw new \InvalidArgumentException("Object not a ClientRequest");
			
			// Add to array for post processing
			$mappings[$request->getId()] = $request;
			$structures[] = $request->getStructure();
		}
		
		// Handle data
		$me = $this;
		$this->sendRequest(
			$structures, function($data) use ($me, $mappings) { $me->handleBatchRequest($data, $mappings); });
	}
	
	/**
	 * Perform a single request
	 * @param ClientRequest $request request to execute
	 */
	public function request(ClientRequest $request) {
		$me = $this;
		$this->sendRequest(
			$request->getStructure(), function($data) use ($me, $request) { $me->handleRequest($data, $request); });
	}
	
	/**
	 * Handle batch request
	 * @param mixed $data received data
	 * @param array $mappings assoc array with id -> ClientRequest mappings
	 */
	public function handleBatchRequest($data, $mappings) {
		// Check if response is an array. If not, then all methods called were
		// notifications
		if (is_array($data)) {
			foreach ($data as $response) {
				// May throw error for defined errors
				$response = new ServerResponse($response);
				$clientRequest = $mappings[$response->getId()];
				$clientRequest->complete($response);
			}
		} else {
			// If we have no mappings, then all requests were notifications.
			// Notifications must have empty responses.
			if (count($mappings) > 0)
				throw \Exception("Sent batch request, but response is not an array!");
		}
	}
	
	/**
	 * Handle a single request
	 * @param mixed $data received data
	 * @param ClientRequest $request associated ClientRequest
	 */
	public function handleRequest($data, $request) {
		// Parse data
		if (is_array($data)) {
			$response = new ServerResponse($data);
			$request->complete($response);
		} else {
			if (!$request->isNotification())  
				throw \Exception("Sent a request, but received invalid data!");
		}
	}
	
	/**
	 * Send request to the server 
	 * @param array $data JSON-RPC data to send
	 * @param Closure $callback function to handle response
	 * @throws HttpError when server returns other status code then 200
	 */
	private function sendRequest(array $data, $callback) {
		// JSON Encode data
		$content = json_encode($data);
		
		// Check if encoding succeeded
		if ($content === false)
			throw new \Exception("Could not encode data to JSON format");
		
		// Add custom HTTP headers
		$headers = Client::$__HTTP_EXTRA_HEADERS;
		
		// Add required headers by the JSON-RPC-over-HTTP extension
		$headers[] = "Accept: application/json-rpc";
		$headers[] = "Content-Type: application/json-rpc"; 
		$headers[] = "Content-length: " . strlen($content);
		
		// Add cookies, if any
		if ($this->_useCookies && count($this->_cookieJar) > 0)
			$headers[] = "Cookie: " . $this->buildCookies();
		
		// Build request
		$context = array(
			"http" => array(
				"method" => "POST",
				"ignore_errors" => true,
				"header" => implode("\r\n", $headers),
				"timeout" => Client::$__HTTP_TIMEOUT,
				"content" => $content
			)
		);
		
		// Debug outgoing data
		$temp = Client::$__DEBUG_SEND_DATA;
		if (is_callable($temp)) $temp($content);
		
		// Process request
		$data = @\file_get_contents($this->_endpointUrl, false, \stream_context_create($context));
		
		// Debug ingoing data
		$temp = Client::$__DEBUG_RECEIVE_DATA;
		if (is_callable($temp)) $temp($data);
		
		// Parse HTTP headers
		$temp = explode(" ", $http_response_header[0], 3);
		$statusCode = (int) $temp[1];
		if (array_search($statusCode, array(200, 204, 400, 404, 500)) === false) 
			throw new HttpError($http_response_header[0], $statusCode);
		
		// Parse cookies, if any
		$this->parseCookies($http_response_header);
		
		// Check for errors
		if ($data === false)
			throw new \Exception("Could not execute request.");
		
		// Now try to decode data, but let the callback decide if it went well
		$data = \json_decode($data, true);
		
		// Call the next function in the chain	
		$callback($data);
	}
	
	/**
	 * Build a string suitable for the Cookie header
	 */
	private function buildCookies() {
		$removes = array();
		$cookies = array();
		
		// Parse all cookies, check them if they aren't expired
		foreach ($this->_cookieJar as $key => $cookie) {
			if (isset($cookie["data"]["expires"]) && time() > $cookie["data"]["expires"]) {
				$remove[] = $key;
				continue;
			}
			
			$cookies[] = "$key={$cookie["value"]}";
		}
		
		// Cleanup expired cookies
		foreach ($removes as $remove) unset($this->_cookieJar[$remove]);
		
		// Done
		return implode("; ", $cookies);
	}
	
	/**
	 * Parse HTTP header and extract cookies
	 * @param array $header HTTP header
	 */
	private function parseCookies(array $header) {
		// Walk through each header line
		foreach ($header as $line) {
			// Check if header matches Set-Cookie
	        if (preg_match( "/^Set-Cookie: /i", $line)) {
				$data = explode(": ", $line, 2);
				$data = $data[1];
				
				$cookieData = array(); 
				$cookieKey = "";
				$cookieValue = "";
				
				// Parse eacht Set-Cookie field
				foreach (explode("; ", $data) as $i => $part) {
					$key = substr($part, 0, strpos($part, "="));
					$value = substr($part, strpos($part, "=") + 1);
					
					if ($i == 0) { // First field always is the cookie
						$cookieKey = $key;
						$cookieValue = $value;
					} else { // And the other fields
						if (strcasecmp("expires", $key) == 0) $value = @strtotime($value);
						
						$cookieData[$key] = $value;
					}
				}
				
				$this->_cookieJar[$cookieKey] = array("value" => $cookieValue, "data" => $cookieData);
	        }
	    }
	}
}

/**
 * Represents a proxy between the remote API en the user implementation.
 */
class ProxyObject {
	/**
	 * @var Client client object to perform requests with
	 */
	private $_client = null;
	
	/**
	 * @var array storage for accessed childs
	 */
	private $_depth = array();
	
	/**
	 * @var Closure callback when request failed
	 */
	private $_errorCallback = null;
	
	/**
	 * @var Closure callback when request completed
	 */
	private $_completeCallback = null;
	
	/**
	 * @var bool whether to automatically execute the request or return it
	 */
	protected $_executeRequest = true;
	
	/**
	 * Create a new ProxyObject which will execute commands on the Clients 
	 * which are executed on this object.
	 * 
	 * @param Client $client Client to execute commonds on
	 * @param Closure $errorCallback optional callback for errors
	 */
	public function __construct(Client $client, $completeCallback = null, $errorCallback = null) {
		$this->_client = $client;
		
		// Complete callback			
		if ($completeCallback !== null && !is_callable($completeCallback))
			throw new \InvalidArgumentException("Complete callback is not callable");
		else
			$this->_completeCallback = $completeCallback;
		
		// Error callback			
		if ($errorCallback !== null && !is_callable($errorCallback))
			throw new \InvalidArgumentException("Error callback is not callable");
		else
			$this->_errorCallback = $errorCallback;
	}
	
	/**
	 * Catch all requested methods and process them via the Client class.
	 * 
	 * @param string $method requested method
	 * @param array $params supplied parameters
	 * @throws RemoteError if an error occures
	 */
	public function __call($method, array $params) {
		$this->_depth[] = $method;
		$method = implode(".", $this->_depth);
		$this->_depth = array();
		$result = null;
		$completeCallback = $this->_completeCallback;
		
		$request = new ClientRequest($method, $params, false, function($request, $response) use (&$result, $completeCallback) {
			if ($response->hasError()) {
				$error = $response->getError();
				throw new RemoteError($error, $error->getMessage(), $error->getCode());
			}
			
			// Execute associated callback
			if ($completeCallback !== null) $completeCallback($request, $response);

			// Save response
			$result = $response->getResult();
		}, $this->_errorCallback);

		// Decide what to do
		if ($this->_executeRequest == true) {
			$this->_client->request($request);
			return $result;
		} else {
			$this->_client->scheduleRequest($request);
			return $request;
		}
	}
	
	/**
	 * Make sure that child member access is supported
	 * 
	 * @param string $var name of child
	 */
	public function __get($var) {
		$this->_depth[] = $var;
		return $this;
	}
}

/**
 * Proxy wrapper for ProxyObject to execute batch requests. Requests will 
 * be scheduled in the Client and executed when you invoke 
 * Client::batchRequest().
 */
class ProxyBatchObject extends ProxyObject {
	/**
	 * @see ProxyObject::__construct()
	 */
	public function __construct(Client $client, $completeCallback = null, $errorCallback = null) {
		$this->_executeRequest = false;
		parent::__construct($client, $completeCallback, $errorCallback);
	}
}

/**
 * Represent client request object
 */
class ClientRequest {
	/**
	 * @var string method to call
	 */
	private $_method = null;
	
	/**
	 * @var array additional parameters
	 */
	private $_params = null;
	
	/**
	 * @var string request id, generated
	 */
	private $_id = null;
	
	/**
	 * @var bool is this request a notification?
	 */
	private $_isNotification = null;
	
	/**
	 * @var Closure callback when request is completed and returned data
	 */
	private $_completeCallback = null;
	
	/**
	 * @var Closure callback when request has error and returned data
	 */
	private $_errorCallback = null;
	
	/**
	 * Construct a new client request or client notification
	 * 
	 * Callbacks can be associated with this request. They will be called
	 * automatically when a request is completed or has errors. Please note:
	 * if a response does not contain an Id, the callbacks cannot be found. 
	 * 
	 * The complete callback will only be called when this request is not 
	 * marked as a notification. 
	 * 
	 * @param string $method method to call
	 * @param array $params additional parameters
	 * @param bool $isNotifcation mark this request as a notification
	 * @param Closure $completeCallback callback
	 * @param Closure $errorCallback callback
	 */
	public function __construct($method, array $params = array(), $isNotification = false, $completeCallback = null, $errorCallback = null) {
		// Required request parameters
		$this->_method = $method;
		$this->_params = $params;
		$this->_id = md5(uniqid(microtime(true), true));
		$this->_isNotification = $isNotification;
		
		// Complete callback
		if ($completeCallback !== null && !is_callable($completeCallback))
			throw new \InvalidArgumentException("Complete callback is not callable");
		else
			$this->_completeCallback = $completeCallback;

		// Error callback			
		if ($errorCallback !== null && !is_callable($errorCallback))
			throw new \InvalidArgumentException("Error callback is not callable");
		else
			$this->_errorCallback = $errorCallback;	
	}
	
	/**
	 * Return request Id
	 * @return string
	 */
	public function getId() {
		return $this->_id;
	}
	
	/**
	 * Return all parameters
	 * @return array 
	 */
	public function getParameters() {
		return $this->_params;
	}
	
	/**
	 * Return parameter by key
	 * @param mixed $key
	 * @return array
	 */
	public function getParameter($key) {
		return $this->_params[$key];
	}
	
	/**
	 * Return true if this request is a notification
	 * @return boolean
	 */
	public function isNotification() {
		return $this->_isNotification;
	}
	
	/**
	 * Build structure to represent JSON-RPC request
	 * @return array
	 */
	public function getStructure() {
		$result = array(
			"jsonrpc" => Client::$__JSONRPC_VERSION,
			"method" => $this->_method,
			"params" => $this->_params
		);	
		
		// Notification or a normal request?
		if ($this->_isNotification === false) $result["id"] = $this->_id;
		
		return $result;
	}
	
	/**
	 * Method which gets called by Client when a request is finished. 
	 * 
	 * Note: when a notification is requested, the complete callback will not
	 * be invoked, only the error callback.
	 * @param ServerResponse $data
	 */
	public function complete(ServerResponse $data) {
		if ($data->hasError() && $this->_errorCallback != null) {
			$callback = $this->_errorCallback;
			$callback($this, $error);
		}
		
		if ($this->_isNotification === false && $this->_completeCallback != null) {
			$callback = $this->_completeCallback;
			$callback($this, $data);
		}
	}
}

/**
 * Server response
 */
class ServerResponse {
	/**
	 * @var mixed server result
	 */
	private $_result = null;
	
	/**
	 * @var ServerResponseError server error (may be null) 
	 */
	private $_error = null;
	
	/**
	 * @var string unique request id (may be null)
	 */
	private $_id = null;
	
	/**
	 * Construct a new server response object. Parse all data received.
	 * @param array $response server response
	 */
	public function __construct(array $response) {
		// Get the Id
		$this->_id = $response["id"];

		if (isset($response["error"])) { // An error happened 
			$this->_error = new ServerResponseError($response["error"]);
		} else { // No, just some results
			// Check if response is valid
			if (!isset($response["jsonrpc"]) && $response["jsonrpc"] != Client::$__JSONRPC_VERSION)
				throw new \Exception("Invalid response from server");
				
			$this->_result = $response["result"]; 
		}
	}
	
	/**
	 * Response Id
	 * @return string
	 */
	public function getId() {
		return $this->_id;
	}
	
	/**
	 * Returns true if response contains an error
	 * @return boolean
	 */
	public function hasError() {
		return $this->_error != null;
	}
	
	/**
	 * Return the error, if any
	 * @return ServerResponseError
	 */
	public function getError() {
		return $this->_error;
	}
	
	/**
	 * Return server result, if any
	 * @return mixed
	 */
	public function getResult() {
		return $this->_result;
	}
}

/**
 * Error object
 */
class ServerResponseError {
	/**
	 * @var int error code
	 */
	private $_code = null;
	
	/**
	 * @var string error mesage
	 */
	private $_message = null;
	
	/**
	 * @var mixed error data
	 */
	private $_data = null;
	
	/**
	 * Construct a new error object.
	 * 
	 * May throw an exception when the error is JSON-RPC protocol related
	 * @param array $error error data from server
	 */
	public function __construct(array $error) {
		$this->_code = (int) $error["code"];
		$this->_message = $error["message"];
		$this->_data = $error["data"];
		
		switch ($this->_code) {
			case -32700:
				throw new ParseError($this);
			case -32600:
				throw new InvalidRequest($this);
			case -32601:
				throw new MethodNotFound($this);
			case -32602:
				throw new InvalidParams($this);
			case -32603:
				throw new InternalError($this);
		}
	}
	
	/**
	 * Return error code
	 * @return int
	 */
	public function getCode() {
		return $this->_code;	
	}
	
	/**
	 * Return error message
	 * @return string 
	 */
	public function getMessage() {
		return $this->_message;
	}
	
	/**
	 * Return error data
	 * @return mixed server representation of error (may be null)
	 */
	public function getData() {
		return $this->_data;
	}
}

/**
 * Exceptions
 */

/**
 * Abastract base class to support de ServerResponseError object
 */
abstract class BaseError extends \Exception {
	private $_serverError = null;
	
	public function __construct(ServerResponseError $error, $message, $code) {
		$this->_serverError = $error;
		parent::__construct($message, $code);
	}
	
	public function getServerError() {
		return $this->_serverError;
	}
}

/**
 * Raised when the JSON could not be parsed by the server
 */
class ParseError extends BaseError {
	public function __construct($error) {
		parent::__construct($error, "Invalid JSON was received by the server.", -32700);
	}
}

/**
 * Raised when the JSON sent is not a request object
 */
class InvalidRequest extends BaseError {
	public function __construct($error) {
		parent::__construct($error, "The JSON sent is not a valid Request object.", -32600);
	}
}

/**
 * Raised when a method is called which does not exists
 */
class MethodNotFound extends BaseError {
	public function __construct($error) {
		parent::__construct($error, "The method does not exist / is not available.", -32601);
	}
}

/**
 * Raised when the number of parameters supplied does not match the definition
 */
class InvalidParams extends BaseError {
	public function __construct($error) {
		parent::__construct($error, "Invalid method parameter(s).", -32602);
	}
}

/**
 * Raised when a server error happens
 */
class InternalError extends BaseError {
	public function __construct($error) {
		parent::__construct($error, "Internal JSON-RPC error.", -32603);
	}
}

/**
 * Raised when the remote function throws an exception
 */
class RemoteError extends BaseError {
	public function __construct($error, $message, $code) {
		parent::__construct($error, $message, $code);
	}
}

/**
 * Raised when the webserver returns another status code then 200
 */
class HttpError extends \Exception { }