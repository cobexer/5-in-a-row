/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */

var gameSocket;

function initNet() {
	gameSocket = new WrappedWebSocket("ws://" + location.hostname + ":12345/websocket/5-in-a-row");
	gameSocket.onmessage = onMessage;
	gameSocket.onopen = onOpen;
	if (gameSocket.readyState == WebSocket.OPEN) {
		gameSocket.onopen();
	}
	$(window).on('beforeunload unload', function() {
		gameSocket && gameSocket.close();
		gameSocket = null;
	});
}

function send(type, data) {
	var msg = data || {};
	msg.type = type;
	gameSocket.send(JSON.stringify(msg));
}

function onOpen() {
	if (!this.initialized) {
		send('init', gamePlayer);
		this.initialized = true;
	}
}

function onMessage(event) {
	//TODO: handle server sent messages
	var msg = JSON.parse(event.data);
	switch(msg.type) {
	// some player clicked somewhere
	case 'click':
		// msg = { player: {...}, x: x, y: y [, isNextPlayer: true] }
		// if (msg.isNextPlayer) ... we are the next one to click
		clickField(msg);
		break;
	// some info about a player in this game changed
	case 'updatePlayer':
		// msg = { oldPlayer: { name: '', color: '', method: '' }, newPlayer: { name: '', color: '', method: '' } }
		var player = msg.oldPlayer;
		if (gamePlayer.name === player.name) {
			// updating self
			player = msg.newPlayer;
			$('#player-name').val(player.name);
			$('#player-color').val(player.color);
			$('#player-method').val(player.method);
		}
		else {
			notify(player.name + " changed his/her name", "he/she want's to be called '" + msg.newPlayer.name + "' now.");
		}
		break;
	// server tells us the config for us
	case 'init':
		// msg = { name: '', color: '', method: '' }
		break;
	// new player joined our game
	case 'join':
		// msg = { player: {...} }
		//TODO: reset the game array
		notify(msg.player.name, "has joined your game.");
		break;
	case 'reset':
		// msg = {}
		//TODO: reset the game array
		break;
	case 'playerLeft':
		// msg = { player: {...} }
		//TODO: reset the game array
		notify(msg.player.name, "has left your game.");
		break;
	case 'newGame':
		// msg= { name: '' }
		notify("New game created", "you are now the leader of the new game named: '" + msg.name + "'.");
		$('#game-admin')
			.show()
			.button({ icons: { primary: 'ui-icon-key' } })
			.on('click', function() {
				//TODO: implement dialog
				$('#game-admin-dialog').dialog({
					modal: true,
					open: function() {
						//TODO: init complex controls?!
					},
					close: function() {
						//TODO: cleanup?
					},
					buttons: {
						OK: function() {
							//TODO. save changes
							//TODO: distribute to other players: $('#game-name').val()
							$(this).dialog('close');
						},
						Cancel: function() {
							$(this).dialog('close');
						}
					}
				});
			});
		$('#game-name')
			.val(msg.name);

		//TODO: reset game array
		break;
	}
}
