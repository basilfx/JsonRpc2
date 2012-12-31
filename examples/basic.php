<?php

// Include required class
include("../JsonRpc2.php");

// Construct a new Client object
$client = new JsonRpc2\Client("http://example.com/api/");

// Single request, no output
$client->request(new JsonRpc2\ClientRequest("foo.bar"));