<?php

// Include required class
include("../JsonRpc2.php");

// Construct a new Client object
$client = new JsonRpc2\Client("http://example.com/api/");

// Attacht debugging functions to visualize ingoing and outgoing data
JsonRpc2\Client::$__DEBUG_SEND_DATA = function($data) { echo "OUT >>> $data\n"; };
JsonRpc2\Client::$__DEBUG_RECEIVE_DATA = function($data) { echo "IN <<< $data\n"; };

// Define sample callback
$onComplete = function($request, $response) { var_dump($response->getResult()); };

// Single request with callback, prints output
$client->request(new JsonRpc2\ClientRequest("foo.bar", array(), false, $onComplete));