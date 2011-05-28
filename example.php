<?php

// Include required class
include("JsonRpc2.php");

// Construct a new Client object and enable cookies
$client = new JsonRpc2\Client("http://example.com/api/", true);

// Define sample callback
$onComplete = function($request, $response) {
	var_dump($response->getResult());
};

// Single request, no output
$client->request(new JsonRpc2\ClientRequest("foo.bar"));

// Single request with callback, prints output
$client->request(new JsonRpc2\ClientRequest("foo.bar", array(), $onComplete));

// Batch request, only last call with print something
$client->batchRequest(array(
	new JsonRpc2\ClientRequest("echo", array(1)), 
	new JsonRpc2\ClientRequest("echo", array(2)),
	new JsonRpc2\ClientRequest("echo", array(3)),
	new JsonRpc2\ClientRequest("echo", array(4)),
	new JsonRpc2\ClientRequest("echo", array(5), $onComplete)
));

// ClientObject, as if the implementation were client side
$api = new JsonRpc2\ClientObject($client);

// Simple usage
$api->foo->bar();

// Print output
var_dump($api->echo(5));

// ClientBatchObject, same as above, but then for batch requests
$batchApi = new JsonRpc2\ClientBatchObject($client);
$batch = array(
	$batchApi->foo->bar(),
	$batchApi->foo->bar(),
	$batchApi->foo->bar(),
	$batchApi->foo->bar(),
	$batchApi->echo(5) // Will not output anything		
);
$client->batchRequest($batch);
