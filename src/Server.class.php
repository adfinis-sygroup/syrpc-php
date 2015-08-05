<?php

namespace SyRPC;

require __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Class for handling the exception of an empty AMQ queue.
 */
class EmptyException         extends \Exception {}

/**
 * Receives requests and sends results from/to a RabbitMQ queue over AMQP.
 */
class Server extends RPCBase {

	/**
	 * Object instance.
	 *
	 * @var Server $_instance
	 */
	static private $_instance;

	/**
	 * Returns a singleton instance of an Server object.
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
	 * Sets up basic consumption on queue for handling requests.
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
		parent::__construct($settings);
		$this->requestChannel->basic_consume(
			$this->requestQueue,
			'',
			false,
			false,
			false,
			false,
			array($this, 'onRequest')
		);
	}

	/**
	 * Receives a request from a RabbitMQ queue over AMQP.
	 *
	 *
	 * @throws        AMQPTimeoutException When a timeout occurs
	 * @param         int    $timeout      Timeout in seconds after which the
	 *                                     listening shall be cancelled
	 * @returns       void
	 */
	public function getRequest($timeout) {
		Common::$lg->addDebug("Server waiting for requests during {$timeout}s on {$this->requestExchange}");
		while (!$this->response) {
			try {
				$this->requestChannel->wait(null, false, $timeout);
			}
			catch (AMQPTimeoutException $e) {
				Common::$lg->addDebug("Server ran into timeout after {$timeout}s");
				throw $e;
			}
		}
		Common::$lg->addDebug("Server got a response");
		$res = $this->response;
		$this->response = null;
		$body = Common::splitMessageBody($res);
		if ($body) {
			$resp     = json_decode($body);
			$resultId = $resp->{'result_id'};
			$data     = $resp->{'data'};
			$type     = $resp->{'type'};
			return array($type, $resultId, $data);
		}
		throw new EmptyException();
	}

	/**
	 * Puts a result on a RabbitMQ queue over AMQP.
	 *
	 * Puts the data for the given result ID (UUID, version 4) on RabbitMQ
	 * queue.
	 *
	 * @param         string $resultId The UUID of the result to put
	 * @param         string $data Data to submit
	 * @returns       void
	 */
	public function putResult($resultId, $data) {
		$routingKey = $resultId;
		$hashId = Common::getHash($routingKey, $this->amqNumQueues);
		$resultQueue = $this->getResultQueue($hashId);
		$body = array(
			'result_id' => $resultId,
			'data'      => $data
		);
		$serializedBody = json_encode($body);
		$message = new AMQPMessage(
			$serializedBody,
			array(
				'content_type'     => Constants\AMQ::CONTENT_TYPE,
				'content_encoding' => $this->msgEncoding,
				'delivery_mode'    => Constants\AMQ::DELIVERY_MODE,
				'correlation_id'   => $hashId,
			)
		);
		$this->resultChannel->basic_publish(
			$message,
			$this->resultExchange,
			$hashId
		);
		Common::$lg->addDebug("Server published result $resultId within {$resultQueue} -> {$this->resultExchange}");
	}

	/**
	 * Callback function for when a request arrives on the request queue.
	 *
	 * @param         AMQPMessage    $msg  The message which arrived
	 * @returns       void
	 */
	public function onRequest($msg) {
		Common::$lg->addDebug("Server received a msg: " . print_r($msg->body, true));
		$body = Common::splitMessageBody($msg->body);
		if ($body) {
			$resp = json_decode($body);
			$resultId = $resp->{'result_id'};
			Common::$lg->addDebug(sprintf(
				"Server received request %s (%s)",
				$resultId, $msg->get('delivery_tag')
			));
			$this->response = $body;
			$this->requestChannel->basic_ack($msg->get('delivery_tag'));
		}
	}
}
