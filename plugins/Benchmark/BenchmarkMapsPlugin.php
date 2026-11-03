<?php

namespace BenchmarkMaps;

use ManiaControl\Callbacks\CallbackListener;
use FML\Controls\Frame;
use FML\Controls\Labels\Label_Button;
use FML\Controls\Labels\Label_Text;
use FML\Controls\Quad;
use FML\Controls\Quads\Quad_BgsPlayerCard;
use FML\ManiaLink;
use FML\Script\Features\Paging;
use ManiaControl\ManiaControl;
use ManiaControl\Maps\MapManager;
use ManiaControl\Manialinks\LabelLine;
use ManiaControl\Manialinks\IconManager;
use ManiaControl\Manialinks\ManialinkManager;
use ManiaControl\Manialinks\ManialinkPageAnswerListener;
use ManiaControl\Manialinks\SidebarMenuEntryListener;
use ManiaControl\Manialinks\SidebarMenuManager;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Utils\Formatter;
use Maniaplanet\DedicatedServer\Xmlrpc\ChangeInProgressException;
use Maniaplanet\DedicatedServer\Xmlrpc\NextMapException;
use Maniaplanet\DedicatedServer\Xmlrpc\NotInListException;

/**
 * Displays the Obstacle Benchmark 2 campaign maps and server availability.
 */
class BenchmarkMapsPlugin implements Plugin, CallbackListener, SidebarMenuEntryListener, ManialinkPageAnswerListener {
	const ID      = 1002;
	const VERSION = 1.0;
	const NAME    = 'Benchmark maps';
	const AUTHOR  = 'Benchmark maps';

	const MENU_ID       = 'BenchmarkMaps.Menu';
	const WIDGET_NAME   = 'BenchmarkMaps.List';
	const ACTION_SHOW   = 'BenchmarkMaps.Show';
	const ACTION_PAGING = 'BenchmarkMaps.Page.';
	const ACTION_ROW = 'BenchmarkMaps.Row.';
	const ACTION_FILTER = 'BenchmarkMaps.Filter.';
	const MAX_MAPS_PER_PAGE = 12;
	const SETTING_JOIN_MESSAGES = 'Send Benchmark Title Join Messages';
	const TABLE_PLAYER_STATS = 'benchmarkmaps_playerstats';
	const CACHE_FILTER_SERVER_ONLY = 'FilterServerOnly';
	const CACHE_FILTER_UNFINISHED = 'FilterUnfinished';
	const CACHE_FILTER_RANK = 'FilterRank';
	const CACHE_CURRENT_PAGE = 'CurrentPage';
	const PLAYER_STATS_CACHE_TTL = 3600;

	/** @var ManiaControl */
	private $maniaControl = null;
	private $playerStatsCache = array();
	private $pendingJoinMessages = array();
	private $pendingListRefresh = array();

	public static function prepare(ManiaControl $maniaControl) {
	}

	public static function getId() {
		return self::ID;
	}

	public static function getName() {
		return self::NAME;
	}

	public static function getVersion() {
		return self::VERSION;
	}

	public static function getAuthor() {
		return self::AUTHOR;
	}

	public static function getDescription() {
		return 'Shows Benchmark 2 campaign maps and whether each map is loaded on this server.';
	}

	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->maniaControl->getSettingManager()->initSetting(
			$this,
			self::SETTING_JOIN_MESSAGES,
			false,
			'Broadcast title, server role, and nation on player and spectator joins.'
		);
		$this->initPlayerStatsCacheTable();

		$manialinkManager = $this->maniaControl->getManialinkManager();
		$this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');
		$manialinkManager->registerManialinkPageAnswerListener(self::ACTION_SHOW, $this, 'handleShow');
		$manialinkManager->registerManialinkPageAnswerRegexListener('/^' . preg_quote(self::ACTION_PAGING, '/') . '(\\d+)$/', $this, 'handlePaging');
		$manialinkManager->registerManialinkPageAnswerRegexListener('/^' . preg_quote(self::ACTION_ROW, '/') . '(Queue|Unqueue|Switch|MX)\\.(\\d+)$/', $this, 'handleRowAction');
		$manialinkManager->registerManialinkPageAnswerRegexListener('/^' . preg_quote(self::ACTION_FILTER, '/') . '(All|Server|Unfinished|Rank\\.(All|Bronze|Silver|Gold|Platinum|Diamond|Master|Grandmaster|Challenger|Celestial))$/', $this, 'handleFilterAction');
		$manialinkManager->getSidebarMenuManager()->addMenuEntry(
			SidebarMenuManager::ORDER_PLAYER_MENU + 7,
			self::MENU_ID,
			$this,
			'displayMenuIcon'
		);

		return true;
	}

	public function unload() {
		if ($this->maniaControl) {
			$this->maniaControl->getManialinkManager()->hideManialink(self::MENU_ID);
		}
	}

	/**
	 * Render the player-actions sidebar icon.
	 *
	 * @param string|null $login
	 */
	public function displayMenuIcon($login = null) {
		$sidebar = $this->maniaControl->getManialinkManager()->getSidebarMenuManager();
		$position = $sidebar->getEntryPosition(self::MENU_ID);
		$itemSize = $this->maniaControl->getSettingManager()->getSettingValue($sidebar, SidebarMenuManager::SETTING_MENU_ITEMSIZE);
		$styleManager = $this->maniaControl->getManialinkManager()->getStyleManager();

		$maniaLink = new ManiaLink(self::MENU_ID);
		$frame = new Frame();
		$maniaLink->addChild($frame);
		$frame->setPosition($position->getX(), $position->getY());
		$frame->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE);

		$background = new \FML\Controls\Quad();
		$frame->addChild($background);
		$background->setSize($itemSize * 1.3, $itemSize * 1.2);
		$background->setStyles($styleManager->getDefaultQuadStyle(), $styleManager->getDefaultQuadSubstyle());

		$icon = new Label_Text();
		$frame->addChild($icon);
		$icon->setSize($itemSize, $itemSize);
		$icon->setPosition(0, 0, 0.2);
		$icon->setText('$sB');
		$icon->setTextSize(2);
		$icon->setTextColor('fd7');
		$icon->setAreaColor('00000000');
		$icon->setAreaFocusColor('00000000');
		$icon->setAlign('center', 'center');
		$icon->setAction(self::ACTION_SHOW);

		$this->maniaControl->getManialinkManager()->sendManialink($maniaLink, $login);
	}

	/**
	 * Open the Benchmark map list.
	 *
	 * @param array  $callback
	 * @param Player $player
	 */
	public function handleShow(array $callback, Player $player) {
		$this->displayBenchmarkList($player, 0);
	}

	/** @param Player $player */
	public function handlePlayerConnect(Player $player) {
		$this->displayMenuIcon($player->login);
		if (!$this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_JOIN_MESSAGES)) {
			return;
		}

		$login = strtolower($player->login);
		$cache = $this->getPlayerStatsCacheEntry($player);
		if ($cache && $this->isPlayerStatsCacheFresh($cache)) {
			$this->sendBenchmarkJoinMessage($player, $cache['stats']);
			return;
		}

		$this->pendingJoinMessages[$login] = $player;
		if (!$this->requestPlayerStats($player, false)) {
			unset($this->pendingJoinMessages[$login]);
			$stats = ($cache && !empty($cache['stats'])) ? $cache['stats'] : array('title' => 'Unknown');
			$this->sendBenchmarkJoinMessage($player, $stats);
		}
	}

	/**
	 * Handle a page selection.
	 *
	 * @param array  $callback
	 * @param Player $player
	 */
	public function handlePaging(array $callback, Player $player) {
		$actionId = $callback[1][2];
		$pageNumber = (int) substr($actionId, strlen(self::ACTION_PAGING));
		$this->displayBenchmarkList($player, max(0, $pageNumber - 1));
	}

	public function handleFilterAction(array $callback, Player $player) {
		$filter = substr($callback[1][2], strlen(self::ACTION_FILTER));
		if ($filter === 'Server') {
			$player->setCache($this, self::CACHE_FILTER_SERVER_ONLY, true);
		} elseif ($filter === 'Unfinished') {
			$player->setCache($this, self::CACHE_FILTER_UNFINISHED, true);
		} elseif ($filter === 'All') {
			$player->destroyCache($this, self::CACHE_FILTER_SERVER_ONLY);
			$player->destroyCache($this, self::CACHE_FILTER_UNFINISHED);
		} elseif (substr($filter, 0, 5) === 'Rank.') {
			$rank = substr($filter, 5);
			if ($rank === 'All') {
				$player->destroyCache($this, self::CACHE_FILTER_RANK);
			} else {
				$player->setCache($this, self::CACHE_FILTER_RANK, $rank);
			}
		}
		$this->displayBenchmarkList($player, 0);
	}

	/** Handle per-map queue, switch, and MX actions. */
	public function handleRowAction(array $callback, Player $player) {
		$parts = explode('.', $callback[1][2]);
		$action = isset($parts[2]) ? $parts[2] : '';
		$index = isset($parts[3]) ? (int) $parts[3] : -1;
		$catalog = $this->getMapCatalog();
		if (!isset($catalog[$index])) {
			return;
		}
		$mapData = $catalog[$index];
		$mapManager = $this->maniaControl->getMapManager();
		$map = $mapManager->getMapByUid($mapData['uid']);

		if ($action === 'Queue' && $map) {
			$mapManager->getMapQueue()->addMapToMapQueue($player->login, $map->uid);
			$this->displayBenchmarkList($player, (int) $player->getCache($this, self::CACHE_CURRENT_PAGE));
			return;
		}
		if ($action === 'Unqueue' && $map) {
			$mapManager->getMapQueue()->removeFromMapQueue($player, $map->uid);
			$this->displayBenchmarkList($player, (int) $player->getCache($this, self::CACHE_CURRENT_PAGE));
			return;
		}

		$canManageMaps = $this->maniaControl->getAuthenticationManager()->checkPermission($player, MapManager::SETTING_PERMISSION_ADD_MAP);
		if (!$canManageMaps) {
			$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
			return;
		}

		if ($action === 'Switch' && $map) {
			$mapManager->getMapQueue()->dontQueueNextMapChange();
			try {
				$this->maniaControl->getClient()->jumpToMapIdent($map->uid);
			} catch (NextMapException $e) {
				$this->maniaControl->getChat()->sendException($e, $player);
				return;
			} catch (NotInListException $e) {
				$this->maniaControl->getChat()->sendException($e, $player);
				return;
			} catch (ChangeInProgressException $e) {
				$this->maniaControl->getChat()->sendException($e, $player);
				return;
			}
			$this->maniaControl->getManialinkManager()->closeWidget($player);
			return;
		}

		if ($action === 'MX' && !$map) {
			if (isset($mapData['mxId'])) {
				$this->maniaControl->getMapManager()->addMapFromMx($mapData['mxId'], $player->login);
			}
		}
	}

	/** @param int $pageIndex */
	private function displayBenchmarkList(Player $player, $pageIndex) {
		$allMaps = $this->getMapCatalog();
		$mapManager = $this->maniaControl->getMapManager();
		$styleManager = $this->maniaControl->getManialinkManager()->getStyleManager();
		$width = $styleManager->getListWidgetsWidth();
		$height = $styleManager->getListWidgetsHeight();
		$serverOnly = $player->getCache($this, self::CACHE_FILTER_SERVER_ONLY) === true;
		$unfinishedOnly = $player->getCache($this, self::CACHE_FILTER_UNFINISHED) === true;
		$selectedRank = $player->getCache($this, self::CACHE_FILTER_RANK);
		$playerStats = $this->getPlayerStats($player);
		$catalog = array();
		foreach ($allMaps as $catalogIndex => $mapData) {
			if ($unfinishedOnly && (!$playerStats || isset($playerStats['mapRanks'][$mapData['uid']]))) {
				continue;
			}
			if ($serverOnly && !$mapManager->getMapByUid($mapData['uid'])) {
				continue;
			}
			if ($selectedRank && $mapData['tier'] !== $selectedRank) {
				continue;
			}
			$mapData['catalogIndex'] = $catalogIndex;
			$catalog[] = $mapData;
		}
		$filteredMapCount = count($catalog);
		$pageCount = max(1, (int) ceil($filteredMapCount / self::MAX_MAPS_PER_PAGE));
		$pageIndex = max(0, min((int) $pageIndex, $pageCount - 1));
		$player->setCache($this, self::CACHE_CURRENT_PAGE, (int) $pageIndex);
		$pageMaps = array_slice($catalog, $pageIndex * self::MAX_MAPS_PER_PAGE, self::MAX_MAPS_PER_PAGE);
		$maniaLink = new ManiaLink(ManialinkManager::MAIN_MLID);
		$script = $maniaLink->getScript();
		$paging = new Paging();
		$script->addFeature($paging);
		$paging->setCustomMaxPageNumber($pageCount);
		$paging->setChunkActionAppendsPageNumber(true);
		$paging->setChunkActions(self::ACTION_PAGING);
		$paging->setStartPageNumber($pageIndex + 1);

		$frame = $styleManager->getDefaultListFrame($script, $paging);
		$maniaLink->addChild($frame);

		$headerFrame = new Frame();
		$frame->addChild($headerFrame);
		$headerFrame->setY($height / 2 - 5);
		$descriptionLabel = $styleManager->getDefaultDescriptionLabel();
		$frame->addChild($descriptionLabel);
		$header = new LabelLine($headerFrame);
		$header->setTextSize(2.0);
		$header->addLabelEntryText('Benchmark 2', -$width / 2 + 5, 35);
		$header->render();
		$this->addPlayerStatsHeader($headerFrame, $player, $width);

		$filterFrame = new Frame();
		$frame->addChild($filterFrame);
		$filterFrame->setY($height / 2 - 11);

		$showLabel = new Label_Text();
		$filterFrame->addChild($showLabel);
		$showLabel->setPosition($width / 2 - 143, 0, 0.2);
		$showLabel->setSize(14, 4);
		$showLabel->setTextSize(1);
		$showLabel->setTextColor('aaa');
		$showLabel->setText('Show:');
		$showLabel->setAlign('center', 'center');

		$showAllButton = new Label_Button();
		$filterFrame->addChild($showAllButton);
		$showAllButton->setPosition($width / 2 - 127, 0, 0.2);
		$showAllButton->setSize(14, 4);
		$showAllButton->setTextSize(1);
		$showAllButton->setText('All');
		$showAllButton->setTextColor(($serverOnly || $unfinishedOnly) ? '888' : 'fd7');
		$showAllButton->setAction(self::ACTION_FILTER . 'All');
		$showAllButton->addTooltipLabelFeature($descriptionLabel, 'Show all maps');

		$serverOnlyButton = new Label_Button();
		$filterFrame->addChild($serverOnlyButton);
		$serverOnlyButton->setPosition($width / 2 - 106, 0, 0.2);
		$serverOnlyButton->setSize(28, 4);
		$serverOnlyButton->setTextSize(1);
		$serverOnlyButton->setText('On server');
		$serverOnlyButton->setTextColor($serverOnly ? 'fd7' : '888');
		$serverOnlyButton->setAction(self::ACTION_FILTER . 'Server');
		$serverOnlyButton->addTooltipLabelFeature($descriptionLabel, 'Show only maps on this server');

		$unfinishedButton = new Label_Button();
		$filterFrame->addChild($unfinishedButton);
		$unfinishedButton->setPosition($width / 2 - 76, 0, 0.2);
		$unfinishedButton->setSize(29, 4);
		$unfinishedButton->setTextSize(1);
		$unfinishedButton->setText('Unfinished');
		$unfinishedButton->setTextColor($unfinishedOnly ? 'fd7' : '888');
		$unfinishedButton->setAction(self::ACTION_FILTER . 'Unfinished');
		$unfinishedButton->addTooltipLabelFeature($descriptionLabel, 'Show maps you have not finished');

		$rankButton = new Label_Button();
		$filterFrame->addChild($rankButton);
		$rankButton->setPosition($width / 2 - 32, 0, 0.2);
		$rankButton->setSize(36, 4);
		$rankButton->setTextSize(1);
		$rankButton->setText('$fd7Rank: ' . ($selectedRank ? $selectedRank : 'All'));
		$rankButton->addTooltipLabelFeature($descriptionLabel, 'Filter maps by rank');

		$rankDropdown = new Frame();
		$rankDropdown->setPosition($width / 2 - 32, $height / 2 - 32, 10);
		$rankDropdown->setVisible(false);
		$rankDropdown->setSize(48, 38);
		$rankDropdownBackground = new Quad();
		$rankDropdown->addChild($rankDropdownBackground);
		$rankDropdownBackground->setSize(48, 38);
		$rankDropdownBackground->setStyles($styleManager->getDefaultMainWindowStyle(), $styleManager->getDefaultMainWindowSubStyle());
		$rankOptions = array('All', 'Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond', 'Master', 'Grandmaster', 'Challenger', 'Celestial');
		foreach ($rankOptions as $optionIndex => $rankOption) {
			$rankOptionButton = new Label_Button();
			$rankDropdown->addChild($rankOptionButton);
			$rankOptionButton->setPosition(0, 15 - $optionIndex * 3.3, 0.2);
			$rankOptionButton->setSize(46, 3.2);
			$rankOptionButton->setTextSize(1);
			$rankOptionButton->setText($rankOption === 'All' ? 'All ranks' : $rankOption);
			$rankOptionButton->setTextColor(($selectedRank ? $selectedRank === $rankOption : $rankOption === 'All') ? 'fd7' : 'fff');
			$rankOptionButton->setAction(self::ACTION_FILTER . 'Rank.' . $rankOption);
		}
		$rankButton->addToggleFeature($rankDropdown);

		$pageFrame = new Frame();
		$frame->addChild($pageFrame);
		$paging->addPageControl($pageFrame, $pageIndex + 1);

		$posY = $height / 2 - 17;
		$rowIndex = $pageIndex * self::MAX_MAPS_PER_PAGE;
		$rankColors = array(
			'Bronze' => 'a83',
			'Silver' => 'fff',
			'Gold' => 'fd7',
			'Platinum' => '9bd',
			'Diamond' => 'd3e',
			'Master' => '87b',
			'Grandmaster' => 'c66',
			'Challenger' => 'fff',
			'Celestial' => 'cef',
		);
		if (empty($pageMaps)) {
			$emptyLabel = new Label_Text();
			$frame->addChild($emptyLabel);
			$emptyLabel->setY(0);
			$emptyLabel->setSize($width - 20, 5);
			$emptyLabel->setAlign('center', 'center');
			$emptyLabel->setTextColor('aaa');
			$emptyLabel->setText($unfinishedOnly && !$playerStats ? 'Loading player map data...' : 'No maps match these filters.');
		}
		$lastRank = null;
		foreach ($pageMaps as $mapData) {
			$catalogIndex = $mapData['catalogIndex'];
			$rowFrame = new Frame();
			$pageFrame->addChild($rowFrame);
			$rowFrame->setY($posY);

			if ($rowIndex % 2 === 0) {
				$background = new Quad_BgsPlayerCard();
				$rowFrame->addChild($background);
				$background->setSize($width, 4);
				$background->setSubStyle(Quad_BgsPlayerCard::SUBSTYLE_BgPlayerCardBig);
				$background->setZ(-0.1);
			}

			$serverMap = $mapManager->getMapByUid($mapData['uid']);
			$isOnServer = (bool) $serverMap;
			$row = new LabelLine($rowFrame);
			$row->setTextSize(1.35);
			$rankText = '';
			if ($mapData['tier'] !== $lastRank) {
				$rankText = '$' . $rankColors[$mapData['tier']] . $mapData['tier'];
			}
			$lastRank = $mapData['tier'];
			$row->addLabelEntryText($rankText, -$width / 2 + 5, 18);
			$mapRank = $playerStats && isset($playerStats['mapRanks'][$mapData['uid']]) ? $playerStats['mapRanks'][$mapData['uid']] : ($playerStats ? '$i$999Unfinished' : '-');
			$row->addLabelEntryText($mapRank, -$width / 2 + 25, 21);
			$row->addLabelEntryText(Formatter::stripDirtyCodes($serverMap ? $serverMap->name : $mapData['name']), -$width / 2 + 47, 57);
			if (!$isOnServer) {
				$row->addLabelEntryText('$CAANot on server', $width / 2 - 31, 21);
			}
			$row->render();

			$queue = $mapManager->getMapQueue();
			$queuedRanking = $queue->getQueuedMapsRanking();
			if ($isOnServer && !isset($queuedRanking[$mapData['uid']])) {
				$queueButton = $this->addRowButton($rowFrame, '+', '09f', $width / 2 - 12, self::ACTION_ROW . 'Queue.' . $catalogIndex);
				$queueButton->addTooltipLabelFeature($descriptionLabel, 'Add ' . $mapData['name'] . ' to the Map Queue');
			} elseif (isset($queuedRanking[$mapData['uid']])) {
				$queuer = $queue->getQueuer($mapData['uid']);
				$queueLabel = new Label_Button();
				$rowFrame->addChild($queueLabel);
				$queueLabel->setPosition($width / 2 - 12, 0, 0.2);
				$queueLabel->setSize(4, 3);
				$queueLabel->setTextSize(1);
				$queueLabel->setText((string) $queuedRanking[$mapData['uid']]);
				$queueLabel->setTextColor('09f');
				if ($queuer && $queuer->login === $player->login) {
					$queueLabel->setAction(self::ACTION_ROW . 'Unqueue.' . $catalogIndex);
					$description = 'Remove ' . $mapData['name'] . ' from the Map Queue';
				} else {
					$description = $mapData['name'] . ' is on Map Queue position ' . $queuedRanking[$mapData['uid']];
				}
				$queueLabel->addTooltipLabelFeature($descriptionLabel, $description);
			}
			$canManageMaps = $this->maniaControl->getAuthenticationManager()->checkPermission($player, MapManager::SETTING_PERMISSION_ADD_MAP);
			if ($isOnServer && $canManageMaps) {
				$switchButton = $this->addRowButton($rowFrame, '»', '0f0', $width / 2 - 7);
				$confirmFrame = $this->buildSwitchConfirmFrame($maniaLink, $posY, $catalogIndex, $width);
				$switchButton->addToggleFeature($confirmFrame);
				$switchButton->addTooltipLabelFeature($descriptionLabel, 'Jump to ' . $mapData['name']);
			} elseif (!$isOnServer && $canManageMaps) {
				$mxIcon = new Quad();
				$rowFrame->addChild($mxIcon);
				$mxIcon->setSize(3, 3);
				$mxIcon->setPosition($width / 2 - 7, 0, 0.2);
				$mxIcon->setImageUrl($this->maniaControl->getManialinkManager()->getIconManager()->getIcon(IconManager::MX_ICON));
				$mxIcon->setImageFocusUrl($this->maniaControl->getManialinkManager()->getIconManager()->getIcon(IconManager::MX_ICON_MOVER));
				$mxIcon->setAction(self::ACTION_ROW . 'MX.' . $catalogIndex);
				$mxIcon->addTooltipLabelFeature($descriptionLabel, 'Add ' . $mapData['name'] . ' from ManiaExchange to the server');
			}

			$posY -= 4.2;
			$rowIndex++;
		}
		$frame->addChild($rankDropdown);

		$profilePageUrl = 'https://obstacle.titlepack.io/event/benchmark/2?player=' . rawurlencode($player->login);
		$profileButton = new Frame();
		$frame->addChild($profileButton);
		$profileButton->setPosition(-$width / 2 + 5 + 48 / 2, -$height / 2 + 9);

		$profileButtonBackground = new Quad_BgsPlayerCard();
		$profileButton->addChild($profileButtonBackground);
		$profileButtonBackground->setSize(48, 4);
		$profileButtonBackground->setSubStyle(Quad_BgsPlayerCard::SUBSTYLE_BgPlayerCardBig);
		$profileButtonBackground->setZ(-1);
		$profileButtonBackground->setUrl($profilePageUrl);

		$profileButtonLabel = new Label_Button();
		$profileButton->addChild($profileButtonLabel);
		$profileButtonLabel->setText('Your benchmark page');
		$profileButtonLabel->setTextSize(1);
		$profileButtonLabel->setUrl($profilePageUrl);

		$this->maniaControl->getManialinkManager()->displayWidget($maniaLink, $player, self::WIDGET_NAME);
		$this->requestPlayerStats($player);
	}

	/** @param int|float $width */
	private function addPlayerStatsHeader(Frame $frame, Player $player, $width) {
		$stats = $this->getPlayerStats($player);
		$playerName = $stats && !empty($stats['playerName']) ? $stats['playerName'] : $player->nickname;
		$rank = !$stats ? '-' : ($stats['rank'] !== '' ? $stats['title'] . ' | #' . $stats['rank'] : 'Unranked');
		$values = array(
			array('Player', $playerName, 19),
			array('Title | Rank', $rank, 27),
			array('Average record', $stats ? $stats['average'] : '-', 14),
			array('Maps finished', $stats ? $stats['finished'] : '-', 18),
			array('Worst record', $stats ? $stats['worst'] : '-', 12),
		);
		$x = -$width / 2 + 42;
		foreach ($values as $column) {
			list($heading, $value, $columnWidth) = $column;
			$headingLabel = new Label_Text();
			$frame->addChild($headingLabel);
			$headingLabel->setPosition($x + $columnWidth / 2, 1.2, 0.2);
			$headingLabel->setSize($columnWidth, 2);
			$headingLabel->setTextSize(0.85);
			$headingLabel->setTextColor('aaa');
			$headingLabel->setText($heading);
			$headingLabel->setAlign('center', 'center');

			$valueLabel = new Label_Text();
			$frame->addChild($valueLabel);
			$valueLabel->setPosition($x + $columnWidth / 2, -1.1, 0.2);
			$valueLabel->setSize($columnWidth, 2.3);
			$valueLabel->setTextSize(1.15);
			$valueLabel->setTextColor('fff');
			$valueLabel->setText((string) $value);
			$valueLabel->setAlign('center', 'center');
			$x += $columnWidth;
		}
	}

	private function initPlayerStatsCacheTable() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_PLAYER_STATS . "` (
				`login` varchar(100) NOT NULL,
				`stats` mediumtext NOT NULL,
				`fetched_at` int(10) unsigned NOT NULL,
				PRIMARY KEY (`login`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
		if (!$mysqli->query($query)) {
			trigger_error('Benchmark Maps player cache table could not be initialized: ' . $mysqli->error, E_USER_WARNING);
		}
	}

	private function getPlayerStatsCacheEntry(Player $player) {
		$login = strtolower($player->login);
		if (isset($this->playerStatsCache[$login])) {
			return $this->playerStatsCache[$login];
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$statement = $mysqli->prepare('SELECT `stats`, `fetched_at` FROM `' . self::TABLE_PLAYER_STATS . '` WHERE `login` = ?');
		if (!$statement) {
			return null;
		}
		$statement->bind_param('s', $login);
		$statement->execute();
		$statement->bind_result($statsJson, $fetchedAt);
		if ($statement->fetch()) {
			$stats = json_decode($statsJson, true);
			$statement->close();
			if (is_array($stats)) {
				$this->playerStatsCache[$login] = array(
					'stats' => $stats,
					'fetchedAt' => (int) $fetchedAt,
					'pending' => false,
					'lastAttemptAt' => 0,
				);
				return $this->playerStatsCache[$login];
			}
		} else {
			$statement->close();
		}
		return null;
	}

	private function getPlayerStats(Player $player) {
		$cache = $this->getPlayerStatsCacheEntry($player);
		return ($cache && isset($cache['stats']) && is_array($cache['stats'])) ? $cache['stats'] : null;
	}

	private function isPlayerStatsCacheFresh(array $cache) {
		return isset($cache['fetchedAt']) && time() - $cache['fetchedAt'] < self::PLAYER_STATS_CACHE_TTL;
	}

	/**
	 * @param string $login
	 * @param int    $fetchedAt
	 */
	private function storePlayerStatsCache($login, array $stats, $fetchedAt) {
		$statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
		if ($statsJson === false) {
			return;
		}
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$statement = $mysqli->prepare('INSERT INTO `' . self::TABLE_PLAYER_STATS . '` (`login`, `stats`, `fetched_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `stats` = VALUES(`stats`), `fetched_at` = VALUES(`fetched_at`)');
		if (!$statement) {
			trigger_error('Benchmark Maps player cache could not be saved: ' . $mysqli->error, E_USER_WARNING);
			return;
		}
		$statement->bind_param('ssi', $login, $statsJson, $fetchedAt);
		$statement->execute();
		$statement->close();
	}

	/**
	 * @param bool $refreshList
	 * @return bool True when request is active; false when skipped.
	 */
	private function requestPlayerStats(Player $player, $refreshList = true) {
		$login = strtolower($player->login);
		$cache = $this->getPlayerStatsCacheEntry($player);
		if ($cache && !empty($cache['pending'])) {
			if ($refreshList) {
				$this->pendingListRefresh[$login] = true;
			}
			return true;
		}
		if ($cache && $this->isPlayerStatsCacheFresh($cache)) {
			return false;
		}
		if ($cache && !empty($cache['lastAttemptAt']) && time() - $cache['lastAttemptAt'] < 60) {
			return false;
		}

		$now = time();
		$this->playerStatsCache[$login] = array(
			'stats' => $cache && isset($cache['stats']) ? $cache['stats'] : null,
			'fetchedAt' => $cache && isset($cache['fetchedAt']) ? $cache['fetchedAt'] : 0,
			'pending' => true,
			'lastAttemptAt' => $now,
		);
		$this->pendingListRefresh[$login] = $refreshList;
		$url = 'https://obstacle.titlepack.io/event/benchmark/2?player=' . rawurlencode($player->login);
		$this->maniaControl->getFileReader()->loadFile($url, function ($content, $error) use ($login, $player) {
			$stats = (!$error && $content) ? $this->parsePlayerStatsPage($content, $player->nickname) : null;
			$previousCache = isset($this->playerStatsCache[$login]) ? $this->playerStatsCache[$login] : array();
			$previousStats = isset($previousCache['stats']) ? $previousCache['stats'] : null;
			$previousFetchedAt = isset($previousCache['fetchedAt']) ? $previousCache['fetchedAt'] : 0;
			$fetchedAt = $stats ? time() : $previousFetchedAt;
			$this->playerStatsCache[$login] = array(
				'stats' => $stats ? $stats : $previousStats,
				'fetchedAt' => $fetchedAt,
				'pending' => false,
				'lastAttemptAt' => time(),
			);
			if ($stats) {
				$this->storePlayerStatsCache($login, $stats, $fetchedAt);
			}

			if (isset($this->pendingJoinMessages[$login])) {
				$joiningPlayer = $this->pendingJoinMessages[$login];
				unset($this->pendingJoinMessages[$login]);
				if ($this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_JOIN_MESSAGES)
				    && $this->maniaControl->getPlayerManager()->getPlayer($joiningPlayer->login, true)) {
					$joinStats = $stats ? $stats : ($previousStats ? $previousStats : array('title' => 'Unknown'));
					$this->sendBenchmarkJoinMessage($joiningPlayer, $joinStats);
				}
			}

			$refreshList = !empty($this->pendingListRefresh[$login]);
			unset($this->pendingListRefresh[$login]);
			if ($stats && $refreshList) {
				$connectedPlayer = $this->maniaControl->getPlayerManager()->getPlayer($player->login, true);
				if ($connectedPlayer) {
					$this->displayBenchmarkList($connectedPlayer, (int) $connectedPlayer->getCache($this, self::CACHE_CURRENT_PAGE));
				}
			}
		});
		return true;
	}

	private function sendBenchmarkJoinMessage(Player $player, array $stats) {
		$color = '$0f0';
		$playerManager = $this->maniaControl->getPlayerManager();
		if ($this->maniaControl->getSettingManager()->getSettingValue($playerManager, PlayerManager::SETTING_JOIN_LEAVE_COLORING)) {
			$color = $this->maniaControl->getColorManager()->getColorByPlayer($player);
		}
		$title = isset($stats['title']) && $stats['title'] ? $stats['title'] : 'Unknown';
		$role = $player->getAuthLevelName();
		$joinedText = $player->isSpectator ? ' joined as Spectator!' : ' joined!';
		$messageFormat = $color . $role . ' %s Rank: %s Nation: %s' . $joinedText;
		$message = $this->maniaControl->getChat()->formatMessage($messageFormat, $player, $title, $player->getCountry());
		$this->maniaControl->getChat()->sendChat($message);
	}

	/**
	 * @param string $html
	 * @param string $fallbackName
	 */
	private function parsePlayerStatsPage($html, $fallbackName) {
		if (!class_exists('DOMDocument')) {
			return null;
		}
		$previousState = libxml_use_internal_errors(true);
		$document = new \DOMDocument();
		$loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
		libxml_clear_errors();
		libxml_use_internal_errors($previousState);
		if (!$loaded) {
			return null;
		}
		$xpath = new \DOMXPath($document);
		$dialog = $xpath->query('//dialog')->item(0);
		if (!$dialog) {
			if (stripos($html, 'Obstacle Leaderboards - Benchmark 2') !== false) {
				return array(
					'playerName' => $fallbackName,
					'title' => 'Unranked',
					'rank' => '',
					'average' => '-',
					'finished' => '0/54',
					'worst' => '-',
					'mapRanks' => array(),
				);
			}
			return null;
		}
		$bodies = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " Tbody ")]', $dialog);
		$body = $bodies->item(0);
		if (!$body) {
			return null;
		}
		$rows = array();
		foreach ($body->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'div') {
				$rows[] = $child;
			}
		}
		if (count($rows) < 2) {
			return null;
		}
		$cells = array();
		foreach ($rows[1]->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'div') {
				$cells[] = $child;
			}
		}
		if (count($cells) < 4) {
			return null;
		}
		$finished = trim($cells[2]->textContent);
		if (preg_match('/^(\d+)\s*(\d+)$/', $finished, $matches)) {
			$finished = $matches[1] . '/' . $matches[2];
		}
		if (strpos($finished, '/') === false) {
			return null;
		}
		$tierProgress = $this->getCompletedTierData($body);
		$completedTierCounts = $tierProgress['counts'];
		$tierTotals = array();
		foreach ($this->getMapCatalog() as $mapData) {
			$tierTotals[$mapData['tier']] = isset($tierTotals[$mapData['tier']]) ? $tierTotals[$mapData['tier']] + 1 : 1;
		}
		$rankTitle = 'Unranked';
		foreach (array('Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond', 'Master', 'Grandmaster', 'Challenger', 'Celestial') as $tier) {
			if (empty($tierTotals[$tier]) || !isset($completedTierCounts[$tier]) || $completedTierCounts[$tier] < $tierTotals[$tier]) {
				break;
			}
			$rankTitle = $tier;
		}
		$playerName = $fallbackName;
		/** @var \DOMElement|null $nameLink */
		$nameLink = $xpath->query('.//h1//a', $dialog)->item(0);
		if ($nameLink && $nameLink->getAttribute('title')) {
			$playerName = $nameLink->getAttribute('title');
		}
		return array(
			'playerName' => $playerName,
			'title' => $rankTitle,
			'rank' => trim($cells[0]->textContent),
			'average' => trim($cells[1]->textContent),
			'finished' => $finished,
			'worst' => trim($cells[3]->textContent),
			'mapRanks' => $tierProgress['mapRanks'],
		);
	}

	private function getCompletedTierData(\DOMElement $body) {
		$children = array();
		foreach ($body->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'div') {
				$children[] = $child;
			}
		}
		$finishedMapsIndex = null;
		foreach ($children as $index => $child) {
			if (trim($child->textContent) === 'Finished maps') {
				$finishedMapsIndex = $index;
				break;
			}
		}
		if ($finishedMapsIndex === null) {
			return array('counts' => array(), 'mapRanks' => array());
		}
		$counts = array();
		$mapRanks = array();
		for ($index = $finishedMapsIndex + 1; $index < count($children); $index++) {
			$sectionChildren = array();
			foreach ($children[$index]->childNodes as $child) {
				if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'div') {
					$sectionChildren[] = $child;
				}
			}
			if (count($sectionChildren) < 2) {
				continue;
			}
			$headingCells = array();
			foreach ($sectionChildren[0]->childNodes as $child) {
				if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'div') {
					$headingCells[] = $child;
				}
			}
			if (!$headingCells) {
				continue;
			}
			$tier = trim($headingCells[0]->textContent);
			if (!in_array($tier, array('Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond', 'Master', 'Grandmaster', 'Challenger', 'Celestial'), true)) {
				break;
			}
			/** @var \DOMElement $sectionContent */
			$sectionContent = $sectionChildren[1];
			$rows = $sectionContent->getElementsByTagName('div');
			$counts[$tier] = 0;
			foreach ($rows as $row) {
				/** @var \DOMElement $row */
				if (!$row->hasAttribute('tabindex') || $row->getAttribute('tabindex') !== '0') {
					continue;
				}
				$counts[$tier]++;
				$cells = array();
				foreach ($row->childNodes as $cell) {
					if ($cell->nodeType === XML_ELEMENT_NODE && strtolower($cell->nodeName) === 'div') {
						$cells[] = $cell;
					}
				}
				if (count($cells) < 2) {
					continue;
				}
				/** @var \DOMElement $mapCell */
				$mapCell = $cells[1];
				$links = $mapCell->getElementsByTagName('a');
				if (!$links->length) {
					continue;
				}
				/** @var \DOMElement $mapLink */
				$mapLink = $links->item(0);
				$path = parse_url($mapLink->getAttribute('href'), PHP_URL_PATH);
				$mapUid = rawurldecode(basename($path));
				$mapRanks[$mapUid] = trim($cells[0]->textContent);
			}
		}
		return array('counts' => $counts, 'mapRanks' => $mapRanks);
	}

	/**
	 * @param string     $text
	 * @param string     $color
	 * @param int|float  $x
	 * @param string|null $action
	 */
	private function addRowButton(Frame $frame, $text, $color, $x, $action = null) {
		$button = new Label_Button();
		$frame->addChild($button);
		$button->setPosition($x, 0, 0.2);
		$button->setSize(4, 3);
		$button->setTextSize(2);
		$button->setText($text);
		$button->setTextColor($color);
		if ($action) {
			$button->setAction($action);
		}
		return $button;
	}

	/**
	 * @param int|float $posY
	 * @param int       $rowIndex
	 * @param int|float $width
	 */
	private function buildSwitchConfirmFrame(ManiaLink $maniaLink, $posY, $rowIndex, $width) {
		$styleManager = $this->maniaControl->getManialinkManager()->getStyleManager();
		$confirmFrame = new Frame();
		$maniaLink->addChild($confirmFrame);
		$confirmFrame->setPosition($width / 2 + 6, $posY);
		$confirmFrame->setVisible(false);
		$confirmFrame->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE);

		$background = new Quad();
		$confirmFrame->addChild($background);
		$background->setStyles($styleManager->getDefaultMainWindowStyle(), $styleManager->getDefaultMainWindowSubStyle());
		$background->setSize(12, 4);
		$background->setZ(-0.5);

		$card = new Quad_BgsPlayerCard();
		$confirmFrame->addChild($card);
		$card->setSubStyle(Quad_BgsPlayerCard::SUBSTYLE_BgCardSystem);
		$card->setSize(11, 3.5);
		$card->setZ(-0.3);

		$label = new Label_Button();
		$confirmFrame->addChild($label);
		$label->setTextSize(1);
		$label->setText('Sure?');
		$label->setScale(0.90);
		$label->setX(-1.3);

		$confirm = new Label_Button();
		$confirmFrame->addChild($confirm);
		$confirm->setPosition(3.2, 0.4, 0.2);
		$confirm->setSize(3, 3);
		$confirm->setTextSize(2);
		$confirm->setText('»');
		$confirm->setTextColor('0f0');
		$card->setAction(self::ACTION_ROW . 'Switch.' . $rowIndex);
		return $confirmFrame;
	}

	/**
	 * Hardcoded Benchmark 2 map list. `uid` values are Obstacle map game IDs.
	 *
	 * @return array
	 */
	private function getMapCatalog() {
		$catalog = array(
			array('tier' => 'Bronze', 'name' => 'Apprendre le Rocket Wall Jump 2.0', 'uid' => 'OV3Jj9DyWfEXF3b8eE8IUi1H0_0_benchmark', 'mxId' => 43623),
			array('tier' => 'Bronze', 'name' => 'Step by Step (by Aurel et Swytsh)', 'uid' => '5yF8Cze4zJF4QasxsbzppEgW8L1_benchmark', 'mxId' => 43572),
			array('tier' => 'Bronze', 'name' => 'AVEN tutorial', 'uid' => 'oRiNhlUaqI4cdIekBXZtHZFNZZf_benchmark', 'mxId' => 43631),
			array('tier' => 'Bronze', 'name' => 'Are you skilled? RENEWAL', 'uid' => 'ak_I4mg0AGeR4dQ5JLI_pli_zC1_benchmark', 'mxId' => 43609),
			array('tier' => 'Bronze', 'name' => 'Oxy ~ Apprendre le Air control 2.0', 'uid' => 'vBWGGaTxrTo2Hrj0EZ9IidxDFLb_benchmark', 'mxId' => 43577),
			array('tier' => 'Bronze', 'name' => 'Awayness', 'uid' => 'toIu64S2pWdT6VioAPloXwAgLEi_benchmark', 'mxId' => 43606),
			array('tier' => 'Silver', 'name' => 'ҒlıcκMasτeг seгıes #1 - Flick Initiate', 'uid' => 'FZl0xyN41ttgmJrf61XKDFwCIpd_benchmark', 'mxId' => 43628),
			array('tier' => 'Silver', 'name' => 'A New Challenge', 'uid' => 'BkoB85WN0PDQ7O9r20NMrRL3A6f_benchmark', 'mxId' => 43612),
			array('tier' => 'Silver', 'name' => 'Fox Tutorial! V2', 'uid' => 'A8EPXDpshYfqUh3xW7gM_JxjgSg_benchmark', 'mxId' => 43597),
			array('tier' => 'Silver', 'name' => 'Out For Revenge !', 'uid' => 'diVZEB5eGowZvmK1LgiM27VTL54_benchmark', 'mxId' => 43578),
			array('tier' => 'Silver', 'name' => 'Prove yourself (by Aurel & L3k0rSS)', 'uid' => 'D2sMRq545lxBPYtJIjbFzxgjHS3_benchmark', 'mxId' => 43630),
			array('tier' => 'Silver', 'name' => 'faith kz tutorial lvl 1', 'uid' => 'kXHQ22wm00yBIckLT0NihmC8OB9_benchmark', 'mxId' => 43598),
			array('tier' => 'Gold', 'name' => 'Once Upon A Time (Inter-Hard)', 'uid' => 'Y7W8owGSb9Qj1w0fY6f_q8vmbKb_benchmark', 'mxId' => 43579),
			array('tier' => 'Gold', 'name' => 'Training style〜 From inter to hard', 'uid' => '66rEYKLZ2Og7rWC9IOOdLk8LKyb_benchmark', 'mxId' => 43568),
			array('tier' => 'Gold', 'name' => 'ҒlıcκMasτeг seгıes #1,5 - Enthusiast', 'uid' => '2Dbt3c0NsC_eUmQmEc_1n3lpfih_benchmark', 'mxId' => 43590),
			array('tier' => 'Gold', 'name' => 'TRAIN - KanaWJ (lvl1) N°1', 'uid' => 'x_0rohnl9NZ4UzhjrYJ0m1tGHNl_benchmark', 'mxId' => 43569),
			array('tier' => 'Gold', 'name' => 'Faith Improvisus', 'uid' => 'j81BhKTVB5FnkvEihcZ9FaGo6Z2_benchmark', 'mxId' => 43621),
			array('tier' => 'Gold', 'name' => 'No Rox Training (lvl 1)', 'uid' => 'SgLOMWNgxSwtlox9lPYyzCQjTr_benchmark', 'mxId' => 43581),
			array('tier' => 'Platinum', 'name' => 'ҒlıcκMasτeг seгıes #2 - Flick Apprentice', 'uid' => 'J17aq60rmfUBQiSzkKiQAQSzyS4_benchmark', 'mxId' => 43624),
			array('tier' => 'Platinum', 'name' => 'Lesson of Zooming', 'uid' => 'iojY5_jiskdsRqW0g5adgNhD4i0_benchmark', 'mxId' => 43591),
			array('tier' => 'Platinum', 'name' => 'BatFlix (lvl 1,5)', 'uid' => '0cn7Shl_s9dyOLFBdlS4k0rgJfd_benchmark', 'mxId' => 43618),
			array('tier' => 'Platinum', 'name' => 'Aerial Recklessness Hard', 'uid' => 'cvvlEaXsSnnmFBOk7dPNwNizf3e_benchmark', 'mxId' => 43620),
			array('tier' => 'Platinum', 'name' => 'BLOUKHIPPE', 'uid' => 'ceNhENIxYXEGnJCnoGzXCbxLOJm_benchmark', 'mxId' => 43603),
			array('tier' => 'Platinum', 'name' => 'Australis', 'uid' => 'v_bWZ2TqJtUgIZgRuD7PpI_ql7h_benchmark', 'mxId' => 43608),
			array('tier' => 'Diamond', 'name' => 'TRAIN - HippeMove (lvl1) N°1', 'uid' => 'yKUzyJRnqqt6SbykrWfvDzmtePh_benchmark', 'mxId' => 43570),
			array('tier' => 'Diamond', 'name' => '฿unnimprovisus', 'uid' => 'a10vM3RvKvsSKouW8vitdKoUjWh_benchmark', 'mxId' => 43567),
			array('tier' => 'Diamond', 'name' => 'Neon ☆ Star', 'uid' => 'ExF_FL4GddMmmp4U2JVOGVq77bb_benchmark', 'mxId' => 43582),
			array('tier' => 'Diamond', 'name' => 'ױImmunityוי', 'uid' => '0VNtkbYbjlUTDR2x5uP2_L6HwBh_benchmark', 'mxId' => 43595),
			array('tier' => 'Diamond', 'name' => '○Solexium○', 'uid' => '79dDAyxs4OWv9j34jFvF0vKPGa_benchmark', 'mxId' => 43574),
			array('tier' => 'Diamond', 'name' => 'Beautiful Mistake', 'uid' => '6drs2qo4UyiOCUSmYSI6r76EhA8_benchmark', 'mxId' => 43604),
			array('tier' => 'Master', 'name' => 'Deaf', 'uid' => 'tfCRch0b7oPGIsjg2IrwjKn1_Zi_benchmark', 'mxId' => 43601),
			array('tier' => 'Master', 'name' => 'intensive boot camp', 'uid' => 'BJqxdSPcVE7xLWpaMBpHsXc44Lh_benchmark', 'mxId' => 43594),
			array('tier' => 'Master', 'name' => 'Marabout', 'uid' => 'To8pnvm5y1nYxOjHTclHEVe8ml8_benchmark', 'mxId' => 43584),
			array('tier' => 'Master', 'name' => 'faith improviplus', 'uid' => 'tl2zP4OMMeMAhYYEf9_oYhnKK1b_benchmark', 'mxId' => 43600),
			array('tier' => 'Master', 'name' => 'WaterLands', 'uid' => 'sK2ZRYJna8F873kLG8g3_0ZR4Km_benchmark', 'mxId' => 43566),
			array('tier' => 'Master', 'name' => 'Sweatyness', 'uid' => 'Pn4LO9YeQlGwU409uOfN3X0clfh_benchmark', 'mxId' => 43571),
			array('tier' => 'Grandmaster', 'name' => 'ҒlıcκMasτeг seгıes #3 - Jøurneyman', 'uid' => 'iWa4rDZUqP6RG1maeuJx4_6D8Vi_benchmark', 'mxId' => 43588),
			array('tier' => 'Grandmaster', 'name' => 'LTFRMFC', 'uid' => 'Lb_TvFROKkpXvv8xig2O_FA2yD3_benchmark', 'mxId' => 43586),
			array('tier' => 'Grandmaster', 'name' => 'ױRavioli Powered Gameplayוי', 'uid' => 'Pis7xMCAV1943rysUG0_WryxLx9_benchmark', 'mxId' => 43575),
			array('tier' => 'Grandmaster', 'name' => 'Necessary Lies', 'uid' => 'q7SnC7eJeubTHtifMRXfLCp3CN1_benchmark', 'mxId' => 43583),
			array('tier' => 'Grandmaster', 'name' => '[Fixed] Obstacle Hєατy Bєατy', 'uid' => '5L3CeBg1iksjfSv87yhdK__orng', 'mxId' => 44348),
			array('tier' => 'Grandmaster', 'name' => 'intensive contactless camp', 'uid' => 'k8pnz4nrME6CuBRWvk2THdlSMrl_benchmark', 'mxId' => 43593),
			array('tier' => 'Challenger', 'name' => 'Chronopolis', 'uid' => 'DTxnOybbNrFE1yRs9Q02fgdv9ki_benchmark', 'mxId' => 44826),
			array('tier' => 'Challenger', 'name' => 'Australis Survival', 'uid' => 'q0BkPC5HL7E6EjpmuZmLY8ZcbMk_benchmark', 'mxId' => 44831),
			array('tier' => 'Challenger', 'name' => 'Magnum Opus', 'uid' => 'u6s_8d_Vufz39O6cz4Fk7m0omS8_benchmark', 'mxId' => 44828),
			array('tier' => 'Challenger', 'name' => 'ױKneed For Speedוי', 'uid' => 'A3UBjfc_2KoH1IOYlZYa0RK9hB5_benchmark', 'mxId' => 44830),
			array('tier' => 'Challenger', 'name' => 'Sunrise Angel', 'uid' => 'oSvRLnz0WuXU7InfzRQeuSOM1I7_benchmark', 'mxId' => 44829),
			array('tier' => 'Challenger', 'name' => 'Notions', 'uid' => 'p7891tSwUNPdJTUOZJIAPKOwXo8_benchmark', 'mxId' => 44827),
			array('tier' => 'Celestial', 'name' => 'Mystical Mists', 'uid' => 'CN4p2yVEoTpThuomUv8mXTHA_E5_benchmark', 'mxId' => 44836),
			array('tier' => 'Celestial', 'name' => '«An Extreme Night»', 'uid' => 'PPY4hsBdfKwvudd5hIzorXVcr1d_benchmark', 'mxId' => 44832),
			array('tier' => 'Celestial', 'name' => 'Space Survival', 'uid' => 'KdfiToAnkJunJquMCTP4eJ5OD7_benchmark', 'mxId' => 44835),
			array('tier' => 'Celestial', 'name' => 'Twisted Fantasy', 'uid' => '7rcybRLuBVGllLc8PGJsXJquhB5_benchmark', 'mxId' => 44834),
			array('tier' => 'Celestial', 'name' => 'ҒlıcκMasτeг seгıes #3 - Jøurneyman (Pixou\'s style)', 'uid' => 'mubdPvy9QLifdj6W4BU9odMa4na_benchmark', 'mxId' => 44833),
			array('tier' => 'Celestial', 'name' => 'faith supremacy', 'uid' => 'sjMtphzeC3s9x9yPHlusduR9CQ0_benchmark', 'mxId' => 44837),
		);
		return $catalog;
	}
}
