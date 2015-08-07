<?php

namespace SyRPC;

use PhpAmqpLib\Connection\AMQPConnection;

/**
 * Base class for SyMonitoring RPC (remote procedure call)
 */
class RPCBase {

	/**
	 * Name of the application.
	 *
	 * @var string $appName
	 */
	protected $appName;
	/**
	 * Virtualhost used within AMQ.
	 *
	 * @var string $amqVirtualhost
	 */
	protected $amqVirtualhost;
	/**
	 * Time to live for a single AMQ queue
	 *
	 * @var int $amqTTL
	 */
	protected $amqTTL;
	/**
	 * Time to live for a single AMQ message
	 *
	 * @var int $amqMsgTTL
	 */
	protected $amqMsgTTL;
	/**
	 * Number of queues to be spawned.
	 *
	 * @var int $amqNumQueues
	 */
	protected $amqNumQueues;
	/**
	 * Hostname of the AMQ host.
	 *
	 * @var string $amqHost
	 */
	protected $amqHost;
	/**
	 * Username for connecting to AMQ host.
	 *
	 * @var string $amqUser
	 */
	protected $amqUser;
	/**
	 * Password for connecting to AMQ host.
	 *
	 * @var string $amqPassword
	 */
	protected $amqPassword;
	/**
	 * Connection to AMQ host.
	 *
	 * @var AbstractConnection $amqConnection
	 */
	protected $amqConnection;
	/**
	 * Response to a request.
	 *
	 * @var string $response
	 */
	protected $response;
	/**
	 * The UUID of the requested result.
	 *
	 * @var string $waitId
	 */
	protected $waitId;
	/**
	 * Exchange for making requests.
	 *
	 * @var string $requestExchange
	 */
	protected $requestExchange;
	/**
	 * Channel for making requests.
	 *
	 * @var AbstractChannel $requestChannel
	 */
	protected $requestChannel;
	/**
	 * Queue for making requests.
	 *
	 * @var string $requestQueue
	 */
	protected $requestQueue;
	/**
	 * Routing key for identifying an requests within AMQ
	 *
	 * @var string $requestRoutingKey
	 */
	protected $requestRoutingKey;
	/**
	 * Exchange for getting results.
	 *
	 * @var string $resultExchange
	 */
	protected $resultExchange;
	/**
	 * Channel for getting results.
	 *
	 * @var AbstractChannel $resultChannel
	 */
	protected $resultChannel;
	/**
	 * Array holding NUM_QUEUES queues for receiving results.
	 *
	 * @var array $resultQueues
	 */
	protected $resultQueues;
	/**
	 * Encoding of the messages.
	 *
	 * @var string $msgEncoding
	 */
	protected $msgEncoding;

	/**
	 * Class constructor.
	 *
	 * @param         array  $settings Array holding settings whereas those are:
	 *                       * app_name        (mandatory)
	 *                       * amq_host        (mandatory)
	 *                       * amq_virtualhost (optional)
	 *                       * amq_user        (optional)
	 *                       * amq_password    (optional)
	 *                       * amq_transport   (optional)
	 *                       * amq_ttl         (optional)
	 *                       * amq_msg_ttl     (optional)
	 *                       * amq_num_queues  (optional)
	 *                       * msg_encoding    (optional)
	 * @return        void
	 */
	protected function __construct($settings) {
		$this->appName         = $settings['app_name'];
		if (array_key_exists('amq_virtualhost', $settings)) {
			$this->amqVirtualhost = $settings['amq_virtualhost'];
		}
		else {
			$this->amqVirtualhost = Constants\AMQ::VIRTUALHOST;
		}
		if (array_key_exists('amq_ttl', $settings)) {
			$this->amqTTL = $settings['amq_ttl'];
		}
		else {
			$this->amqTTL = Constants\AMQ::TTL;
		}
		if (array_key_exists('amq_msg_ttl', $settings)) {
			$this->amqMsgTTL = $settings['amq_msg_ttl'];
		}
		else {
			$this->amqMsgTTL = Constants\AMQ::MSG_TTL;
		}
		if (array_key_exists('amq_num_queues', $settings)) {
			$this->amqNumQueues = $settings['amq_num_queues'];
		}
		else {
			$this->amqNumQueues = Constants\AMQ::NUM_QUEUES;
		}
		if (array_key_exists('msg_encoding', $settings)) {
			$this->msgEncoding = $settings['msg_encoding'];
		}
		else {
			$this->msgEncoding = Constants\General::ENCODING;
		}
		$this->amqHost           = $settings['amq_host'];
		$this->amqUser           = $settings['amq_user'];
		$this->amqPassword       = $settings['amq_password'];
		$this->amqConnection     = null;
		$this->response          = null;
		$this->waitId            = null;
		$this->requestChannel    = null;
		$this->requestExchange   = null;
		$this->requestQueue      = null;
		$this->requestRoutingKey = null;
		$this->resultChannel     = null;
		$this->resultExchange    = null;
		$this->resultQueues      = new \SplFixedArray($this->amqNumQueues);
		$this->initAMQConnection();
		$this->setupRequestQueue();
		$this->setupResultQueues();
	}

	/**
	 * Class destructor.
	 */
	public function __destruct() {
		if ($this->amqConnection) {
			if ($this->requestChannel) {
				$this->requestChannel->close();
			}
			if ($this->resultChannel) {
				$this->resultChannel->close();
			}
			$this->amqConnection->close();
			Common::$lg->addDebug("Closed connection to AMQ");
		}
	}

	/**
	 * Initializes a connection to AMQ using the provided settings.
	 *
	 * @throws \Exception     When the connection to AMQ cannot be established
	 */
	private function initAMQConnection() {
		Common::$lg->addDebug("Initializing connection to AMQ");
		try {
			$this->amqConnection = new AMQPConnection(
				$this->amqHost,
				Constants\AMQ::DEFAULT_PORT,
				$this->amqUser,
				$this->amqPassword,
				$this->amqVirtualhost
			);
			Common::$lg->addDebug("Successfully connected to AMQ");
		}
		catch (\Exception $e) {
			Common::$lg->addCritical(sprintf(
				"%s: %s","Could not connect to AMQ", $e->getMessage()
			));
			throw $e;
		}
	}

	/**
	 * Returns a result queue for the given index.
	 *
	 * Sets up a result queue for the given index if the queue already exists,
	 * otherwise the queue is created and bound to the result exchange.
	 *
	 * @param         int       $index   Index of the result queue to get/create
	 * @returns       mixed Result queue for the given index
	 */
	protected function getResultQueue($index) {
		Common::$lg->addDebug("Trying to get result queue for index {$index}");
		$queue = $this->resultQueues[$index];
		if ($queue) {
			Common::$lg->addDebug("Already had queue for index {$index}");
			return $queue;
		}
		else {
			Common::$lg->addDebug("Setting up queue for index {$index}");
			Common::$lg->addDebug(sprintf(Constants\RPC::RESULT_QUEUE_NAME, $this->appName, $index));
			list($queue) = $this->resultChannel->queue_declare(
				sprintf(Constants\RPC::RESULT_QUEUE_NAME, $this->appName, $index),
				false,
				true,
				false,
				false,
				false,
				array(
					'x-expires'     => array("I", $this->amqTTL    * 1000),
					'x-message-ttl' => array("I", $this->amqMsgTTL * 1000),
				)
			);
			$this->resultChannel->queue_bind(
				$queue,
				$this->resultExchange,
				$index,
				false
			);
			$this->resultQueues[$index] = $queue;
			return $queue;
		}
	}

	/**
	 * Sets up a work queue for handling requests.
	 * Uses exchange `direct`, with all the same exchange name, routing key
	 * and queue name.
	 *
	 * @returns void
	 */
	private function setupRequestQueue() {
		Common::$lg->addDebug("Setting up request queue");
		$this->requestChannel = $this->amqConnection->channel();
		$this->requestRoutingKey = sprintf(Constants\RPC::REQUEST_NAME, $this->appName);
		$this->requestExchange = $this->requestRoutingKey;
		$this->requestChannel->exchange_declare(
			$this->requestExchange,
			Constants\RPC::REQUEST_EXCHANGE_TYPE,
			false,
			true,
			// Must be false because of some bug
			false
		);
		list($this->requestQueue) = $this->requestChannel->queue_declare(
			sprintf(Constants\RPC::REQUEST_NAME, $this->appName),
			false,
			true,
			false,
			false
		);
		$this->requestChannel->queue_bind(
			$this->requestQueue,
			$this->requestExchange,
			$this->requestRoutingKey
		);
		Common::$lg->addDebug("Finished setting up request queue");
	}

	/**
	 * Sets up a channel and exchange for results.
	 *
	 * @returns void
	 */
	private function setupResultQueues() {
		Common::$lg->addDebug(sprintf("Setting up %d result queues", $this->amqNumQueues));
		$this->resultChannel = $this->amqConnection->channel();
		$this->resultExchange = sprintf(Constants\RPC::REQUEST_NAME, $this->appName);
		$this->resultChannel->exchange_declare(
			$this->resultExchange,
			Constants\RPC::RESULT_EXCHANGE_TYPE,
			false,
			true,
			false
		);
		Common::$lg->addDebug("Finished setting up result queues");
	}
}
