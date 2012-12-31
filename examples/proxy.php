<?php

// Include required class
include("../JsonRpc2.php");

// Construct a new Client object
$client = new JsonRpc2\Client("http://example.com/api/");

// ProxyObject, as if the implementation existed client side
$api = new JsonRpc2\ProxyObject($client);

// Simple usage 1. This call will be translated '$client->request(new JsonRpc2\ClientRequest("foo.bar"))'
$api->foo->bar();

// Simple usage 2. Print output
var_dump($api->echo(5));

// Batch usage 1. Create batch object and execute requests
$batchApi = new JsonRpc2\ProxyBatchObject($client);
$batch = array(
    $batchApi->foo->bar(),
    $batchApi->foo->bar(),
    $batchApi->foo->bar(),
    $batchApi->foo->bar(),
    $batchApi->echo(5) // Will output      
);
$client->batchRequest($batch);

// Batch usage 2. $batchApi will schedule requests automatically, so you do not
// have to use the array.
$batchApi->foo->bar(1);
$batchApi->foo->bar(2);
$batchApi->foo->bar(3);
$client->batchRequest(); // Commit the above 3 commands