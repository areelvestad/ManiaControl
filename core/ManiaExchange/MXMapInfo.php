<?php

namespace ManiaControl\ManiaExchange;

use ManiaControl\Utils\Formatter;

/**
 * Mania Exchange Map Info Object
 *
 * @author    Xymph
 * @updated   kremsy <kremsy@maniacontrol.com>
 * @copyright 2014-2020 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class MXMapInfo {
	/*
	 * Public properties
	 */
	public $prefix, $id, $uid, $name, $userid, $author, $uploaded, $updated, $type, $maptype;
	public                                                                          $titlepack, $style, $envir, $mood, $dispcost, $lightmap, $modname, $exever;
	public                                                                          $exebld, $routes, $length, $unlimiter, $laps, $difficulty, $lbrating, $trkvalue;
	public                                                                          $replaytyp, $replayid, $replaycnt, $authorComment, $commentCount, $awards;
	public                                                                          $pageurl, $replayurl, $imageurl, $thumburl, $downloadurl, $dir;
	public                                                                          $ratingVoteCount, $ratingVoteAverage, $vehicleName;

	/**
	 * Returns map object with all available data from MX map data
	 *
	 * @param String $prefix MX URL prefix
	 * @param        $mx
	 * @return \ManiaControl\ManiaExchange\MXMapInfo|void
	 */
	public function __construct($prefix, $mx) {
		$config       = self::getSiteConfig($prefix);
		$this->prefix = $config['prefix'];
		$this->dir    = $config['dir'];

		if (!$mx) {
			return;
		}

		$this->id  = self::readProperty($mx, array('MapId', 'MapID', 'TrackID'), 0);
		$this->uid = self::readProperty($mx, array('MapUid', 'TrackUID'), '');

		$gbxMapName = self::readProperty($mx, array('GbxMapName'));
		if (!$gbxMapName || $gbxMapName === '?') {
			$this->name = self::readProperty($mx, array('Name'), '');
		} else {
			$this->name = Formatter::stripDirtyCodes($gbxMapName);
		}

		$this->userid      = self::readNestedProperty($mx, array('Uploader', 'UserId'), self::readProperty($mx, array('UserID'), 0));
		$this->author      = self::readNestedProperty($mx, array('Uploader', 'Name'), self::readNestedProperty($mx, array('Authors', 0, 'User', 'Name'), self::readProperty($mx, array('Username'), '')));
		$this->uploaded    = self::readProperty($mx, array('UploadedAt'), '');
		$this->updated     = self::readProperty($mx, array('UpdatedAt'), '');
		$this->type        = self::readProperty($mx, array('TypeName', 'Type'), '');
		$this->maptype     = self::readProperty($mx, array('MapType'), '');
		$this->titlepack   = self::readProperty($mx, array('TitlePack'), '');
		$this->style       = self::readProperty($mx, array('StyleName', 'Style'), '');
		$this->envir       = self::readProperty($mx, array('EnvironmentName', 'Environment'), '');
		$this->mood        = self::readProperty($mx, array('Mood', 'MoodFull'), '');
		$this->dispcost    = self::readProperty($mx, array('DisplayCost'), 0);
		$this->lightmap    = self::readProperty($mx, array('Lightmap'), 0);
		$this->modname     = self::readProperty($mx, array('ModName'), '');
		$this->exever      = self::readProperty($mx, array('ExeVersion'), '');
		$this->exebld      = self::readProperty($mx, array('ExeBuild'), '');
		$this->routes      = self::readProperty($mx, array('RouteName', 'Routes'), '');
		$this->length      = self::readProperty($mx, array('LengthName', 'Length'), '');
		$this->unlimiter   = self::readProperty($mx, array('UnlimiterRequired'), false);
		$this->laps        = self::readProperty($mx, array('Laps'), 0);
		$this->difficulty  = self::readProperty($mx, array('DifficultyName', 'Difficulty'), '');
		$this->lbrating    = self::readProperty($mx, array('LBRating'), 0);
		$this->trkvalue    = self::readProperty($mx, array('TrackValue'), 0);
		$this->replaytyp   = self::readProperty($mx, array('ReplayTypeName', 'ReplayType'), '');
		$this->replayid    = self::readProperty($mx, array('ReplayWRID'), 0);
		$this->replaycnt   = self::readProperty($mx, array('ReplayCount'), 0);
		$this->awards      = self::readProperty($mx, array('AwardCount'), 0);
		$this->vehicleName = self::readProperty($mx, array('VehicleName'), '');

		$this->authorComment = self::readProperty($mx, array('Comments', 'AuthorComments'), '');
		$this->commentCount  = self::readProperty($mx, array('CommentCount'), 0);

		$this->ratingVoteCount   = self::readProperty($mx, array('RatingVoteCount'), 0);
		$this->ratingVoteAverage = self::readProperty($mx, array('RatingVoteAverage'), 0);

		if (!$this->trkvalue && $this->lbrating > 0) {
			$this->trkvalue = $this->lbrating;
		} elseif (!$this->lbrating && $this->trkvalue > 0) {
			$this->lbrating = $this->trkvalue;
		}

		$baseUrl           = $config['baseUrl'];
		$this->pageurl     = $baseUrl . '/' . $this->dir . '/view/' . $this->id;
		$this->downloadurl = $baseUrl . '/' . $this->dir . '/download/' . $this->id;

		if (self::readProperty($mx, array('HasImages', 'HasScreenshot'), false)) {
			$this->imageurl = $baseUrl . '/' . $this->dir . '/screenshot/normal/' . $this->id;
		} else {
			$this->imageurl = '';
		}

		if (self::readProperty($mx, array('HasThumbnail'), false)) {
			$this->thumburl = $baseUrl . '/' . $this->dir . '/thumbnail/' . $this->id;
		} else {
			$this->thumburl = '';
		}

		if ($this->prefix === 'tm' && $this->replayid > 0) {
			$this->replayurl = $baseUrl . '/replays/download/' . $this->replayid;
		} else {
			$this->replayurl = '';
		}
	}

	/**
	 * Resolve the MX base URL for the given title prefix.
	 *
	 * @param string $prefix
	 * @return string
	 */
	public static function getExchangeBaseUrl($prefix) {
		$config = self::getSiteConfig($prefix);
		return $config['baseUrl'];
	}

	/**
	 * Resolve host and URL path settings for an MX title prefix.
	 *
	 * @param string $prefix
	 * @return array
	 */
	private static function getSiteConfig($prefix) {
		$prefix = strtolower((string) $prefix);

		switch ($prefix) {
			case 'tm':
				return array(
					'prefix'  => 'tm',
					'baseUrl' => 'https://tm.mania.exchange',
					'dir'     => 'tracks',
				);
			case 'sm':
				return array(
					'prefix'  => 'sm',
					'baseUrl' => 'https://sm.mania.exchange',
					'dir'     => 'maps',
				);
			default:
				return array(
					'prefix'  => $prefix,
					'baseUrl' => 'https://' . $prefix . '.mania.exchange',
					'dir'     => ($prefix === 'tm' ? 'tracks' : 'maps'),
				);
		}
	}

	/**
	 * Read the first available property from an MX payload.
	 *
	 * @param object $mx
	 * @param array  $properties
	 * @param mixed  $default
	 * @return mixed
	 */
	private static function readProperty($mx, array $properties, $default = null) {
		foreach ($properties as $property) {
			if (is_object($mx) && property_exists($mx, $property) && $mx->{$property} !== null) {
				return $mx->{$property};
			}
		}

		return $default;
	}

	/**
	 * Read a nested property from an MX payload.
	 *
	 * @param mixed $value
	 * @param array $path
	 * @param mixed $default
	 * @return mixed
	 */
	private static function readNestedProperty($value, array $path, $default = null) {
		foreach ($path as $segment) {
			if (is_object($value) && property_exists($value, $segment)) {
				$value = $value->{$segment};
				continue;
			}
			if (is_array($value) && isset($value[$segment])) {
				$value = $value[$segment];
				continue;
			}

			return $default;
		}

		return ($value === null ? $default : $value);
	}
}
