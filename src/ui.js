/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph & Dietmar Watzinger. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */

var x_size_array = 20;
var y_size_array = 15;
var field_size = 32;
var x_offset = 0;
var y_offset = 0;
	
var array = new Array(x_size_array);
var animation_counter;

var array_window;
var game_array;

//one pc play
var n = 0;
var player1 = new Array("Corni", "x", "green");
var player2 = new Array("Didi", "circle", "red");

function init()
{
	drawBackground();
	
	game_array = $("#game_array");
	array_window = game_array[0].getContext('2d');
	game_array.on('click', clickField);

	for(var i=0; i<array.length; i++)
	{
		array[i] = new Array(y_size_array);
	}
}

function drawBackground()
{
	var background = $("#background")[0];
	var background_window = background.getContext('2d');
	var img = new Image();  
	img.onload = function()
	{
		background_window.drawImage(img,x_offset, y_offset, x_size_array*field_size, y_size_array*field_size);
		for(x=0; x<array.length; x++)
		{
			for(y=0; y<array[0].length; y++)
			{
				background_window.globalCompositeOperation = "xor";
				background_window.strokeStyle = "rgb(0,0,0)";
				background_window.strokeRect(x_offset + field_size*x, (y_size_array*field_size + y_offset - field_size) - field_size*y, field_size, field_size);
			}
		}
    }
	img.src = 'style/wolken.jpg';
}

function setField(x, y, player)
{
	if(array[x][y] != null || (y != 0 && array[x][y-1] == null))
	{
		return false;
	}
	array[x][y] = player;
	setFieldAnimation(x, y, y_size_array-1, player);
	
	return true;
}

function setFieldAnimation(x, y, animation_counter, player)
{
	setTimeout(function()
	{
		switch(player[1])
		{
			case "circle":
				drawCircle(x, animation_counter, player);
				break;
			case "x":
				drawX(x, animation_counter, player);
				break;
		}
		
		array_window.clearRect(x_offset + field_size*x, (y_size_array*field_size + y_offset - field_size) - field_size*(animation_counter+1), field_size, field_size);
		animation_counter --;
		
		if (animation_counter >= y)
		{
			self.setFieldAnimation(x, y, animation_counter, player);
		}
	}, 35);
}

function drawCircle(x, y, player)
{
	x = (x_offset + field_size/2) + field_size*x;
	y = y_size_array*field_size + y_offset - field_size/2 - field_size*y;
	
	array_window.fillStyle = player[2];
	array_window.beginPath();
	array_window.arc(x, y, field_size/2, 0, 2*Math.PI, true);
	array_window.fill();
}

function drawX(x, y, player)
{
	x = x_offset + field_size*x + field_size/2;
	y = y_size_array*field_size + y_offset - field_size/2 - field_size*y;
	
	array_window.save();
	array_window.translate(x, y)
	array_window.rotate(Math.PI / 4);
	array_window.fillStyle = player[2];
	array_window.fillRect(-field_size/2, -2.5, field_size, 5);
	array_window.rotate(Math.PI / 2);
	array_window.fillRect(-field_size/2, -2.5, field_size, 5);
	array_window.restore();
}

function clickField(e)
{
	var coords = getCursorPosition(e);
	var player;
	if(n%2 == 0) player = player1;
	else player = player2;
	
	
	if(coords != null)
	{
		n++;
		var x = coords[0];
		var y = coords[1];
		setField(x, y, player);
		setTimeout(function()
		{
			checkWin(x, y, player);
		}, 1000);
	}
}

function getCursorPosition(e)
{
    var x;
    var y;
    if (e.pageX || e.pageY)
	{
      x = e.pageX;
      y = e.pageY;
    }
    else
	{
      x = e.clientX + document.body.scrollLeft +
           document.documentElement.scrollLeft;
      y = e.clientY + document.body.scrollTop +
           document.documentElement.scrollTop;
    }
    var offset = game_array.offset();
	x -= offset.left;
	y -= offset.top;
	
	if(x<x_offset || x>(x_offset + x_size_array*field_size) || y<y_offset || y>(y_offset + y_size_array*field_size))
	{
		return null;
	}
	
	y = Math.floor((y_offset + y_size_array*field_size - y)/field_size);
    x = Math.floor((x - x_offset)/field_size);
	
	var coords = new Array(x, y);
    return coords;

}

function checkWin(x, y, player)
{
	// check x Achse
	var count = 1;
	for(var i = 1; i<5 && x-i>=0; i++)
	{
		if(array[x-i][y] == null) break;
		if(array[x-i][y][0] == player[0]) count++;
		else break;
	}
	for(var i = 1; i<5 && x+i<=(x_size_array-1); i++)
	{
		if(array[x+i][y] == null) break;
		if(array[x+i][y][0] == player[0]) count++;
		else break;
	}
	if(count >= 5)
	{
		alert(player[0] + " win");
		return true;
	}
	
	// check y Achse
	count = 1;
	for(var i = 1; i<5 && y-i>=0; i++)
	{
		if(array[x][y-i] == null) break;
		if(array[x][y-i][0] == player[0]) count++;
		else break;
	}
	if(count >= 5)
	{
		alert(player[0] + " win");
		return true;
	}
	
	// check top-left to right-down diagonal
	count = 1;
	for(var i = 1; i<5 && x-i>=0 && y+i<=(y_size_array-1); i++)
	{
		if(array[x-i][y+i] == null) break;
		if(array[x-i][y+i][0] == player[0]) count++;
		else break;
	}
	for(var i = 1; i<5 && x+i<=(x_size_array-1) && y-i>=0; i++)
	{
		if(array[x+i][y-i] == null) break;
		if(array[x+i][y-i][0] == player[0]) count++;
		else break;
	}
	if(count >= 5)
	{
		alert(player[0] + " win");
		return true;
	}
	
	// check top-right to left-down diagonal
	count = 1;
	for(var i = 1; i<5 && x+i<=(x_size_array-1) && y+i<=(y_size_array-1); i++)
	{
		if(array[x+i][y+i] == null) break;
		if(array[x+i][y+i][0] == player[0]) count++;
		else break;
	}
	for(var i = 1; i<5 && x-i>=0 && y-i>=0; i++)
	{
		if(array[x-i][y-i] == null) break;
		if(array[x-i][y-i][0] == player[0]) count++;
		else break;
	}
	if(count >= 5)
	{
		alert(player[0] + " win");
		return true;
	}
	return false;
}