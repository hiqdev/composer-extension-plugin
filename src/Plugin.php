<?php

/*
 * Composer plugin for Yii extensions
 *
 * @link      https://github.com/hiqdev/composer-extension-plugin
 * @package   composer-extension-plugin
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016, HiQDev (http://hiqdev.com/)
 */

namespace hiqdev\composerextensionplugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

/**
 * Plugin class.
 *
 * @author Andrii Vasyliev <sol@hiqdev.com>
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    const PACKAGE_TYPE          = 'yii2-extension';
    const EXTRA_OPTION_NAME     = 'extension-plugin';
    const YII2_EXTENSIONS_FILE  = 'yiisoft/extensions.php';
    const OUTPUT_PATH           = 'hiqdev';
    const BASE_DIR_SAMPLE       = '<base-dir>';
    const VENDOR_DIR_SAMPLE     = '<base-dir>/vendor';

    /**
     * @var PackageInterface[] the array of active composer packages
     */
    protected $packages;

    /**
     * @var string absolute path to the package base directory.
     */
    protected $baseDir;

    /**
     * @var string absolute path to vendor directory.
     */
    protected $vendorDir;

    /**
     * @var Filesystem utility
     */
    protected $filesystem;

    /**
     * @var array whole data
     */
    protected $data = [
        'extensions' => [],
        'common' => [
            'aliases' => [
                '@vendor' => self::VENDOR_DIR_SAMPLE,
            ],
        ],
    ];

    /**
     * @var Composer instance
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    public $io;

    /**
     * Initializes the plugin object with the passed $composer and $io.
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Returns list of events the plugin is subscribed to.
     * @return array list of events
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => [
                ['onPostAutoloadDump', 0],
            ],
        ];
    }

    /**
     * Simply rewrites extensions file from scratch.
     * @param Event $event
     */
    public function onPostAutoloadDump(Event $event)
    {
        $this->io->writeError('<info>Generating extensions files</info>');
        $this->processPackage($this->composer->getPackage());
        foreach ($this->getPackages() as $package) {
            if ($package instanceof \Composer\Package\CompletePackageInterface) {
                $this->processPackage($package);
            }
        }

    //  $this->saveFile(static::YII2_EXTENSIONS_FILE, $this->data['extensions']);
        $this->saveFile(static::YII2_EXTENSIONS_FILE, []);
        foreach ($this->data as $name => $data) {
            $this->saveFile($this->buildOutputPath($name), $data);
        }
    }

    public function buildOutputPath($name)
    {
        return static::OUTPUT_PATH . DIRECTORY_SEPARATOR . $name . '.php';
    }

    /**
     * Writes file.
     * @param string $file
     * @param array $data
     */
    protected function saveFile($file, array $data)
    {
        $path = $this->getVendorDir() . '/' . $file;
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $array = str_replace("'" . self::BASE_DIR_SAMPLE, '$baseDir . \'', var_export($data, true));
        file_put_contents($path, "<?php\n\n\$baseDir = dirname(dirname(__DIR__));\n\nreturn $array;\n");
    }

    /**
     * Scans the given package and collects extensions data.
     * @param PackageInterface $package
     */
    public function processPackage(PackageInterface $package)
    {
        $extra = $package->getExtra();
        $files = isset($extra[self::EXTRA_OPTION_NAME]) ? $extra[self::EXTRA_OPTION_NAME] : [];
        if ($package->getType() !== self::PACKAGE_TYPE && empty($files)) {
            return;
        }

        $extension = [
            'name'    => $package->getName(),
            'version' => $package->getVersion(),
        ];
        if ($package->getVersion() === '9999999-dev') {
            $reference = $package->getSourceReference() ?: $package->getDistReference();
            if ($reference) {
                $extension['reference'] = $reference;
            }
        }
        $this->data['extensions'][$package->getName()] = $extension;

        $this->data['common']['aliases'] = array_merge(
            $this->data['common']['aliases'],
            $this->prepareAliases($package, 'psr-0'),
            $this->prepareAliases($package, 'psr-4')
        );

        foreach ($files as $name => $path) {
            $config = $this->readExtraConfig($package, $path);
            $this->data[$name] = isset($this->data[$name]) ? static::mergeConfig($this->data[$name], $config) : $config;
        }
    }

    /**
     * Merges two or more arrays into one recursively.
     * Based on Yii2 yii\helpers\BaseArrayHelper::merge.
     * @param array $a array to be merged to
     * @param array $b array to be merged from
     * @return array the merged array
     */
    public static function mergeConfig($a, $b)
    {
        $args = func_get_args();
        $res = array_shift($args);
        foreach ($args as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $k => $v) {
                if (is_int($k)) {
                    if (isset($res[$k])) {
                        $res[] = $v;
                    } else {
                        $res[$k] = $v;
                    }
                } elseif (is_array($v) && isset($res[$k]) && is_array($res[$k])) {
                    $res[$k] = self::mergeConfig($res[$k], $v);
                } else {
                    $res[$k] = $v;
                }
            }
        }

        return $res;
    }

    /**
     * Read extra config.
     * @param string $file
     * @return array
     */
    protected function readExtraConfig(PackageInterface $package, $file)
    {
        $path = $this->preparePath($package, $file);
        if (!file_exists($path)) {
            $this->io->writeError('<error>Non existent extraconfig file</error> ' . $file . ' in ' . $package->getName());
            exit(1);
        }
        return require $path;
    }

    /**
     * Prepare aliases.
     *
     * @param PackageInterface $package
     * @param string 'psr-0' or 'psr-4'
     * @return array
     */
    protected function prepareAliases(PackageInterface $package, $psr)
    {
        $autoload = $package->getAutoload();
        if (empty($autoload[$psr])) {
            return [];
        }

        $aliases = [];
        foreach ($autoload[$psr] as $name => $path) {
            if (is_array($path)) {
                // ignore psr-4 autoload specifications with multiple search paths
                // we can not convert them into aliases as they are ambiguous
                continue;
            }
            $name = str_replace('\\', '/', trim($name, '\\'));
            $path = $this->preparePath($package, $path);
            $path = $this->substitutePath($path, $this->getBaseDir(), self::BASE_DIR_SAMPLE);
            if ('psr-0' === $psr) {
                $path .= '/' . $name;
            }
            $aliases["@$name"] = $path;
        }

        return $aliases;
    }

    /**
     * Substitute path with alias if applicable.
     * @param string $path
     * @param string $dir
     * @param string $alias
     * @return string
     */
    public function substitutePath($path, $dir, $alias)
    {
        return (substr($path, 0, strlen($dir) + 1) === $dir . '/') ? $alias . substr($path, strlen($dir)) : $path;
    }

    public function preparePath(PackageInterface $package, $path)
    {
        if (!$this->getFilesystem()->isAbsolutePath($path)) {
            $prefix = $package instanceof RootPackageInterface ? $this->getBaseDir() : $this->getVendorDir() . '/' . $package->getPrettyName();
            $path = $prefix . '/' . $path;
        }

        return $this->getFilesystem()->normalizePath($path);
    }

    /**
     * Sets [[packages]].
     * @param PackageInterface[] $packages
     */
    public function setPackages(array $packages)
    {
        $this->packages = $packages;
    }

    /**
     * Gets [[packages]].
     * @return \Composer\Package\PackageInterface[]
     */
    public function getPackages()
    {
        if ($this->packages === null) {
            $this->packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        }

        return $this->packages;
    }

    /**
     * Get absolute path to package base dir.
     * @return string
     */
    public function getBaseDir()
    {
        if ($this->baseDir === null) {
            $this->baseDir = dirname($this->getVendorDir());
        }

        return $this->baseDir;
    }

    /**
     * Get absolute path to composer vendor dir.
     * @return string
     */
    public function getVendorDir()
    {
        if ($this->vendorDir === null) {
            $dir = $this->composer->getConfig()->get('vendor-dir', '/');
            $this->vendorDir = $this->getFilesystem()->normalizePath($dir);
        }

        return $this->vendorDir;
    }

    /**
     * Getter for filesystem utility.
     * @return Filesystem
     */
    public function getFilesystem()
    {
        if ($this->filesystem === null) {
            $this->filesystem = new Filesystem();
        }

        return $this->filesystem;
    }
}
