<?php

namespace ManiaControl\Maps;

use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;

/**
 * Persistent map favorites helper.
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014-2020 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class MapFavorites {
	/*
	 * Constants
	 */
	const TABLE_MAPFAVORITES = 'mc_mapfavorites';

	/*
	 * Private properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;

	/**
	 * Construct a new map favorites helper.
	 *
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->initTable();
	}

	/**
	 * Add a map to a player's favorites.
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @return bool
	 */
	public function addFavorite(Player $player, Map $map) {
		if (!$this->isValidFavoriteTarget($player, $map)) {
			return false;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "INSERT INTO `" . self::TABLE_MAPFAVORITES . "` (
				`playerIndex`,
				`mapUid`
				) VALUES (
				?, ?
				) ON DUPLICATE KEY UPDATE
				`playerIndex` = VALUES(`playerIndex`);";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}

		$playerIndex = (int) $player->index;
		$statement->bind_param('is', $playerIndex, $map->uid);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return false;
		}

		$statement->close();
		return true;
	}

	/**
	 * Remove a map from a player's favorites.
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @return bool
	 */
	public function removeFavorite(Player $player, Map $map) {
		if (!$this->isValidFavoriteTarget($player, $map)) {
			return false;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "DELETE FROM `" . self::TABLE_MAPFAVORITES . "`
				WHERE `playerIndex` = ?
				AND `mapUid` = ?;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}

		$playerIndex = (int) $player->index;
		$statement->bind_param('is', $playerIndex, $map->uid);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return false;
		}

		$statement->close();
		return true;
	}

	/**
	 * Check whether a map is favorited by a player.
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @return bool
	 */
	public function isFavorite(Player $player, Map $map) {
		$lookup = $this->getFavoriteMapUidLookup($player);
		return isset($lookup[$map->uid]);
	}

	/**
	 * Get all favorite map UIDs for a player.
	 *
	 * @param Player $player
	 * @return string[]
	 */
	public function getFavoriteMapUids(Player $player) {
		if (!$player || (int) $player->index <= 0) {
			return array();
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SELECT `mapUid` FROM `" . self::TABLE_MAPFAVORITES . "`
				WHERE `playerIndex` = ?;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return array();
		}

		$playerIndex = (int) $player->index;
		$statement->bind_param('i', $playerIndex);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return array();
		}

		$statement->bind_result($mapUid);
		$favoriteMapUids = array();
		while ($statement->fetch()) {
			$favoriteMapUids[] = $mapUid;
		}
		$statement->close();

		return $favoriteMapUids;
	}

	/**
	 * Get a lookup table of favorite UIDs for a player.
	 *
	 * @param Player $player
	 * @return array
	 */
	public function getFavoriteMapUidLookup(Player $player) {
		$favoriteMapUids = $this->getFavoriteMapUids($player);
		$favoriteMapUidLookup = array();
		foreach ($favoriteMapUids as $mapUid) {
			$favoriteMapUidLookup[$mapUid] = true;
		}
		return $favoriteMapUidLookup;
	}

	/**
	 * Get the player's favorite maps that are currently loaded on the server.
	 *
	 * @param Player $player
	 * @return Map[]
	 */
	public function getFavoriteMaps(Player $player) {
		$favoriteMapUidLookup = $this->getFavoriteMapUidLookup($player);
		if (empty($favoriteMapUidLookup)) {
			return array();
		}

		$favoriteMaps = array();
		$maps         = $this->maniaControl->getMapManager()->getMaps();
		foreach ($maps as $map) {
			if (!$map instanceof Map) {
				continue;
			}

			if (isset($favoriteMapUidLookup[$map->uid])) {
				$favoriteMaps[] = $map;
			}
		}

		return $favoriteMaps;
	}

	/**
	 * Initialize the favorites table.
	 *
	 * @return bool
	 */
	private function initTable() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_MAPFAVORITES . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`playerIndex` int(11) NOT NULL,
				`mapUid` varchar(50) NOT NULL,
				`created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `player_map_favorite` (`playerIndex`, `mapUid`),
				KEY `mapUid` (`mapUid`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Player favorite maps' AUTO_INCREMENT=1;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
			return false;
		}
		return $result;
	}

	/**
	 * Check whether the player and map can be persisted as a favorite.
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @return bool
	 */
	private function isValidFavoriteTarget(Player $player = null, Map $map = null) {
		if (!$player || !$map) {
			return false;
		}
		if ((int) $player->index <= 0) {
			return false;
		}
		if (!$map->uid) {
			return false;
		}
		return true;
	}
}
