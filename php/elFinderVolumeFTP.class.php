<?php

/**
 * Simple elFinder driver for FTP
 *
 * @author Dmitry (dio) Levashov
 * @author Cem (discofever)
 **/
class elFinderVolumeFTP extends elFinderVolumeDriver
{

    /**
     * Driver id
     * Must be started from letter and contains [a-z0-9]
     * Used as part of volume id
     *
     * @var string
     **/
    protected $driverId = 'f';

    /**
     * FTP Connection Instance
     *
     * @var resource a FTP stream
     **/
    protected $connect = null;

    /**
     * Directory for tmp files
     * If not set driver will try to use tmbDir as tmpDir
     *
     * @var string
     **/
    protected $tmpPath = '';

    /**
     * Last FTP error message
     *
     * @var string
     **/
    protected $ftpError = '';

    /**
     * FTP server output list as ftp on linux
     *
     * @var bool
     **/
    protected $ftpOsUnix;

    /**
     * FTP LIST command option
     *
     * @var string
     */
    protected $ftpListOption = '-al';


    /**
     * Is connected server Pure FTPd?
     *
     * @var bool
     */
    protected $isPureFtpd = false;

    /**
     * Is connected server with FTPS?
     *
     * @var bool
     */
    protected $isFTPS = false;

    /**
     * Tmp folder path
     *
     * @var string
     **/
    protected $tmp = '';

    /**
     * FTP command `MLST` support
     *
     * @var bool
     */
    private $MLSTsupprt = false;

    /**
     * Calling cacheDir() target path with non-MLST
     *
     * @var string
     */
    private $cacheDirTarget = '';

    /**
     * Constructor
     * Extend options with required fields
     *
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     */
    public function __construct()
    {
        $opts = [
            'host' => 'localhost',
            'user' => '',
            'pass' => '',
            'port' => 21,
            'mode' => 'passive',
            'ssl' => false,
            'path' => '/',
            'timeout' => 20,
            'owner' => true,
            'tmbPath' => '',
            'tmpPath' => '',
            'separator' => '/',
            'checkSubfolders' => -1,
            'dirMode' => 0755,
            'fileMode' => 0644,
            'rootCssClass' => 'elfinder-navbar-root-ftp',
            'ftpListOption' => '-al',
        ];
        $this->options = array_merge($this->options, $opts);
        $this->options['mimeDetect'] = 'internal';
    }

    /**
     * Prepare
     * Call from elFinder::netmout() before volume->mount()
     *
     * @param $options
     *
     * @return array volume root options
     * @author Naoki Sawada
     */
    public function netmountPrepare($options)
    {
        if (!empty($_REQUEST['encoding']) && iconv('UTF-8', (string)$_REQUEST['encoding'], '') !== false) {
            $options['encoding'] = $_REQUEST['encoding'];
            if (!empty($_REQUEST['locale']) && setlocale(LC_ALL, $_REQUEST['locale'])) {
                setlocale(LC_ALL, elFinder::$locale);
                $options['locale'] = $_REQUEST['locale'];
            }
        }
        if (!empty($_REQUEST['FTPS'])) {
            $options['ssl'] = true;
        }
        $options['statOwner'] = true;
        $options['allowChmodReadOnly'] = true;
        $options['acceptedName'] = '#^[^/\\?*:|"<>]*[^./\\?*:|"<>]$#';
        return $options;
    }

    /*********************************************************************/
    /*                        INIT AND CONFIGURE                         */
    /*********************************************************************/

    /**
     * Prepare FTP connection
     * Connect to remote server and check if credentials are correct, if so, store the connection id in $ftp_conn
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    #[\Override]
    protected function init()
    {
        if (!$this->options['host']
            || !$this->options['port']) {
            return $this->setError('Required options undefined.');
        }

        if (!$this->options['user']) {
            $this->options['user'] = 'anonymous';
            $this->options['pass'] = '';
        }
        if (!$this->options['path']) {
            $this->options['path'] = '/';
        }

        // make ney mount key
        $this->netMountKey = md5(
            implode(
                '-',
                ['ftp', $this->options['host'], $this->options['port'], $this->options['path'], $this->options['user']]
            )
        );

        if (!function_exists('ftp_connect')) {
            return $this->setError('FTP extension not loaded.');
        }

        // remove protocol from host
        $scheme = parse_url((string)$this->options['host'], PHP_URL_SCHEME);

        if ($scheme) {
            $this->options['host'] = substr((string)$this->options['host'], strlen($scheme) + 3);
        }

        // normalize root path
        $this->root = $this->options['path'] = $this->_normpath($this->options['path']);

        if (empty($this->options['alias'])) {
            $this->options['alias'] = $this->options['user'] . '@' . $this->options['host'];
            if (!empty($this->options['netkey'])) {
                elFinder::$instance->updateNetVolumeOption($this->options['netkey'], 'alias', $this->options['alias']);
            }
        }

        $this->rootName = $this->options['alias'];
        $this->options['separator'] = '/';

        if (is_null($this->options['syncChkAsTs'])) {
            $this->options['syncChkAsTs'] = true;
        }

        if (isset($this->options['ftpListOption'])) {
            $this->ftpListOption = $this->options['ftpListOption'];
        }

        return $this->needOnline? $this->connect() : true;

    }


    /**
     * Configure after successfull mount.
     *
     * @return void
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    #[\Override]
    protected function configure()
    {
        parent::configure();

        if (!empty($this->options['tmpPath'])) {
            if ((is_dir($this->options['tmpPath']) || mkdir($this->options['tmpPath'], 0755, true)) && is_writable($this->options['tmpPath'])) {
                $this->tmp = $this->options['tmpPath'];
            }
        }
        if (!$this->tmp && ($tmp = elFinder::getStaticVar('commonTempPath'))) {
            $this->tmp = $tmp;
        }

        // fallback of $this->tmp
        if (!$this->tmp && $this->tmbPathWritable) {
            $this->tmp = $this->tmbPath;
        }

        if (!$this->tmp) {
            $this->disabled[] = 'mkfile';
            $this->disabled[] = 'paste';
            $this->disabled[] = 'duplicate';
            $this->disabled[] = 'upload';
            $this->disabled[] = 'edit';
            $this->disabled[] = 'archive';
            $this->disabled[] = 'extract';
        }

        // echo $this->tmp;

    }

    /**
     * Connect to ftp server
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function connect()
    {
        $withSSL = empty($this->options['ssl']) ? '' : ' with SSL';
        if ($withSSL) {
            if (!function_exists('ftp_ssl_connect') || !($this->connect = ftp_ssl_connect($this->options['host'], $this->options['port'], $this->options['timeout']))) {
                return $this->setError('Unable to connect to FTP server ' . $this->options['host'] . $withSSL);
            }
            $this->isFTPS = true;
        } else {
            if (!($this->connect = ftp_connect($this->options['host'], $this->options['port'], $this->options['timeout']))) {
                return $this->setError('Unable to connect to FTP server ' . $this->options['host']);
            }
        }
        if (!ftp_login($this->connect, $this->options['user'], $this->options['pass'])) {
            $this->umount();
            return $this->setError('Unable to login into ' . $this->options['host'] . $withSSL);
        }

        // try switch utf8 mode
        if ($this->encoding) {
            ftp_raw($this->connect, 'OPTS UTF8 OFF');
        } else {
            ftp_raw($this->connect, 'OPTS UTF8 ON');
        }

        $help = ftp_raw($this->connect, 'HELP');
        $this->isPureFtpd = stripos(implode(' ', $help), 'Pure-FTPd') !== false;

        if (!$this->isPureFtpd) {
            // switch off extended passive mode - may be usefull for some servers
            // this command, for pure-ftpd, doesn't work and takes a timeout in some pure-ftpd versions
            ftp_raw($this->connect, 'epsv4 off');
        }
        // enter passive mode if required
        $pasv = ($this->options['mode'] == 'passive');
        if (!ftp_pasv($this->connect, $pasv)) {
            if ($pasv) {
                $this->options['mode'] = 'active';
            }
        }

        // enter root folder
        if (!ftp_chdir($this->connect, $this->root)
            || $this->root != ftp_pwd($this->connect)) {
            $this->umount();
            return $this->setError('Unable to open root folder.');
        }

        // check for MLST support
        $features = ftp_raw($this->connect, 'FEAT');
        if (!is_array($features)) {
            $this->umount();
            return $this->setError('Server does not support command FEAT.');
        }

        foreach ($features as $feat) {
            if (str_starts_with(trim($feat), 'MLST')) {
                $this->MLSTsupprt = true;
                break;
            }
        }

        return true;
    }

    /**
     * Call ftp_rawlist with option prefix
     *
     * @param string $path
     *
     * @return array
     */
    protected function ftpRawList($path)
    {
        if ($this->isPureFtpd) {
            $path = str_replace(' ', '\ ', $path);
        }
        if ($this->ftpListOption) {
            $path = $this->ftpListOption . ' ' . $path;
        }
        $res = ftp_rawlist($this->connect, $path);
        if ($res === false) {
            $res = [];
        }
        return $res;
    }

    /*********************************************************************/
    /*                               FS API                              */
    /*********************************************************************/

    /**
     * Close opened connection
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    public function umount()
    {
        $this->connect && ftp_close($this->connect);
    }


    /**
     * Parse line from ftp_rawlist() output and return file stat (array)
     *
     * @param  string $raw line from ftp_rawlist() output
     * @param         $base
     * @param bool    $nameOnly
     *
     * @return array
     * @author Dmitry Levashov
     */
    protected function parseRaw($raw, $base, $nameOnly = false)
    {
        static $now;
        static $lastyear;

        if (!$now) {
            $now = time();
            $lastyear = date('Y') - 1;
        }

        $info = preg_split("/\s+/", $raw, 8);
        if (isset($info[7])) {
            [$info[7], $info[8]] = explode(' ', $info[7], 2);
        }
        $stat = [];

        if (!isset($this->ftpOsUnix)) {
            $this->ftpOsUnix = !preg_match('/\d/', substr($info[0], 0, 1));
        }
        if (!$this->ftpOsUnix) {
            $info = $this->normalizeRawWindows($raw);
        }

        if (count($info) < 9 || $info[8] == '.' || $info[8] == '..') {
            return false;
        }

        $name = $info[8];

        if (preg_match('|(.+)\-\>(.+)|', (string)$name, $m)) {
            $name = trim($m[1]);
            // check recursive processing
            if ($this->cacheDirTarget && $this->_joinPath($base, $name) !== $this->cacheDirTarget) {
                return [];
            }
            if (!$nameOnly) {
                $target = trim($m[2]);
                if (substr($target, 0, 1) !== $this->separator) {
                    $target = $this->getFullPath($target, $base);
                }
                $target = $this->_normpath($target);
                $stat['name'] = $name;
                $stat['target'] = $target;
                return $stat;
            }
        }

        if ($nameOnly) {
            return ['name' => $name];
        }

        if (is_numeric($info[5]) && !$info[6] && !$info[7]) {
            // by normalizeRawWindows()
            $stat['ts'] = $info[5];
        } else {
            $stat['ts'] = strtotime($info[5] . ' ' . $info[6] . ' ' . $info[7]);
            if ($stat['ts'] && $stat['ts'] > $now && str_contains((string)$info[7], ':')) {
                $stat['ts'] = strtotime($info[5] . ' ' . $info[6] . ' ' . $lastyear . ' ' . $info[7]);
            }
            if (empty($stat['ts'])) {
                $stat['ts'] = strtotime($info[6] . ' ' . $info[5] . ' ' . $info[7]);
                if ($stat['ts'] && $stat['ts'] > $now && str_contains((string)$info[7], ':')) {
                    $stat['ts'] = strtotime($info[6] . ' ' . $info[5] . ' ' . $lastyear . ' ' . $info[7]);
                }
            }
        }

        if ($this->options['statOwner']) {
            $stat['owner'] = $info[2];
            $stat['group'] = $info[3];
            $stat['perm'] = substr((string)$info[0], 1);
            //
            // if not exists owner in LS ftp ==>                    isowner = true
            // if is defined as option : 'owner' => true            isowner = true
            //
            // if exist owner in LS ftp  and 'owner' => False       isowner =   result of    owner(file) == user(logged with ftp)
            //
            $stat['isowner'] = isset($stat['owner']) ? ($this->options['owner'] ? true : ($stat['owner'] == $this->options['user'])) : true;
        }

        $owner_computed = $stat['isowner'] ?? $this->options['owner'];
        $perm = $this->parsePermissions($info[0], $owner_computed);
        $stat['name'] = $name;
        $stat['mime'] = str_starts_with(strtolower((string)$info[0]), 'd') ? 'directory' : $this->mimetype(
            $stat['name'],
            true
        );
        $stat['size'] = $stat['mime'] == 'directory' ? 0 : $info[4];
        $stat['read'] = $perm['read'];
        $stat['write'] = $perm['write'];

        return $stat;
    }

    /**
     * Normalize MS-DOS style FTP LIST Raw line
     *
     * @param  string $raw line from FTP LIST (MS-DOS style)
     *
     * @return array
     * @author Naoki Sawada
     **/
    protected function normalizeRawWindows($raw)
    {
        $info = array_pad([], 9, '');
        $item = preg_replace('#\s+#', ' ', trim($raw), 3);
        [$date, $time, $size, $name] = explode(' ', (string)$item, 4);
        $format = strlen($date) === 8 ? 'm-d-yH:iA' : 'Y-m-dH:i';
        $dateObj = DateTime::createFromFormat($format, $date . $time);
        $info[5] = strtotime($dateObj->format('Y-m-d H:i'));
        $info[8] = $name;
        if ($size === '<DIR>') {
            $info[4] = 0;
            $info[0] = 'drwxr-xr-x';
        } else {
            $info[4] = (int)$size;
            $info[0] = '-rw-r--r--';
        }
        return $info;
    }

    /**
     * Parse permissions string. Return array(read => true/false, write => true/false)
     *
     * @param  string $perm                        permissions string   'rwx' + 'rwx' + 'rwx'
     *                                             ^       ^       ^
     *                                             |       |       +->   others
     *                                             |       +--------->   group
     *                                             +----------------->   owner
     *                                             The isowner parameter is computed by the caller.
     *                                             If the owner parameter in the options is true, the user is the actual owner of all objects even if che user used in the ftp Login
     *                                             is different from the file owner id.
     *                                             If the owner parameter is false to understand if the user is the file owner we compare the ftp user with the file owner id.
     * @param Boolean $isowner                     . Tell if the current user is the owner of the object.
     *
     * @return array
     * @author Dmitry (dio) Levashov
     * @author Ugo Vierucci
     */
    protected function parsePermissions($perm, $isowner = true)
    {
        $res = [];
        $parts = [];
        for ($i = 0, $l = strlen($perm); $i < $l; $i++) {
            $parts[] = substr($perm, $i, 1);
        }

        $read = ($isowner && $parts[1] == 'r') || $parts[4] == 'r' || $parts[7] == 'r';

        return [
            'read' => $parts[0] == 'd' ? $read && (($isowner && $parts[3] == 'x') || $parts[6] == 'x' || $parts[9] == 'x') : $read,
            'write' => ($isowner && $parts[2] == 'w') || $parts[5] == 'w' || $parts[8] == 'w'
        ];
    }

    /**
     * Cache dir contents
     *
     * @param  string $path dir path
     *
     * @return void
     * @author Dmitry Levashov
     **/
    #[\Override]
    protected function cacheDir($path)
    {
        $this->dirsCache[$path] = [];
        $hasDir = false;

        $list = [];
        $encPath = $this->convEncIn($path);
        foreach ($this->ftpRawList($encPath) as $raw) {
            if (($stat = $this->parseRaw($raw, $encPath))) {
                $list[] = $stat;
            }
        }
        $list = $this->convEncOut($list);
        $prefix = ($path === $this->separator) ? $this->separator : $path . $this->separator;
        $targets = [];
        foreach ($list as $stat) {
            $p = $prefix . $stat['name'];
            if (isset($stat['target'])) {
                // stat later
                $targets[$stat['name']] = $stat['target'];
            } else {
                $stat = $this->updateCache($p, $stat);
                if (empty($stat['hidden'])) {
                    if (!$hasDir && $stat['mime'] === 'directory') {
                        $hasDir = true;
                    }
                    $this->dirsCache[$path][] = $p;
                }
            }
        }
        // stat link targets
        foreach ($targets as $name => $target) {
            $stat = [];
            $stat['name'] = $name;
            $p = $prefix . $name;
            $cacheDirTarget = $this->cacheDirTarget;
            $this->cacheDirTarget = $this->convEncIn($target, true);
            if ($tstat = $this->stat($target)) {
                $stat['size'] = $tstat['size'];
                $stat['alias'] = $target;
                $stat['thash'] = $tstat['hash'];
                $stat['mime'] = $tstat['mime'];
                $stat['read'] = $tstat['read'];
                $stat['write'] = $tstat['write'];

                if (isset($tstat['ts'])) {
                    $stat['ts'] = $tstat['ts'];
                }
                if (isset($tstat['owner'])) {
                    $stat['owner'] = $tstat['owner'];
                }
                if (isset($tstat['group'])) {
                    $stat['group'] = $tstat['group'];
                }
                if (isset($tstat['perm'])) {
                    $stat['perm'] = $tstat['perm'];
                }
                if (isset($tstat['isowner'])) {
                    $stat['isowner'] = $tstat['isowner'];
                }
            } else {

                $stat['mime'] = 'symlink-broken';
                $stat['read'] = false;
                $stat['write'] = false;
                $stat['size'] = 0;

            }
            $this->cacheDirTarget = $cacheDirTarget;
            $stat = $this->updateCache($p, $stat);
            if (empty($stat['hidden'])) {
                if (!$hasDir && $stat['mime'] === 'directory') {
                    $hasDir = true;
                }
                $this->dirsCache[$path][] = $p;
            }
        }

        if (isset($this->sessionCache['subdirs'])) {
            $this->sessionCache['subdirs'][$path] = $hasDir;
        }
    }

    /**
     * Return ftp transfer mode for file
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function ftpMode($path)
    {
        return str_starts_with($this->mimetype($path), 'text/') ? FTP_ASCII : FTP_BINARY;
    }

    /*********************** paths/urls *************************/

    /**
     * Return parent directory path
     *
     * @param  string $path file path
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function _dirname($path)
    {
        $parts = explode($this->separator, trim($path, $this->separator));
        array_pop($parts);
        return $this->separator . implode($this->separator, $parts);
    }

    /**
     * Return file name
     *
     * @param  string $path file path
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function _basename($path)
    {
        $parts = explode($this->separator, trim($path, $this->separator));
        return array_pop($parts);
    }

    /**
     * Join dir name and file name and retur full path
     *
     * @param  string $dir
     * @param  string $name
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _joinPath($dir, $name)
    {
        return rtrim($dir, $this->separator) . $this->separator . $name;
    }

    /**
     * Return normalized path, this works the same as os.path.normpath() in Python
     *
     * @param  string $path path
     *
     * @return string
     * @author Troex Nevelin
     **/
    protected function _normpath($path)
    {
        if (empty($path)) {
            $path = '.';
        }
        // path must be start with /
        $path = preg_replace('|^\.\/?|', $this->separator, $path);
        $path = preg_replace('/^([^\/])/', "/$1", (string)$path);

        if ($path[0] === $this->separator) {
            $initial_slashes = true;
        } else {
            $initial_slashes = false;
        }

        if (($initial_slashes)
            && (str_starts_with((string)$path, '//'))
            && (!str_contains((string)$path, '///'))) {
            $initial_slashes = 2;
        }

        $initial_slashes = (int)$initial_slashes;

        $comps = explode($this->separator, (string)$path);
        $new_comps = [];
        foreach ($comps as $comp) {
            if (in_array($comp, ['', '.'])) {
                continue;
            }

            if (($comp != '..')
                || (!$initial_slashes && !$new_comps)
                || ($new_comps && (end($new_comps) == '..'))) {
                array_push($new_comps, $comp);
            } elseif ($new_comps) {
                array_pop($new_comps);
            }
        }
        $comps = $new_comps;
        $path = implode($this->separator, $comps);
        if ($initial_slashes) {
            $path = str_repeat($this->separator, $initial_slashes) . $path;
        }

        return $path ?: '.';
    }

    /**
     * Return file path related to root dir
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _relpath($path)
    {
        if ($path === $this->root) {
            return '';
        } else {
            if (str_starts_with($path, $this->root)) {
                return ltrim(substr($path, strlen($this->root)), $this->separator);
            } else {
                // for link
                return $path;
            }
        }
    }

    /**
     * Convert path related to root dir into real path
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _abspath($path)
    {
        if ($path === $this->separator) {
            return $this->root;
        } else {
            if ($path[0] === $this->separator) {
                // for link
                return $path;
            } else {
                return $this->_joinPath($this->root, $path);
            }
        }
    }

    /**
     * Return fake path started from root dir
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _path($path)
    {
        return $this->rootName . ($path == $this->root ? '' : $this->separator . $this->_relpath($path));
    }

    /**
     * Return true if $path is children of $parent
     *
     * @param  string $path   path to check
     * @param  string $parent parent path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _inpath($path, $parent)
    {
        return $path == $parent || str_starts_with($path, rtrim($parent, $this->separator) . $this->separator);
    }

    /***************** file stat ********************/
    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally
     * If file does not exists - returns empty array or false.
     *
     * @param  string $path file path
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _stat($path)
    {
        $outPath = $this->convEncOut($path);
        if (isset($this->cache[$outPath])) {
            return $this->convEncIn($this->cache[$outPath]);
        } else {
            $this->convEncIn();
        }
        if (!$this->MLSTsupprt) {
            if ($path === $this->root) {
                $res = [
                    'name' => $this->root,
                    'mime' => 'directory',
                    'dirs' => -1
                ];
                if ($this->needOnline && (($this->ARGS['cmd'] === 'open' && $this->ARGS['target'] === $this->encode($this->root)) || $this->isMyReload())) {
                    $check = [
                        'ts' => true,
                        'dirs' => true,
                    ];
                    $ts = 0;
                    foreach ($this->ftpRawList($path) as $str) {
                        $info = preg_split('/\s+/', (string)$str, 9);
                        if ($info[8] === '.') {
                            $info[8] = 'root';
                            if ($stat = $this->parseRaw(implode(' ', $info), $path)) {
                                unset($stat['name']);
                                $res = array_merge($res, $stat);
                                if ($res['ts']) {
                                    $ts = 0;
                                    unset($check['ts']);
                                }
                            }
                        }
                        if ($check && ($stat = $this->parseRaw($str, $path))) {
                            if (isset($stat['ts']) && !empty($stat['ts'])) {
                                $ts = max($ts, $stat['ts']);
                            }
                            if (isset($stat['dirs']) && $stat['mime'] === 'directory') {
                                $res['dirs'] = 1;
                                unset($stat['dirs']);
                            }
                            if (!$check) {
                                break;
                            }
                        }
                    }
                    if ($ts) {
                        $res['ts'] = $ts;
                    }
                    $this->cache[$outPath] = $res;
                }
                return $res;
            }

            $pPath = $this->_dirname($path);
            if ($this->_inPath($pPath, $this->root)) {
                $outPPpath = $this->convEncOut($pPath);
                if (!isset($this->dirsCache[$outPPpath])) {
                    $parentSubdirs = null;
                    if (isset($this->sessionCache['subdirs']) && isset($this->sessionCache['subdirs'][$outPPpath])) {
                        $parentSubdirs = $this->sessionCache['subdirs'][$outPPpath];
                    }
                    $this->cacheDir($outPPpath);
                    if ($parentSubdirs) {
                        $this->sessionCache['subdirs'][$outPPpath] = $parentSubdirs;
                    }
                }
            }

            $stat = $this->convEncIn($this->cache[$outPath] ?? []);
            if (!$this->mounted) {
                // dispose incomplete cache made by calling `stat` by 'startPath' option
                $this->cache = [];
            }
            return $stat;
        }
        $raw = ftp_raw($this->connect, 'MLST ' . $path);
        if (is_array($raw) && count($raw) > 1 && substr(trim($raw[0]), 0, 1) == 2) {
            $parts = explode(';', trim($raw[1]));
            array_pop($parts);
            $parts = array_map('strtolower', $parts);
            $stat = [];
            $mode = '';
            foreach ($parts as $part) {
                [$key, $val] = explode('=', $part, 2);

                switch ($key) {
                    case 'type':
                        if (str_contains($val, 'dir')) {
                            $stat['mime'] = 'directory';
                        } elseif (str_contains($val, 'link')) {
                            $stat['mime'] = 'symlink';
                            break(2);
                        } else {
                            $stat['mime'] = $this->mimetype($path);
                        }
                        break;

                    case 'size':
                        $stat['size'] = $val;
                        break;

                    case 'modify':
                        $ts = mktime(intval(substr($val, 8, 2)), intval(substr($val, 10, 2)), intval(substr($val, 12, 2)), intval(substr($val, 4, 2)), intval(substr($val, 6, 2)), substr($val, 0, 4));
                        $stat['ts'] = $ts;
                        break;

                    case 'unix.mode':
                        $mode = strval($val);
                        break;

                    case 'unix.uid':
                        $stat['owner'] = $val;
                        break;

                    case 'unix.gid':
                        $stat['group'] = $val;
                        break;

                    case 'perm':
                        $val = strtolower($val);
                        $stat['read'] = (int)preg_match('/e|l|r/', $val);
                        $stat['write'] = (int)preg_match('/w|m|c/', $val);
                        if (!preg_match('/f|d/', $val)) {
                            $stat['locked'] = 1;
                        }
                        break;
                }
            }

            if (empty($stat['mime'])) {
                return [];
            }

            // do not use MLST to get stat of symlink
            if ($stat['mime'] === 'symlink') {
                $this->MLSTsupprt = false;
                $res = $this->_stat($path);
                $this->MLSTsupprt = true;
                return $res;
            }

            if ($stat['mime'] === 'directory') {
                $stat['size'] = 0;
            }

            if ($mode) {
                $stat['perm'] = '';
                if ($mode[0] === '0') {
                    $mode = substr($mode, 1);
                }

                $perm = [];
                for ($i = 0; $i <= 2; $i++) {
                    $perm[$i] = [false, false, false];
                    $n = $mode[$i] ?? 0;

                    if ($n - 4 >= 0) {
                        $perm[$i][0] = true;
                        $n -= 4;
                        $stat['perm'] .= 'r';
                    } else {
                        $stat['perm'] .= '-';
                    }

                    if ($n - 2 >= 0) {
                        $perm[$i][1] = true;
                        $n -= 2;
                        $stat['perm'] .= 'w';
                    } else {
                        $stat['perm'] .= '-';
                    }

                    if ($n - 1 == 0) {
                        $perm[$i][2] = true;
                        $stat['perm'] .= 'x';
                    } else {
                        $stat['perm'] .= '-';
                    }
                }

                $stat['perm'] = trim($stat['perm']);
                //
                // if not exists owner in LS ftp ==>                    isowner = true
                // if is defined as option : 'owner' => true            isowner = true
                //
                // if exist owner in LS ftp  and 'owner' => False        isowner =   result of    owner(file) == user(logged with ftp)

                $owner_computed = isset($stat['owner']) ? ($this->options['owner'] ? true : ($stat['owner'] == $this->options['user'])) : true;

                $read = ($owner_computed && $perm[0][0]) || $perm[1][0] || $perm[2][0];

                $stat['read'] = $stat['mime'] == 'directory' ? $read && (($owner_computed && $perm[0][2]) || $perm[1][2] || $perm[2][2]) : $read;
                $stat['write'] = ($owner_computed && $perm[0][1]) || $perm[1][1] || $perm[2][1];

                if ($this->options['statOwner']) {
                    $stat['isowner'] = $owner_computed;
                } else {
                    unset($stat['owner'], $stat['group'], $stat['perm']);
                }
            }

            return $stat;

        }

        return [];
    }

    /**
     * Return true if path is dir and has at least one childs directory
     *
     * @param  string $path dir path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _subdirs($path)
    {

        foreach ($this->ftpRawList($path) as $str) {
            $info = preg_split('/\s+/', (string)$str, 9);
            if (!isset($this->ftpOsUnix)) {
                $this->ftpOsUnix = !preg_match('/\d/', substr($info[0], 0, 1));
            }
            if (!$this->ftpOsUnix) {
                $info = $this->normalizeRawWindows($str);
            }
            $name = isset($info[8]) ? trim($info[8]) : '';
            if ($name && $name !== '.' && $name !== '..' && str_starts_with(strtolower((string)$info[0]), 'd')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return object width and height
     * Ususaly used for images, but can be realize for video etc...
     *
     * @param  string $path file path
     * @param  string $mime file mime type
     *
     * @return string|false
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function _dimensions($path, $mime)
    {
        $ret = false;
        if ($imgsize = $this->getImageSize($path, $mime)) {
            $ret = ['dim' => $imgsize['dimensions']];
            if (!empty($imgsize['url'])) {
                $ret['url'] = $imgsize['url'];
            }
        }
        return $ret;
    }

    /******************** file/dir content *********************/

    /**
     * Return files list in directory.
     *
     * @param  string $path dir path
     *
     * @return array
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    protected function _scandir($path)
    {
        $files = [];

        foreach ($this->ftpRawList($path) as $str) {
            if (($stat = $this->parseRaw($str, $path, true))) {
                $files[] = $this->_joinPath($path, $stat['name']);
            }
        }

        return $files;
    }

    /**
     * Open file and return file pointer
     *
     * @param  string $path file path
     * @param string  $mode
     *
     * @return false|resource
     * @throws elFinderAbortException
     * @internal param bool $write open file for writing
     * @author   Dmitry (dio) Levashov
     */
    protected function _fopen($path, $mode = 'rb')
    {
        // try ftp stream wrapper
        if ($this->options['mode'] === 'passive' && ini_get('allow_url_fopen')) {
            $url = ($this->isFTPS ? 'ftps' : 'ftp') . '://' . $this->options['user'] . ':' . $this->options['pass'] . '@' . $this->options['host'] . ':' . $this->options['port'] . $path;
            if (strtolower($mode[0]) === 'w') {
                $context = stream_context_create(['ftp' => ['overwrite' => true]]);
                $fp = fopen($url, $mode, false, $context);
            } else {
                $fp = fopen($url, $mode);
            }
            if ($fp) {
                return $fp;
            }
        }

        if ($this->tmp) {
            $local = $this->getTempFile($path);
            $fp = fopen($local, 'wb');
            $ret = ftp_nb_fget($this->connect, $fp, $path, FTP_BINARY);
            while ($ret === FTP_MOREDATA) {
                elFinder::extendTimeLimit();
                $ret = ftp_nb_continue($this->connect);
            }
            if ($ret === FTP_FINISHED) {
                fclose($fp);
                $fp = fopen($local, $mode);
                return $fp;
            }
            fclose($fp);
            is_file($local) && unlink($local);
        }

        return false;
    }

    /**
     * Close opened file
     *
     * @param  resource $fp file pointer
     * @param string    $path
     *
     * @return void
     * @author Dmitry (dio) Levashov
     */
    protected function _fclose($fp, $path = '')
    {
        is_resource($fp) && fclose($fp);
        if ($path) {
            unlink($this->getTempFile($path));
        }
    }

    /********************  file/dir manipulations *************************/

    /**
     * Create dir and return created dir path or false on failed
     *
     * @param  string $path parent dir path
     * @param string  $name new directory name
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkdir($path, $name)
    {
        $path = $this->_joinPath($path, $name);
        if (ftp_mkdir($this->connect, $path) === false) {
            return false;
        }

        $this->options['dirMode'] && ftp_chmod($this->connect, $this->options['dirMode'], $path);
        return $path;
    }

    /**
     * Create file and return it's path or false on failed
     *
     * @param  string $path parent dir path
     * @param string  $name new file name
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkfile($path, $name)
    {
        if ($this->tmp) {
            $path = $this->_joinPath($path, $name);
            $local = $this->getTempFile();
            $res = touch($local) && ftp_put($this->connect, $path, $local, FTP_ASCII);
            unlink($local);
            return $res ? $path : false;
        }
        return false;
    }

    /**
     * Create symlink. FTP driver does not support symlinks.
     *
     * @param  string $target link target
     * @param  string $path   symlink path
     * @param string  $name
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     */
    protected function _symlink($target, $path, $name)
    {
        return false;
    }

    /**
     * Copy file into another file
     *
     * @param  string $source    source file path
     * @param  string $targetDir target directory path
     * @param  string $name      new file name
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _copy($source, $targetDir, $name)
    {
        $res = false;

        if ($this->tmp) {
            $local = $this->getTempFile();
            $target = $this->_joinPath($targetDir, $name);

            if (ftp_get($this->connect, $local, $source, FTP_BINARY)
                && ftp_put($this->connect, $target, $local, $this->ftpMode($target))) {
                $res = $target;
            }
            unlink($local);
        }

        return $res;
    }

    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param  string $source source file path
     * @param         $targetDir
     * @param  string $name   file name
     *
     * @return bool|string
     * @internal param string $target target dir path
     * @author   Dmitry (dio) Levashov
     */
    protected function _move($source, $targetDir, $name)
    {
        $target = $this->_joinPath($targetDir, $name);
        return ftp_rename($this->connect, $source, $target) ? $target : false;
    }

    /**
     * Remove file
     *
     * @param  string $path file path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _unlink($path)
    {
        return ftp_delete($this->connect, $path);
    }

    /**
     * Remove dir
     *
     * @param  string $path dir path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _rmdir($path)
    {
        return ftp_rmdir($this->connect, $path);
    }

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param  resource $fp   file pointer
     * @param  string   $dir  target dir path
     * @param  string   $name file name
     * @param  array    $stat file stat (required by some virtual fs)
     *
     * @return bool|string
     * @author Dmitry (dio) Levashov
     **/
    protected function _save($fp, $dir, $name, $stat)
    {
        $path = $this->_joinPath($dir, $name);
        return ftp_fput($this->connect, $path, $fp, $this->ftpMode($path))
            ? $path
            : false;
    }

    /**
     * Get file contents
     *
     * @param  string $path file path
     *
     * @return string|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function _getContents($path)
    {
        $contents = '';
        if (($fp = $this->_fopen($path))) {
            while (!feof($fp)) {
                $contents .= fread($fp, 8192);
            }
            $this->_fclose($fp, $path);
            return $contents;
        }
        return false;
    }

    /**
     * Write a string to a file
     *
     * @param  string $path    file path
     * @param  string $content new file content
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _filePutContents($path, $content)
    {
        $res = false;

        if ($this->tmp) {
            $local = $this->getTempFile();

            if (file_put_contents($local, $content, LOCK_EX) !== false
                && ($fp = fopen($local, 'rb'))) {
                $file = $this->stat($this->convEncOut($path, false));
                if (!empty($file['thash'])) {
                    $path = $this->decode($file['thash']);
                }
                clearstatcache();
                $res = ftp_fput($this->connect, $path, $fp, $this->ftpMode($path));
                fclose($fp);
            }
            file_exists($local) && unlink($local);
        }

        return $res;
    }

    /**
     * Detect available archivers
     *
     * @return void
     * @throws elFinderAbortException
     */
    protected function _checkArchivers()
    {
        $this->archivers = $this->getArchivers();
        return;
    }

    /**
     * chmod availability
     *
     * @param string $path
     * @param string $mode
     *
     * @return bool
     */
    protected function _chmod($path, $mode)
    {
        $modeOct = is_string($mode) ? octdec($mode) : octdec(sprintf("%04o", $mode));
        return ftp_chmod($this->connect, $modeOct, $path);
    }

    /**
     * Extract files from archive
     *
     * @param  string $path archive path
     * @param  array  $arc  archiver command and arguments (same as in $this->archivers)
     *
     * @return true
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     */
    protected function _extract($path, $arc)
    {
        $dir = $this->tempDir();
        if (!$dir) {
            return false;
        }

        $basename = $this->_basename($path);
        $localPath = $dir . DIRECTORY_SEPARATOR . $basename;

        if (!ftp_get($this->connect, $localPath, $path, FTP_BINARY)) {
            //cleanup
            $this->rmdirRecursive($dir);
            return false;
        }

        $this->unpackArchive($localPath, $arc);

        $this->archiveSize = 0;

        // find symlinks and check extracted items
        $checkRes = $this->checkExtractItems($dir);
        if ($checkRes['symlinks']) {
            $this->rmdirRecursive($dir);
            return $this->setError(array_merge($this->error, [elFinder::ERROR_ARC_SYMLINKS]));
        }
        $this->archiveSize = $checkRes['totalSize'];
        if ($checkRes['rmNames']) {
            foreach ($checkRes['rmNames'] as $name) {
                $this->addError(elFinder::ERROR_SAVE, $name);
            }
        }

        $filesToProcess = self::listFilesInDirectory($dir, true);

        // no files - extract error ?
        if (empty($filesToProcess)) {
            $this->rmdirRecursive($dir);
            return false;
        }

        // check max files size
        if ($this->options['maxArcFilesSize'] > 0 && $this->options['maxArcFilesSize'] < $this->archiveSize) {
            $this->rmdirRecursive($dir);
            return $this->setError(elFinder::ERROR_ARC_MAXSIZE);
        }

        $extractTo = $this->extractToNewdir; // 'auto', ture or false

        // archive contains one item - extract in archive dir
        $name = '';
        $src = $dir . DIRECTORY_SEPARATOR . $filesToProcess[0];
        if (($extractTo === 'auto' || !$extractTo) && count($filesToProcess) === 1 && is_file($src)) {
            $name = $filesToProcess[0];
        } else if ($extractTo === 'auto' || $extractTo) {
            // for several files - create new directory
            // create unique name for directory
            $src = $dir;
            $splits = elFinder::splitFileExtention(basename($path));
            $name = $splits[0];
            $test = $this->_joinPath(dirname($path), $name);
            if ($this->stat($test)) {
                $name = $this->uniqueName(dirname($path), $name, '-', false);
            }
        }

        if ($name !== '' && is_file($src)) {
            $result = $this->_joinPath(dirname($path), $name);

            if (!ftp_put($this->connect, $result, $src, FTP_BINARY)) {
                $this->rmdirRecursive($dir);
                return false;
            }
        } else {
            $dstDir = $this->_dirname($path);
            $result = [];
            if (is_dir($src) && $name) {
                $target = $this->_joinPath($dstDir, $name);
                $_stat = $this->_stat($target);
                if ($_stat) {
                    if (!$this->options['copyJoin']) {
                        if ($_stat['mime'] === 'directory') {
                            $this->delTree($target);
                        } else {
                            $this->_unlink($target);
                        }
                        $_stat = false;
                    } else {
                        $dstDir = $target;
                    }
                }
                if (!$_stat && (!$dstDir = $this->_mkdir($dstDir, $name))) {
                    $this->rmdirRecursive($dir);
                    return false;
                }
                $result[] = $dstDir;
            }
            foreach ($filesToProcess as $name) {
                $name = rtrim((string)$name, DIRECTORY_SEPARATOR);
                $src = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_dir($src)) {
                    $p = dirname($name);
                    if ($p === '.') {
                        $p = '';
                    }
                    $name = basename($name);
                    $target = $this->_joinPath($this->_joinPath($dstDir, $p), $name);
                    $_stat = $this->_stat($target);
                    if ($_stat) {
                        if (!$this->options['copyJoin']) {
                            if ($_stat['mime'] === 'directory') {
                                $this->delTree($target);
                            } else {
                                $this->_unlink($target);
                            }
                            $_stat = false;
                        }
                    }
                    if (!$_stat && (!$target = $this->_mkdir($this->_joinPath($dstDir, $p), $name))) {
                        $this->rmdirRecursive($dir);
                        return false;
                    }
                } else {
                    $target = $this->_joinPath($dstDir, $name);
                    if (!ftp_put($this->connect, $target, $src, FTP_BINARY)) {
                        $this->rmdirRecursive($dir);
                        return false;
                    }
                }
                $result[] = $target;
            }
            if (!$result) {
                $this->rmdirRecursive($dir);
                return false;
            }
        }

        is_dir($dir) && $this->rmdirRecursive($dir);

        $this->clearcache();
        return $result ?: false;
    }

    /**
     * Create archive and return its path
     *
     * @param  string $dir   target dir
     * @param  array  $files files names list
     * @param  string $name  archive name
     * @param  array  $arc   archiver options
     *
     * @return string|bool
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     */
    protected function _archive($dir, $files, $name, $arc)
    {
        // get current directory
        $cwd = getcwd();

        $tmpDir = $this->tempDir();
        if (!$tmpDir) {
            return false;
        }

        //download data
        if (!$this->ftp_download_files($dir, $files, $tmpDir)) {
            //cleanup
            $this->rmdirRecursive($tmpDir);
            return false;
        }

        $remoteArchiveFile = false;
        if ($path = $this->makeArchive($tmpDir, $files, $name, $arc)) {
            $remoteArchiveFile = $this->_joinPath($dir, $name);
            if (!ftp_put($this->connect, $remoteArchiveFile, $path, FTP_BINARY)) {
                $remoteArchiveFile = false;
            }
        }

        //cleanup
        if (!$this->rmdirRecursive($tmpDir)) {
            return false;
        }

        return $remoteArchiveFile;
    }

    /**
     * Create writable temporary directory and return path to it.
     *
     * @return string path to the new temporary directory or false in case of error.
     */
    private function tempDir()
    {
        $tempPath = tempnam($this->tmp, 'elFinder');
        if (!$tempPath) {
            $this->setError(elFinder::ERROR_CREATING_TEMP_DIR, $this->tmp);
            return false;
        }
        $success = unlink($tempPath);
        if (!$success) {
            $this->setError(elFinder::ERROR_CREATING_TEMP_DIR, $this->tmp);
            return false;
        }
        $success = mkdir($tempPath, 0700, true);
        if (!$success) {
            $this->setError(elFinder::ERROR_CREATING_TEMP_DIR, $this->tmp);
            return false;
        }
        return $tempPath;
    }

    /**
     * Gets an array of absolute remote FTP paths of files and
     * folders in $remote_directory omitting symbolic links.
     *
     * @param $remote_directory string remote FTP path to scan for file and folders recursively
     * @param $targets          array  Array of target item. `null` is to get all of items
     *
     * @return array of elements each of which is an array of two elements:
     * <ul>
     * <li>$item['path'] - absolute remote FTP path</li>
     * <li>$item['type'] - either 'f' for file or 'd' for directory</li>
     * </ul>
     */
    protected function ftp_scan_dir($remote_directory, $targets = null)
    {
        $buff = $this->ftpRawList($remote_directory);
        $items = [];
        if ($targets && is_array($targets)) {
            $targets = array_flip($targets);
        } else {
            $targets = false;
        }
        foreach ($buff as $str) {
            $info = preg_split("/\s+/", (string)$str, 9);
            if (!isset($this->ftpOsUnix)) {
                $this->ftpOsUnix = !preg_match('/\d/', substr($info[0], 0, 1));
            }
            if (!$this->ftpOsUnix) {
                $info = $this->normalizeRawWindows($str);
            }
            $type = substr((string)$info[0], 0, 1);
            $name = trim((string)$info[8]);
            if ($name !== '.' && $name !== '..' && (!$targets || isset($targets[$name]))) {
                switch ($type) {
                    case 'l' : //omit symbolic links
                    case 'd' :
                        $remote_file_path = $this->_joinPath($remote_directory, $name);
                    $item = [];
                        $item['path'] = $remote_file_path;
                        $item['type'] = 'd'; // normal file
                        $items[] = $item;
                        $items = array_merge($items, $this->ftp_scan_dir($remote_file_path));
                        break;
                    default:
                        $remote_file_path = $this->_joinPath($remote_directory, $name);
                        $item = [];
                        $item['path'] = $remote_file_path;
                        $item['type'] = 'f'; // normal file
                        $items[] = $item;
                }
            }
        }
        return $items;
    }

    /**
     * Downloads specified files from remote directory
     * if there is a directory among files it is downloaded recursively (omitting symbolic links).
     *
     * @param       $remote_directory     string remote FTP path to a source directory to download from.
     * @param array $files                list of files to download from remote directory.
     * @param       $dest_local_directory string destination folder to store downloaded files.
     *
     * @return bool true on success and false on failure.
     */
    private function ftp_download_files($remote_directory, array $files, $dest_local_directory)
    {
        $contents = $this->ftp_scan_dir($remote_directory, $files);
        if (!isset($contents)) {
            $this->setError(elFinder::ERROR_FTP_DOWNLOAD_FILE, $remote_directory);
            return false;
        }
        $remoteDirLen = strlen((string)$remote_directory);
        foreach ($contents as $item) {
            $relative_path = substr((string)$item['path'], $remoteDirLen);
            $local_path = $dest_local_directory . DIRECTORY_SEPARATOR . $relative_path;
            $success = match ($item['type']) {
                'd' => mkdir($local_path),
                'f' => ftp_get($this->connect, $local_path, $item['path'], FTP_BINARY),
                default => true,
            };
            if (!$success) {
                $this->setError(elFinder::ERROR_FTP_DOWNLOAD_FILE, $remote_directory);
                return false;
            }
        }
        return true;
    }

    /**
     * Delete local directory recursively.
     *
     * @param $dirPath string to directory to be erased.
     *
     * @return bool true on success and false on failure.
     * @throws Exception
     */
    private function deleteDir($dirPath)
    {
        if (!is_dir($dirPath)) {
            $success = unlink($dirPath);
        } else {
            $success = true;
            foreach (array_reverse(elFinderVolumeFTP::listFilesInDirectory($dirPath, false)) as $path) {
                $path = $dirPath . DIRECTORY_SEPARATOR . $path;
                if (is_link($path)) {
                    unlink($path);
                } else if (is_dir($path)) {
                    $success = rmdir($path);
                } else {
                    $success = unlink($path);
                }
                if (!$success) {
                    break;
                }
            }
            if ($success) {
                $success = rmdir($dirPath);
            }
        }
        if (!$success) {
            $this->setError(elFinder::ERROR_RM, $dirPath);
            return false;
        }
        return $success;
    }

    /**
     * Returns array of strings containing all files and folders in the specified local directory.
     *
     * @param        $dir
     * @param        $omitSymlinks
     * @param string $prefix
     *
     * @return array array of files and folders names relative to the $path
     * or an empty array if the directory $path is empty,
     * <br />
     * false if $path is not a directory or does not exist.
     * @throws Exception
     * @internal param string $path path to directory to scan.
     */
    private static function listFilesInDirectory($dir, $omitSymlinks, $prefix = '')
    {
        if (!is_dir($dir)) {
            return false;
        }
        $excludes = [".", ".."];
        $result = [];
        $files = self::localScandir($dir);
        if (!$files) {
            return [];
        }
        foreach ($files as $file) {
            if (!in_array($file, $excludes)) {
                $path = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_link($path)) {
                    if ($omitSymlinks) {
                        continue;
                    } else {
                        $result[] = $prefix . $file;
                    }
                } else if (is_dir($path)) {
                    $result[] = $prefix . $file . DIRECTORY_SEPARATOR;
                    $subs = elFinderVolumeFTP::listFilesInDirectory($path, $omitSymlinks, $prefix . $file . DIRECTORY_SEPARATOR);
                    if ($subs) {
                        $result = array_merge($result, $subs);
                    }

                } else {
                    $result[] = $prefix . $file;
                }
            }
        }
        return $result;
    }

} // END class
