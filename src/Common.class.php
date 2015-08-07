<?php

namespace SyRPC;

use Monolog\Logger;
use Monolog\Handler\SyslogHandler;

/**
 * Helper class for handling common tasks.
 */
class Common {
	public static $lg;
	protected static $setupDone = false;

	/**
	 * Sets up a default logger for logging application specific messages.
	 *
	 * @param         str    $ident  The identity (application name) for syslog
	 * @param         int    $level  The level as of will be logged
	 * @returns       Logger         Root logger object
	 */
	public static function setupLogger($ident, $level)
	{
		if (static::$setupDone) {
			return static::$lg;
		}
		$root = new Logger(Constants\General::APP_NAME);
		$syslogHandler = new SyslogHandler($ident, LOG_USER, $level);
		$root->pushHandler($syslogHandler);
		static::$setupDone = true;
		return $root;
	}

	/**
	 * Generates an universally unique identifier.
	 *
	 * Source: http://php.net/manual/en/function.uniqid.php#94959
	 *
	 * @returns       string       The generated UUID
	 */
	public static function getUUID4() {
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

			// 32 bits for "time_low"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),

			// 16 bits for "time_mid"
			mt_rand(0, 0xffff),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0fff) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3fff) | 0x8000,

			// 48 bits for "node"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}

	/**
	 * Tries to split a message by \0.
	 *
	 * A received message may contain the encoding within the front of its
	 * body, separated by \0. This method tries to extract only the body, as
	 * PHP supports no encoding when en- resp. decoding JSON.
	 *
	 * @param         string    $messageBody  The body of the message
	 *                                        containing the encoding eventually
	 * @returns       string                  The body of the message without
	 *                                        the encoding
	 */
	public static function splitMessageBody($messageBody) {
		$body = strstr($messageBody, "\0");
		if (!$body) {
			return $messageBody;
		}
		return preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $body);
	}

	/**
	 * Hashes the given string based on the number of queuesd, using a salt.
	 *
	 * @param         string    $string  The string to be hashed
	 * @param         int       $count   [optional] The number of queues used
	 * @returns       string             SipHash of the given string
	 */
	public static function getHash($string, $count=Constants\AMQ::NUM_QUEUES) {
		/* Only use the last 31 bits of the 64- bit hash:
		 * - siphash actually is a 64-bit hash, but PHP does not always support
		 *   64-bit integers
		 * - therefore, sip_hash() would return an 8-byte string which can't be
		 *   used to take the modulus
		 * - unpack() only introduces 64-bit integers in version 5.6 and we want to
		 *   support windows
		 * - sip_hash32() returns "hash & 0xFFFFFFFF", i.e. a 32-bit integer...
		 * - ...but PHP does not support unsigned integers, so we need to cut off the
		 *   highest bit
		 * - This does not significantly affect the distribution, so it is safe to
		 *   use only part of the hash. If $count is a power of two the distribution
		 *   stays intact.
		 */
		$hash32 = \sip_hash32(Constants\AMQ::HASH, $string) & 0x7FFFFFFF;
		return $hash32 % $count;
	}
}
