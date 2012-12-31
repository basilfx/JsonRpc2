<?php

// Include required class
include("../JsonRpc2.php");

// Construct a new Client object
$client = new JsonRpc2\Client("http://example.com/api/");

// Define sample callback
$onComplete = function($request, $response) { var_dump($response->getResult()); };

// Batch request, only last call will print something
$client->batchRequest(array(
    new JsonRpc2\ClientRequest("echo", array(1)), 
    new JsonRpc2\ClientRequest("echo", array(2)),
    new JsonRpc2\ClientRequest("echo", array(3)),
    new JsonRpc2\ClientRequest("echo", array(4)),
    new JsonRpc2\ClientRequest("echo", array(5), false, $onComplete)
));