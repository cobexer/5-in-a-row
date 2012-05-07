<?php
/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */

class WebSocketServer {
	// see http://tools.ietf.org/html/rfc6455#section-1.3
	private static $HandshakeMagicGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

	public static $doDebug = false;
	public static $allowUnmaskedPayload = false;

	public static function log($message) {
		$time = microtime(true);
		printf("%s%03d[%03d] %s\n", strftime("%Y%m%d %H:%M:%S.", $time), ($time % 1000), ($time - floor($time)) * 1000, $message);
	}

	public function logSocketError($message, $socket = false) {
		$errorcode = false !== $socket ? socket_last_error($socket) : socket_last_error();
		if ($errorcode) {
			$error = socket_strerror($errorcode);
			self::log($message . ': ' . $error);
		}
	}

	private $socket;
	private $sockets = array();
	private $clients = array();
	private $endpoints = array();

	function __construct($address, $port) {
		set_time_limit(0);
		ob_implicit_flush();

		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die(self::logSocketError("failed creating server socket"));
		socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1) or die(self::logSocketError("setting options failed"));
		socket_bind($this->socket, $address, $port) or die(self::logSocketError("bind failed"));
		socket_listen($this->socket, 10) or die(self::logSocketError("listen failed"));
		$this->sockets[] = $this->socket;
	}

	public function run() {
		for(;;) {
			$changed = $this->sockets;
			$write = null;
			$except = $this->sockets;
			socket_select($changed, $write, $except, null);
			foreach($changed as $s) {
				if($s === $this->socket) {
					$clientSocket = socket_accept($this->socket);
					if (false === $clientSocket) {
						self::logSocketError("error accepting new client");
						continue;
					}
					self::log("new connection accepted " . $clientSocket);
					$this->sockets[] = $clientSocket;
				}
				else {
					$read = @socket_recv($s, $buffer, 8 * 1024, 0); // FIXME: this limit is silly, fix it when going to non-blocking sockets
					if (false === $read) {
						self::logSocketError("error receiving data from client " . $s);
						$this->disconnect($s, false);
					}
					elseif (0 === $read) {
						$this->disconnect($s, true); // EOF, close connection
					}
					else {
						if ($this->getUserBySocket($s, $user)) {
							try {
								$this->onMessage($user, new WebSocketMessage($buffer));
							}
							catch (Exception $ex) {
								self::log('error processing message from (' . $s . '), disconnecting. The error was: ' . $ex->getMessage());
								$user->disconnect(false);
							}
						}
						else {
							try {
								$user = $this->doHandshake($s, $buffer);
								$this->clients[] = $user;
							}
							catch (HandshakeException $ex) {
								self::log('Handshake with (' . $s . ') failed: ' . $ex->getMessage());
								array_splice($this->sockets, array_search($s, $this->sockets), 1);
								socket_close($s);
								self::log('connection with (' . $s . ') closed');
							}
						}
					}
				}
			}
			foreach($except as $s) {
				if($s === $this->socket) {
					self::logSocketError("failure detected on socket: " . $s, $s);
				}
				else {
					$this->disconnect($s, false);
				}
			}

		}
	}

	public function addEndpoint(WebSocketEndpoint $endpoint) {
		self::log("adding endpoint for resource '" . $endpoint->getResource() . "'");
		$this->endpoints[$endpoint->getResource()] = $endpoint;
	}

	function disconnect($clientSocket, $success = true) {
		$theUser = null;
		foreach($this->clients as $index => $user) {
			if ($user->getSocket() === $clientSocket) {
				$theUser = $user;
				array_splice($this->clients, $index, 1);
				break;
			}
		}
		$index = array_search($clientSocket, $this->sockets);
		if (!$success) {
			self::logSocketError('error on socket (' . $clientSocket . '), closing connection', $clientSocket);
		}
		@socket_shutdown($clientSocket, 2); // both
		@socket_close($clientSocket);
		if (false !== $index) {
			array_splice($this->sockets, $index, 1);
		}
		if ($theUser) {
			$theUser->onDisconnected($success);
		}
	}

	private function onMessage(WebSocketUser $user, WebSocketMessage $message) {
		self::log('(' . $user->getSocket() . ')::onMessage opcode: 0x' . dechex($message->opcode));
		switch ($message->opcode) {
			case WebSocketMessage::$OPCODE_TEXT:
			case WebSocketMessage::$OPCODE_BINARY:
				if (!$user->is(WebSocketUser::$STATUS_CLOSED)) {
					$user->onMessage($message);
				}
				break;
			case WebSocketMessage::$OPCODE_DISCONNECT:
				if ($user->is(WebSocketUser::$STATUS_ONLINE)) {
					$user->send(new WebSocketControlMessageShutdown());
					$user->changeStatus(WebSocketUser::$STATUS_CLOSED);
					socket_shutdown($user->getSocket(), 1); // shutdown writing side of socket
				}
				elseif ($user->is(WebSocketUser::$STATUS_CLOSING)) {
					$user->changeStatus(WebSocketUser::$STATUS_CLOSED);
					$this->disconnect($user->getSocket(), true);
				}
				else {
					// duplicated close message, just shutdown
					$this->disconnect($user->getSocket(), false);
				}
				break;
			case WebSocketMessage::$OPCODE_PING:
				$user->send(new WebSocketControlMessagePong($message->data));
				break;
			case WebSocketMessage::$OPCODE_PONG:
				$user->onPong($message);
				break;
			default:
				self::log('unknown opcode, disconnecting');
				$this->disconnect($user->getSocket(), false);
				break;
		}
	}

	private function writeHandshakeResponse($socket, array $headers) {
		$response = join("\r\n", $headers) . "\r\n\r\n";
		$responseLength = strlen($response);
		return $responseLength === socket_write($socket, $response);
	}

	private function doHandshake($socket, $buffer) {
		try {
			$headers = $this->getHeaders($buffer);
			$response = array(
				"HTTP/1.1 101 Switching Protocols",
				"Upgrade: websocket",
				"Connection: Upgrade",
				"Sec-WebSocket-Accept: " . base64_encode(sha1($headers['sec-websocket-key'] . self::$HandshakeMagicGUID, true))
			);
			if (isset($headers['origin'])) {
				array_push($response, "Access-Control-Allow-Origin: *");
			}
			$foundEndpoint = false;
			foreach($this->endpoints as $eresource => $endpoint) {
				if (0 === strcmp($eresource, $headers[0])) {
					$foundEndpoint = $endpoint;
				}
			}
			if (false === $foundEndpoint) {
				throw new ReadHandshakeException('No endpoint for resource: "' . $headers[0] . '" found', array(
					'HTTP/1.1 404 Not Found',
					'Connection: close'
				));
			}
			if (!$this->writeHandshakeResponse($socket, $response)) {
				self::logSocketError('Error writing the handshake response to the client', $socket);
				throw new HandshakeException('--^');
			}
			$user = $foundEndpoint->onNewUser($socket);
			if ($user instanceof WebSocketUser) {
				self::log('New user (' . $socket . ') for endpoint "' . $headers[0] . '" created');
			}
			else {
				throw new HandshakeException('The endpoint for resource "' . $headers[0] . '" did not return a valid user');
			}
			return $user;
		}
		catch (ReadHandshakeException $ex) {
			$this->writeHandshakeResponse($socket, $ex->getResponseHeaders());
			throw $ex;
		}
	}

	private function checkHeader($headers, $required, $name, &$result, $value = null, $acceptMultiple = true) {
		$name = strtolower($name);
		if (isset($headers[$name])) {
			$header = $headers[$name];
			if ($acceptMultiple) {
				if (null !== $value) {
					if (is_array($header)) {
						foreach($header as $h) {
							if (0 === strcasecmp($h, $value)) {
								$result = $header;
								return true;
							}
						}
					}
					elseif (0 === strcasecmp($value, $header)) {
						$result = $header;
						return true;
					}
				}
				else {
					$result = $header;
					return true;
				}
			}
			elseif (!is_array($header)) {
				$result = $header;
				return true;
			}
		}
		if (true === $required) {
			throw new ReadHandshakeException('header "' . $name . '" missing or invalid');
		}
		return false;
	}

	private function getHeaders($req) {
		$dummy; // used for headers where the value does not matter
		$reqHeaders = explode("\r\n", trim($req));
		$headers = array();
		if (is_array($reqHeaders) &&
			count($reqHeaders) > 0 &&
			preg_match('/^GET\\s*(.*?)\\s*HTTP\\/(\\d\\.\\d)$/', $reqHeaders[0], $requestLine) &&
			version_compare($requestLine[2], '1.1', '>=')) {
			$headers[0] = $requestLine[1];
		}
		else {
			throw new ReadHandshakeException('Request line malformed');
		}
		array_splice($reqHeaders, 0, 1); // remove request line
		foreach($reqHeaders as $header) {
			if (preg_match('/^([^:]*):(.*)$/', $header, $match)) {
				$name = trim(strtolower($match[1]));
				$val = trim($match[2]);
				$value = explode(', ', $val);
				if (1 >= count($value)) {
					$value = $val;
				}
				if (!isset($headers[$name])) {
					$headers[$name] = $value;
				}
				elseif (is_array($headers[$name])) {
					array_push($headers[$name], $value);
				}
				else {
					$headers[$name] = array($headers[$name], $value);
				}
			}
			else {
				throw new ReadHandshakeException('Malformed header found');
			}
		}
		// check mandatory headers
		$this->checkHeader($headers, true, 'Sec-WebSocket-Key', $dummy, null, false);
		$this->checkHeader($headers, true, 'Sec-WebSocket-Version', $version);
		if (($version !== '13') || (is_array($version) && false === array_search('13', $version))) {
			throw new ReadHandshakeException("unsupported Protocol version", array(
				'HTTP/1.1 426 Upgrade Required',
				'Sec-WebSocket-Version: 13'
			));
		}
		$this->checkHeader($headers, true, 'Upgrade', $dummy, 'websocket', false);
		$this->checkHeader($headers, true, 'Connection', $dummy, 'upgrade');
		if ($this->checkHeader($headers, false, 'Sec-WebSocket-Protocol', $protocols)) {
			//check if one of the supplied sub protocols is supported and echo that back to the client - NYI
		}
		if ($this->checkHeader($headers, false, 'Sec-WebSocket-Extensions', $extensions)) {
			//check the supplied extensions - NYI
		}
		return $headers;
	}

	function getUserBySocket($socket, &$user) {
		foreach ($this->clients as $u) {
			if ($u->getSocket() === $socket) {
				$user = $u;
				return true;
			}
		}
		return false;
	}

	public function getAllClients() {
		return $this->clients;
	}

	private function newUser($resource, $socket) {
		foreach($this->endpoints as $eresource => $endpoint) {
			if ($eresource === $resource) {
				$user = $endpoint->onNewUser($socket);
				if (!$user instanceof WebSocketUser) {
					throw new HandshakeException('The endpoint for resource "' . $resource . '" did not return a valid user');
				}
				self::log('new user (' . $socket . ') for endpoint "' . $resource . '" created');
				return $user;
			}
		}
		throw new HandshakeException('No endpoint for resource: "' . $resource . '" found');
	}
}

class HandshakeException extends Exception {
	public function __construct($message) {
		parent::__construct($message);
	}
}

class ReadHandshakeException extends HandshakeException {
	private $responseHeaders;
	public function __construct($message, $responseHeaders = null) {
		parent::__construct($message);
		$this->responseHeaders = $responseHeaders;
		if (null === $this->responseHeaders) {
			$this->responseHeaders = array(
				'HTTP/1.1 400 Bad Request'
			);
		}
	}

	public function getResponseHeaders() {
		return $this->responseHeaders;
	}
}

class IllegalStateChangeException extends Exception {
	public function __construct($message) {
		parent::__construct($message);
	}
}

class InvalidFrameException extends Exception {
	public function __construct($message) {
		parent::__construct($message);
	}
}

class FailedToWriteDataException extends Exception {
	public function __construct($message) {
		parent::__construct($message);
	}
}

interface WebSocketEndpoint {
	/*
	 * Implement the getResource method to return the resource this endpoint wants to listen for users.
	 */
	function getResource();
	/*
	 * Implement the onNewUser method to create a new WebSocketUser instance for the given socket,
	 * in case the returned user is falsy the connection will be closed immediately.
	 */
	function onNewUser($socket);
}

class WebSocketMessage {
	//other opcodes are considered invalid(reserved, thus not expected)
	public static $OPCODE_CONT = 0x0;
	public static $OPCODE_TEXT = 0x1;
	public static $OPCODE_BINARY = 0x2;
	public static $OPCODE_DISCONNECT = 0x8;
	public static $OPCODE_PING = 0x9;
	public static $OPCODE_PONG = 0xA;

	public $opcode;
	public $data;
	private $payload;
	public function __construct($opcode, $payload = null) {
		if (is_int($opcode)) {
			// construct new server to client frame
			$this->opcode = $opcode;
			$this->payload = $payload;
		}
		else {
			// decode client to server frame
			$this->constructFromBytes($opcode);
		}
	}

	private function constructFromBytes($bytes) {
		$byte0 = ord($bytes[0]);
		$frame = array(
			'fin'  => 0 !== ($byte0 & 0x80),
			'rsv1' => 0 !== ($byte0 & 0x40),
			'rsv2' => 0 !== ($byte0 & 0x20),
			'rsv3' => 0 !== ($byte0 & 0x10),
			'opcode' => $byte0 & 0x0F,
			'masked' => 0 !== (ord($bytes[1]) & 0x80)
		);
		$length = ord($bytes[1]) & 0x7F;
		$decodedData = '';
		if($frame['masked'] === true) {
			if($length === 126) {
				$frame['mask'] = substr($bytes, 4, 4);
				$frame['rawPayload'] = substr($bytes, 8);
			}
			elseif($length === 127) {
				$frame['mask'] = substr($bytes, 10, 4);
				$frame['rawPayload'] = substr($bytes, 14);
			}
			else {
				$frame['mask'] = substr($bytes, 2, 4);
				$frame['rawPayload'] = substr($bytes, 6);
			}
			$mask = $frame['mask'];
			$rawPayload = $frame['rawPayload'];
			$payloadLength = strlen($rawPayload);
			for($i = 0; $i < $payloadLength; ++$i) {
				$decodedData .= $rawPayload[$i] ^ $mask[$i % 4];
			}
		}
		elseif (WebSocketServer::$allowUnmaskedPayload) {
			if($length === 126) {
				$decodedData = substr($bytes, 4);
			}
			elseif($length === 127) {
				$decodedData = substr($bytes, 10);
			}
			else {
				$decodedData = substr($bytes, 2);
			}
		}
		else {
			throw new InvalidFrameException('received unmasked frame, but unmasked frames are disabled.');
		}
		if (WebSocketServer::$doDebug) {
			if (isset($frame['mask'])) {
				$frame['mask'] = bin2hex($frame['mask']);
			}
			$frame['payload'] = $decodedData;
			var_dump(array($frame, bin2hex($frame['rawPayload']), $decodedData));
		}
		$this->opcode = $frame['opcode'];
		$this->data = $decodedData;
	}

	public function send($socket) {
		// message framing NYI
		$message = '';
		$message[] = chr(0x80 + $this->opcode);
		$payloadLength = strlen($this->payload);
		// masking not supported (and not needed from server to client, thus the next bit is always 0)
		if ($payloadLength <= 125) {
			$message[] = chr($payloadLength);
		}
		elseif ($payloadLength <= 0xFFFF) {
			// this branch is untested
			$message[] = chr(126);
			$message[] = chr(($payloadLength & 0xFF00) >> 8);
			$message[] = chr(($payloadLength & 0x00FF) >> 0);
		}
		else {
			// this branch is untested
			$message[] = chr(127);
			$message[] = chr(($payloadLength & 0xFF00000000000000) >> 56);
			$message[] = chr(($payloadLength & 0x00FF000000000000) >> 48);
			$message[] = chr(($payloadLength & 0x0000FF0000000000) >> 40);
			$message[] = chr(($payloadLength & 0x000000FF00000000) >> 32);
			$message[] = chr(($payloadLength & 0x00000000FF000000) >> 24);
			$message[] = chr(($payloadLength & 0x0000000000FF0000) >> 16);
			$message[] = chr(($payloadLength & 0x000000000000FF00) >>  8);
			$message[] = chr(($payloadLength & 0x00000000000000FF) >>  0);
		}
		$message = join('', $message);
		WebSocketServer::log(__FUNCTION__ . ': writing response 0x' . dechex($this->opcode) . ', ' . $payloadLength . ' Bytes payload');
		$written = @socket_write($socket, $message);
		if ($written !== strlen($message)) {
			//TODO: continue writes in such cases
			throw new FailedToWriteDataException('writing header failed');
		}
		if ($payloadLength > 0) {
			$written = @socket_write($socket, $this->payload);
			if ($written !== $payloadLength) {
				//TODO: continue writes in such cases
				throw new FailedToWriteDataException('writing payload failed');
			}
		}
	}

	public function isText() {
		return $this->opcode === self::$OPCODE_TEXT;
	}

	public function isBinary() {
		return $this->opcode === self::$OPCODE_BINARY;
	}

	public function __toString() {
		return __CLASS__ . '[0x' . dechex($this->opcode) . ', ' . max(strlen($this->data), strlen($this->payload)) . ' Bytes]';
	}
}

class WebSocketControlMessageShutdown extends WebSocketMessage {
	public function __construct($statusCode = null, $payload = null) {
		//TODO: add support for error message and error payload
		parent::__construct(self::$OPCODE_DISCONNECT);
	}
}

class WebSocketControlMessagePing extends WebSocketMessage {
	public function __construct($payload) {
		parent::__construct(self::$OPCODE_PING, $payload);
	}
}

class WebSocketControlMessagePong extends WebSocketMessage {
	public function __construct($payload) {
		parent::__construct(self::$OPCODE_PONG, $payload);
	}
}

abstract class WebSocketUser {
	public static $STATUS_ONLINE = 1;
	public static $STATUS_CLOSING = 2;
	public static $STATUS_CLOSED = 3;
	private $socket;
	private $status;
	public function __construct($socket) {
		$this->socket = $socket;
		$this->status = self::$STATUS_ONLINE;
	}

	public function is($status) {
		return $this->status === $status;
	}

	public function changeStatus($status) {
		switch($status) {
			case self::$STATUS_CLOSING:
				if ($this->is(self::$STATUS_ONLINE)) {
					$this->status = $status;
					return;
				}
				break;
			case self::$STATUS_CLOSED:
				if ($this->is(self::$STATUS_CLOSING) || $this->is(self::$STATUS_ONLINE)) {
					$this->status = $status;
					return;
				}
				break;
			default:
				throw new IllegalStateChangeException('unknown status ' . $status);
				break;
		}
		throw new IllegalStateChangeException('cannot change status from ' . $this->status . ' to ' . $status);
	}

	public function getSocket() {
		return $this->socket;
	}

	public function ping($payload = null) {
		$this->send(new WebSocketControlMessagePing($payload));
	}

	public function send($message) {
		if ($this->is(self::$STATUS_ONLINE)) {
			if (is_array($message)) {
				$message = new WebSocketMessage(WebSocketMessage::$OPCODE_TEXT, json_encode($message));
			}
			if (!($message instanceof WebSocketMessage)) {
				$message = new WebSocketMessage(WebSocketMessage::$OPCODE_TEXT, $message);
			}
			WebSocketServer::log('(' . $this->socket . ')::send ' . $message);
			$message->send($this->socket);
		}
		else {
			WebSocketServer::log('(' . $this->socket . ')::send tried to send message to client that is not online (' . $this->status . ')');
		}
	}

	public function disconnect() {
		if ($this->is(self::$STATUS_ONLINE)) {
			$this->send(new WebSocketControlMessageShutdown());
			$this->changeStatus(self::$STATUS_CLOSING);
			socket_shutdown($this->socket, 1); // shutdown write side of the socket
		}
	}

	/*
	 * override the onMessage handler to process messages from this user
	 */
	abstract function onMessage(WebSocketMessage $msg);

	/*
	 * override the onPong handler to receive notification when a the other end responded to a ping
	 */
	public function onPong(WebSocketMessage $msg) {
	}

	/*
	 * called after this user has been disconnected
	 */
	abstract function onDisconnected($success);
}