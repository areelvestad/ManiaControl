<?php

namespace ManiaControl\ManiaExchange;

use ManiaControl\Files\AsyncHttpRequest;
use ManiaControl\General\UsageInformationAble;
use ManiaControl\General\UsageInformationTrait;
use ManiaControl\ManiaControl;
use Maniaplanet\DedicatedServer\Xmlrpc\GameModeException;

/**
 * Mania Exchange Map Searching Class
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014-2020 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class ManiaExchangeMapSearch implements UsageInformationAble {
	use UsageInformationTrait;

	const V2_FIELDS = 'MapId,MapUid,Name,GbxMapName,UploadedAt,UpdatedAt,Uploader.UserId,Uploader.Name,MapType,Mood,AwardCount,CommentCount,TrackValue,HasThumbnail,HasImages';

	//Search orders (prior parameter) https://api2.mania.exchange/documents/enums#orderings
	const SEARCH_ORDER_NONE               = -1;
	const SEARCH_ORDER_TRACK_NAME         = 0;
	const SEARCH_ORDER_AUTHOR             = 1;
	const SEARCH_ORDER_UPLOADED_NEWEST    = 2;
	const SEARCH_ORDER_UPLOADED_OLDEST    = 3;
	const SEARCH_ORDER_UPDATED_NEWEST     = 4;
	const SEARCH_ORDER_UPDATED_OLDEST     = 5;
	const SEARCH_ORDER_ACTIVITY_LATEST    = 6;
	const SEARCH_ORDER_ACTIVITY_OLDEST    = 7;
	const SEARCH_ORDER_AWARDS_MOST        = 8;
	const SEARCH_ORDER_AWARDS_LEAST       = 9;
	const SEARCH_ORDER_COMMENTS_MOST      = 10;
	const SEARCH_ORDER_COMMENTS_LEAST     = 11;
	const SEARCH_ORDER_DIFFICULTY_EASIEST = 12;
	const SEARCH_ORDER_DIFFICULTY_HARDEST = 13;
	const SEARCH_ORDER_LENGTH_SHORTEST    = 14;
	const SEARCH_ORDER_LENGTH_LONGEST     = 15;
	const SEARCH_ORDER_TRACK_VALUE_LTH    = 24;
	const SEARCH_ORDER_TRACK_VALUE_HTL    = 25;
	const SEARCH_ORDER_ONLINE_RATING_LTH  = 26;
	const SEARCH_ORDER_ONLINE_RATING_HTL  = 27;

    //Special Search Orders (mode parameter): https://api2.mania.exchange/documents/enums#modes
	const SEARCH_ORDER_SPECIAL_DEFAULT                  = 0;
	const SEARCH_ORDER_SPECIAL_USER_TRACKS              = 1;
	const SEARCH_ORDER_SPECIAL_LATEST_TRACKS            = 2;
	const SEARCH_ORDER_SPECIAL_RECENTLY_AWARDED         = 3;
	const SEARCH_ORDER_SPECIAL_BEST_OF_WEEK_AWARDS      = 4;
	const SEARCH_ORDER_SPECIAL_BEST_OF_MONTH_AWARDS     = 5;
	const SEARCH_ORDER_SPECIAL_MX_SUPPORTER_TRACKS      = 10;
	const SEARCH_ORDER_SPECIAL_DUO_ACCOUNT_TRACKS       = 11;
	const SEARCH_ORDER_SPECIAL_MOST_COMPETITIVE_WEEK    = 19;
	const SEARCH_ORDER_SPECIAL_MOST_COMPETITIVE_MONTH   = 20;
	const SEARCH_ORDER_SPECIAL_BEST_ONLINE_RATING_WEEK  = 21;
	const SEARCH_ORDER_SPECIAL_BEST_ONLINE_RATING_MONTH = 22;

	//Private Properties
	private $url         = "";
	private $titlePrefix = "";

	private $mode              = null;
	private $mapName           = null;
	private $authorName        = null;
	private $mod               = null;
	private $authorId          = null;
	private $maniaScriptType   = null;
	private $titlePack         = null;
	private $replayType        = null;
	private $style             = null;
	private $length            = null;
	private $lengthOperator    = null;
	private $priorityOrder     = null;
	private $secondaryOrder    = null;
	private $environments      = null;
	private $vehicles          = null;
	private $page              = null;
	private $mapLimit          = null;
	private $unreleased        = null;
	private $mapGroup          = null;
	private $commentsMinLength = null;
	private $customScreenshot  = null;
	private $minExeBuild       = null;
	private $envMix            = null;
	private $ghostBlocks       = null;
	private $embeddedObjects   = null;
	private $key               = null;
	private $mp4               = null;

	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;

	//TODO use class by mxlist

	/**
	 * Construct map manager
	 *
	 * @param \ManiaControl\ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;

		$this->titlePrefix = $this->maniaControl->getMapManager()->getCurrentMap()->getGame();

		$this->url = MXMapInfo::getExchangeBaseUrl($this->titlePrefix) . '/api/maps';

		$titleIdParts = explode('@', (string) $this->maniaControl->getServer()->titleId);
		if (!empty($titleIdParts[0])) {
			$this->titlePack = $titleIdParts[0];
		}

		/*if ($key = $this->maniaControl->getSettingManager()->getSettingValue($this->maniaControl->getMapManager()->getMXManager(), ManiaExchangeManager::SETTING_MX_KEY)) {
			$this->url .= "&key=" . $key;
		}*/


		//Set some defaults:
		$this->mapLimit      = 100;
		$this->priorityOrder = self::SEARCH_ORDER_UPDATED_NEWEST;
		$this->mp4           = true;

		//Set Min Exe Build Default for games which are not Trackmania
		/*if ($this->titlePrefix !== "tm") {
			$this->minExeBuild = ManiaExchangeManager::MIN_EXE_BUILD;
		}*/

		//Set MapTypes
		try {
			$scriptInfos           = $this->maniaControl->getClient()->getModeScriptInfo();
			$mapTypes              = $scriptInfos->compatibleMapTypes;
			$this->maniaScriptType = $this->resolvePreferredMapType($mapTypes);
		} catch (GameModeException $e) {
		}

		if (!$this->maniaScriptType) {
			$this->maniaScriptType = $this->getCurrentMapType();
		}

	}

	/**
	 * Fetch a MapList Asynchronously
	 *
	 * @param callable $function
	 */
	public function fetchMapsAsync(callable $function) {
		$this->fetchMapsDetailedAsync(function (array $maps, $error = null) use (&$function) {
			call_user_func($function, $maps);
		});
	}

	/**
	 * Fetch a MapList asynchronously and also report transport / decode errors.
	 *
	 * @param callable $function
	 */
	public function fetchMapsDetailedAsync(callable $function) {
		$this->fetchMapsByQuery($this->buildQuery(), $function);
	}

	/**
	 * Build the current MX v2 query array.
	 *
	 * @return array
	 */
	public function buildQuery() {
		$query = array(
			'fields' => self::V2_FIELDS,
		);

		if ($this->mapName) {
			$query['name'] = $this->mapName;
		}
		if ($this->authorName) {
			$query['author'] = $this->authorName;
		}
		if ($this->titlePack) {
			$query['titlepack'] = $this->titlePack;
		}

		$mapTypes = $this->normalizeQueryValue($this->maniaScriptType);
		if ($mapTypes) {
			$query['maptype'] = $mapTypes;
		}
		if ($this->environments) {
			$query['environment'] = $this->normalizeQueryValue($this->environments);
		}
		if ($this->page) {
			$query['page'] = $this->page;
		}
		if ($this->mapLimit) {
			$query['limit'] = $this->mapLimit;
		}

		return $query;
	}

	/**
	 * Build the normalized cache key for the current search.
	 *
	 * @return string
	 */
	public function getCacheKey() {
		return self::buildCacheKey($this->titlePrefix, $this->buildQuery());
	}

	/**
	 * Build the normalized cache key for a query.
	 *
	 * @param string $titlePrefix
	 * @param array  $query
	 * @return string
	 */
	public static function buildCacheKey($titlePrefix, array $query) {
		ksort($query);
		return $titlePrefix . ':' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Execute an MX v2 query and retry once without maptype if it yields no results.
	 *
	 * @param array    $query
	 * @param callable $function
	 * @param bool     $allowMapTypeRetry
	 */
	private function fetchMapsByQuery(array $query, callable $function, $allowMapTypeRetry = true) {
		$asyncHttpRequest = new AsyncHttpRequest($this->maniaControl, $this->url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
		$asyncHttpRequest->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
		$asyncHttpRequest->setCallable(function ($mapInfo, $error) use (&$function, $query, $allowMapTypeRetry) {
			if ($error) {
				trigger_error($error);
				call_user_func($function, array(), $error);
				return;
			}

			$mxMapList = json_decode($mapInfo);

			if (!isset($mxMapList->Results) || !is_array($mxMapList->Results)) {
				$errorMessage = 'Cannot decode searched JSON data';
				trigger_error($errorMessage);
				call_user_func($function, array(), $errorMessage);
				return;
			}

			$mxMapList = $mxMapList->Results;

			if ($mxMapList === null) {
				$errorMessage = 'Cannot decode searched JSON data';
				trigger_error($errorMessage);
				call_user_func($function, array(), $errorMessage);
				return;
			}

			if (empty($mxMapList) && $allowMapTypeRetry && isset($query['maptype'])) {
				unset($query['maptype']);
				$this->fetchMapsByQuery($query, $function, false);
				return;
			}

			$maps = array();
			foreach ($mxMapList as $map) {
				if (!empty($map)) {
					array_push($maps, new MXMapInfo($this->titlePrefix, $map));
				}
			}

			call_user_func($function, $maps, null);
		});

		$asyncHttpRequest->getData();
	}

	/**
	 * Normalize query values for MX v2 string filters.
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	private function normalizeQueryValue($value) {
		if (is_array($value)) {
			$value = array_filter($value, 'strlen');
			if (empty($value)) {
				return null;
			}
			return implode(',', $value);
		}

		if ($value === null || $value === '') {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Resolve the best usable map type filter for MX v2.
	 * MX returns no results for comma-separated maptype values, so if the mode exposes
	 * multiple compatible types we fall back to the current map's single type.
	 *
	 * @param mixed $mapTypes
	 * @return string|null
	 */
	private function resolvePreferredMapType($mapTypes) {
		if (is_array($mapTypes)) {
			$mapTypes = array_values(array_filter($mapTypes, 'strlen'));
			if (count($mapTypes) === 1) {
				return (string) $mapTypes[0];
			}
			return $this->getCurrentMapType();
		}

		if ($mapTypes === null || $mapTypes === '') {
			return $this->getCurrentMapType();
		}

		$mapTypes = preg_split('/\s*,\s*/', (string) $mapTypes);
		$mapTypes = array_values(array_filter($mapTypes, 'strlen'));
		if (count($mapTypes) === 1) {
			return (string) $mapTypes[0];
		}

		return $this->getCurrentMapType();
	}

	/**
	 * Get the current map's single mode type when available.
	 *
	 * @return string|null
	 */
	private function getCurrentMapType() {
		$currentMap = $this->maniaControl->getMapManager()->getCurrentMap();
		if ($currentMap && !empty($currentMap->mapType)) {
			return (string) $currentMap->mapType;
		}

		return null;
	}


	/**
	 * Get the Current Environment by String
	 *
	 * @param string $env
	 * @return int
	 */
	public static function getEnvironment($env) {
		switch ($env) {
			case 'TMCanyon':
				return 1;
			case 'TMStadium':
				return 2;
			case 'TMValley':
				return 3;
			case 'TMLagoon':
				return 4;
			default:
				return -1;
		}
	}

	/**
	 * @param int $mode
	 */
	public function setMode($mode) {
		$this->mode = $mode;
	}

	/**
	 * @param string $mapName
	 */
	public function setMapName($mapName) {
		$this->mapName = $mapName;
	}

	/**
	 * @param string $authorName
	 */
	public function setAuthorName($authorName) {
		$this->authorName = $authorName;
	}

	/**
	 * @param int $authorId
	 */
	public function setAuthorId($authorId) {
		$this->authorId = $authorId;
	}

	/**
	 * @param string $maniaScriptType
	 */
	public function setManiaScriptType($maniaScriptType) {
		$this->maniaScriptType = $maniaScriptType;
	}

	/**
	 * @param string $mod
	 */
	public function setMod($mod) {
		$this->mod = $mod;
	}

	/**
	 * @param string $titlePack
	 */
	public function setTitlePack($titlePack) {
		$this->titlePack = $titlePack;
	}

	/**
	 * @param int $replayType
	 */
	public function setReplayType($replayType) {
		$this->replayType = $replayType;
	}

	/**
	 * @param int $length
	 */
	public function setLength($length) {
		$this->length = $length;
	}

	/**
	 * @param int $style
	 */
	public function setStyle($style) {
		$this->style = $style;
	}

	/**
	 * @param int $lengthOperator
	 */
	public function setLengthOperator($lengthOperator) {
		$this->lengthOperator = $lengthOperator;
	}

	/**
	 * @param int $secondaryOrder
	 */
	public function setSecondarySortOrder($secondaryOrder) {
		$this->secondaryOrder = $secondaryOrder;
	}

	/**
	 * @param int $priorityOrder
	 */
	public function setPrioritySortOrder($priorityOrder) {
		$this->priorityOrder = $priorityOrder;
	}

	/**
	 * @param string $environments
	 */
	public function setEnvironments($environments) {
		$this->environments = $environments;
	}

	/**
	 * @param int $page
	 */
	public function setPage($page) {
		$this->page = $page;
	}

	/**
	 * @param string $vehicles
	 */
	public function setVehicles($vehicles) {
		$this->vehicles = $vehicles;
	}

	/**
	 * @param bool $unreleased
	 */
	public function setUnreleased($unreleased) {
		$this->unreleased = $unreleased;
	}

	/**
	 * @param int $mapGroup
	 */
	public function setMapGroup($mapGroup) {
		$this->mapGroup = $mapGroup;
	}

	/**
	 * @param int $commentsMinLength
	 */
	public function setCommentsMinLength($commentsMinLength) {
		$this->commentsMinLength = $commentsMinLength;
	}

	/**
	 * @param bool $customScreenshot
	 */
	public function setCustomScreenshot($customScreenshot) {
		$this->customScreenshot = $customScreenshot;
	}

	/**
	 * @param bool $envMix
	 */
	public function setEnvMix($envMix) {
		$this->envMix = $envMix;
	}

	/**
	 * @param string $minExeBuild
	 */
	public function setMinExeBuild($minExeBuild) {
		$this->minExeBuild = $minExeBuild;
	}

	/**
	 * @param bool $ghostBlocks
	 */
	public function setGhostBlocks($ghostBlocks) {
		$this->ghostBlocks = $ghostBlocks;
	}

	/**
	 * @param bool $embeddedObjects
	 */
	public function setEmbeddedObjects($embeddedObjects) {
		$this->embeddedObjects = $embeddedObjects;
	}

	/**
	 * @param null $mapLimit
	 */
	public function setMapLimit($mapLimit) {
		$this->mapLimit = $mapLimit;
	}

	/**
	 * @param null $key
	 */
	public function setKey($key) {
		$this->key = $key;
	}
}
