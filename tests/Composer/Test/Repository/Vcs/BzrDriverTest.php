<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Repository\Vcs;

use Composer\Config;
use Composer\Repository\Vcs\BzrDriver;

/**
 * @author Jérémy Subtil <jeremy.subtil@gmail.com>
 */
class BzrDriverTest extends \PHPUnit_Framework_TestCase
{
    public static function urlProvider()
    {
        return array(
            array('bzr+ssh://bazaar.launchpad.net/~jdoe/project/trunk', 'bzr+ssh://bazaar.launchpad.net/~jdoe/project/trunk'),
            array('file:///home/jdoe/repo', '/home/jdoe/repo'),
            array('file://home/jdoe/repo', 'file://home/jdoe/repo'),
        );
    }

    /**
     * @dataProvider urlProvider
     */
    public function testUrl($expectedUrl, $url)
    {
        $console = $this->getMock('Composer\IO\IOInterface');
        $process = $this->getMock('Composer\Util\ProcessExecutor', array('execute'));
        $config = $this->getConfig();

        $repoConfig = array(
            'url' => $url,
        );

        $bzr = new BzrDriver($repoConfig, $console, $config, $process);
        $bzr->initialize();

        $this->assertEquals($expectedUrl, $bzr->getUrl());
    }

    public static function sourceProvider()
    {
        return array(
            array('bzr+ssh://bazaar.launchpad.net/~jdoe/project/trunk', 'bzr+ssh://bazaar.launchpad.net/~jdoe/project/trunk'),
            array('file://home/jdoe/repo', 'file://home/jdoe/repo'),
        );
    }

    /**
     * @dataProvider sourceProvider
     */
    public function testSource($url, $baseUrl)
    {
        $identifier = 'dev';

        $console = $this->getMock('Composer\IO\IOInterface');
        $process = $this->getMock('Composer\Util\ProcessExecutor', array('execute'));
        $config = $this->getConfig();

        $repoConfig = array(
            'url' => $url,
        );

        $bzr = new BzrDriver($repoConfig, $console, $config, $process);
        $bzr->initialize();

        $expected = array('type' => 'bzr', 'url' => $baseUrl, 'reference' => $identifier);

        $this->assertEquals($expected, $bzr->getSource($identifier));
    }

    public function testComposerInfo()
    {
        $url = 'bzr+ssh://bazaar.launchpad.net/~jdoe/project/trunk';
        $identifier = 'dev';
        $name = 'foo/bar';
        $time = 'Wed 2013-03-20 22:00:00 +0100';
        $expectedTime = '2013-03-20 22:00:00';

        $console = $this->getMock('Composer\IO\IOInterface');
        $config = $this->getConfig(true);

        $output1 = '{ "name": "'.$name.'" }';
        $output2 = "------------------------------------------------------------\n";
        $output2 .= "revno: 888\n";
        $output2 .= "committer: John Doe <jdoe@example.com>\n";
        $output2 .= "branch nick: foobar\n";
        $output2 .= "timestamp: $time\n";
        $output2 .= "message:\n  blabla\n";

        $process = $this->getMock('Composer\Util\ProcessExecutor', array('execute'));
        $process->expects($this->at(1))
            ->method('execute')
            ->will($this->returnExecuteMock($output1, 0));
        $process->expects($this->at(2))
            ->method('execute')
            ->will($this->returnExecuteMock($output2, 0));


        $repoConfig = array(
            'url' => $url,
        );

        $bzr = new BzrDriver($repoConfig, $console, $config, $process);
        $bzr->initialize();

        // first call, no cache
        $result = $bzr->getComposerInformation($identifier);
        $expected = array(
            'name' => $name,
            'time' => $expectedTime,
        );

        $this->assertEquals($expected, $result);

        // use cache
        $result = $bzr->getComposerInformation($identifier);
        $this->assertEquals($expected, $result);
    }

    public function testTags()
    {
        $url = 'bzr+ssh://bazaar.launchpad.net/~jdoe/project/trunk';

        $console = $this->getMock('Composer\IO\IOInterface');
        $config = $this->getConfig(true);

        $output = "1.0                  100\n";
        $output .= "1.1                  110\n";
        $output .= "2.0                  155\n";

        $process = $this->getMock('Composer\Util\ProcessExecutor', array('execute'));
        $process->expects($this->at(0))
            ->method('execute')
            ->will($this->returnExecuteMock($output, 0));

        $repoConfig = array(
            'url' => $url,
        );

        $bzr = new BzrDriver($repoConfig, $console, $config, $process);
        $bzr->initialize();

        $result = $bzr->getTags();
        $expected = array(
            '1.0' => '1.0',
            '1.1' => '1.1',
            '2.0' => '2.0',
        );

        $this->assertEquals($expected, $result);
    }

    public static function supportProvider()
    {
        return array(
            array('http://foobar.org', true),
            array('https://foobar.org', true),
            array('bzr://foobar.org', true),
            array('bzr+ssh://bazaar.launchpad.net', true),
        );
    }

    /**
     * @dataProvider supportProvider
     */
    public function testSupport($url, $assertion)
    {
        if ($assertion === true) {
            $this->assertTrue(BzrDriver::supports($this->getMock('Composer\IO\IOInterface'), $url));
        } else {
            $this->assertFalse(BzrDriver::supports($this->getMock('Composer\IO\IOInterface'), $url));
        }
    }

    /**
     * @param bool $uniqueConf  True to use a new and unique conf dir
     * @return Config
     */
    private function getConfig($uniqueConf = false)
    {
        $confId = $uniqueConf ? '-'.uniqid() : '';

        $config = new Config();
        $config->merge(array(
            'config' => array(
                'home' => sys_get_temp_dir() . '/composer-test'.$confId,
            ),
        ));

        return $config;
    }

    private function returnExecuteMock($expectedOutput, $returnValue)
    {
        return $this->returnCallback(function ($command, &$output = null, $cwd = null) use ($expectedOutput, $returnValue) {
            $output = $expectedOutput;
            return $returnValue;
        });
    }
}
