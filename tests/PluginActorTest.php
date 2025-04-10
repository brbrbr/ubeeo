<?php

namespace Brambring\Plugin\System\Ubeeo\Tests;

use Blc\Tests\UnitTestCase;
use Brambring\Plugin\System\Ubeeo\Extension\PluginActor;
use Joomla\CMS\Plugin\PluginHelper;

class PluginActorTest extends UnitTestCase
{
    protected function setUp(): void
    {
        $this->initApplication();
    }
    public function testCanBoot()
    {
        $plugin = $this->getApplication()->bootPlugin('ubeeo', 'system');
        $this->assertTrue(PluginHelper::isEnabled('system', 'ubeeo'));
        return $plugin;
    }

    public function testGetSubscribedEvents(): void
    {
        $events = PluginActor::getSubscribedEvents();

        $this->assertIsArray($events);
        $this->assertArrayHasKey('onError', $events);
        $this->assertArrayHasKey('onExtensionAfterSave', $events);
        $this->assertArrayHasKey('onTaskOptionsList', $events);
        $this->assertArrayHasKey('onExecuteTask', $events);
        $this->assertArrayHasKey('onAjaxUbeeo', $events);
        $this->assertArrayHasKey('onContentAfterTitle', $events);
        $this->assertArrayHasKey('onContentAfterDisplay', $events);
        $this->assertArrayHasKey('onContentBeforeDisplay', $events);
        $this->assertArrayHasKey('onContentPrepareForm', $events);
        foreach ($events as $event => $value) {
            if (\is_array($value)) {
                $method = $value[0];
            } else {
                $method = $value;
            }
            $this->assertTrue(method_exists(PluginActor::class, $method));
        }
    }

    public function testGetVacancyField(): void
    {
        $plugin = $this->testCanBoot();
        $result = $plugin->getVacancyField(true);
        $this->assertIsString($result);

        $result = $plugin->getVacancyField(false);
        $this->assertIsInt($result);
    }

    public function testGetBaseHost(): void
    {
        $plugin     = $this->testCanBoot();
        $reflection = new \ReflectionClass($plugin);
        $method     = $reflection->getMethod('getBaseHost');
        $method->setAccessible(true);

        $plugin->params->set('environment', 'DEV');
        $this->assertEquals('api.dev.ats-platform.com', $method->invoke($plugin));

        $plugin->params->set('environment', 'TEST');
        $this->assertEquals('api.acc.ats-platform.com', $method->invoke($plugin));

        $plugin->params->set('environment', 'PROD');
        $this->assertEquals('api.ats-platform.com', $method->invoke($plugin));
    }

    public function testGetApplicantResourceBaseUrl(): void
    {
        $plugin     = $this->testCanBoot();
        $reflection = new \ReflectionClass($plugin);
        $method     = $reflection->getMethod('getApplicantResourceBaseUrl');
        $method->setAccessible(true);

        $plugin->params->set('environment', 'DEV');
        $this->assertEquals('https://applicant.dev.ats-platform.com/static/', $method->invoke($plugin));

        $plugin->params->set('environment', 'TEST');
        $this->assertEquals('https://applicant.acc.ats-platform.com/static/', $method->invoke($plugin));

        $plugin->params->set('environment', 'PROD');
        $this->assertEquals('https://applicant.ats-platform.com/static/', $method->invoke($plugin));
    }

    public function testGetVacancyFeedUrl(): void
    {
        $plugin     = $this->testCanBoot();
        $reflection = new \ReflectionClass($plugin);
        $method     = $reflection->getMethod('getVacancyFeedUrl');
        $method->setAccessible(true);

        $plugin->params->set('vacancy_feed_token', '');
        $this->assertEquals('', $method->invoke($plugin));

        $plugin->params->set('vacancy_feed_token', 'test-token');
        $this->assertStringContainsString('test-token', $method->invoke($plugin));
    }

    public function testCheckParams(): void
    {
        $plugin     = $this->testCanBoot();
        $reflection = new \ReflectionClass($plugin);
        $method     = $reflection->getMethod('checkParams');
        $method->setAccessible(true);

        $plugin->params->set('cat_id', 1);
        // Valid params should return true
        $this->assertTrue($method->invoke($plugin));

        // Test invalid params
        $plugin->params->set('cat_id', 0);
        $this->expectException(\RuntimeException::class);
        $method->invoke($plugin);
    }
}
