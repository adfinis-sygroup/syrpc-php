<?php

namespace SyRPC;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Sends requests and receives results to/from a RabbitMQ queue over AMQP.
 *
 * Generates an UUID (version 4) per request and submits the reuquest to
 * RabbitMQ. Receives results for a a given result ID.
 */
class Client extends RPCBase {

	/**
	 * Object instance.
	 *
	 * @var Client $_instance
	 */
	static private $_instance;

	/**
	 * Returns a singleton instance of an Client object.
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
	 *                       * timeout         (optional)
	 *
	 * @return        RPCBase  instance
	 */
	public static function & getInstance($settings) {

		if (is_null(self::$_instance)) {
			self::$_instance = new self($settings);
		}
		return self::$_instance;
	}

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
	 *                       * timeout         (optional)
	 *
	 * @return        void
	 */
	protected function __construct($settings) {
		parent::__construct($settings);
	}

	/**
	 * Puts a requests on a RabbitMQ queue over AMQP.
	 *
	 * Generates a request using an UUID (version 4) for identification for the
	 * given type and data and puts it on RabbitMQ queue.
	 *
	 * @param         string $type Type of the request, e.g. 'ping'
	 * @param         string $data Optional. Data to submit
	 * @returns       string       The generated UUID
	 */
	public function putRequest($type, $data=array()) {
		$resultId = Common::getUUID4();
		$body = array(
			'result_id' => $resultId,
			'type'     => trim($type),
			'data'      => $data
		);
		$message = new AMQPMessage(
			json_encode($body),
			array(
				'content_type'    => Constants\AMQ::CONTENT_TYPE,
				'content_encoding'=> $this->msgEncoding,
				'delivery_mode'   => Constants\AMQ::DELIVERY_MODE,
				'correlation_id'  => $resultId
			)
		);
		$this->requestChannel->basic_publish(
			$message,
			$this->requestExchange,
			$this->requestRoutingKey
		);
		Common::$lg->addDebug("Client put request $resultId on $this->requestExchange");
		return $resultId;
	}

	/**
	 * Tries to get the result for given result ID from RabbitMQ.
	 *
	 * @throws        AMQPTimeoutException
	 * @param         string $resultId The UUID of the result to getc
	 * @param         int    $timeout  Timeout in seconds after which the fetching shall stop
	 * @returns       string           JSON encoded data string
	 */
	public function getResult($resultId, $timeout=null) {
		if (is_null($timeout)) {
			$timeout = $this->timeout;
		}
		$hashId = Common::getHash($resultId, $this->amqNumQueues);
		$resultQueue = $this->getResultQueue($hashId);
		$this->waitId   = $resultId;
		Common::$lg->addDebug(sprintf(
			"Client waiting for request %s during %ds on %s (exchange %s)",
			$resultId,
			$timeout,
			$resultQueue,
			$this->resultExchange
		));
		$this->resultChannel->basic_consume(
			$resultQueue,             // Queue
			'',                       // Consumer tag
			false,                    // No local
			false,                    // No ack
			false,                    // No exclusive
			false,                    // No wait
			array($this, 'onResult')  // Callback
		);
		while (!$this->response) {
			try {
				$this->resultChannel->wait(null, false, $timeout);
			}
			catch (AMQPTimeoutException $e) {
				Common::$lg->addCritical("Client hit the fan after {$timeout}s");
				throw $e;
			}
		}
		$res  = json_decode($this->response);
		$data = $res->{'data'};
		$this->response = null;
		return $data;
	}

	/**
	 * Callback function for when a result arrives on the subscribed queue.
	 *
	 * @param         AMQPMessage    $msg  The message which arrived
	 * @returns       void
	 */
	public function onResult($msg) {
		Common::$lg->addDebug(sprintf("Client received a result msg"));
		$resp = json_decode($msg->body);
		$resultId = $resp->{'result_id'};
		if ($this->waitId == $resultId) {
			Common::$lg->addDebug("Client got result for {$this->waitId}");
			$this->response = $msg->body;
			$msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
		}
		else {
			Common::$lg->addWarning("Client received a wrong result {$resultId}");
			$msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], true);
			$this->response = null;
		}
	}
}
