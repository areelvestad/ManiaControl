<?php

namespace ManiaControl\Maps;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Files\AsyncHttpRequest;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;

/**
 * Global favorites sync helper.
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014-2020 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class MapFavoritesSync implements CallbackListener, TimerListener {
	/*
	 * Constants
	 */
	const SETTING_GLOBAL_FAVORITES_ENABLED = 'Global Favorites Enabled';
	const TABLE_SERVER_AUTH                = 'mc_fav_server';
	const API_BASE_URL                     = 'https://api.areelvestad.no/maniacontrol';
	const PUSH_INTERVAL_SECONDS            = 60;
	const PULL_ON_PLAYER_CONNECT           = true;
	const PULL_ON_FAVORITES_OPEN           = true;
	const SYNC_BATCH_SIZE                  = 20;

	/*
	 * Private properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;
	/** @var MapManager $mapManager */
	private $mapManager = null;
	private $pendingEvents = array();
	private $pendingPulls = array();
	private $nextQueueRunAt = 0;
	private $registrationInProgress = false;

	/**
	 * Construct a new global favorites sync helper.
	 *
	 * @param ManiaControl $maniaControl
	 * @param MapManager   $mapManager
	 */
	public function __construct(ManiaControl $maniaControl, MapManager $mapManager) {
		$this->maniaControl = $maniaControl;
		$this->mapManager   = $mapManager;
		$this->initAuthTable();
		$this->ensureInstallationId();
	}

	/**
	 * Check whether the feature is currently enabled.
	 *
	 * @return bool
	 */
	public function isFeatureEnabled() {
		return (bool) $this->maniaControl->getSettingManager()->getSettingValue($this->mapManager, self::SETTING_GLOBAL_FAVORITES_ENABLED);
	}

	/**
	 * Process the sync queue on the configured interval.
	 */
	public function handleSyncTimer() {
		if (!$this->isFeatureEnabled()) {
			return;
		}

		$time = microtime(true);
		if ($time < $this->nextQueueRunAt) {
			return;
		}

		$this->nextQueueRunAt = $time + self::PUSH_INTERVAL_SECONDS;

		if (!$this->hasApiKey()) {
			$this->ensureRegistration();
			return;
		}

		$this->processQueue(self::SYNC_BATCH_SIZE);
	}

	/**
	 * Warm a player's local favorite cache on connect.
	 *
	 * @param Player $player
	 */
	public function handlePlayerConnect(Player $player) {
		if (!$player || !$this->isFeatureEnabled() || !self::PULL_ON_PLAYER_CONNECT) {
			return;
		}

		$this->pullFavoritesForPlayer($player, false);
	}

	/**
	 * Trigger a background pull when the player opens the favorites view.
	 *
	 * @param Player $player
	 */
	public function pullFavoritesOnOpen(Player $player) {
		if (!$player || !$this->isFeatureEnabled() || !self::PULL_ON_FAVORITES_OPEN) {
			return;
		}

		$this->pullFavoritesForPlayer($player, true);
	}

	/**
	 * Push a queued sync row by event UUID.
	 *
	 * @param string $eventUuid
	 * @return bool
	 */
	public function pushQueuedEventByUuid($eventUuid) {
		if (!$this->isFeatureEnabled()) {
			return false;
		}
		if (!$this->ensureRegistration()) {
			return false;
		}

		$syncRow = $this->mapManager->getMapFavorites()->getSyncRow($eventUuid);
		if (!$syncRow) {
			return false;
		}

		return $this->pushQueuedEvent($syncRow);
	}

	/**
	 * Process due queue rows.
	 *
	 * @param int $limit
	 */
	public function processQueue($limit = 20) {
		if (!$this->isFeatureEnabled()) {
			return;
		}
		if (!$this->ensureRegistration()) {
			return;
		}

		$limit = max(1, (int) $limit);
		$syncRows = $this->mapManager->getMapFavorites()->getDueSyncRows($limit);
		foreach ($syncRows as $syncRow) {
			$this->pushQueuedEvent($syncRow);
		}
	}

	/**
	 * Pull the authoritative global favorite state for one player.
	 *
	 * @param Player $player
	 * @param bool   $refreshFavoriteView
	 * @return bool
	 */
	public function pullFavoritesForPlayer(Player $player, $refreshFavoriteView = false) {
		if (!$player || !$player->login || !$this->isFeatureEnabled()) {
			return false;
		}
		if (!$this->ensureRegistration()) {
			return false;
		}

		$playerLogin = (string) $player->login;
		if (isset($this->pendingPulls[$playerLogin])) {
			return false;
		}

		$url = $this->buildEndpointUrl('player.php?login=' . urlencode($playerLogin));
		if (!$url) {
			return false;
		}

		$this->pendingPulls[$playerLogin] = true;

		$asyncHttpRequest = new AsyncHttpRequest($this->maniaControl, $url);
		$asyncHttpRequest->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
		$asyncHttpRequest->setHeaders($this->buildAuthHeaders());
		$asyncHttpRequest->setCallable(function ($json, $error) use (&$playerLogin, &$refreshFavoriteView) {
			unset($this->pendingPulls[$playerLogin]);

			if ($error) {
				Logger::log('Global favorites pull failed for ' . $playerLogin . ': ' . $error);
				return;
			}

			$data = json_decode($json, true);
			if (!$this->isSuccessfulApiResponse($data)) {
				$this->handleApiFailure($data);
				Logger::log('Global favorites pull returned an invalid response for ' . $playerLogin . '.');
				return;
			}

			$changed = $this->mapManager->getMapFavorites()->mergeGlobalFavorites($playerLogin, $data['favorites']);
			if (!$changed || !$refreshFavoriteView) {
				return;
			}

			$connectedPlayer = $this->maniaControl->getPlayerManager()->getPlayer($playerLogin, true);
			if (!$connectedPlayer) {
				return;
			}
			if (!$this->mapManager->getMapList()->isFavoriteViewActive($connectedPlayer)) {
				return;
			}

			$this->mapManager->getMapList()->showCurrentView($connectedPlayer);
		});
		$asyncHttpRequest->getData();

		return true;
	}

	/**
	 * Push one queued sync row to the global API.
	 *
	 * @param array $syncRow
	 * @return bool
	 */
	private function pushQueuedEvent(array $syncRow) {
		$eventUuid = (string) (isset($syncRow['eventUuid']) ? $syncRow['eventUuid'] : '');
		if ($eventUuid === '' || isset($this->pendingEvents[$eventUuid])) {
			return false;
		}

		$url = $this->buildEndpointUrl('event.php');
		if (!$url) {
			return false;
		}

		$payload = array(
			'eventUuid'   => $eventUuid,
			'playerLogin' => (string) $syncRow['playerLogin'],
			'mapUid'      => (string) $syncRow['mapUid'],
			'operation'   => (string) $syncRow['operation'],
			'eventTime'   => $this->sqlDateTimeToIso8601((string) $syncRow['eventTime']),
		);

		$this->pendingEvents[$eventUuid] = true;

		$asyncHttpRequest = new AsyncHttpRequest($this->maniaControl, $url);
		$asyncHttpRequest->setContent(json_encode($payload));
		$asyncHttpRequest->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
		$asyncHttpRequest->setHeaders($this->buildAuthHeaders());
		$asyncHttpRequest->setCallable(function ($json, $error) use (&$eventUuid) {
			unset($this->pendingEvents[$eventUuid]);

			if ($error) {
				$this->mapManager->getMapFavorites()->markSyncFailure($eventUuid, $error);
				return;
			}

			$data = json_decode($json, true);
			if (!$this->isSuccessfulApiResponse($data)) {
				$this->handleApiFailure($data);
				$message = 'Invalid global favorites API response.';
				if (is_array($data) && !empty($data['error'])) {
					$message = (string) $data['error'];
				}
				$this->mapManager->getMapFavorites()->markSyncFailure($eventUuid, $message);
				return;
			}

			$this->mapManager->getMapFavorites()->deleteSyncRow($eventUuid);
		});
		$asyncHttpRequest->postData();

		return true;
	}

	/**
	 * Ensure the local installation is registered and has an API key.
	 *
	 * @param bool $force
	 * @return bool
	 */
	private function ensureRegistration($force = false) {
		if (!$this->isFeatureEnabled()) {
			return false;
		}

		$installationId = $this->ensureInstallationId();
		if (!$installationId) {
			return false;
		}

		if (!$force && $this->hasApiKey()) {
			return true;
		}
		if ($this->registrationInProgress) {
			return false;
		}

		$this->registerInstallation($installationId);
		return false;
	}

	/**
	 * Register this ManiaControl installation with the global API.
	 *
	 * @param string $installationId
	 * @return bool
	 */
	private function registerInstallation($installationId) {
		$url = $this->buildEndpointUrl('register.php');
		if (!$url) {
			return false;
		}

		$this->registrationInProgress = true;

		$payload = array(
			'installationId'    => $installationId,
			'serverLogin'       => (string) $this->maniaControl->getServer()->login,
			'maniaControlVersion' => ManiaControl::VERSION,
			'titleId'           => (string) $this->maniaControl->getServer()->titleId,
		);

		$asyncHttpRequest = new AsyncHttpRequest($this->maniaControl, $url);
		$asyncHttpRequest->setContent(json_encode($payload));
		$asyncHttpRequest->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
		$asyncHttpRequest->setCallable(function ($json, $error) {
			$this->registrationInProgress = false;

			if ($error) {
				Logger::log('Global favorites registration failed: ' . $error);
				return;
			}

			$data = json_decode($json, true);
			if (!is_array($data) || empty($data['ok']) || empty($data['installationId']) || empty($data['apiKey'])) {
				Logger::log('Global favorites registration returned an invalid response.');
				return;
			}

			$this->storeCredentials((string) $data['installationId'], (string) $data['apiKey']);
			$this->processQueue(self::SYNC_BATCH_SIZE);

			foreach ($this->maniaControl->getPlayerManager()->getPlayers() as $player) {
				if (!$player instanceof Player || !$player->login) {
					continue;
				}

				$this->pullFavoritesForPlayer($player, $this->mapManager->getMapList()->isFavoriteViewActive($player));
			}
		});
		$asyncHttpRequest->postData();

		return true;
	}

	/**
	 * Build an API endpoint URL from the configured base URL.
	 *
	 * @param string $endpoint
	 * @return string
	 */
	private function buildEndpointUrl($endpoint) {
		return rtrim(self::API_BASE_URL, '/') . '/' . ltrim($endpoint, '/');
	}

	/**
	 * Build the authentication headers for API requests.
	 *
	 * @return array
	 */
	private function buildAuthHeaders() {
		return array(
			'X-ManiaControl-Installation: ' . $this->getInstallationId(),
			'X-ManiaControl-Key: ' . $this->getApiKey(),
		);
	}

	/**
	 * Check whether an API response is a success payload.
	 *
	 * @param mixed $data
	 * @return bool
	 */
	private function isSuccessfulApiResponse($data) {
		return (is_array($data) && !empty($data['ok']));
	}

	/**
	 * Handle a failed authenticated API response.
	 *
	 * @param mixed $data
	 */
	private function handleApiFailure($data) {
		if (!is_array($data) || empty($data['error'])) {
			return;
		}

		$error = strtolower(trim((string) $data['error']));
		if ($error === 'invalid api key.' || $error === 'unknown or disabled installation.') {
			$this->clearApiKey();
			$this->ensureRegistration(true);
		}
	}

	/**
	 * Initialize the hidden local auth table.
	 */
	private function initAuthTable() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_SERVER_AUTH . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`installationId` char(36) NOT NULL,
				`apiKey` varchar(100) DEFAULT NULL,
				`created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`changed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `installationId` (`installationId`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Global favorites local auth' AUTO_INCREMENT=1;";
		$mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
		}
	}

	/**
	 * Ensure the local auth row exists and has an installation UUID.
	 *
	 * @return string
	 */
	private function ensureInstallationId() {
		$authRow = $this->getAuthRow();
		if ($authRow && !empty($authRow['installationId'])) {
			return (string) $authRow['installationId'];
		}

		$installationId = $this->generateUuid();
		$mysqli         = $this->maniaControl->getDatabase()->getMysqli();
		$query          = "INSERT INTO `" . self::TABLE_SERVER_AUTH . "` (
				`installationId`,
				`apiKey`
				) VALUES (
				?, NULL
				);";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return '';
		}
		$statement->bind_param('s', $installationId);
		$statement->execute();
		if ($statement->error) {
			trigger_error($statement->error);
			$statement->close();
			return '';
		}
		$statement->close();

		return $installationId;
	}

	/**
	 * Store the issued API credentials locally.
	 *
	 * @param string $installationId
	 * @param string $apiKey
	 * @return bool
	 */
	private function storeCredentials($installationId, $apiKey) {
		$installationId = trim((string) $installationId);
		$apiKey         = trim((string) $apiKey);
		if ($installationId === '' || $apiKey === '') {
			return false;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "UPDATE `" . self::TABLE_SERVER_AUTH . "` SET
				`installationId` = ?,
				`apiKey` = ?
				LIMIT 1;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		$statement->bind_param('ss', $installationId, $apiKey);
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
	 * Clear the locally stored API key.
	 */
	private function clearApiKey() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "UPDATE `" . self::TABLE_SERVER_AUTH . "` SET
				`apiKey` = NULL
				LIMIT 1;";
		$mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
		}
	}

	/**
	 * Check whether a local API key is stored.
	 *
	 * @return bool
	 */
	private function hasApiKey() {
		return ($this->getApiKey() !== '');
	}

	/**
	 * Get the local installation ID.
	 *
	 * @return string
	 */
	private function getInstallationId() {
		$authRow = $this->getAuthRow();
		if (!$authRow) {
			return '';
		}
		return (string) $authRow['installationId'];
	}

	/**
	 * Get the locally stored API key.
	 *
	 * @return string
	 */
	private function getApiKey() {
		$authRow = $this->getAuthRow();
		if (!$authRow || !isset($authRow['apiKey'])) {
			return '';
		}
		return trim((string) $authRow['apiKey']);
	}

	/**
	 * Fetch the local auth row.
	 *
	 * @return array|null
	 */
	private function getAuthRow() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SELECT `installationId`, `apiKey`
				FROM `" . self::TABLE_SERVER_AUTH . "`
				ORDER BY `index` ASC
				LIMIT 1;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		if (!$result) {
			return null;
		}

		$row = $result->fetch_assoc();
		$result->free();
		return ($row ? $row : null);
	}

	/**
	 * Convert a SQL datetime(6) value to an ISO 8601 UTC timestamp.
	 *
	 * @param string $dateTimeValue
	 * @return string
	 */
	private function sqlDateTimeToIso8601($dateTimeValue) {
		$dateTimeValue = trim((string) $dateTimeValue);
		if ($dateTimeValue === '') {
			return '';
		}

		$fraction = '';
		if (strpos($dateTimeValue, '.') !== false) {
			list($base, $fractionPart) = explode('.', $dateTimeValue, 2);
			$dateTimeValue = $base;
			$fraction = '.' . str_pad(substr(preg_replace('/[^0-9].*$/', '', $fractionPart), 0, 6), 6, '0');
		}

		return str_replace(' ', 'T', $dateTimeValue) . $fraction . 'Z';
	}

	/**
	 * Generate a UUID string.
	 *
	 * @return string
	 */
	private function generateUuid() {
		if (function_exists('random_bytes')) {
			$bytes = random_bytes(16);
			$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
			$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
			$hex = bin2hex($bytes);
		} else if (function_exists('openssl_random_pseudo_bytes')) {
			$bytes = openssl_random_pseudo_bytes(16);
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
