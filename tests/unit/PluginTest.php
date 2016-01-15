<?php

/*
 * Composer plugin for Yii extensions
 *
 * @link      https://github.com/hiqdev/composer-extension-plugin
 * @package   composer-extension-plugin
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016, HiQDev (http://hiqdev.com/)
 */

namespace hiqdev\composerextensionplugin\tests\unit;

use Composer\Composer;
use Composer\Config;
use hiqdev\composerextensionplugin\Plugin;

/**
 * Class PluginTest.
 */
class PluginTest extends \PHPUnit_Framework_TestCase
{
    private $object;
    private $io;
    private $composer;
    private $event;
    private $packages = [];

    public function setUp()
    {
        parent::setUp();
        $this->composer = new Composer();
        $this->composer->setConfig(new Config(true, getcwd()));
        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->event = $this->getMock('Composer\Script\Event', [], ['test', $this->composer, $this->io]);

        $this->object = new Plugin();
        $this->object->setPackages($this->packages);
        $this->object->activate($this->composer, $this->io);
    }

    public function testGetPackages()
    {
        $this->assertSame($this->packages, $this->object->getPackages());
    }

    public function testGetSubscribedEvents()
    {
        $this->assertInternalType('array', $this->object->getSubscribedEvents());
    }
}
