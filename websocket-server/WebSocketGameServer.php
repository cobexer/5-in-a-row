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
		$game = $this->games[] = new GameState($this, $creator);
		return $game;
	}

	public function destroyGame(GameState $game) {
		$index = array_search($game, $this->games, true);
		if (false !== $index) {
			array_splice($this->games, $index, 1);
		}
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

	function getName() {
		return $this->name;
	}

	function getColor() {
		return $this->color;
	}

	function onMessage(WebSocketMessage $msg) {
		if ($msg->isText()) {
			$msgObj = json_decode($msg->data, true);

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
						$this->name = $msgObj['name'];
						$this->color = $msgObj['color'];
						$this->method = $msgObj['method'];
						$player = $this->getUserObj();
						$player['type'] = 'init';
						$this->send($player);
						//code to autostart a game, or auto join a non full game
						$games = $this->gameServer->getOpenGames();
						if (count($games) > 0) {
							if ($games[0]->join($this)) {
								$this->game = $games[0];
							}
						}
						else {
							$this->game = $this->gameServer->newGame($this);
							$msg = array(
								'type' => 'newGame',
								'name' => $this->name . "'s game"
							);
							$this->send($msg);
						}
						break;
					case 'updatePlayer':
						$this->updatePlayer($msgObj);
						break;
					case 'newGame':
						if ($this->game) {
							$this->game->leave();
						}
						$this->game = $this->gameServer->newGame($this);
						$msg = array(
							'type' => 'newGame',
							'name' => $this->name . "'s game"
						);
						$this->send($msg);
						break;
					default:
						echo 'unknown message ' . $msg;
						break;
				}
				//TODO: process user inputs
			}
		}
	}

	public function onDisconnected($success) {
		if ($this->game) {
			$this->game->leave($this);
			$this->game = null;
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
		$old = $this->getUserObj();
		if (isset($newData['name'])) {
			$this->name = $newData['name'];
		}
		if (isset($newData['color'])) {
			$this->color = $newData['color'];
		}
		if (isset($newData['method'])) {
			$this->method = $newData['method'];
		}
		$msgObj = array(
			'type' => 'updatePlayer',
			'oldPlayer' => $old,
			'newPlayer' => $this->getUserObj()
		);
		$msg = json_encode($msgObj);
		if ($this->game) {
			foreach($this->game->getUsers() as $u) {
				$u->send($msg);
			}
		}
		else {
			$this->send($msg);
		}
	}
}

class GameState {
	private static $availableColors = array('red', 'green', 'blue', 'orange', 'yellow', 'lime');
	private $gameServer;
	private $users;
	private $creator;
	public function __construct(WebSocketGameServer $gameServer, WebSocketGameUser $creator) {
		$this->gameServer = $gameServer;
		$this->users = array($creator);
		$this->creator = $creator;
	}

	public function isFull() {
		return count($this->users) === 3;
	}

	public function checkPlayer(WebSocketGameUser $user, $makeUnique = false) {
		$newName = $user->getName();
		// make sure the new user's name is unique
		do {
			$nameDuplicated = false;
			foreach($this->users as $u) {
				if ($user !== $u && 0 === strcmp($u->getName(), $newName)) {
					if (!$makeUnique) {
						return false;
					}
					$newName .= '_';
					$nameDuplicated = true;
					break;
				}
			}
		}
		while($nameDuplicated);
		$availableColors = self::$availableColors;
		$takenColors = array();
		foreach($this->users as $u) {
			if ($u !== $user) {
				$takenColors[$u->getColor()] = true;
			}
		}
		$newColor = $user->getColor();
		while (isset($takenColors[$newColor])) {
			if (!$makeUnique) {
				return false;
			}
			$newColor = self::$availableColors[mt_rand(0, count(self::$availableColors) - 1)];
		}
		if (0 !== strcmp($newName, $user->getName()) || 0 !== strcmp($newColor, $user->getColor())) {
			$user->updatePlayer(array(
				'name' => $newName,
				'color' => $newColor
			));
		}
		return true;
	}

	public function join(WebSocketGameUser $user) {
		if ($this->isFull()) {
			$user->send(array(
				'type' => 'error',
				'error' => 'Game already full.'
			));
			return false;
		}

		if (false === array_search($user, $this->users, true)) {
			$this->users[] = $user;
			$this->checkPlayer($user, true);
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
			return true;
		}
		else {
			$user->send(array(
				'type' => 'error',
				'error' => 'Cannot join the same game multiple times.'
			));
			return false;
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
			if ($this->creator === $user) {
				// the creator left, destroy this game
				$this->gameServer->destroyGame($this);
				$msg = json_encode(array(
					'type' => 'destroyGame',
					'reason' => 'Creator left'
				));
			}
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