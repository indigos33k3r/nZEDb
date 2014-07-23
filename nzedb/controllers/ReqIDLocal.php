<?php

use nzedb\db\Settings;

/**
 * Attempts to find a PRE name for a release using a request ID from our local pre database,
 * or internet request id database using a Standalone -- more intensive methods
 *
 * Class ReqIDLocal
 */
class ReqIDLocal
{

	// Request ID.
	const REQID_OLD    = -4; // We rechecked the web a second time and didn't find a title so don't process it again.
	const REQID_NONE   = -3; // The Request ID was not found locally or via web lookup.
	const REQID_ZERO   = -2; // The Request ID was 0.
	const REQID_NOLL   = -1; // Request ID was not found via local lookup.
	const REQID_UPROC  =  0; // Release has not been processed.
	const REQID_FOUND  =  1; // Request ID found and release was updated.

	/**
	 * @var bool Echo to CLI?
	 */
	protected $echoOutput;

	/**
	 * @var Category
	 */
	protected $category;

	/**
	 * @var int $charGuid The first char of the release guid for threading
	 */
	protected $charGuid;

	/**
	 * @var nzedb\db\DB
	 */
	protected $pdo;

	/**
	 * @var ConsoleTools
	 */
	protected $consoleTools;

	/**
	 * @var ColorCLI
	 */
	protected $colorCLI;

	/**
	 * @var bool The title found from a request ID lookup.
	 */
	protected $newTitle = false;

	/**
	 * @var int The found request ID for the release.
	 */
	protected $requestID = self::REQID_ZERO;

	/**
	 * @var array The result of a SQL query.
	 */
	protected $run = array();

	/**
	 * @var int the command line show arg represented
	 * as an assigned integer value
	 */
	protected $show = 0;

	/**
	 * @param ColorCLI $ColorCLI
	 * @param bool     $echoOutput
	 */
	public function __construct($ColorCLI, $echoOutput = false)
	{
		$this->echoOutput = ($echoOutput && nZEDb_ECHOCLI);
		$this->category = new Categorize();
		$this->pdo = new Settings();
		$this->groups = new Groups($this->pdo);
		$this->consoleTools = new ConsoleTools();
		$this->colorCLI = $ColorCLI;
	}

	/**
	 * Main Worker Function that spawns child functions
	 *
	 * @param array  $argv
	 * @param string $charGuid
	 */
	public function standaloneLocalLookup($argv, $charGuid = '')
	{
		$timestart = time();
		$counted = $counter = $total = 0;
		(isset($argv[2]) && $argv[2] === 'show' ? $this->show = 1 : $this->show = 0);

		$this->charGuid = $charGuid;

		$res = $this->pdo->queryDirect($this->_buildWorkQuery($argv, $this->charGuid));

		if ($res !== false) {
			$total = $res->rowCount();
		}

		if ($total > 0) {

			$this->_enumerateWork($total);

			foreach ($res as $row) {
				$this->requestID = 0;
				$this->requestID = $this->_siftReqId($row['name']);

				$this->newTitle = '';

				// Do a local lookup using multiple possible methods
				$this->newTitle = $this->_stageLookup($this->requestID, $row['groupname'], $row['name']);

				if (is_array($this->newTitle) && $this->newTitle['title'] != '') {
					$title = $this->newTitle['title'];
					$preid = $this->newTitle['id'];
					$determinedcat = $this->category->determineCategory($title, $row['gid']);
					$this->_updateRelease($title, $preid, $determinedcat, $row, $this->show);
					$counted++;
				} else {
					$this->_requestIdNotFound($row['id'], ($row['reqidstatus'] == self::REQID_UPROC ? self::REQID_NOLL : self::REQID_NONE));
				}

				if ($this->show === 0) {
					$this->consoleTools->overWritePrimary("Renamed Releases: [" . number_format($counted) . "] " . $this->consoleTools->percentString(++$counter, $total));
				}
			}
			echo $this->colorCLI->header("\nRenamed " . number_format($counted) . " releases in " . $this->consoleTools->convertTime(time() - $timestart) . ".");
		} else {
			echo $this->colorCLI->info("No work to process." . PHP_EOL);
		}
	}

	/**
	 * Builds work query for main function
	 *
	 * @param array  $argv
	 * @param string $charGuid
	 *
	 * @return string
	 */
	protected function _buildWorkQuery($argv, $charGuid)
	{

		$this->charGuid = $charGuid;
		$guidLike = '';

		if ($this->charGuid != '') {
			$guidLike = sprintf('
						AND r.guid %s',
						$this->pdo->likeString($this->charGuid, false, true)
			);
		}

		switch(true) {
			case isset($argv[2]) && is_numeric($argv[2]):
				$time =
					sprintf(
						' OR r.postdate > NOW() - INTERVAL %d HOUR)',
						$argv[2]
					);
				break;
			case isset($argv[3]) && is_numeric($argv[3]):
				$time =
					sprintf(
						' OR r.postdate > NOW() - INTERVAL %d HOUR)',
						$argv[3]
					);
				break;
			default:
				$time = ')';
				break;
		}

		switch ($argv[1]) {
			case "all":	//runs on every release not already PreDB Matched
				$where = "WHERE nzbstatus = 1 AND preid = 0 AND isrequestid = 1";
				break;
			case "full":	//runs on all releases not already renamed not already PreDB matched
				$where =
						sprintf(
							"WHERE %s nzbstatus = 1
							AND preid = 0
							AND (isrenamed = 0 AND isrequestid = 1 %s
							AND reqidstatus in (%d, %d, %d)",
							$guidLike,
							$time,
							self::REQID_UPROC,
							self::REQID_NOLL,
							self::REQID_NONE
						);
				break;
			default:	// NUMERIC - runs on all releases not already renamed limited by user not already PreDB matched
				$where =
						sprintf(
							"WHERE %s nzbstatus = 1
							AND preid = 0
							AND (isrenamed = 0 AND isrequestid = 1 %s
							AND reqidstatus in (%d, %d, %d)
							ORDER BY postdate DESC
							LIMIT %d",
							$guidLike,
							$time,
							self::REQID_UPROC,
							self::REQID_NOLL,
							self::REQID_NONE,
							$argv[1]
						);
				break;
		}
		return
			sprintf("
				SELECT r.id, r.name, r.categoryid, r.reqidstatus,
					g.name AS groupname, g.id as gid
				FROM releases r
				LEFT JOIN groups g ON r.group_id = g.id %s",
				$where
			);
	}

	/**
	 * Displays pre-run statistics
	 *
	 * @param int $total
	 */
	protected function _enumerateWork($total)
	{
		$reqidcount = $this->pdo->queryOneRow('SELECT COUNT(*) AS count FROM predb WHERE requestid > 0');
		echo $this->colorCLI->header(PHP_EOL . "Comparing " . number_format($total) . " releases against " . number_format($reqidcount['count']) . " Local requestID's." . PHP_EOL);
		sleep(2);
	}

	private $oldName;
	private $groupName;

	/**
	 * Sub function that attempts to match RequestID Releases
	 * by preg_matching the title from the usenet name
	 *
	 * @param string $groupName
	 * @param string $oldName
	 *
	 * @return array|bool
	 */
	protected function _multiLookup($groupName, $oldName)
	{
		$this->oldName = $oldName;
		$this->groupName = $groupName;

		$regex1 =
				'/^\[\s*\d+\s*\][ -]+(\[(ISO|FULL|PART|MP3|0DAY|android)\][ -]+)?\[(alt-?bin| ?#?a[a-z0-9. -]+)((@?ef{1,2})?net)? ?\]' .
				'[ -]+(\[(ISO|FULL|PART|MP3|0DAY|android)\][ -]+)?(\[\s*\d+\s*\][ -]+)?(\[\d+\/\d+\][ -]+)?(\"|\[)\s*' .
				'(?P<title>.+?)(\.+(vol\d+\+\d+\.)?(-cd\d\.)?(avi|jpg|nzb|m3u|mkv|par2|part\d+|nfo|sample|sfv|rar|r?\d{1,3}|\d+|zip)*)?\s*(\"|\])' .
				'[ -]*(\[\d+\/\d+\][ -]*)?((\"\s*(?P<filename1>.+?)([-.]sample)?([-.]cd(\d|[ab]))?(\.+(vol\d+\+\d+\.)?([-.]d\d\.)?([-.]part\d+)?' .
				'(avi|jpg|nzb|m3u|mkv|par2|nfo|sample|sfv|rar|r?\d{1,3}|\d+|zip)*)?\s*\")| - (?P<filename2>.+?) (yEnc|\(\d+\/\d+\)))?.*/i'
		;

		$regex2 =
				'/^\[\s*\d+\s*\].*' .
				'\"\s*(?P<title>.+?)(\.+(vol\d+\+\d+\.)?(-cd\d\.)?' .
				'(avi|jpg|nzb|m3u|mkv|par2|part\d+|nfo|sample|sfv|rar|r?\d{1,3}|\d+|zip)*)\s*\".*/i'
		;

		switch (true) {
			case preg_match($regex1, $this->oldName, $matches):
			case preg_match($regex2, $this->oldName, $matches):
				$this->run = $this->pdo->queryOneRow(
								sprintf(
									"SELECT id, title FROM predb " .
									"WHERE title = %s OR filename = %s %s",
									$this->pdo->escapeString($matches['title']),
									$this->pdo->escapeString($matches['title']),
									(
										isset($matches['filename1']) && $matches['filename1'] !== ''
										? 'OR filename = ' . $this->pdo->escapeString($matches['filename1'])
										:
										(
											isset($matches['filename2']) && $matches['filename2'] !== ''
											? 'OR filename = ' . $this->pdo->escapeString($matches['filename2'])
											: ''
										)
									)
								)
				);
				if ($this->run !== false) {
					return array('title' => $this->run['title'], 'id' => $this->run['id']);
				}
				continue;
			default:
				return false;
		}
		return false;
	}

	/**
	 * Sub function that updates release reqidstatus
	 * when no match is found or ReqID is bad
	 *
	 * @param int $releaseID
	 * @param int $status
	 */
	protected function _requestIdNotFound($releaseID, $status)
	{

		if ($releaseID == 0) {
			return;
		}

		$this->pdo->queryExec(
					sprintf('
						UPDATE releases SET reqidstatus = %d
						WHERE id = %d',
						$status,
						$releaseID
			)
		);
	}

	/**
	 * Sub function that preg_matches the ReqID
	 * from the release usenet name and returns
	 *
	 * @param string $releaseName
	 *
	 * @return int
	 */
	protected function _siftReqId($releaseName)
	{
		switch (true) {
			case preg_match('/\[ ?#?scnzb@?efnet ?\]\[(?P<reqid>\d+)\]/', $releaseName, $requestID):
				if ((int) $requestID['reqid'] > 0) {
					return (int) $requestID['reqid'];
				} else {
					break;
				}
			case preg_match('/\[\s*(\d+)\s*\]/', $releaseName, $requestID):
			case preg_match('/^REQ\s*(\d{4,6})/i', $releaseName, $requestID):
			case preg_match('/^(\d{4,6})-\d{1}\[/', $releaseName, $requestID):
			case preg_match('/(\d{4,6}) -/',$releaseName, $requestID):
				if ((int) $requestID[1] > 0) {
					return (int) $requestID[1];
				} else {
					break;
				}
		}
		return self::REQID_ZERO;
	}

	/**
	 * Sub function that attempts to remap the
	 * release group_id by extracting the new groupname
	 * from the release usenet name
	 *
	 * @param int $requestID
	 * @param string $groupName
	 * @param string $oldName
	 *
	 * @return array|bool
	 */
	protected function _singleAltLookup($requestID, $groupName, $oldName)
	{
		$this->oldName = $oldName;

		switch (true) {
			case $groupName == 'alt.binaries.etc':
				$groupName = 'alt.binaries.teevee';
				break;
			case strpos($this->oldName, 'teevee') !== false:
				$groupName = 'alt.binaries.teevee';
				break;
			case strpos($this->oldName, 'moovee') !== false:
				$groupName = 'alt.binaries.moovee';
				break;
			case strpos($this->oldName, 'erotica') !== false:
				$groupName = 'alt.binaries.erotica';
				break;
			case strpos($this->oldName, 'foreign') !== false:
				$groupName = 'alt.binaries.mom';
				break;
			case strpos($this->oldName, 'inner-sanctum') !== false:
				$groupName = 'alt.binaries.inner-sanctum';
				break;
			case strpos($this->oldName, 'sounds.flac') !== false:
				$groupName = 'alt.binaries.sounds.flac';
				break;
			case strpos($this->oldName, 'scnzb') !== false:
				$groupName = 'alt.binaries.boneless';
				break;
			case strpos($this->oldName, 'hdtv.x264') !== false:
				$groupName = 'alt.binaries.hdtv.x264';
				break;
			default:
				return false;
		}
		$groupid = $this->groups->getIDByName($groupName);
		$this->run = $this->pdo->queryOneRow(
							sprintf("
								SELECT id, title FROM predb
								WHERE requestid = %d AND group_id = %d",
								$requestID,
								$groupid
							)
		);
		if (isset($this->run['title'])) {
			return array('title' => $this->run['title'], 'id' => $this->run['id']);
		}
		return false;
	}

	/**
	 * Match function that sorts requestID to
	 * its match function
	 *
	 * @param int $requestID
	 * @param string $groupName
	 * @param string $oldName
	 *
	 * @return array|bool
	 */
	protected function _stageLookup($requestID, $groupName, $oldName)
	{

		$this->groupName = $groupName;
		$this->oldName = $oldName;
		$this->requestID = $requestID;

		if ((int) $this->requestID == -2) {
			return $this->_multiLookup($this->groupName, $this->oldName);
		}

		$groupid = $this->groups->getIDByName($groupName);
		$this->run = $this->pdo->queryDirect(
							sprintf(
								'SELECT id, title FROM predb ' .
								'WHERE requestid = %d AND group_id = %d',
								$this->requestID,
								$groupid
							)
		);
		if ($this->run !== false) {
			$total = $this->run->rowCount();
			switch ((int) $total) {
				case 1:
					foreach ($this->run as $row) {
						if (preg_match('/s\d+/i', $row['title']) && !preg_match('/s\d+e\d+/i', $row['title'])) {
							return false;
						}
						return array('title' => $row['title'], 'id' => $row['id']);
					}
					continue;
				default:
					//Prevents multiple Pre releases with the same requestid/group from being renamed to the same Pre
					return $this->_multiLookup($this->groupName, $this->oldName);
			}
		} else {
			$result = $this->_singleAltLookup($this->requestID, $this->groupName, $this->oldName);
			if (is_array($result) && is_numeric($result['id']) && $result['title'] !== '') {
				return $result;
			} else {
				return $this->_multiLookup($this->groupName, $this->oldName);
			}
		}
		return false;
	}

	/**
	 * Updates release information when a proper
	 * requestid match is found
	 *
	 * @param string $title
	 * @param int $preid
	 * @param int $determinedcat
	 * @param array $row
	 * @param int $show
	 */
	protected function _updateRelease($title, $preid, $determinedcat, $row, $show)
	{
		$this->show = $show;

		if ($determinedcat == $row['categoryid']) {
			$this->run = $this->pdo->queryExec(
							sprintf(
								'UPDATE releases SET preid = %d, reqidstatus = %d, isrenamed = 1, iscategorized = 1, searchname = %s ' .
								'WHERE id = %d',
								$preid,
								self::REQID_FOUND,
								$this->pdo->escapeString($title),
								$row['id']
							)
			);
		} else {
			$this->run = $this->pdo->queryExec(
							sprintf(
								'UPDATE releases SET rageid = -1, seriesfull = NULL, season = NULL, episode = NULL, tvtitle = NULL, tvairdate = NULL, ' .
								'imdbid = NULL, musicinfoid = NULL, consoleinfoid = NULL, bookinfoid = NULL, anidbid = NULL, ' .
								'preid = %d, reqidstatus = %d, isrenamed = 1, iscategorized = 1, searchname = %s, categoryid = %d WHERE id = %d',
								$preid,
								self::REQID_FOUND,
								$this->pdo->escapeString($title),
								$determinedcat,
								$row['id']
							)
			);
		}
		if ($row['name'] !== $title && $this->show === 1) {
				$newcatname = $this->category->getNameByID($determinedcat);
				$oldcatname = $this->category->getNameByID($row['categoryid']);

				NameFixer::echoChangedReleaseName(
									array(
										'new_name'     => $title,
										'old_name'     => $row['name'],
										'new_category' => $newcatname,
										'old_category' => $oldcatname,
										'group'        => $row['groupname'],
										'release_id'   => $row['id'],
										'method'       => 'misc/update/requestid.php'
									)
				);
		}
	}
}