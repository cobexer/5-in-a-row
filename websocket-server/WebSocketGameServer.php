<?php
/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */

require_once(dirname(realpath(__FILE__)) . '/WebSocketServer.php');

class WebSocketGameServer implements WebSocketEndpoint {
	private $websocketServer;
	public function __construct(WebSocketServer $server) {
		$this->websocketServer = $server;
	}
	public function getResource() {
		return '/websocket/5-in-a-row';
	}
	public function getAllClients() {
		return $this->websocketServer->getAllClients();
	}
	public function onNewUser($socket) {
		return new WebSocketGameUser($this, $socket);
	}
}

class WebSocketGameUser extends WebSocketUser {
	private $gameServer;
	private $name;
	public function __construct(WebSocketGameServer $gameServer, $socket) {
		parent::__construct($socket);
		$this->gameServer = $gameServer;
		$this->name = "Guest" . (rand() % 10000);
	}
	function onMessage($msg) {
		$msgObj = json_decode($msg, true);
		if ($msgObj) {
			//TODO: process user inputs
		}
	}
	public function onDisconnected($success) {
		//TODO. notify other game partners that this user is gone
	}
}