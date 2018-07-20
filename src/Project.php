<?php
/**
 * created: 2017
 *
 * @author    bchatard
 * @license   MIT
 */

require_once __DIR__ . '/lib/Item.php';
require_once __DIR__ . '/lib/Result.php';
require_once __DIR__ . '/lib/ProjectName.php';
require_once __DIR__ . '/lib/Cache.php';

class Project
{

    const PATH_RECENT_PROJECT_DIRECTORIES = '/options/recentProjectDirectories.xml';
    const PATH_RECENT_PROJECTS = '/options/recentProjects.xml';
    const PATH_RECENT_SOLUTIONS = '/options/recentSolutions.xml';

    const XPATH_RECENT_PROJECT_DIRECTORIES = "//component[@name='RecentDirectoryProjectsManager']/option[@name='recentPaths']/list/option/@value";
    const XPATH_RECENT_PROJECTS = "//component[@name='RecentProjectsManager']/option[@name='recentPaths']/list/option/@value";
    const XPATH_RECENT_SOLUTIONS = "//component[@name='RiderRecentProjectsManager']/option[@name='recentPaths']/list/option/@value";

    const XPATH_PROJECT_NAME = "(//component[@name='ProjectView']/panes/pane[@id='ProjectPane']/subPane/PATH/PATH_ELEMENT/option/@value)[1]";
    const XPATH_PROJECT_NAME_ALT = "(//component[@name='ProjectView']/panes/pane[@id='ProjectPane']/subPane/expand/path/item[contains(@type, ':ProjectViewProjectNode')]/@name)[1]";
    const XPATH_PROJECT_NAME_AS = "((/project/component[@name='ChangeListManager']/ignored[contains(@path, '.iws')]/@path)[1])";
    // doesn't works: http://php.net/manual/en/simplexmlelement.xpath.php#93730
//    const XPATH_PROJECT_NAME_AS = "substring-before(((/project/component[@name='ChangeListManager']/ignored[contains(@path, '.iws')]/@path)[1]), '.iws')";


    /**
     * @var string
     */
    private $jetbrainsApp;
    /**
     * @var Result
     */
    private $result;
    /**
     * @var string
     */
    private $jetbrainsAppPath;
    /**
     * @var string
     */
    private $jetbrainsAppConfigPath;
    /**
     * @var bool
     */
    private $debug;
    /**
     * @var string
     */
    private $debugFile;

    /**
     * @var Cache
     */
    private $cache;
    /**
     * @var string
     */
    private $cacheDir;

    private $projectDirs;


    /**
     * @param string $jetbrainsApp
     * @throws \RuntimeException
     */
    public function __construct($jetbrainsApp)
    {
        date_default_timezone_set('UTC');

        error_reporting(0); // hide all errors (not safe at all, but if a warning occur, it break the response)

        $this->jetbrainsApp = $jetbrainsApp;
        $this->result = new Result();

        $this->debug = isset($_SERVER['jb_debug']) ? (bool)$_SERVER['jb_debug'] : false;

        $this->cacheDir = $_SERVER['alfred_workflow_cache'];
        if (!mkdir($this->cacheDir) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->cacheDir));
        }

        $this->projectDirs = $_SERVER['jb_project_dirs'];

        if ($this->debug) {
            $this->result->enableDebug();

            $this->debugFile = $this->cacheDir . '/debug_' . date('Ymd') . '.log';

            $this->log('PHP version: ' . PHP_VERSION);
            $this->log("Received bin: {$this->jetbrainsApp}");
        }
    }

    /**
     * @param string $query
     * @return Result
     */
    public function search($query)
    {
        $this->log("\n" . __FUNCTION__ . "({$query})");
        $query = $this->parseQuery($query);

        $hasQuery = !($query === '');
        try {
            $this->checkJetbrainsApp();
            $projectsData = $this->getProjectsData();
            $allProjects = $this->getAllProjects(explode(":", $this->projectDirs));

            $addedProjectPaths = array();
            foreach ($projectsData as $project) {
                if ($hasQuery) {
                    if (stripos($project['name'], $query) !== false
                        || stripos($project['basename'], $query) !== false
                    ) {
                        if (!isset($addedProjectPaths[$project['path']])) {
                           $addedProjectPaths[$project['path']] = TRUE;
                           $this->addProjectItem($project['name'], $project['path']);
                        }
                    }
                } else {
                    if (!isset($addedProjectPaths[$project['path']])) {
                        $addedProjectPaths[$project['path']] = TRUE;
                        $this->addProjectItem($project['name'], $project['path']);
                    }
                }
            }

            foreach ($allProjects as $project) {
                if ($hasQuery) {
                    if (stripos($project['name'], $query) !== false) {
                        if (!isset($addedProjectPaths[$project['path']])) {
                           $addedProjectPaths[$project['path']] = TRUE;
                           $this->addProjectItem($project['name'], $project['path']);
                        }
                    }
                } else {
                    if (!isset($addedProjectPaths[$project['path']])) {
                        $addedProjectPaths[$project['path']] = TRUE;
                        $this->addProjectItem($project['name'], $project['path']);
                    }
                }
            }


        } catch (\Exception $e) {
            $this->addErrorItem($e);
        }

        if (!$this->result->hasItems()) {
            if ($hasQuery) {
                $this->addNoProjectMatchItem($query);
            } else {
                $this->addNoResultItem();
            }
        }

        $this->addDebugItem();

        $this->log("Projects: {$this->result->__toString()}");

        return $this->result;
    }

    private function getAllProjects($paths) {
        $projects = [];

        foreach ($paths as $path) {
            $projects = $this->listProjectFolders($path);
            foreach ($projects as $project) {
                $tokens = explode("/", $project);
                $name = array_pop($tokens);
                $p = array();
                $p['name'] = $name;
                $p['path'] = $project;
                $projects []= $p;
            }
        }
        return $projects;
    }

    private function listProjectFolders($dir, $depth=0){

        if (is_dir($dir.'/.git')) {
            return array($dir);
        }
        if ($depth >= 6) {
            return array();
        }

        $ffs = scandir($dir);

        unset($ffs[array_search('.', $ffs, true)]);
        unset($ffs[array_search('..', $ffs, true)]);

        // prevent empty ordered elements
        if (count($ffs) < 1)
            return array();
        $dirs = [];

        foreach($ffs as $ff){
            if(is_dir($dir.'/'.$ff)) $dirs = array_merge($dirs, $this->listProjectFolders($dir.'/'.$ff, $depth+1));
        }
        return $dirs;
    }

    /**
     * @param string $query
     * @return string
     */
    private function parseQuery($query)
    {
        $query = str_replace('\ ', ' ', $query);

        return trim($query);
    }

    /**
     * @return array
     * @throws \RuntimeException
     */
    private function getProjectsData()
    {
        $this->log("\n" . __FUNCTION__);

        $projectsData = $this->cache->getProjectsData();
        if (count($projectsData)) {
            $this->log(' return projects data from cache');

            return $projectsData;
        }

        if (is_readable($this->jetbrainsAppConfigPath . self::PATH_RECENT_PROJECT_DIRECTORIES)) {
            $this->log(' Work with: ' . self::PATH_RECENT_PROJECT_DIRECTORIES);
            $file = $this->jetbrainsAppConfigPath . self::PATH_RECENT_PROJECT_DIRECTORIES;
            $xpath = self::XPATH_RECENT_PROJECT_DIRECTORIES;
        } elseif (is_readable($this->jetbrainsAppConfigPath . self::PATH_RECENT_PROJECTS)) {
            $this->log(' Work with: ' . self::PATH_RECENT_PROJECTS);
            $file = $this->jetbrainsAppConfigPath . self::PATH_RECENT_PROJECTS;
            $xpath = self::XPATH_RECENT_PROJECTS;
        } elseif (is_readable($this->jetbrainsAppConfigPath . self::PATH_RECENT_SOLUTIONS)) {
            $this->log(' Work with: ' . self::PATH_RECENT_SOLUTIONS);
            $file = $this->jetbrainsAppConfigPath . self::PATH_RECENT_SOLUTIONS;
            $xpath = self::XPATH_RECENT_SOLUTIONS;
        } else {
            throw new \RuntimeException("Can't find 'options' XML in '{$this->jetbrainsAppConfigPath}'", 100);
        }

        $projectsData = [];

        $optionXml = new SimpleXMLElement($file, null, true);
        $optionElements = $optionXml->xpath($xpath);

        $this->log(' Project Paths:');
        $this->log($optionElements);

        /** @var SimpleXMLElement $optionElement */
        foreach ($optionElements as $optionElement) {
            if ($optionElement->value) {
                $path = str_replace(
                    ['$USER_HOME$', '$APPLICATION_CONFIG_DIR$'],
                    [$_SERVER['HOME'], $this->jetbrainsAppConfigPath],
                    $optionElement->value->__toString()
                );

                $this->log("\nProcess {$path}");

                if (is_readable($path)) {
                    $name = $this->getProjectName($path);
                    if ($name) {
                        $projectsData[] = [
                            'name'     => $name,
                            'path'     => $path,
                            'basename' => basename($path),
                        ];
                    } else {
                        $this->log("  Can't find project name");
                    }
                } else {
                    $this->log(" {$path} doesn't exists");
                }
            }
        }

        $this->log('Projects Data:');
        $this->log($projectsData);
        $this->cache->setProjectsData($projectsData);

        return $projectsData;
    }

    /**
     * @param string $path
     * @return bool|string
     */
    private function getProjectName($path)
    {
        $this->log(__FUNCTION__);

        $logger = function ($message) {
            $this->log($message);
        };

        $getProjectName = new ProjectName();

        $case = [
            "{$path}/.idea/name"          => 'getViaName',
            "{$path}/.idea/.name"         => 'getViaDotName',
            "{$path}/.idea/*.iml"         => 'getViaDotIml',
            "{$path}/.idea/workspace.xml" => 'getViaWorkspace',
            $path                         => 'getViaDotSln',
        ];

        foreach ($case as $argPath => $method) {
            if ($projectName = $getProjectName->$method($argPath, $logger)) {
                return $projectName;
            }
        }

        return false;
    }

    /**
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private function checkJetbrainsApp()
    {
        $this->log("\n" . __FUNCTION__);

        $paths = [
            'RUN_PATH'    => 'jetbrainsAppPath',
            'CONFIG_PATH' => 'jetbrainsAppConfigPath',
        ];

        $handle = @fopen($this->jetbrainsApp, 'rb');
        if ($handle) {
            while (($row = fgets($handle)) !== false) {

                foreach ($paths as $var => $field) {
                    if (strpos($row, "{$var} =") === 0) {
                        $path = str_replace("{$var} = u", '', $row);
                        $path = trim($path);
                        $path = trim($path, "'");
                        if (is_dir($path) && is_readable($path)) {
                            $this->$field = $path;

                            $this->log("{$field}: {$this->$field}");

                            break;
                        }
                    }

                }

                if ($this->jetbrainsAppPath && $this->jetbrainsAppConfigPath) {
                    $this->result->addVariable('bin', $this->jetbrainsApp);

                    break;
                }

            }
            if (!$this->jetbrainsAppPath) {
                throw new \RuntimeException("Can't find application path for '{$this->jetbrainsApp}'");
            }
            if (!$this->jetbrainsAppConfigPath) {
                throw new \RuntimeException("Can't find application configuration path for '{$this->jetbrainsApp}'");
            }
        } else {
            throw new \InvalidArgumentException("Can't find command line launcher for '{$this->jetbrainsApp}'");
        }

        $cacheKey = str_replace('/', '_', $this->jetbrainsApp);
        $cacheFile = "{$this->cacheDir}/{$cacheKey}.json";

        $this->log("Cache Key: {$cacheKey}");
        $this->log("Cache File: {$cacheFile}");

        $this->cache = new Cache($cacheFile);
    }

    /**
     * @param string $name
     * @param string $path
     */
    private function addProjectItem($name, $path)
    {
        $item = new Item();
        $item->setUid($name)
             ->setTitle($name)
             ->setMatch($name)
             ->setSubtitle($path)
             ->setArg($path)
             ->setAutocomplete($name)
             ->setIcon($this->jetbrainsAppPath, 'fileicon')
             ->setText($path, $path)
             ->setVariables('name', $name);

        $this->result->addItem($item);
    }

    /**
     * @param string $query
     */
    private function addNoProjectMatchItem($query)
    {
        $item = new Item();
        $item->setUid('not_found')
             ->setTitle("No project match '{$query}'")
             ->setSubtitle("No project match '{$query}'")
             ->setArg('')
             ->setAutocomplete('')
             ->setValid(false)
             ->setIcon($this->jetbrainsAppPath, 'fileicon');

        $this->result->addItem($item);

        $this->log('No project match');
    }

    private function addNoResultItem()
    {
        $item = new Item();
        $item->setUid('none')
             ->setTitle("Can't find projects")
             ->setSubtitle('check configuration or contact developer')
             ->setArg('')
             ->setAutocomplete('')
             ->setValid(false)
             ->setIcon($this->jetbrainsAppPath, 'fileicon');

        $this->result->addItem($item);

        $this->log('No results');
    }

    /**
     * @param \Exception $e
     */
    private function addErrorItem($e)
    {
        $item = new Item();
        $item->setUid("e_{$e->getCode()}")
             ->setTitle($e->getMessage())
             ->setSubtitle('Please enable log and contact developer')
             ->setArg('')
             ->setAutocomplete('')
             ->setValid(false)
             ->setIcon(($e instanceof \RuntimeException) ? 'AlertStopIcon.icns' : 'AlertCautionIcon.icns')
             ->setText($e->getTraceAsString());

        $this->result->addItem($item);

        $this->log($e);
    }

    private function addDebugItem()
    {
        if ($this->debug) {
            $item = new Item();
            $item->setUid('debug')
                 ->setTitle("Debug file: {$this->debugFile}")
                 ->setSubtitle('Add this file to your issue - ⌘+C to get the path')
                 ->setArg('')
                 ->setAutocomplete('')
                 ->setValid(false)
                 ->setIcon('AlertNoteIcon.icns')
                 ->setText($this->debugFile);

            $this->result->addItem($item);
        }
    }

    /**
     * @param string|array|\stdClass $message
     */
    private function log($message)
    {
        if ($this->debug) {
            if ($message instanceof \Exception) {
                $message = $message->__toString();
            } elseif (is_object($message) || is_array($message)) {
                $message = print_r($message, true);
            }

            $message .= "\n";

            file_put_contents($this->debugFile, $message, FILE_APPEND);
        }
    }

}
