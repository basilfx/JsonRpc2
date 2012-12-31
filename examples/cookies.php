<?php

// Include required class
include("../JsonRpc2.php");

// Construct a new Client object, with cookies enabled
$client = new JsonRpc2\Client("http://example.com/api/"m true);

// Login, which should be true.
var_dump($client->request(new JsonRpc2\ClientRequest("auth.login", array("username", "password"))));

// Cookie call, should print username for this session
var_dump($client->request(new JsonRpc2\ClientRequest("auth.whoami")));