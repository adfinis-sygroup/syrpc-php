<?php
/**
 * This file holds several constants used within SyMonitoring RPC frontend.
 */

namespace SyRPC\Constants;

use Monolog\Logger;

// Define an AMQ queue timeout of 3 hours
// Yes, we need to do it as define, as PHP does not allow constants from
// expressions..
define('AMQ_TTL', 3 * 60 * 60);

/**
 * General constants.
 */
class General {
    const ENCODING    = 'utf-8';
    const APP_NAME    = 'symonitoring_rpc';
    const NUM_WORKERS = 2;
	const TIMEOUT     = 30; //in seconds
}

/**
 * AMQ specific constants.
 */
class AMQ {
    const VIRTUALHOST   = '/';
    const TTL           = AMQ_TTL;
    const MSG_TTL       = 10;
    const NUM_QUEUES    = 64;
    const HASH          = 'EdaeYa6eesh3ahSh';
    const DEFAULT_PORT  = 5672;
    const CONTENT_TYPE  = 'application/json';
    const DELIVERY_MODE = 2;
}

/**
 * RPC specific constants.
 */
class RPC {
    const REQUEST_NAME          = '%s_request';
    const REQUEST_EXCHANGE_TYPE = 'direct';
    const RESULT_EXCHANGE_NAME  = '%s_result_exchange';
    const RESULT_EXCHANGE_TYPE  = 'direct';
    const RESULT_QUEUE_NAME     = '%s_result_queue_%s';
}

class LOGGING {
	const IDENTITY = 'symonitoring-rpc-frontend';
	const LEVEL    = Logger::DEBUG;
}
