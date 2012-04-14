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
	private $socket;
	private $sockets = array();
	private $clients = array();
	private $endpoints = array();

	function __construct($address, $port) {
		set_time_limit(0);
		ob_implicit_flush();

		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die($this->logSocketError("failed creating server socket"));
		socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1) or die($this->logSocketError("setting options failed"));
		socket_bind($this->socket, $address, $port) or die($this->logSocketError("bind failed"));
		socket_listen($this->socket, 10) or die($this->logSocketError("listen failed"));
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
						$this->logSocketError("error accepting new client");
						continue;
					}
					$this->log("new connection accepted " . $clientSocket);
					$this->sockets[] = $clientSocket;
				}
				else {
					$read = @socket_recv($s, $buffer, 4096, 0);
					if (0 === $read || false === $read) {
						if (false === $read) {
							$this->logSocketError("error receiving data from client");
						}
						$this->log("socket_recv failed, disconnect client " . $s);
						$this->disconnect($s);
					}
					else {
						if ($this->getUserBySocket($s, $user)) {
							$user->onMessage($this->unwrap($buffer));
						}
						else {
							$this->log("new client, initiating handshake " . $s);
							$resource = $this->dohandshake($s, $buffer);
							$user = $this->newUser($resource, $s);
							if (!$user) {
								$this->log("failed to create user object, disconecting " . $s);
								array_splice($this->sockets, array_search($s, $this->sockets), 1);
								socket_close($s);
							}
							else {
								$user->setWebsocketServer($this);
								$this->clients[] = $user;
							}
						}
					}
				}
			}
			foreach($except as $s) {
				if($s === $this->socket) {
					$this->logSocketError("failure detected on socket: " . $s, $s);
				}
				else {
					$this->disconnect($s, false);
				}
			}

		}
	}

	public function addEndpoint(WebSocketEndpoint $endpoint) {
		$this->log("adding endpoint for resource '" . $endpoint->getResource() . "'");
		$this->endpoints[$endpoint->getResource()] = $endpoint;

	}

	private function logSocketError($message, $socket = false) {
		$errorcode = false !== $socket ? socket_last_error($socket) : socket_last_error();
		if ($errorcode) {
			$error = socket_strerror($errorcode);
			log($message . ': ' . $error);
		}
	}

	private function log($message) {
		$time = time();
		printf("%s%03d %s\n", strftime("%Y%m%d %H:%M:%S.", $time), ($time % 1000), $message);
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
			$this->logSocketError("error processng socket " . $clientSocket . ", closing connection", $clientSocket);
		}
		@socket_close($clientSocket);
		if ($index >= 0) {
			array_splice($this->sockets, $index, 1);
		}
		if ($theUser) {
			$theUser->onDisconnected($success);
		}
	}

	private function dohandshake($socket, $buffer) {
		$headers = $this->getheaders($buffer);
		//TODO: check the request!! (check at least resource, and version, and key of course)
		$upgrade  = array(
			"HTTP/1.1 101 WebSocket Protocol Handshake",
			"Upgrade: WebSocket",
			"Connection: Upgrade",
			"Sec-WebSocket-Origin: " . $headers['origin'],
			"Sec-WebSocket-Accept: " .  $this->calcKeyHybi10($headers['key'])
		);
		$upgrade = join("\r\n", $upgrade) . "\r\n\r\n";

		socket_write($socket, $upgrade, strlen($upgrade));
		return $headers['resource'];
	}

	private function calcKeyHybi10($key) {
		$sha = sha1(trim($key) . self::$HandshakeMagicGUID, true);
		return base64_encode($sha);
	}

	private function getheaders($req) {
		//TODO: check this function for conformance with "Reading the Client's Opening Handshake"
		$headers = array();
		if (preg_match("/GET (.*) HTTP/", $req, $match)) {
			$headers['resource'] = $match[1];
		}
		if (preg_match("/Host: (.*)\r\n/", $req, $match)) {
			$headers['host'] = $match[1];
		}
		if (preg_match("/Origin: (.*)\r\n/", $req, $match)) {
			$headers['origin'] = $match[1];
		}
		if (preg_match("/Sec-WebSocket-Version: (.*)\r\n/" , $req, $match)) {
			$headers['version'] = $match[1];
		}
		if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/" , $req, $match)) {
			$headers['key'] = $match[1];
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

	public function send($socket, $message) {
		$header = chr(0x81);
		$headerLength = 1;

		// Payload length:  7 bits, 7+16 bits, or 7+64 bits
		$messageLength = strlen($message);

		if ($messageLength <= 125) {
			$header[1] = chr($messageLength);
			$headerLength = 2;
		}
		elseif ($messageLength <= 65535) {
			$header[1] = chr(126);
			$header[2] = chr($messageLength >> 8);
			$header[3] = chr($messageLength & 0xFF);
			$headerLength = 4;
		}
		else {
			// maybe add a check for the length > 0x7FFF...
			$header[1] = chr(127);
			$header[2] = chr(($messageLength & 0xFF00000000000000) >> 56);
			$header[3] = chr(($messageLength & 0x00FF000000000000) >> 48);
			$header[4] = chr(($messageLength & 0x0000FF0000000000) >> 40);
			$header[5] = chr(($messageLength & 0x000000FF00000000) >> 32);
			$header[6] = chr(($messageLength & 0x00000000FF000000) >> 24);
			$header[7] = chr(($messageLength & 0x0000000000FF0000) >> 16);
			$header[8] = chr(($messageLength & 0x000000000000FF00) >>  8);
			$header[9] = chr(($messageLength & 0x00000000000000FF) >>  0);
			$headerLength = 10;
		}

		$responseLength = $headerLength + $messageLength;
		$written = @socket_write($socket, $header . $message, $responseLength);
		//TODO: continue writes in such cases
		if (false === $written) {
			logSocketError("failed to write to the client, disconnecting");
			$this->disconnect($socket);
			return false;
		}
		elseif ($written < $responseLength) {
			$this->disconnect($socket);
			return false;
		}
		return true;
	}

	function wrap($msg = "") {
		return chr(0).$msg.chr(255);
	}

	// copied from http://lemmingzshadow.net/386/php-websocket-serverclient-nach-draft-hybi-10/
	function unwrap($data = "") {
		$bytes = $data;
		$dataLength = '';
		$mask = '';
		$coded_data = '';
		$decodedData = '';
		$secondByte = sprintf('%08b', ord($bytes[1]));
		$masked = ($secondByte[0] == '1') ? true : false;
		$dataLength = ($masked === true) ? ord($bytes[1]) & 127 : ord($bytes[1]);
		if($masked === true) {
			if($dataLength === 126) {
				$mask = substr($bytes, 4, 4);
				$coded_data = substr($bytes, 8);
			}
			elseif($dataLength === 127) {
				$mask = substr($bytes, 10, 4);
				$coded_data = substr($bytes, 14);
			}
			else {
				$mask = substr($bytes, 2, 4);
				$coded_data = substr($bytes, 6);
			}
			for($i = 0; $i < strlen($coded_data); $i++) {
				$decodedData .= $coded_data[$i] ^ $mask[$i % 4];
			}
		}
		else {
			if($dataLength === 126) {
				$decodedData = substr($bytes, 4);
			}
			elseif($dataLength === 127) {
				$decodedData = substr($bytes, 10);
			}
			else {
				$decodedData = substr($bytes, 2);
			}
		}
		return $decodedData;
	}

	private function newUser($resource, $socket) {
		$this->log("searching endpoint for resource '$resource'");
		foreach($this->endpoints as $eresource => $endpoint) {
			if ($eresource === $resource) {
				return $endpoint->onNewUser($socket);
			}
		}
		$this->log("not found");
		return false;
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


abstract class WebSocketUser {
	private $socket;
	private $websocketServer;
	public function __construct($socket) {
		$this->socket = $socket;
	}
	public function setWebsocketServer(WebSocketServer $wss) {
		$this->websocketServer = $wss;
	}
	public function getSocket() {
		return $this->socket;
	}
	public function send($message) {
		if (is_array($message)) {
			$message = json_encode($message);
		}
		return $this->websocketServer->send($this->socket, $message);
	}

	/*
	 * override the onMessage handler to process messages from this user
	 */
	abstract function onMessage($msg);

	/*
	 * called after this user has been disconnected
	 */
	abstract function onDisconnected($success);
}