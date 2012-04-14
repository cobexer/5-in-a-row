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
	private $games;
	public function __construct(WebSocketServer $server) {
		$this->websocketServer = $server;
		$this->games = array();
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

	public function newGame(WebSocketGameUser $creator) {
		$game = $this->games[] = new GameState($creator);
		return $game;
	}

	public function getOpenGames() {
		$games = array();
		foreach($this->games as $g) {
			if (!$g->isFull()) {
				$games[] = $g;
			}
		}
		return $games;
	}
}

class WebSocketGameUser extends WebSocketUser {
	private $gameServer;
	private $game;
	private $name;
	private $color;
	private $method;
	public function __construct(WebSocketGameServer $gameServer, $socket) {
		parent::__construct($socket);
		$this->gameServer = $gameServer;
		$this->name = 'Guest' . (rand() % 10000);
		$this->color = 'red';
		$this->method = 'x';
	}

	function onMessage($msg) {
		$msgObj = json_decode($msg, true);
		if ($msgObj && isset($msgObj['type'])) {
			switch($msgObj['type']) {
				case 'click':
					//TODO: check for validity (range checks, may this player click?, ...)
					$msgObj['player'] = $this->getUserObj();
					$click = json_encode($msgObj);
					foreach($this->game->getUsers() as $user) {
						$user->send($click);
					}
					break;
				case 'init':
					$this->updatePlayer($msgObj);
					$player = $this->getUserObj();
					$player['type'] = 'init';
					$this->send($player);
					break;
				case 'updatePlayer':
					$this->updatePlayer($msgObj);
					break;
			}
			//TODO: process user inputs
		}
	}

	public function onDisconnected($success) {
		if ($this->game) {
			$this->game->leave($this);
		}
	}

	public function getUserObj() {
		return array(
			'name' => $this->name,
			'color' => $this->color,
			'method' => $this->method
		);
	}

	public function updatePlayer($newData) {
		if ($this->game) {
			$old = $this->getUserObj();
		}
		if (isset($newData['name'])) {
			$this->name = $newData['name'];
		}
		if (isset($newData['color'])) {
			$this->color = $newData['color'];
		}
		if (isset($newData['method'])) {
			$this->method = $newData['method'];
		}
		if ($this->game) {
			$msgObj = array(
				'type' => 'updatePlayer',
				'oldPlayer' => $old,
				'newPlayer' => $this->getUserObj()
			);
			$msg = json_encode($msgObj);
			foreach($this->game->getUsers() as $u) {
				$u->send($msg);
			}
		}
	}
}

class GameState {
	private $users;
	private $creator;
	public function __construct(WebSocketGameUser $creator) {
		$this->users = array($creator);
		$this->creator = $creator;
	}

	public function isFull() {
		return count($this->users) === 3;
	}

	public function join(WebSocketGameUser $user) {
		if ($this->isFull()) {
			$user->send(array(
				'type' => 'error',
				'error' => 'Game already full.'
			));
			return;
		}

		if (false === array_search($user, $this->users, true)) {
			$this->users[] = $user;
			$newName = $user->name;
			// make sure the new user's name is unique
			do {
				$nameDuplicated = false;
				foreach($this->users as $u) {
					if (0 === strcmp($u->name, $newName)) {
						$newName += '_';
						$nameDuplicated = true;
						break;
					}
				}
			}
			while($nameDuplicated);
			if (0 !== strcmp($newName, $user->name)) {
				$user->updatePlayer(array('name' => $newName));
			}
			$msg = json_encode(array(
				'type' => 'join',
				'player' => $user->getUserObj()
			));
			foreach($this->users as $u) {
				if ($u !== $user) {
					$u->send($msg);
				}
			}
			//TODO: reset the game state!
		}
		else {
			$user->send(array(
				'type' => 'error',
				'error' => 'Cannot join the same game multiple times.'
			));
		}
	}

	public function leave(WebSocketGameUser $user) {
		$pos = array_search($user, $this->users, true);
		if (false !== $pos) {
			$msg = json_encode(array(
				'type' => 'playerLeft',
				'player' => $user->getUserObj()
			));
			array_splice($this->users, $pos, 1);
			foreach($this->users as $u) {
				if ($user !== $u) {
					$u->send($msg);
				}
			}
			//TODO: reset the game state!
		}
	}

	public function getUsers() {
		return $this->users;
	}
}