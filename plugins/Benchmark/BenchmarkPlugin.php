<?php

namespace BenchmarkMaps;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Files\AsyncHttpRequest;
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
class BenchmarkPlugin implements Plugin, CallbackListener, CommandListener, SidebarMenuEntryListener, ManialinkPageAnswerListener {
	const ID      = 1002;
	const VERSION = 1.0;
	const NAME    = 'Benchmark';
	const AUTHOR  = 'Benchmark';

	const MENU_ID       = 'BenchmarkMaps.Menu';
	const WIDGET_NAME   = 'BenchmarkMaps.List';
	const ACTION_SHOW   = 'BenchmarkMaps.Show';
	const ACTION_SHOW_LEADERBOARD = 'BenchmarkMaps.ShowLeaderboard';
	const ACTION_PAGING = 'BenchmarkMaps.Page.';
	const ACTION_LEADERBOARD_PAGING = 'BenchmarkMaps.LeaderboardPage.';
	const ACTION_ROW = 'BenchmarkMaps.Row.';
	const ACTION_FILTER = 'BenchmarkMaps.Filter.';
	const MAX_MAPS_PER_PAGE = 12;
	const SETTING_JOIN_MESSAGES = 'Send Benchmark join & leave messages';
	const TABLE_PLAYER_STATS = 'benchmarkmaps_playerstats';
	const CACHE_FILTER_SERVER_ONLY = 'FilterServerOnly';
	const CACHE_FILTER_UNFINISHED = 'FilterUnfinished';
	const CACHE_FILTER_RANK = 'FilterRank';
	const CACHE_CURRENT_PAGE = 'CurrentPage';
	const CACHE_CURRENT_VIEW = 'CurrentView';
	const CACHE_LEADERBOARD_PAGE = 'LeaderboardPage';
	const PLAYER_STATS_CACHE_TTL = 3600;
	const LEADERBOARD_CACHE_TTL = 21600;
	const LEADERBOARD_TITLE_BATCH_SIZE = 12;

	/** @var ManiaControl */
	private $maniaControl = null;
	private $playerStatsCache = array();
	private $playerStatsRequestIds = array();
	/** @var array<int, array<string, mixed>>|null */
	private $leaderboardCache = null;
	private $leaderboardFetchedAt = 0;
	private $leaderboardCacheLoaded = false;
	private $leaderboardRequestPending = false;
	private $leaderboardLastAttemptAt = 0;
	private $leaderboardRequestId = 0;
	private $pendingLeaderboardViewers = array();
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
		return 'Shows Benchmark 2 maps, player progress, and the campaign leaderboard.';
	}

	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->maniaControl->getSettingManager()->initSetting(
			$this,
			self::SETTING_JOIN_MESSAGES,
			false,
			'Broadcast server role, nickname, title, and nation when a player or spectator joins.'
		);
		$this->initPlayerStatsCacheTable();
		$this->maniaControl->getCommandManager()->registerCommandListener(
			'benchmarkclearcache',
			$this,
			'handleClearPlayerStatsCache',
			false,
			'Clears your cached Benchmark data; it will be fetched again when you open the Benchmark menu.'
		);

		$manialinkManager = $this->maniaControl->getManialinkManager();
		$this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERDISCONNECT, $this, 'handlePlayerDisconnect');
		$manialinkManager->registerManialinkPageAnswerListener(self::ACTION_SHOW, $this, 'handleShow');
		$manialinkManager->registerManialinkPageAnswerListener(self::ACTION_SHOW_LEADERBOARD, $this, 'handleShowLeaderboard');
		$manialinkManager->registerManialinkPageAnswerRegexListener('/^' . preg_quote(self::ACTION_PAGING, '/') . '(\\d+)$/', $this, 'handlePaging');
		$manialinkManager->registerManialinkPageAnswerRegexListener('/^' . preg_quote(self::ACTION_LEADERBOARD_PAGING, '/') . '(\\d+)$/', $this, 'handleLeaderboardPaging');
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

	/** Open the campaign leaderboard. */
	public function handleShowLeaderboard(array $callback, Player $player) {
		$this->displayLeaderboardList($player, 0);
	}

	/** Handle a leaderboard page selection. */
	public function handleLeaderboardPaging(array $callback, Player $player) {
		$actionId = $callback[1][2];
		$pageNumber = (int) substr($actionId, strlen(self::ACTION_LEADERBOARD_PAGING));
		$this->displayLeaderboardList($player, max(0, $pageNumber - 1));
	}

	/** Clear the invoking player's persisted and in-memory Benchmark data. */
	public function handleClearPlayerStatsCache(array $chatCallback, Player $player) {
		$login = strtolower($player->login);
		unset($this->playerStatsCache[$login]);
		unset($this->pendingJoinMessages[$login]);
		unset($this->pendingListRefresh[$login]);
		$this->playerStatsRequestIds[$login] = isset($this->playerStatsRequestIds[$login])
			? $this->playerStatsRequestIds[$login] + 1
			: 1;

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$mysqli->query('UPDATE `' . self::TABLE_PLAYER_STATS . '` SET `leaderboard_seen_at` = 0 WHERE `leaderboard_member` = 1');
		$this->leaderboardFetchedAt = 0;
		$this->leaderboardCacheLoaded = false;
		$this->leaderboardCache = null;
		$statement = $mysqli->prepare('DELETE FROM `' . self::TABLE_PLAYER_STATS . '` WHERE `login` = ?');
		if (!$statement) {
			$this->maniaControl->getChat()->sendError('Could not clear your Benchmark cache.', $player);
			return;
		}
		$statement->bind_param('s', $login);
		$success = $statement->execute();
		$statement->close();
		if (!$success) {
			$this->maniaControl->getChat()->sendError('Could not clear your Benchmark cache.', $player);
			return;
		}

		$this->maniaControl->getChat()->sendSuccess('Benchmark cache cleared. Reopen the Benchmark list to fetch fresh data.', $player);
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

	/** Send PlayerManager-style leave messages when Benchmark messages are enabled. */
	public function handlePlayerDisconnect(Player $player) {
		if (!$this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_JOIN_MESSAGES)
		    || $player->isFakePlayer()) {
			return;
		}

		$playTime = Formatter::formatTimeH(time() - $player->joinTime);
		$color = '$0f0';
		if ($this->maniaControl->getSettingManager()->getSettingValue(
			$this->maniaControl->getPlayerManager(),
			PlayerManager::SETTING_JOIN_LEAVE_COLORING
		)) {
			$color = $this->maniaControl->getColorManager()->getColorByPlayer($player);
		}

		$authName = $player->getAuthLevelName();
		$message = $this->maniaControl->getChat()->formatMessage(
			"{$color}{$authName} %s has left after %s!",
			$player,
			$playTime
		);
		$this->maniaControl->getChat()->sendChat($message);
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
		$player->setCache($this, self::CACHE_CURRENT_VIEW, 'maps');
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

		$this->addNavigationButton($frame, 'Leaderboard', self::ACTION_SHOW_LEADERBOARD, $width, $height);

		$this->maniaControl->getManialinkManager()->displayWidget($maniaLink, $player, self::WIDGET_NAME);
		$this->requestPlayerStats($player);
	}

	/**
	 * Render the shared, locally paged campaign leaderboard.
	 * @param int $pageIndex
	 */
	private function displayLeaderboardList(Player $player, $pageIndex) {
		$player->setCache($this, self::CACHE_CURRENT_VIEW, 'leaderboard');
		$player->setCache($this, self::CACHE_LEADERBOARD_PAGE, (int) $pageIndex);
		$this->requestLeaderboard($player);

		$rows = is_array($this->leaderboardCache) ? $this->leaderboardCache : array();
		$styleManager = $this->maniaControl->getManialinkManager()->getStyleManager();
		$width = $styleManager->getListWidgetsWidth();
		$height = $styleManager->getListWidgetsHeight();
		$pageCount = max(1, (int) ceil(count($rows) / self::MAX_MAPS_PER_PAGE));
		$pageIndex = max(0, min((int) $pageIndex, $pageCount - 1));
		$player->setCache($this, self::CACHE_LEADERBOARD_PAGE, $pageIndex);
		$pageRows = array_slice($rows, $pageIndex * self::MAX_MAPS_PER_PAGE, self::MAX_MAPS_PER_PAGE);

		$maniaLink = new ManiaLink(ManialinkManager::MAIN_MLID);
		$script = $maniaLink->getScript();
		$paging = new Paging();
		$script->addFeature($paging);
		$paging->setCustomMaxPageNumber($pageCount);
		$paging->setChunkActionAppendsPageNumber(true);
		$paging->setChunkActions(self::ACTION_LEADERBOARD_PAGING);
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

		$pageFrame = new Frame();
		$frame->addChild($pageFrame);
		$paging->addPageControl($pageFrame, $pageIndex + 1);

		$headingFrame = new Frame();
		$frame->addChild($headingFrame);
		$headingFrame->setY($height / 2 - 11);
		$this->addLeaderboardColumnHeading($headingFrame, 'Rank', -$width / 2 + 5, 13);
		$this->addLeaderboardColumnHeading($headingFrame, 'Player', -$width / 2 + 20, 55);
		$this->addLeaderboardColumnHeading($headingFrame, 'Title', -$width / 2 + 77, 34);
		$this->addLeaderboardColumnHeading($headingFrame, 'Average rank', $width / 2 - 48, 28);
		$this->addLeaderboardColumnHeading($headingFrame, 'Finished', $width / 2 - 19, 22);

		if (!$pageRows) {
			$emptyLabel = new Label_Text();
			$frame->addChild($emptyLabel);
			$emptyLabel->setY(0);
			$emptyLabel->setSize($width - 20, 5);
			$emptyLabel->setAlign('center', 'center');
			$emptyLabel->setTextColor('aaa');
			$emptyLabel->setText($this->leaderboardRequestPending ? 'Loading Benchmark leaderboard...' : 'Leaderboard unavailable. Reopen this list to retry.');
		}

		$posY = $height / 2 - 16;
		$rowIndex = $pageIndex * self::MAX_MAPS_PER_PAGE;
		$tierColors = array(
			'Bronze' => 'a83', 'Silver' => 'fff', 'Gold' => 'fd7',
			'Platinum' => '9bd', 'Diamond' => 'd3e', 'Master' => '87b',
			'Grandmaster' => 'c66', 'Challenger' => 'fff', 'Celestial' => 'cef',
		);
		foreach ($pageRows as $entry) {
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

			$row = new LabelLine($rowFrame);
			$row->setTextSize(1.25);
			$row->addLabelEntryText((string) $entry['rank'], -$width / 2 + 5, 13);
			$row->addLabelEntryText($entry['playerName'], -$width / 2 + 20, 55);
			$title = isset($entry['title']) ? $entry['title'] : 'Unknown';
			$titleColor = isset($tierColors[$title]) ? '$' . $tierColors[$title] : '$999';
			$row->addLabelEntryText($titleColor . $title, -$width / 2 + 77, 34);
			$row->addLabelEntryText((string) $entry['average'], $width / 2 - 48, 28);
			$finished = (string) $entry['finished'];
			if (strpos($finished, '/') === false) {
				$finished .= '/' . count($this->getMapCatalog());
			}
			$row->addLabelEntryText($finished, $width / 2 - 19, 22);
			$row->render();
			$posY -= 4.2;
			$rowIndex++;
		}

		$this->addNavigationButton($frame, 'Benchmark maps', self::ACTION_SHOW, $width, $height);
		$this->maniaControl->getManialinkManager()->displayWidget($maniaLink, $player, self::WIDGET_NAME);
		$this->requestPlayerStats($player);
	}

	/**
	 * @param string    $text
	 * @param int|float $x
	 * @param int|float $width
	 */
	private function addLeaderboardColumnHeading(Frame $frame, $text, $x, $width) {
		$label = new Label_Text();
		$frame->addChild($label);
		$label->setPosition($x, 0, 0.2);
		$label->setSize($width, 3);
		$label->setTextSize(0.9);
		$label->setTextColor('aaa');
		$label->setText($text);
		$label->setAlign('left', 'center');
	}

	/**
	 * @param string    $text
	 * @param string    $action
	 * @param int|float $width
	 * @param int|float $height
	 */
	private function addNavigationButton(Frame $frame, $text, $action, $width, $height) {
		$buttonFrame = new Frame();
		$frame->addChild($buttonFrame);
		$buttonFrame->setPosition(-$width / 2 + 5 + 48 / 2, -$height / 2 + 9);

		$background = new Quad_BgsPlayerCard();
		$buttonFrame->addChild($background);
		$background->setSize(48, 4);
		$background->setSubStyle(Quad_BgsPlayerCard::SUBSTYLE_BgPlayerCardBig);
		$background->setZ(-1);
		$background->setAction($action);

		$label = new Label_Button();
		$buttonFrame->addChild($label);
		$label->setText($text);
		$label->setTextSize(1);
		$label->setAction($action);
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
				`leaderboard_member` tinyint(1) unsigned NOT NULL DEFAULT 0,
				`leaderboard_seen_at` int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (`login`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
		if (!$mysqli->query($query)) {
			trigger_error('Benchmark Maps player cache table could not be initialized: ' . $mysqli->error, E_USER_WARNING);
			return;
		}
		foreach (array(
			'leaderboard_member' => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
			'leaderboard_seen_at' => 'int(10) unsigned NOT NULL DEFAULT 0',
		) as $column => $definition) {
			$result = $mysqli->query("SHOW COLUMNS FROM `" . self::TABLE_PLAYER_STATS . "` LIKE '" . $column . "'");
			if ($result && $result->num_rows === 0) {
				$mysqli->query('ALTER TABLE `' . self::TABLE_PLAYER_STATS . '` ADD COLUMN `' . $column . '` ' . $definition);
			}
			if ($result) {
				$result->free();
			}
		}
	}

	private function loadLeaderboardCache() {
		if ($this->leaderboardCacheLoaded) {
			return;
		}
		$this->leaderboardCacheLoaded = true;
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$result = $mysqli->query('SELECT `login`, `stats`, `leaderboard_seen_at` FROM `' . self::TABLE_PLAYER_STATS . '` WHERE `leaderboard_member` = 1');
		if (!$result) {
			return;
		}
		$rows = array();
		$latestSync = 0;
		while ($row = $result->fetch_assoc()) {
			$stats = json_decode($row['stats'], true);
			if (!is_array($stats)) {
				continue;
			}
			$login = strtolower($row['login']);
			$stats['login'] = $login;
			$rows[] = $stats;
			$latestSync = max($latestSync, (int) $row['leaderboard_seen_at']);
		}
		$result->free();
		usort($rows, function ($a, $b) {
			return (int) $a['rank'] - (int) $b['rank'];
		});
		$this->leaderboardCache = $rows;
		$this->leaderboardFetchedAt = $latestSync;
	}

	private function isLeaderboardCacheFresh() {
		return is_array($this->leaderboardCache)
			&& time() - $this->leaderboardFetchedAt < self::LEADERBOARD_CACHE_TTL;
	}

	private function requestLeaderboard(Player $player) {
		$this->loadLeaderboardCache();
		$login = strtolower($player->login);
		if ($this->leaderboardRequestPending) {
			$this->pendingLeaderboardViewers[$login] = true;
			return;
		}
		if ($this->isLeaderboardCacheFresh()) {
			return;
		}
		if ($this->leaderboardLastAttemptAt && time() - $this->leaderboardLastAttemptAt < 60) {
			return;
		}

		$this->leaderboardRequestPending = true;
		$this->leaderboardLastAttemptAt = time();
		$this->leaderboardRequestId++;
		$this->pendingLeaderboardViewers[$login] = true;
		$requestId = $this->leaderboardRequestId;
		$query = 'query BenchmarkLeaderboard {' .
			' event(handle: "benchmark") {' .
			'  edition(editionId: 2) {' .
			'   mappack { leaderboard { rank rankAvg mapFinished player { login name } } }' .
			'  }' .
			' }' .
			'}';
		$this->sendGraphQlRequest($query, array(), function ($content, $error) use ($requestId) {
			$rows = (!$error && $content) ? $this->parseLeaderboardSummaryResponse($content) : null;
			if ($rows === null) {
				$this->logLeaderboardError('roster query failed', $error, $content);
				$this->finishLeaderboardRequest($requestId);
				return;
			}
			$this->fetchLeaderboardTitleBatch($requestId, $rows, 0);
		});
	}

	/**
	 * @param int                         $requestId
	 * @param array<int, array<string, mixed>> $rows
	 * @param int                         $offset
	 */
	private function fetchLeaderboardTitleBatch($requestId, array $rows, $offset) {
		if ($requestId !== $this->leaderboardRequestId) {
			return;
		}
		if ($offset >= count($rows)) {
			if (!$this->storeLeaderboardPlayers($rows)) {
				// Keep the UI usable even if database migration or persistence failed.
				foreach ($rows as &$row) {
					$row['finished'] = $row['finished'] . '/' . count($this->getMapCatalog());
				}
				unset($row);
				$this->leaderboardCache = $rows;
				$this->leaderboardFetchedAt = time();
			}
			$this->finishLeaderboardRequest($requestId);
			return;
		}

		$batch = array_slice($rows, $offset, self::LEADERBOARD_TITLE_BATCH_SIZE);
		$selection = array();
		foreach ($batch as $batchIndex => $entry) {
			$login = json_encode($entry['login']);
			if ($login === false) {
				$this->finishLeaderboardRequest($requestId);
				return;
			}
			$selection[] = 'p' . $batchIndex . ': player(login: ' . $login . ') { categorizedRanks { categoryName nbMaps ranks { rank } } }';
		}
		$query = 'query BenchmarkLeaderboardTitles {' .
			' event(handle: "benchmark") { edition(editionId: 2) { ' . implode(' ', $selection) . ' } }' .
			'}';
		$this->sendGraphQlRequest($query, array(), function ($content, $error) use ($requestId, $rows, $offset, $batch) {
			$response = (!$error && $content) ? json_decode($content, true) : null;
			$edition = isset($response['data']['event']['edition']) ? $response['data']['event']['edition'] : null;
			if (!is_array($edition)) {
				$this->logLeaderboardError('player title batch failed', $error, $content);
				$this->finishLeaderboardRequest($requestId);
				return;
			}
			foreach ($batch as $batchIndex => $entry) {
				$alias = 'p' . $batchIndex;
				$playerData = isset($edition[$alias]) ? $edition[$alias] : null;
				if (!is_array($playerData) || !isset($playerData['categorizedRanks']) || !is_array($playerData['categorizedRanks'])) {
					// Keep the roster entry; player may have no category data yet.
					$rows[$offset + $batchIndex]['title'] = 'Unranked';
					continue;
				}
				$completed = array();
				$totals = array();
				foreach ($playerData['categorizedRanks'] as $category) {
					if (empty($category['categoryName'])) {
						continue;
					}
					$tier = $category['categoryName'];
					$completed[$tier] = isset($category['ranks']) && is_array($category['ranks']) ? count($category['ranks']) : 0;
					$totals[$tier] = isset($category['nbMaps']) ? (int) $category['nbMaps'] : 0;
				}
				$rows[$offset + $batchIndex]['title'] = $this->getRankTitle($completed, $totals);
			}
			$this->fetchLeaderboardTitleBatch($requestId, $rows, $offset + count($batch));
		});
	}

	/** Persist roster membership and player stats in the shared player cache table. */
	private function storeLeaderboardPlayers(array $rows) {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$fetchedAt = time();
		$existingStats = array();
		$result = $mysqli->query('SELECT `login`, `stats` FROM `' . self::TABLE_PLAYER_STATS . '`');
		if ($result) {
			while ($cachedRow = $result->fetch_assoc()) {
				$cachedStats = json_decode($cachedRow['stats'], true);
				if (is_array($cachedStats)) {
					$existingStats[strtolower($cachedRow['login'])] = $cachedStats;
				}
			}
			$result->free();
		}
		$mysqli->begin_transaction();
		if (!$mysqli->query('UPDATE `' . self::TABLE_PLAYER_STATS . '` SET `leaderboard_member` = 0')) {
			$this->logLeaderboardError('could not reset roster membership', $mysqli->error);
			$mysqli->rollback();
			return false;
		}
		$statement = $mysqli->prepare('INSERT INTO `' . self::TABLE_PLAYER_STATS . '` (`login`, `stats`, `fetched_at`, `leaderboard_member`, `leaderboard_seen_at`) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE `stats` = VALUES(`stats`), `fetched_at` = VALUES(`fetched_at`), `leaderboard_member` = 1, `leaderboard_seen_at` = VALUES(`leaderboard_seen_at`)');
		if (!$statement) {
			$this->logLeaderboardError('could not prepare player cache upsert', $mysqli->error);
			$mysqli->rollback();
			return false;
		}
		$leaderboard = array();
		foreach ($rows as $row) {
			$login = strtolower($row['login']);
			$previousStats = isset($existingStats[$login]) ? $existingStats[$login] : array();
			$stats = array(
				'playerName' => $row['playerName'],
				'title' => $row['title'],
				'rank' => (string) $row['rank'],
				'average' => $row['average'],
				'finished' => $row['finished'] . '/' . count($this->getMapCatalog()),
				'worst' => $row['worst'] !== '-' ? $row['worst'] : (isset($previousStats['worst']) ? $previousStats['worst'] : '-'),
				'mapRanks' => isset($previousStats['mapRanks']) && is_array($previousStats['mapRanks']) ? $previousStats['mapRanks'] : array(),
			);
			// Escape Unicode so four-byte player-name characters work with older utf8 DB columns.
			$statsJson = json_encode($stats);
			if ($statsJson === false) {
				$statement->close();
				$mysqli->rollback();
				return false;
			}
			$statement->bind_param('ssii', $login, $statsJson, $fetchedAt, $fetchedAt);
			if (!$statement->execute()) {
			$this->logLeaderboardError('player cache upsert failed', $statement->error);
				$statement->close();
				$mysqli->rollback();
				return false;
			}
			$stats['login'] = $login;
			$this->playerStatsCache[$login] = array('stats' => $stats, 'fetchedAt' => $fetchedAt, 'pending' => false, 'lastAttemptAt' => $fetchedAt);
			$leaderboard[] = $stats;
		}
		$statement->close();
		if (!$mysqli->commit()) {
			$this->logLeaderboardError('could not commit player cache transaction', $mysqli->error);
			$mysqli->rollback();
			return false;
		}
		usort($leaderboard, function ($a, $b) { return (int) $a['rank'] - (int) $b['rank']; });
		$this->leaderboardCache = $leaderboard;
		$this->leaderboardFetchedAt = $fetchedAt;
		$this->leaderboardCacheLoaded = true;
		return true;
	}

	/**
	 * @param string      $context
	 * @param mixed       $error
	 * @param string|null $content
	 */
	private function logLeaderboardError($context, $error, $content = null) {
		$message = 'Benchmark leaderboard ' . $context;
		if ($error) {
			$message .= ': ' . (string) $error;
		}
		if ($content) {
			$response = json_decode($content, true);
			if (isset($response['errors']) && is_array($response['errors'])) {
				$messages = array();
				foreach ($response['errors'] as $apiError) {
					if (isset($apiError['message'])) {
						$messages[] = $apiError['message'];
					}
				}
				if ($messages) {
					$message .= ': ' . implode('; ', $messages);
				}
			} elseif (!$response) {
				$message .= ': ' . substr($content, 0, 300);
			}
		}
		trigger_error($message, E_USER_WARNING);
	}

	/** @param int $requestId */
	private function finishLeaderboardRequest($requestId) {
		if ($requestId !== $this->leaderboardRequestId) {
			return;
		}
		$this->leaderboardRequestPending = false;
		$viewers = $this->pendingLeaderboardViewers;
		$this->pendingLeaderboardViewers = array();
		foreach ($viewers as $login => $unused) {
			$player = $this->maniaControl->getPlayerManager()->getPlayer($login, true);
			if (!$player || $player->getCache($this, self::CACHE_CURRENT_VIEW) !== 'leaderboard') {
				continue;
			}
			$this->displayLeaderboardList($player, (int) $player->getCache($this, self::CACHE_LEADERBOARD_PAGE));
		}
	}

	/**
	 * @param string               $query
	 * @param array<string, mixed> $variables
	 * @param callable             $callback
	 */
	private function sendGraphQlRequest($query, array $variables, $callback) {
		$requestBody = json_encode(array('query' => $query, 'variables' => (object) $variables));
		if ($requestBody === false) {
			call_user_func($callback, null, 'Unable to encode GraphQL request.');
			return;
		}
		$request = new AsyncHttpRequest($this->maniaControl, 'https://obstacle.titlepack.io/api/graphql');
		$request->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
		$request->setContent($requestBody);
		$request->setCallable($callback);
		$request->postData();
	}

	/**
	 * @param string $response
	 * @return array<int, array<string, mixed>>|null
	 */
	private function parseLeaderboardSummaryResponse($response) {
		$data = json_decode($response, true);
		$entries = isset($data['data']['event']['edition']['mappack']['leaderboard'])
			? $data['data']['event']['edition']['mappack']['leaderboard']
			: null;
		if (!is_array($entries)) {
			return null;
		}
		$rows = array();
		foreach ($entries as $entry) {
			if (!isset($entry['player']['login'])) {
				return null;
			}
			$rows[] = array(
				'rank' => isset($entry['rank']) ? (int) $entry['rank'] : count($rows) + 1,
				'login' => strtolower($entry['player']['login']),
				'playerName' => isset($entry['player']['name']) ? $entry['player']['name'] : $entry['player']['login'],
				'title' => 'Unknown',
				'average' => isset($entry['rankAvg']) && is_numeric($entry['rankAvg']) ? $this->formatAverageRank($entry['rankAvg']) : '-',
				'finished' => isset($entry['mapFinished']) ? (int) $entry['mapFinished'] : 0,
				'worst' => isset($entry['worstRank']) && (int) $entry['worstRank'] > 0 ? (string) $entry['worstRank'] : '-',
				'mapRanks' => array(),
			);
		}
		return $rows;
	}

	/**
	 * @param int|float|string $average
	 * @return string
	 */
	private function formatAverageRank($average) {
		return rtrim(rtrim(number_format((float) $average, 2, '.', ''), '0'), '.');
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
		// Keep cached JSON ASCII-safe for databases whose existing column still uses three-byte utf8.
		$statsJson = json_encode($stats);
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
		$requestId = isset($this->playerStatsRequestIds[$login]) ? $this->playerStatsRequestIds[$login] + 1 : 1;
		$this->playerStatsRequestIds[$login] = $requestId;
		$this->pendingListRefresh[$login] = $refreshList;
		$url = 'https://obstacle.titlepack.io/api/graphql';
		$query = 'query BenchmarkPlayerStats($login: String!) {' .
			' event(handle: "benchmark") {' .
			'  edition(editionId: 2) {' .
			'   player(login: $login) {' .
			'    player { name } rank rankAvg mapFinished worstRank' .
			'    categorizedRanks { categoryName nbMaps ranks { rank map { map { gameId } } } }' .
			'   }' .
			'  }' .
			' }' .
			'}';
		$requestBody = json_encode(array(
			'query' => $query,
			'variables' => array('login' => $player->login),
		));
		if ($requestBody === false) {
			$this->playerStatsCache[$login]['pending'] = false;
			return false;
		}

		$request = new AsyncHttpRequest($this->maniaControl, $url);
		$request->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
		$request->setContent($requestBody);
		$request->setCallable(function ($content, $error) use ($login, $player, $requestId) {
			if (!isset($this->playerStatsRequestIds[$login]) || $this->playerStatsRequestIds[$login] !== $requestId) {
				return;
			}
			$stats = (!$error && $content) ? $this->parsePlayerStatsResponse($content, $player->nickname) : null;
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
				if ($this->leaderboardCacheLoaded) {
					$this->leaderboardCacheLoaded = false;
					$this->loadLeaderboardCache();
				}
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
					if ($connectedPlayer->getCache($this, self::CACHE_CURRENT_VIEW) === 'leaderboard') {
						$this->displayLeaderboardList($connectedPlayer, (int) $connectedPlayer->getCache($this, self::CACHE_LEADERBOARD_PAGE));
					} else {
						$this->displayBenchmarkList($connectedPlayer, (int) $connectedPlayer->getCache($this, self::CACHE_CURRENT_PAGE));
					}
				}
			}
		});
		$request->postData();
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
	 * Convert the public Benchmark GraphQL response into the cache format used by the UI.
	 *
	 * @param string $response
	 * @param string $fallbackName
	 * @return array|null
	 */
	private function parsePlayerStatsResponse($response, $fallbackName) {
		$responseData = json_decode($response, true);
		$playerData = isset($responseData['data']['event']['edition']['player'])
			? $responseData['data']['event']['edition']['player']
			: null;
		if (!is_array($playerData)) {
			return null;
		}

		$mapRanks = array();
		if (isset($playerData['categorizedRanks']) && is_array($playerData['categorizedRanks'])) {
			foreach ($playerData['categorizedRanks'] as $category) {
				if (empty($category['ranks']) || !is_array($category['ranks'])) {
					continue;
				}
				foreach ($category['ranks'] as $rankData) {
					$mapUid = isset($rankData['map']['map']['gameId']) ? $rankData['map']['map']['gameId'] : null;
					if ($mapUid !== null && isset($rankData['rank'])) {
						$mapRanks[$mapUid] = (string) $rankData['rank'];
					}
				}
			}
		}

		$completedTierCounts = array();
		foreach ($this->getMapCatalog() as $mapData) {
			$tier = $mapData['tier'];
			if (isset($mapRanks[$mapData['uid']])) {
				$completedTierCounts[$tier] = isset($completedTierCounts[$tier]) ? $completedTierCounts[$tier] + 1 : 1;
			}
		}
		$rankTitle = $this->getRankTitle($completedTierCounts);

		$playerName = isset($playerData['player']['name']) && $playerData['player']['name'] !== ''
			? $playerData['player']['name']
			: $fallbackName;
		$rank = isset($playerData['rank']) && (int) $playerData['rank'] > 0 ? (string) $playerData['rank'] : '';
		$average = isset($playerData['rankAvg']) && is_numeric($playerData['rankAvg'])
			? $this->formatAverageRank($playerData['rankAvg'])
			: '-';
		$worst = isset($playerData['worstRank']) && (int) $playerData['worstRank'] > 0
			? (string) $playerData['worstRank']
			: '-';
		$mapsFinished = isset($playerData['mapFinished']) ? (int) $playerData['mapFinished'] : count($mapRanks);

		return array(
			'playerName' => $playerName,
			'title' => $rankTitle,
			'rank' => $rank,
			'average' => $average,
			'finished' => $mapsFinished . '/' . count($this->getMapCatalog()),
			'worst' => $worst,
			'mapRanks' => $mapRanks,
		);
	}

	private function getRankTitle(array $completedTierCounts, array $tierTotals = null) {
		if ($tierTotals === null) {
			$tierTotals = array();
			foreach ($this->getMapCatalog() as $mapData) {
				$tier = $mapData['tier'];
				$tierTotals[$tier] = isset($tierTotals[$tier]) ? $tierTotals[$tier] + 1 : 1;
			}
		}
		$title = 'Unranked';
		foreach (array('Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond', 'Master', 'Grandmaster', 'Challenger', 'Celestial') as $tier) {
			if (empty($tierTotals[$tier]) || !isset($completedTierCounts[$tier]) || $completedTierCounts[$tier] < $tierTotals[$tier]) {
				break;
			}
			$title = $tier;
		}
		return $title;
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
