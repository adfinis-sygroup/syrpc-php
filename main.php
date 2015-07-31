<?php

require 'src/vendor/autoload.php';

use Monolog\Logger;
use SyRPC\Common;
use SyRPC\Server;
use SyRPC\Client;

define("MODE_CLIENT", 'client');
define("MODE_SERVER", 'server');

function setupLogger() {
	foreach (Common::$lg->getHandlers() as $handler) {
		$handler->setLevel(Logger::DEBUG);
	}
}

function initSettings() {
	$settings = array();
	$settings['app_name']        = 'symonitoring_rpc';
	$settings['amq_virtualhost'] = '/';
	$settings['amq_host']        = 'localhost';
	$settings['amq_user']        = 'guest';
	$settings['amq_password']    = 'guest';
	return $settings;
}

function runClient($runnerMode=false) {
	Common::$lg->addDebug(sprintf("Starting client in runner mode: %s", ($runnerMode === true)));
	$settings = initSettings();
	$timeout = 10;
	$client = new Client($settings);
	setupLogger();
	if ($runnerMode) {
		while (true) {
			try {
				clientRunner($client, $timeout);
			}
			catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
				continue;
			}
		}
	}
	else {
		clientRunner($client, $timeout);
	}
}

function clientRunner($client, $timeout) {
	$type = 'ping';
	$data = json_encode(array(
		'foo' => 'bar',
		'baz' => 9001
	));
	$resultId = $client->putRequest($type, $data);
	Common::$lg->addDebug("Client put request $resultId on AMQ");
	$data = $client->getResult($resultId, $timeout);
	if ($data) {
		Common::$lg->addDebug("Client got data for {$resultId}");
	}
	else {
		Common::$lg->addDebug("Client got no data for {$resultId} within timeout {$timeout}s");
	}
}

function runServer($runnerMode=false) {
	Common::$lg->addDebug(sprintf("Starting server in runner mode: %s", ($runnerMode === true)));
	$settings = initSettings();
	$timeout = 10;
	$server = new Server($settings);
	setupLogger();
	if ($runnerMode) {
		while (true) {
			try {
				serverRunner($server, $timeout);
			}
			catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
				continue;
			}
		}
	}
	else {
		serverRunner($server, $timeout);
	}
}

function serverRunner($server, $timeout) {
	list($type, $resultId, $data) = $server->getRequest($timeout);
	if ($resultId) {
		Common::$lg->addDebug("Server got request {$resultId}");
		$type = 'some funny other type';
		$data = json_encode(array(
			'data' => array(
				'foo' => 'baz',
				'baz' => 9100,
				'is_running' => 'true',
			),
			'type_' => $type
		));
		$server->putResult($resultId, $data);
		Common::$lg->addDebug("Server put result for request {$resultId}");
	}
	else {
		Common::$lg->addDebug("Server got no request within timeout {$timeout}s");
	}
}

if (isset($argv[1])) {
	$mode = $argv[1];
	$runner = isset($argv[2]) && $argv[2] == 'runner';
	if ($mode == MODE_CLIENT) {
		runClient($runner);
	}
	else if ($mode == MODE_SERVER) {
		runServer($runner);
	}
	else {
		Common::$lg->addCritical("Unknown mode, select either 'client' or 'server'");
	}
}
else {
		Common::$lg->addCritical("No mode provided, provide either 'client' or 'server'");
}
