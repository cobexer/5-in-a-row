<?php
/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */

require_once(dirname(realpath(__FILE__)) . '/WebSocketServer.php');

class WebSocketChatServer implements WebSocketEndpoint {
	private $websocketServer;
	public function __construct(WebSocketServer $server) {
		$this->websocketServer = $server;
	}
	public function getResource() {
		return '/websocket/Chat';
	}
	public function getAllClients() {
		return $this->websocketServer->getAllClients();
	}
	public function onNewUser($socket) {
		return new WebSocketChatUser($this, $socket);
	}
}

class WebSocketChatUser extends WebSocketUser {
	private $chatServer;
	private $name;
	public function __construct(WebSocketChatServer $chatServer, $socket) {
		parent::__construct($socket);
		$this->chatServer = $chatServer;
		$this->name = "Guest" . (rand() % 10000);
	}
	/*
	 * override the onMessage handler to process messages from users
	 */
	function onMessage($msg) {
		$msgObj = json_decode($msg, true);
		if ($msgObj && isset($msgObj['message'])) {
			if ($msgObj['message'][0] != '/') {
				$response = $this->getResponseObj($msgObj['message']);
				$msg = json_encode($response);
				foreach($this->chatServer->getAllClients() as $client) {
					$client->send($msg);
				}
			}
			else {
				if (preg_match('/(\/\S*)\s*(.*)/', $msgObj['message'], $match)) {
					switch ($match[1]) {
						case '/name':
						case '/me':
							$this->name = $match[2];
							break;
						default:
							$this->send(json_encode($this->getResponseObj('unknown command', false)));
						    break;
					}
				}
				else {
					$this->send(json_encode($this->getResponseObj('sorry not understood', false)));
				}
			}
		}
	}
	private function getResponseObj($message, $success = true) {
		$response = array(
			'status' => 'ok',
			'time' => strftime("%H:%M:%S", time()),
			'username' => $this->name,
			'message' => $message
		);
		if (false === $success) {
			$response['status'] = 'failed';
			$response['username'] = 'server';
		}
		return $response;
	}
	public function onDisconnected($success) {
		$broadcast = $this->getResponseObj("User '" . $this->name . "' left", false);
		$msg = json_encode($broadcast);
		foreach($this->chatServer->getAllClients() as $client) {
			if ($client !== $this) {
				$client->send($msg);
			}
		}
	}
}