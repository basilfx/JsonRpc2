<?php

// Include required class
include("JsonRpc2.php");

// Construct a new Client object and enable cookies
$client = new JsonRpc2\Client("http://example.com/api/", true);

// Attacht debugging functions to visualize ingoing and outgoing data
JsonRpc2\Client::$__DEBUG_SEND_DATA = function($data) { echo "OUT >>> $data\n"; };
JsonRpc2\Client::$__DEBUG_RECEIVE_DATA = function($data) { echo "IN <<< $data\n"; };

// Define sample callback
$onComplete = function($request, $response) { var_dump($response->getResult()); };

// Single request, no output
$client->request(new JsonRpc2\ClientRequest("foo.bar"));

// Single request with callback, prints output
$client->request(new JsonRpc2\ClientRequest("foo.bar", array(), false, $onComplete));

// Batch request, only last call with print something
$client->batchRequest(array(
	new JsonRpc2\ClientRequest("echo", array(1)), 
	new JsonRpc2\ClientRequest("echo", array(2)),
	new JsonRpc2\ClientRequest("echo", array(3)),
	new JsonRpc2\ClientRequest("echo", array(4)),
	new JsonRpc2\ClientRequest("echo", array(5), false, $onComplete)
));

// Notification
$client->request(new JsonRpc2\ClientRequest("notify", array(), true));

// Errors will still be handeled by the error callback, if any
$client->request(new JsonRpc2\ClientRequest("notif", array(), true)); 

// Callback will not be executed!
$client->request(new JsonRpc2\ClientRequest("notify", array(), true, $onComplete)); 

// ProxyObject, as if the implementation were client side
$api = new JsonRpc2\ProxyObject($client);

// Simple usage
$api->foo->bar();

// Print output
var_dump($api->echo(5));

// ProxyBatchObject, same as above, but then for batch requests
$batchApi = new JsonRpc2\ProxyBatchObject($client);
$batch = array(
	$batchApi->foo->bar(),
	$batchApi->foo->bar(),
	$batchApi->foo->bar(),
	$batchApi->foo->bar(),
	$batchApi->echo(5) // Will not output anything		
);
$client->batchRequest($batch);

// ProxyBatchObject, the easy way. $batchApi will schedule requests automatically
$batchApi->foo->bar(1),
$batchApi->foo->bar(2),
$batchApi->foo->bar(3),
$client->batchRequest(); // Commit the above 3 commands

