<?php

require 'src/vendor/autoload.php';

use Monolog\Logger;
use SyRPC\Common;
use SyRPC\Server;
use SyRPC\Client;

define("MODE_CLIENT", 'client');
define("MODE_SERVER", 'server');

function setupLogger() {
	\SyRPC\Common::$lg = \SyRPC\Common::setupLogger(
			'syrpc-php-test',
			Logger::DEBUG
	);
}

function getSettings() {
	$settings = array();
	$settings['app_name']        = 'syrpc';
	$settings['amq_virtualhost'] = '/';
	$settings['amq_host']        = 'localhost';
	$settings['amq_user']        = 'guest';
	$settings['amq_password']    = 'guest';
	return $settings;
}

function runServerForever() {
	runServer(true);
}

function runServer($forever=false) {
	Common::$lg->addDebug(sprintf("Starting server in forever mode: %s", ($forever === true)));
	$settings = getSettings();
	$server = Server::getInstance($settings);
	if ($forever) {
		while (true) {
			try {
				serveOne($server);
			}
			catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
				exit(1);
			}
		}
	}
	else {
		serveOne($server);
	}
}

function serveOne($server) {
	list($type, $resultId, $data) = $server->getRequest();
	Common::$lg->addDebug("Server got request {$resultId}");
	if ($type == 'echo') {
		$server->putResult($resultId, $data);
		Common::$lg->addDebug("Server put result for request {$resultId}");
	}
	else {
		Common::$lg->addDebug("Server got no request within timeout");
	}
}

function runClient() {
	$settings = getSettings();
	$client = Client::getInstance($settings);
	$type = 'echo';
	$data = array(
		'foo' => 'bar',
		'baz' => 9001
	);
	$resultId = $client->putRequest($type, $data);
	Common::$lg->addDebug("Client put request $resultId on AMQ");
	$result = $client->getResult($resultId);
	Common::$lg->addDebug("Client got data for {$resultId}");
	if (
		$result->{'foo'} == $data['foo'] &&
		$result->{'baz'} == $data['baz']
	) {
		exit(0);
	}
	else {
		echo "Client received wrong data for {$resultId}";
		exit(1);
	}
}

setupLogger();

if (isset($argv[1])) {
	$mode = $argv[1];
	$forever = isset($argv[2]) && $argv[2] == 'forever';
	if ($mode == MODE_CLIENT) {
		runClient($forever);
	}
	else if ($mode == MODE_SERVER) {
		runServer($forever);
	}
	else {
		Common::$lg->addCritical("Unknown mode, select either 'client' or 'server'");
	}
}
else {
		Common::$lg->addCritical("No mode provided, provide either 'client' or 'server'");
}
