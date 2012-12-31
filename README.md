# JSON-RPC 2.0
A JSON-RPC 2.0 class for PHP5.3+, including Batch and Notify support.

## Features
* Designed for PHP5.3+
* Fully supports JSON-RPC2.0 + HTTP extension, including batch requests and notifications. 
* Requests can be made stateful by enabling cookies. They are automatically captured from the webserver.
* ClientObject and ClientBatchObject, as if the requested methods existed locally
* Callbacks, also for debugging purposes

## Installation
* Copy the file `JsonRpc2.php` to your desired location.
* Include it in your source

## Examples
See the folder `examples` for a few examples.

## License
See the `LICENSE` file (MIT).