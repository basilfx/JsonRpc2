<?php 
/**
 * JSON-RPC 2.0 class for PHP5.3. Does not require cURL-lib and uses closures
 * to associate callbacks. Also provides a ClientObject to simplify requests
 * to the server, as if it were local implementations
 * 
 * Fully supports JSON-RPC2.0, including batch requests and notifications. 
 * Requests can be made stateful by enabling cookies. They are automatically
 * captured from the webserver. 
 * 
 * @author Bas Stottelaar <basstottelaar {at} gmail {dot} com>
 * @license GPLv3
 * @version 1.0
 */

namespace JsonRpc2;

/**
 * 
 * @author basilfx
 */
class Client {
	/**
	 * @var int Number of seconds before a timeout happens
	 */
	public static  $__HTTP_TIMEOUT = 30;
	
	/**
	 * @var array Extra HTTP headers
	 */
	public static  $__HTTP_HEADERS = array(
		"Accept: application/json-rpc",
		"Content-Type: application/json-rpc",
		"X-Application: JsonRpc2-for-PHP5.3",
		"Connection: close"
	);
	
	/**
	 * @var string JSON-RPC version
	 */
	public static  $__JSONRPC_VERSION = "2.0";
	
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
	 * Perform a batch request
	 * @param array $requests batch of requests
	 */
	public function batchRequest(array $requests) {
		$mappings = array();
		$structures = array();
		
		foreach ($requests as $request) {
			if (!($request instanceof ClientRequest))
				throw new \InvalidArgumentException("Object not a ClientRequest");
			
			// Add to array
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
		}
	}
	
	/**
	 * Handle a single request
	 * @param mixed $data received data
	 * @param ClientRequest $request associated ClientRequest
	 */
	public function handleRequest($data, $request) {
		// If data is no array, then it probably was an notification
		if (is_array($data)) {
			$response = new ServerResponse($data);
			$request->complete($response);
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
		
		// Add extra HTTP headers
		$headers = Client::$__HTTP_HEADERS;
		
		// Add cookies, if any
		if ($this->_useCookies && count($this->_cookieJar) > 0)
			$headers[] = "Cookie: " . $this->buildCookies();
		
		// Build request
		$context = array(
			"http" => array(
				"method" => "POST",
				"header" => implode("\r\n", $headers),
				"timeout" => Client::$__HTTP_TIMEOUT,
				"content" => $content
			)
		);
	
		// Process request
		$data = @\file_get_contents($this->_endpointUrl, false, \stream_context_create($context));
		
		// Parse HTTP headers
		$temp = explode(" ", $http_response_header[0], 3);
		$statusCode = (int) $temp[1];
		if ($statusCode != 200) throw new HttpError($temp[2], $statusCode);
		
		// Parse cookies, if any
		$this->parseCookies($http_response_header);
		
		// Check for errors
		if ($data === false)
			throw new \Exception("Could not execute request.");
			
		// Now try to decode data
		$data = \json_decode($data, true);
		
		// Check if decoding succeeded
		if ($data === false)
			throw new \Exception("Could not decode server data");
		
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
class ClientObject {
	/**
	 * @var Client client object to perform requests with
	 */
	private $_client = null;
	
	/**
	 * @var array storage for accessed childs
	 */
	private $_depth = array();
	
	public function __construct(Client $client) {
		$this->_client = $client;
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
		$result = null;

		$request = new ClientRequest($method, $params, function($request, $response) use (&$result) {
			if ($response->hasError()) {
				$error = $response->getError();
				throw new RemoteError($error, $error->getMessage(), $error->getCode());
			}
			
			$result = $response->getResult();
		});
		
		$this->_client->request($request);
		$this->_depth = array();
		return $result;
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
	 * string request id, generated
	 */
	private $_id = null;
	
	/**
	 * @var Closure callback when request is completed and returned data
	 */
	private $_completeCallback = null;
	
	/**
	 * @var Closure callback when request has error and returned data
	 */
	private $_errorCallback = null;
	
	/**
	 * Construct a new client request. Automatically generates an Id.
	 * 
	 * Callbacks can be associated with this request. They will be called
	 * automatically when a request is completed or has errors. Please note:
	 * if a response does not contain an Id, the callbacks cannot be found. 
	 * Also, notifications do not return any data and therefore, callbacks
	 * will not be executed 
	 * 
	 * @param string $method method to call
	 * @param array $params additional parameters
	 * @param Closure $completeCallback callback
	 * @param Closure $errorCallback callback
	 */
	public function __construct($method, array $params = array(), $completeCallback = null, $errorCallback = null) {
		// Required request parameters
		$this->_method = $method;
		$this->_params = $params;
		$this->_id = md5(uniqid(microtime( true ), true));
		
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
	 * Build structure to represent JSON-RPC request
	 * @return array
	 */
	public function getStructure() {
		return array(
			"jsonrpc" => Client::$__JSONRPC_VERSION,
			"method" => $this->_method,
			"params" => $this->_params,
			"id" => $this->_id
		);	
	}
	
	/**
	 * Method which gets called by Client when data is received
	 * @param ServerResponse $data
	 */
	public function complete(ServerResponse $data) {
		if ($data->hasError() && $this->_errorCallback != null) {
			$callback = $this->_errorCallback;
			$callback($this, $error);
		}
		
		if ($this->_completeCallback != null) {
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
		// Check if response is valid
		if (!isset($response["jsonrpc"]) && $response["jsonrpc"] != Client::$__JSONRPC_VERSION)
			throw new \Exception("Invalid response from server");
		
		// Get the Id
		$this->_id = $response["id"];

		if (isset($response["error"])) // An error happened 
			$this->_error = new ServerResponseError($response["error"]);
		else // No, just some results
			$this->_result = $response["result"]; 
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