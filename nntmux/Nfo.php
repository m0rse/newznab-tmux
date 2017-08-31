<?php

namespace nntmux;

use nntmux\db\DB;
use App\Models\Settings;
use nntmux\utility\Utility;
use dariusiii\rarinfo\SfvInfo;
use dariusiii\rarinfo\Par2Info;
use nntmux\processing\PostProcess;

/**
 * Class Nfo
 * Class for handling fetching/storing of NFO files.
 */
class Nfo
{
    /**
     * Instance of class Settings.
     * @var DB
     */
    public $pdo;

    /**
     * How many nfo's to process per run.
     * @var int
     */
    private $nzbs;

    /**
     * Max NFO size to process.
     * @var string|int
     */
    private $maxsize;

    /**
     * Max amount of times to retry to download a Nfo.
     * @var string|int
     */
    private $maxRetries;

    /**
     * Min NFO size to process.
     * @var string|int
     */
    private $minsize;

    /**
     * Path to temporarily store files.
     * @var string
     */
    private $tmpPath;

    /**
     * Echo to cli?
     * @var bool
     */
    protected $echo;

    const NFO_FAILED = -9; // We failed to get a NFO after admin set max retries.
	const NFO_UNPROC = -1; // Release has not been processed yet.
	const NFO_NONFO = 0; // Release has no NFO.
	const NFO_FOUND = 1; // Release has an NFO.

	/**
	 * Default constructor.
	 *
	 * @param array $options Class instance / echo to cli.
	 *
	 * @throws \Exception
	 */
    public function __construct(array $options = [])
    {
        $defaults = [
			'Echo'     => false,
			'Settings' => null,
		];
        $options += $defaults;
        $this->echo = ($options['Echo'] && NN_ECHOCLI);
        $this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
        $this->nzbs = Settings::value('..maxnfoprocessed') !== '' ? (int) Settings::value('..maxnfoprocessed') : 100;
        $this->maxsize = Settings::value('..maxsizetoprocessnfo') !== '' ? (int) Settings::value('..maxsizetoprocessnfo') : 100;
        $this->maxsize = $this->maxsize > 0 ? ('AND size < '.($this->maxsize * 1073741824)) : '';
        $this->minsize = Settings::value('..minsizetoprocessnfo') !== '' ? (int) Settings::value('..minsizetoprocessnfo') : 100;
        $this->minsize = $this->minsize > 0 ? ('AND size > '.($this->minsize * 1048576)) : '';
        $this->maxRetries = (int) Settings::value('..maxnforetries') >= 0 ? -((int) Settings::value('..maxnforetries') + 1) : self::NFO_UNPROC;
        $this->maxRetries = $this->maxRetries < -8 ? -8 : $this->maxRetries;
        $this->tmpPath = (string) Settings::value('..tmpunrarpath');
        if (! preg_match('/[\/\\\\]$/', $this->tmpPath)) {
            $this->tmpPath .= DS;
        }
    }

    /**
     * Look for a TV Show ID in a string.
     *
     * @param string  $str   The string with a Show ID.
     *
     * @return array|bool   Return array with show ID and site source or false on failure.
     */
    public function parseShowId($str)
    {
        $return = false;

        if (preg_match('/tvmaze\.com\/shows\/(\d{1,6})/i', $str, $matches)) {
            $return =
			[
				'showid' => trim($matches[1]),
				'site'   => 'tvmaze',
			];
        }

        if (preg_match('/imdb\.com\/title\/(tt\d{1,8})/i', $str, $matches)) {
            $return =
				[
					'showid' => trim($matches[1]),
					'site'   => 'imdb',
				];
        }

        if (preg_match('/thetvdb\.com\/\?tab=series&id=(\d{1,8})/i', $str, $matches)) {
            $return =
				[
					'showid' => trim($matches[1]),
					'site'   => 'thetvdb',
				];
        }

        return $return;
    }

    /**
     * Confirm this is an NFO file.
     *
     * @param string $possibleNFO The nfo.
     * @param string $guid        The guid of the release.
     *
     * @return bool               True on success, False on failure.
     * @throws \Exception
     */
    public function isNFO(&$possibleNFO, $guid): bool
    {
        if ($possibleNFO === false) {
            return false;
        }

        // Make sure it's not too big or small, size needs to be at least 12 bytes for header checking. Ignore common file types.
        $size = strlen($possibleNFO);
        if ($size < 65535 &&
			$size > 11 &&
			! preg_match(
				'/\A(\s*<\?xml|=newz\[NZB\]=|RIFF|\s*[RP]AR|.{0,10}(JFIF|matroska|ftyp|ID3))|;\s*Generated\s*by.*SF\w/i', $possibleNFO)) {
            // File/GetId3 work with files, so save to disk.
            $tmpPath = $this->tmpPath.$guid.'.nfo';
            file_put_contents($tmpPath, $possibleNFO);

            // Linux boxes have 'file' (so should Macs), Windows *can* have it too: see GNUWIN.txt in docs.
            $result = Utility::fileInfo($tmpPath);
            if (! empty($result)) {

				// Check if it's text.
                if (preg_match('/(ASCII|ISO-8859|UTF-(8|16|32).*?)\s*text/', $result)) {
                    @unlink($tmpPath);

                    return true;

                    // Or binary.
                }

                if (preg_match('/^(JPE?G|Parity|PNG|RAR|XML|(7-)?[Zz]ip)/', $result) || preg_match('/[\x00-\x08\x12-\x1F\x0B\x0E\x0F]/', $possibleNFO)) {
                    @unlink($tmpPath);

                    return false;
                }
            }

            // If above checks couldn't  make a categorical identification, Use GetId3 to check if it's an image/video/rar/zip etc..
            $check = (new \getID3())->analyze($tmpPath);
            @unlink($tmpPath);
            if (isset($check['error'])) {

				// Check if it's a par2.
                $par2info = new Par2Info();
                $par2info->setData($possibleNFO);
                if ($par2info->error) {
                    // Check if it's an SFV.
                    $sfv = new SfvInfo();
                    $sfv->setData($possibleNFO);
                    if ($sfv->error) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Add an NFO from alternate sources. ex.: PreDB, rar, zip, etc...
     *
     * @param string $nfo     The nfo.
     * @param array  $release The SQL row for this release.
     * @param NNTP   $nntp    Instance of class NNTP.
     *
     * @return bool           True on success, False on failure.
     * @throws \Exception
     */
    public function addAlternateNfo(&$nfo, $release, $nntp): bool
    {
        if ($release['id'] > 0 && $this->isNFO($nfo, $release['guid'])) {
            $check = $this->pdo->queryOneRow(sprintf('SELECT releases_id FROM release_nfos WHERE releases_id = %d', $release['id']));

            if ($check === false) {
                $this->pdo->queryInsert(
					sprintf('INSERT INTO release_nfos (nfo, releases_id) VALUES (compress(%s), %d)',
						$this->pdo->escapeString($nfo),
						$release['id']
					)
				);
            }

            $this->pdo->queryExec(sprintf('UPDATE releases SET nfostatus = %d WHERE id = %d', self::NFO_FOUND, $release['id']));

            if (! isset($release['completion'])) {
                $release['completion'] = 0;
            }

            if ((int) $release['completion'] === 0) {
                $nzbContents = new NZBContents(
					[
						'Echo' => $this->echo,
						'NNTP' => $nntp,
						'Nfo'  => $this,
						'Settings'   => $this->pdo,
						'PostProcess'   => new PostProcess(['Echo' => $this->echo, 'Settings' => $this->pdo, 'Nfo' => $this]),
					]
				);
                $nzbContents->parseNZB($release['guid'], $release['id'], $release['groups_id']);
            }

            return true;
        }

        return false;
    }

    /**
     * Get a string like this:
     * "AND r.nzbstatus = 1 AND r.nfostatus BETWEEN -8 AND -1 AND r.size < 1073741824 AND r.size > 1048576"
     * To use in a query.
     *
     * @return string
     * @throws \Exception
     * @static
     */
    public static function NfoQueryString()
    {
        $maxSize = (int) Settings::value('..maxsizetoprocessnfo');
        $minSize = (int) Settings::value('..minsizetoprocessnfo');
        $dummy = (int) Settings::value('..maxnforetries');
        $maxRetries = ($dummy >= 0 ? -($dummy + 1) : self::NFO_UNPROC);

        return sprintf(
			'AND r.nzbstatus = %d AND r.nfostatus BETWEEN %d AND %d %s %s',
			NZB::NZB_ADDED,
			($maxRetries < -8 ? -8 : $maxRetries),
			self::NFO_UNPROC,
			($maxSize > 0 ? ('AND r.size < '.($maxSize * 1073741824)) : ''),
			($minSize > 0 ? ('AND r.size > '.($minSize * 1048576)) : '')
		);
    }

    /**
     * Attempt to find NFO files inside the NZB's of releases.
     *
     * @param        $nntp
     * @param string $groupID     (optional) Group ID.
     * @param string $guidChar    (optional) First character of the release GUID (used for multi-processing).
     * @param int    $processImdb (optional) Attempt to find IMDB id's in the NZB?
     * @param int    $processTv   (optional) Attempt to find Tv id's in the NZB?
     *
     * @return int How many NFO's were processed?
     * @throws \Exception
     */
    public function processNfoFiles($nntp, $groupID = '', $guidChar = '', $processImdb = 1, $processTv = 1): int
    {
        $ret = 0;
        $guidCharQuery = ($guidChar === '' ? '' : 'AND r.leftguid = '.$this->pdo->escapeString($guidChar));
        $groupIDQuery = ($groupID === '' ? '' : 'AND r.groups_id = '.$groupID);
        $optionsQuery = self::NfoQueryString($this->pdo);

        $res = $this->pdo->query(
			sprintf('
				SELECT r.id, r.guid, r.groups_id, r.name
				FROM releases r
				WHERE 1=1 %s %s %s
				ORDER BY r.nfostatus ASC, r.postdate DESC
				LIMIT %d',
				$optionsQuery,
				$guidCharQuery,
				$groupIDQuery,
				$this->nzbs
			)
		);
        $nfoCount = count($res);

        if ($nfoCount > 0) {
            ColorCLI::doEcho(
				ColorCLI::primary(
					PHP_EOL.
					($guidChar === '' ? '' : '['.$guidChar.'] ').
					($groupID === '' ? '' : '['.$groupID.'] ').
					'Processing '.$nfoCount.
					' NFO(s), starting at '.$this->nzbs.
					' * = hidden NFO, + = NFO, - = no NFO, f = download failed.'
				)
			);

            if ($this->echo) {
                // Get count of releases per nfo status
                $nfoStats = $this->pdo->queryDirect(
					sprintf('
						SELECT r.nfostatus AS status, COUNT(r.id) AS count
						FROM releases r
						WHERE 1=1 %s %s %s
						GROUP BY r.nfostatus
						ORDER BY r.nfostatus ASC',
						$optionsQuery,
						$guidCharQuery,
						$groupIDQuery
					)
				);
                if ($nfoStats instanceof \Traversable) {
                    $outString = PHP_EOL.'Available to process';
                    foreach ($nfoStats as $row) {
                        $outString .= ', '.$row['status'].' = '.number_format($row['count']);
                    }
                    ColorCLI::doEcho(ColorCLI::header($outString.'.'));
                }
            }

            $groups = new Groups(['Settings' => $this->pdo]);
            $nzbContents = new NZBContents(
				[
					'Echo' => $this->echo,
					'NNTP' => $nntp,
					'Nfo' => $this,
					'Settings' => $this->pdo,
					'PostProcess' => new PostProcess(['Echo' => $this->echo, 'Nfo' => $this, 'Settings' => $this->pdo]),
				]
			);
            $movie = new Movie(['Echo' => $this->echo, 'Settings' => $this->pdo]);

            foreach ($res as $arr) {
                $fetchedBinary = $nzbContents->getNfoFromNZB($arr['guid'], $arr['id'], $arr['groups_id'], $groups->getNameByID($arr['groups_id']));
                if ($fetchedBinary !== false) {
                    // Insert nfo into database.
                    $cp = 'COMPRESS(%s)';
                    $nc = $this->pdo->escapeString($fetchedBinary);

                    $ckreleaseid = $this->pdo->queryOneRow(sprintf('SELECT releases_id FROM release_nfos WHERE releases_id = %d', $arr['id']));
                    if (! isset($ckreleaseid['id'])) {
                        $this->pdo->queryInsert(sprintf('INSERT INTO release_nfos (nfo, releases_id) VALUES ('.$cp.', %d)', $nc, $arr['id']));
                    }
                    $this->pdo->queryExec(sprintf('UPDATE releases SET nfostatus = %d WHERE id = %d', self::NFO_FOUND, $arr['id']));
                    $ret++;
                    $movie->doMovieUpdate($fetchedBinary, 'nfo', $arr['id'], $processImdb);

                    // If set scan for tv info.
                    if ($processTv === 1) {
                        (new PostProcess(['Echo' => $this->echo, 'Settings' => $this->pdo]))->processTv($groupID, $guidChar, $processTv);
                    }
                }
            }
        }

        // Remove nfo that we cant fetch after 5 attempts.
        $releases = $this->pdo->queryDirect(
			sprintf(
				'SELECT r.id
				FROM releases r
				WHERE r.nzbstatus = %d
				AND r.nfostatus < %d AND r.nfostatus > %d %s %s',
				NZB::NZB_ADDED,
				$this->maxRetries,
				self::NFO_FAILED,
				$groupIDQuery,
				$guidCharQuery
			)
		);

        if ($releases instanceof \Traversable) {
            foreach ($releases as $release) {
                // remove any releasenfo for failed
                $this->pdo->queryExec(sprintf('
					DELETE FROM release_nfos WHERE nfo IS NULL AND releases_id = %d',
						$release['id']
					)
				);

                // set release.nfostatus to failed
                $this->pdo->queryExec(sprintf('
					UPDATE releases r SET r.nfostatus = %d WHERE r.id = %d',
						self::NFO_FAILED,
						$release['id']
					)
				);
            }
        }

        if ($this->echo) {
            if ($nfoCount > 0) {
                echo PHP_EOL;
            }
            if ($ret > 0) {
                ColorCLI::doEcho($ret.' NFO file(s) found/processed.', true);
            }
        }

        return $ret;
    }
}
