ManiaControl (SM:Obstacle fork)
============

Fork of a fork of ManiaControl. I'm not smart, so maybe this explode servers.

Changes are only tested on Shootmania: Strom dedicated servers, and done with intention to improve the experience for player on Obstacle titlepack servers.


## What's new, updated and fixed in this fork:

* Fixes to the server map list.
	* Added support for the updated Mania-Exchange API to the MX map list. You can now browse, search and add maps from MX again! 
	* Improved map list performance and added a server-side cache.
 	* Can now handle very large server map lists.
	* Improved search functionality and paging
 

* Adds a favourite map system to the server map list that works across servers.
	* The favourite map system database is hosted externally by areelvestad.
 	* Tested only on ShootMania servers.

* Adds a new optional plugin for the ShootMania: Obstacle “Benchmark” event, where players can see their rank, which maps they have and haven’t completed, the Benchmark leaderboard, and more.

* Fixes to the ManiaControl update and autoupdate system. Servers will update ManiaControl from this fork.


## Setup:

1.	Copy all files into the desired directory.

2.	Configure the needed settings in the 'configs/server.xml' file:

	2.1	Enter Your ManiaPlanet-server information.
			(The needed settings are defined in the file 'dedicated_cfg.txt' of your game server.)

	2.2	Enter the Information about your MySQL server, user and database.

	2.3	Add as many player logins of master-administrators as you wish.

3.	Run the controller with the shell script 'ManiaControl.sh' (on UNIX) or with the batch file 'ManiaControl.bat' (on Windows).

4.	Enjoy!


## Windows:

- ManiaControl is mainly tested on UNIX machines.
- Even though it might run properly on Windows we can't promise it will work in all cases.
- In order to run ManiaControl on Windows you have to alter the file 'ManiaControl.bat' and enter the path to your php.exe!


## Requirements:
- MySQL Database
- PHP 5.4+
- Needed extensions (on ManiaControl startup you will see if you have them installed and activated):
	- php_mysqli
	- php_curl
	- php_xmlrpc (TM only, recommended for SM)
	- php_zlib
	- php_zip
	- php_mbstring
