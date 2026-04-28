<?php

namespace ManiaControl\Maps;

use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;

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
	const TABLE_FAVORITE_STATE   = 'mc_fav_state';
	const TABLE_FAVORITE_SYNC    = 'mc_fav_sync';
	const TABLE_LEGACY_FAVORITES = 'mc_mapfavorites';

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
		$this->initTables();
		$this->migrateLegacyFavorites();
	}

	/**
	 * Add a map to a player's favorites.
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @return bool
	 */
	public function addFavorite(Player $player, Map $map) {
		return $this->setFavoriteState($player, $map, true);
	}

	/**
	 * Remove a map from a player's favorites.
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @return bool
	 */
	public function removeFavorite(Player $player, Map $map) {
		return $this->setFavoriteState($player, $map, false);
	}

	/**
	 * Persist the favorite state for a player and map.
	 *
	 * @param Player      $player
	 * @param Map         $map
	 * @param bool        $isFavorite
	 * @param string|null $eventTime
	 * @param bool        $queueSync
	 * @return bool
	 */
	public function setFavoriteState(Player $player, Map $map, $isFavorite, $eventTime = null, $queueSync = true) {
		if (!$this->isValidFavoriteTarget($player, $map)) {
			return false;
		}

		$eventTime = $this->normalizeDateTimeValue($eventTime);
		if ($eventTime === '') {
			$eventTime = $this->buildCurrentDateTimeValue();
		}

		if (!$this->upsertFavoriteStateByLogin($player->login, $map->uid, (bool) $isFavorite, $eventTime)) {
			return false;
		}

		$mapFavoritesSync = $this->maniaControl->getMapManager()->getMapFavoritesSync();
		if ($queueSync && $mapFavoritesSync && $mapFavoritesSync->isFeatureEnabled()) {
			$operation = ($isFavorite ? 'add' : 'remove');
			$this->queueSyncEvent($player->login, $map->uid, $operation, $eventTime);
		}

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
	 * Get all active favorite map UIDs for a player.
	 *
	 * @param Player $player
	 * @return string[]
	 */
	public function getFavoriteMapUids(Player $player) {
		if (!$player || !$player->login) {
			return array();
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SELECT `mapUid` FROM `" . self::TABLE_FAVORITE_STATE . "`
				WHERE `playerLogin` = ?
				AND `isFavorite` = 1;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return array();
		}

		$playerLogin = (string) $player->login;
		$statement->bind_param('s', $playerLogin);
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
	 * Queue a sync event for the global favorites API.
	 *
	 * @param string      $playerLogin
	 * @param string      $mapUid
	 * @param string      $operation
	 * @param string|null $eventTime
	 * @return string|false
	 */
	public function queueSyncEvent($playerLogin, $mapUid, $operation, $eventTime = null) {
		$playerLogin = trim((string) $playerLogin);
		$mapUid      = trim((string) $mapUid);
		$operation   = strtolower(trim((string) $operation));
		$eventTime   = $this->normalizeDateTimeValue($eventTime);

		if ($playerLogin === '' || $mapUid === '' || ($operation !== 'add' && $operation !== 'remove')) {
			return false;
		}
		if ($eventTime === '') {
			$eventTime = $this->buildCurrentDateTimeValue();
		}

		$eventUuid   = $this->generateEventUuid();
		$serverLogin = (string) $this->maniaControl->getServer()->login;

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "INSERT INTO `" . self::TABLE_FAVORITE_SYNC . "` (
				`eventUuid`,
				`playerLogin`,
				`mapUid`,
				`operation`,
				`eventTime`,
				`serverLogin`,
				`retryCount`,
				`nextRetryAt`,
				`lastError`
				) VALUES (
				?, ?, ?, ?, ?, ?, 0, ?, NULL
				);";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}

		$statement->bind_param('sssssss', $eventUuid, $playerLogin, $mapUid, $operation, $eventTime, $serverLogin, $eventTime);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return false;
		}
		$statement->close();

		$mapFavoritesSync = $this->maniaControl->getMapManager()->getMapFavoritesSync();
		if ($mapFavoritesSync) {
			$mapFavoritesSync->pushQueuedEventByUuid($eventUuid);
		}

		return $eventUuid;
	}

	/**
	 * Get one queued sync row.
	 *
	 * @param string $eventUuid
	 * @return array|null
	 */
	public function getSyncRow($eventUuid) {
		$eventUuid = trim((string) $eventUuid);
		if ($eventUuid === '') {
			return null;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SELECT `eventUuid`, `playerLogin`, `mapUid`, `operation`, `eventTime`, `serverLogin`, `retryCount`, `nextRetryAt`, `lastError`
				FROM `" . self::TABLE_FAVORITE_SYNC . "`
				WHERE `eventUuid` = ?
				LIMIT 1;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}

		$statement->bind_param('s', $eventUuid);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return null;
		}

		$statement->bind_result($rowEventUuid, $playerLogin, $mapUid, $operation, $eventTime, $serverLogin, $retryCount, $nextRetryAt, $lastError);
		$row = null;
		if ($statement->fetch()) {
			$row = array(
				'eventUuid'   => $rowEventUuid,
				'playerLogin' => $playerLogin,
				'mapUid'      => $mapUid,
				'operation'   => $operation,
				'eventTime'   => $this->normalizeDateTimeValue($eventTime),
				'serverLogin' => $serverLogin,
				'retryCount'  => (int) $retryCount,
				'nextRetryAt' => $this->normalizeDateTimeValue($nextRetryAt),
				'lastError'   => $lastError,
			);
		}
		$statement->close();

		return $row;
	}

	/**
	 * Get due sync rows.
	 *
	 * @param int $limit
	 * @return array
	 */
	public function getDueSyncRows($limit = 20) {
		$limit = (int) $limit;
		if ($limit <= 0) {
			$limit = 20;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SELECT `eventUuid`, `playerLogin`, `mapUid`, `operation`, `eventTime`, `serverLogin`, `retryCount`, `nextRetryAt`, `lastError`
				FROM `" . self::TABLE_FAVORITE_SYNC . "`
				WHERE `nextRetryAt` <= ?
				ORDER BY `nextRetryAt` ASC
				LIMIT {$limit};";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return array();
		}

		$now = $this->buildCurrentDateTimeValue();
		$statement->bind_param('s', $now);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return array();
		}

		$statement->bind_result($eventUuid, $playerLogin, $mapUid, $operation, $eventTime, $serverLogin, $retryCount, $nextRetryAt, $lastError);
		$rows = array();
		while ($statement->fetch()) {
			$rows[] = array(
				'eventUuid'   => $eventUuid,
				'playerLogin' => $playerLogin,
				'mapUid'      => $mapUid,
				'operation'   => $operation,
				'eventTime'   => $this->normalizeDateTimeValue($eventTime),
				'serverLogin' => $serverLogin,
				'retryCount'  => (int) $retryCount,
				'nextRetryAt' => $this->normalizeDateTimeValue($nextRetryAt),
				'lastError'   => $lastError,
			);
		}
		$statement->close();

		return $rows;
	}

	/**
	 * Delete a queued sync row.
	 *
	 * @param string $eventUuid
	 * @return bool
	 */
	public function deleteSyncRow($eventUuid) {
		$eventUuid = trim((string) $eventUuid);
		if ($eventUuid === '') {
			return false;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "DELETE FROM `" . self::TABLE_FAVORITE_SYNC . "`
				WHERE `eventUuid` = ?;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}

		$statement->bind_param('s', $eventUuid);
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
	 * Mark a queued sync row as failed and schedule a retry.
	 *
	 * @param string $eventUuid
	 * @param string $error
	 * @return bool
	 */
	public function markSyncFailure($eventUuid, $error) {
		$row = $this->getSyncRow($eventUuid);
		if (!$row) {
			return false;
		}

		$retryCount  = (int) $row['retryCount'] + 1;
		$nextRetryAt = $this->buildRetryDateTimeValue($retryCount);
		$error       = trim((string) $error);
		if ($error === '') {
			$error = 'Unknown global favorites sync error.';
		}
		if (strlen($error) > 255) {
			$error = substr($error, 0, 255);
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "UPDATE `" . self::TABLE_FAVORITE_SYNC . "` SET
				`retryCount` = ?,
				`nextRetryAt` = ?,
				`lastError` = ?
				WHERE `eventUuid` = ?;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}

		$statement->bind_param('isss', $retryCount, $nextRetryAt, $error, $eventUuid);
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
	 * Merge global favorite state rows into the local cache.
	 *
	 * @param string $playerLogin
	 * @param array  $favoriteStateRows
	 * @return bool
	 */
	public function mergeGlobalFavorites($playerLogin, array $favoriteStateRows) {
		$playerLogin = trim((string) $playerLogin);
		if ($playerLogin === '') {
			return false;
		}

		$localRows    = $this->getFavoriteStateRowsByLogin($playerLogin);
		$localLookup  = array();
		$localChanged = false;

		foreach ($localRows as $localRow) {
			$localLookup[$localRow['mapUid']] = $localRow;
		}

		foreach ($favoriteStateRows as $favoriteStateRow) {
			if (is_object($favoriteStateRow)) {
				$favoriteStateRow = (array) $favoriteStateRow;
			}
			if (!is_array($favoriteStateRow)) {
				continue;
			}

			$mapUid = trim((string) (isset($favoriteStateRow['mapUid']) ? $favoriteStateRow['mapUid'] : ''));
			if ($mapUid === '') {
				continue;
			}

			$updatedAt = $this->normalizeDateTimeValue(isset($favoriteStateRow['updatedAt']) ? $favoriteStateRow['updatedAt'] : null);
			if ($updatedAt === '') {
				continue;
			}

			$isFavorite = true;
			if (array_key_exists('isFavorite', $favoriteStateRow)) {
				$isFavorite = ((string) $favoriteStateRow['isFavorite'] === '1' || $favoriteStateRow['isFavorite'] === 1 || $favoriteStateRow['isFavorite'] === true);
			}

			$localRow = null;
			if (isset($localLookup[$mapUid])) {
				$localRow = $localLookup[$mapUid];
			}

			if ($localRow && strcmp($updatedAt, $localRow['updatedAt']) < 0) {
				continue;
			}

			if ($localRow && (int) $localRow['isFavorite'] === (int) $isFavorite && strcmp($updatedAt, $localRow['updatedAt']) === 0) {
				continue;
			}

			if (!$this->upsertFavoriteStateByLogin($playerLogin, $mapUid, $isFavorite, $updatedAt)) {
				continue;
			}

			$localLookup[$mapUid] = array(
				'mapUid'      => $mapUid,
				'isFavorite'  => ($isFavorite ? 1 : 0),
				'updatedAt'   => $updatedAt,
			);
			$localChanged = true;
		}

		return $localChanged;
	}

	/**
	 * Initialize the local favorite state and sync queue tables.
	 *
	 * @return bool
	 */
	private function initTables() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$success = true;

		$query = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_FAVORITE_STATE . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`playerLogin` varchar(100) NOT NULL,
				`mapUid` varchar(50) NOT NULL,
				`isFavorite` tinyint(1) NOT NULL DEFAULT 1,
				`updatedAt` datetime(6) NOT NULL,
				PRIMARY KEY (`index`),
				UNIQUE KEY `player_map` (`playerLogin`, `mapUid`),
				KEY `player_visible` (`playerLogin`, `isFavorite`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Player favorite map state' AUTO_INCREMENT=1;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
			$success = false;
		}

		$query = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_FAVORITE_SYNC . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`eventUuid` char(36) NOT NULL,
				`playerLogin` varchar(100) NOT NULL,
				`mapUid` varchar(50) NOT NULL,
				`operation` varchar(10) NOT NULL,
				`eventTime` datetime(6) NOT NULL,
				`serverLogin` varchar(100) NOT NULL,
				`retryCount` int(11) NOT NULL DEFAULT 0,
				`nextRetryAt` datetime(6) NOT NULL,
				`lastError` varchar(255) DEFAULT NULL,
				PRIMARY KEY (`index`),
				UNIQUE KEY `eventUuid` (`eventUuid`),
				KEY `nextRetryAt` (`nextRetryAt`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Global favorites sync queue' AUTO_INCREMENT=1;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
			$success = false;
		}

		return $success && $result;
	}

	/**
	 * Migrate existing local-only favorites to the login-based storage.
	 */
	private function migrateLegacyFavorites() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SHOW TABLES LIKE '" . self::TABLE_LEGACY_FAVORITES . "';";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return;
		}
		if (!$result || $result->num_rows <= 0) {
			if ($result) {
				$result->free();
			}
			return;
		}
		$result->free();

		$query = "INSERT IGNORE INTO `" . self::TABLE_FAVORITE_STATE . "` (
				`playerLogin`,
				`mapUid`,
				`isFavorite`,
				`updatedAt`
				)
				SELECT players.`login`, legacy.`mapUid`, 1, NOW(6)
				FROM `" . self::TABLE_LEGACY_FAVORITES . "` legacy
				INNER JOIN `" . PlayerManager::TABLE_PLAYERS . "` players
				ON players.`index` = legacy.`playerIndex`;";
		$mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
		}
	}

	/**
	 * Persist one local favorite state row by player login.
	 *
	 * @param string $playerLogin
	 * @param string $mapUid
	 * @param bool   $isFavorite
	 * @param string $updatedAt
	 * @return bool
	 */
	private function upsertFavoriteStateByLogin($playerLogin, $mapUid, $isFavorite, $updatedAt) {
		$playerLogin = trim((string) $playerLogin);
		$mapUid      = trim((string) $mapUid);
		$updatedAt   = $this->normalizeDateTimeValue($updatedAt);
		if ($playerLogin === '' || $mapUid === '' || $updatedAt === '') {
			return false;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "INSERT INTO `" . self::TABLE_FAVORITE_STATE . "` (
				`playerLogin`,
				`mapUid`,
				`isFavorite`,
				`updatedAt`
				) VALUES (
				?, ?, ?, ?
				) ON DUPLICATE KEY UPDATE
				`isFavorite` = VALUES(`isFavorite`),
				`updatedAt` = VALUES(`updatedAt`);";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}

		$favoriteValue = ($isFavorite ? 1 : 0);
		$statement->bind_param('ssis', $playerLogin, $mapUid, $favoriteValue, $updatedAt);
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
	 * Fetch all local favorite state rows for one player login.
	 *
	 * @param string $playerLogin
	 * @return array
	 */
	private function getFavoriteStateRowsByLogin($playerLogin) {
		$playerLogin = trim((string) $playerLogin);
		if ($playerLogin === '') {
			return array();
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SELECT `mapUid`, `isFavorite`, `updatedAt`
				FROM `" . self::TABLE_FAVORITE_STATE . "`
				WHERE `playerLogin` = ?;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return array();
		}

		$statement->bind_param('s', $playerLogin);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return array();
		}

		$statement->bind_result($mapUid, $isFavorite, $updatedAt);
		$rows = array();
		while ($statement->fetch()) {
			$rows[] = array(
				'mapUid'     => $mapUid,
				'isFavorite' => (int) $isFavorite,
				'updatedAt'  => $this->normalizeDateTimeValue($updatedAt),
			);
		}
		$statement->close();

		return $rows;
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
		if (!trim((string) $player->login)) {
			return false;
		}
		if (!$map->uid) {
			return false;
		}
		return true;
	}

	/**
	 * Build the current UTC datetime string with microseconds.
	 *
	 * @return string
	 */
	private function buildCurrentDateTimeValue() {
		$microtime = microtime(true);
		$seconds   = (int) floor($microtime);
		$fraction  = (int) round(($microtime - $seconds) * 1000000);
		if ($fraction >= 1000000) {
			$seconds++;
			$fraction = 0;
		}
		return gmdate('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) $fraction, 6, '0', STR_PAD_LEFT);
	}

	/**
	 * Normalize a SQL or ISO datetime value to UTC SQL datetime(6) format.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeDateTimeValue($value) {
		if ($value === null) {
			return '';
		}

		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		$value = str_replace('T', ' ', $value);
		if (substr($value, -1) === 'Z') {
			$value = substr($value, 0, -1);
		}

		$fraction = '000000';
		if (strpos($value, '.') !== false) {
			list($base, $fractionPart) = explode('.', $value, 2);
			$value     = $base;
			$fraction  = preg_replace('/[^0-9].*$/', '', $fractionPart);
			$fraction  = str_pad(substr($fraction, 0, 6), 6, '0');
		}

		return $value . '.' . $fraction;
	}

	/**
	 * Build the next retry timestamp for a failed sync row.
	 *
	 * @param int $retryCount
	 * @return string
	 */
	private function buildRetryDateTimeValue($retryCount) {
		$retryCount   = max(1, (int) $retryCount);
		$delaySeconds = min(300, 15 * (int) pow(2, min($retryCount - 1, 4)));
		$microtime    = microtime(true) + $delaySeconds;
		$seconds      = (int) floor($microtime);
		$fraction     = (int) round(($microtime - $seconds) * 1000000);
		if ($fraction >= 1000000) {
			$seconds++;
			$fraction = 0;
		}
		return gmdate('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) $fraction, 6, '0', STR_PAD_LEFT);
	}

	/**
	 * Generate a UUID for a sync event.
	 *
	 * @return string
	 */
	private function generateEventUuid() {
		if (function_exists('random_bytes')) {
			$bytes = random_bytes(16);
			$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
			$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
			$hex = bin2hex($bytes);
		} else {
			$hex = md5(uniqid(mt_rand(), true));
			$hex[12] = '4';
			$hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
		}

		return substr($hex, 0, 8) . '-'
			. substr($hex, 8, 4) . '-'
			. substr($hex, 12, 4) . '-'
			. substr($hex, 16, 4) . '-'
			. substr($hex, 20, 12);
	}
}
