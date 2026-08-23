<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Integration;

use Joomla\CMS\Event\Model\BeforeSaveEvent as ModelBeforeSaveEvent;
use Joomla\CMS\Event\User\BeforeSaveEvent as UserBeforeSaveEvent;
use Joomla\CMS\Factory;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event;
use Joomla\Event\EventInterface;
use Joomla\Plugin\System\Spamtroll\Extension\Spamtroll;
use Joomla\Plugin\System\Spamtroll\Service\ClientFactory;
use Joomla\Plugin\System\Spamtroll\Service\Logger;
use Joomla\Plugin\System\Spamtroll\Service\Scanner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Spamtroll\Joomla\Tests\Support\FakeTable;
use Spamtroll\Joomla\Tests\Support\InMemoryDatabase;
use Spamtroll\Joomla\Tests\Support\RecordingApplication;
use Spamtroll\Joomla\Tests\Support\StubHttpClient;
use Spamtroll\Sdk\Http\HttpResponse;

/**
 * Drives the plugin through the *real* `Joomla\Event\Dispatcher` — the same
 * one Joomla 4 and 5 use — instead of a stub.
 *
 * This is the regression guard for the bug that made every registration and
 * every article save return HTTP 500: because the plugin implements
 * `SubscriberInterface`, `CMSPlugin::registerListeners()` hands it straight to
 * `Dispatcher::addSubscriber()` and skips the legacy argument-unwrapping
 * layer. `Dispatcher::dispatch()` then calls `$listener($event)` with exactly
 * one argument, so a three-argument listener raises `ArgumentCountError`
 * before its own `try`/`catch` can run — and `ArgumentCountError` extends
 * `Error`, which Joomla's `catch (\Exception)` does not catch.
 *
 * A stubbed dispatcher cannot catch that. This one can.
 */
final class PluginDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        Factory::$application = new RecordingApplication();
    }

    protected function tearDown(): void
    {
        Factory::$application = null;
    }

    /**
     * The structural guard: every subscribed method must be callable with the
     * single argument the dispatcher supplies.
     */
    public function testEverySubscribedListenerTakesExactlyOneEventArgument(): void
    {
        foreach (Spamtroll::getSubscribedEvents() as $eventName => $method) {
            $reflection = new ReflectionMethod(Spamtroll::class, $method);

            self::assertSame(
                1,
                $reflection->getNumberOfRequiredParameters(),
                sprintf('%s listens on %s and must accept exactly one argument', $method, $eventName)
            );

            $parameter = $reflection->getParameters()[0];
            $type = $parameter->getType();

            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertTrue(
                is_a($type->getName(), EventInterface::class, true),
                sprintf('%s must accept an %s, got %s', $method, EventInterface::class, $type->getName())
            );
        }
    }

    public function testUserRegistrationIsVetoedThroughTheEventResultOnJoomla5(): void
    {
        $dispatcher = $this->dispatcherWith($this->spamScanner());

        $event = new UserBeforeSaveEvent('onUserBeforeSave', [
            'subject' => [],
            'isNew' => true,
            'data' => ['username' => 'spammer', 'email' => 'spam@example.com'],
        ]);

        $dispatcher->dispatch('onUserBeforeSave', $event);

        // User.php:789-794 — Joomla reads the verdict here, not from the
        // listener's return value.
        self::assertSame([false], $event->getArgument('result'));
        self::assertNotSame([], $this->app()->messagesOfType('error'));
    }

    /**
     * Joomla 4 has no concrete class for `onUserBeforeSave`, so
     * `EventAware::triggerEvent()` builds a plain, mutable `Joomla\Event\Event`
     * with positional arguments.
     */
    public function testUserRegistrationIsVetoedThroughTheEventResultOnJoomla4(): void
    {
        $dispatcher = $this->dispatcherWith($this->spamScanner());

        $event = new Event('onUserBeforeSave', [
            0 => [],
            1 => true,
            2 => ['username' => 'spammer', 'email' => 'spam@example.com'],
        ]);

        $dispatcher->dispatch('onUserBeforeSave', $event);

        self::assertSame([false], $event->getArgument('result'));
    }

    public function testProfileEditsAreNotScanned(): void
    {
        $http = new StubHttpClient([]);
        $dispatcher = $this->dispatcherWith($this->scannerFor($http, $this->blockingConfig()));

        $event = new UserBeforeSaveEvent('onUserBeforeSave', [
            'subject' => ['id' => 42],
            'isNew' => false,
            'data' => ['username' => 'alice', 'email' => 'alice@example.com'],
        ]);

        $dispatcher->dispatch('onUserBeforeSave', $event);

        self::assertSame(0, $http->callCount, 'isNew === false must not reach the API');
        self::assertNull($event->getArgument('result'));
    }

    public function testArticleSaveIsVetoedOnJoomla5(): void
    {
        $dispatcher = $this->dispatcherWith($this->spamScanner());

        $table = new FakeTable(['title' => 'Cheap pills', 'introtext' => 'buy now']);
        $event = new ModelBeforeSaveEvent('onContentBeforeSave', [
            'context' => 'com_content.article',
            'subject' => $table,
            'isNew' => true,
            'data' => [],
        ]);

        $dispatcher->dispatch('onContentBeforeSave', $event);

        // AdminModel.php:1293-1299.
        self::assertSame([false], $event->getArgument('result'));
        // AdminModel::save() reports the veto as $table->getError().
        self::assertNotSame('', $table->getError());
    }

    public function testArticleSaveIsVetoedOnJoomla4(): void
    {
        $dispatcher = $this->dispatcherWith($this->spamScanner());

        $table = new FakeTable(['title' => 'Cheap pills', 'introtext' => 'buy now']);
        $event = new Event('onContentBeforeSave', [
            0 => 'com_content.article',
            1 => $table,
            2 => true,
            3 => [],
        ]);

        $dispatcher->dispatch('onContentBeforeSave', $event);

        self::assertSame([false], $event->getArgument('result'));
        self::assertNotSame('', $table->getError());
    }

    /**
     * `Model\BeforeSaveEvent` is immutable on Joomla 5, so a veto written with
     * `setArgument('result', …)` would throw. Prove the plugin uses
     * `addResult()` and that the immutability is really in force.
     */
    public function testJoomla5ContentEventRejectsSetArgument(): void
    {
        $event = new ModelBeforeSaveEvent('onContentBeforeSave', [
            'context' => 'com_content.article',
            'subject' => new FakeTable(),
            'isNew' => true,
            'data' => [],
        ]);

        $this->expectException(\BadMethodCallException::class);
        $event->setArgument('result', [false]);
    }

    public function testCleanContentIsNotVetoed(): void
    {
        $dispatcher = $this->dispatcherWith($this->safeScanner());

        $table = new FakeTable(['title' => 'Release notes', 'introtext' => 'nothing to see']);
        $event = new ModelBeforeSaveEvent('onContentBeforeSave', [
            'context' => 'com_content.article',
            'subject' => $table,
            'isNew' => true,
            'data' => [],
        ]);

        $dispatcher->dispatch('onContentBeforeSave', $event);

        self::assertNull($event->getArgument('result'));
        self::assertSame('', $table->getError());
        self::assertSame([], $this->app()->messagesOfType('error'));
    }

    /**
     * When the package ships without `vendor/`, `services/provider.php` hands
     * the plugin a null scanner. The site must keep working.
     */
    public function testPluginWithoutScannerIsANoOp(): void
    {
        $dispatcher = $this->dispatcherWith(null);

        $event = new UserBeforeSaveEvent('onUserBeforeSave', [
            'subject' => [],
            'isNew' => true,
            'data' => ['username' => 'spammer', 'email' => 'spam@example.com'],
        ]);

        $dispatcher->dispatch('onUserBeforeSave', $event);

        self::assertNull($event->getArgument('result'));
    }

    /**
     * Anything unexpected inside the listener itself must fail open: the
     * subject here throws while its properties are being read.
     */
    public function testListenerFailsOpenWhenTheSubjectMisbehaves(): void
    {
        $dispatcher = $this->dispatcherWith($this->spamScanner());

        $subject = new class () {
            /**
             * @return array<string, mixed>
             */
            public function getProperties(): array
            {
                throw new \RuntimeException('table blew up');
            }
        };

        $event = new ModelBeforeSaveEvent('onContentBeforeSave', [
            'context' => 'com_content.article',
            'subject' => $subject,
            'isNew' => true,
            'data' => [],
        ]);

        $dispatcher->dispatch('onContentBeforeSave', $event);

        self::assertNull($event->getArgument('result'), 'an internal error must never block the save');
    }

    private function dispatcherWith(?Scanner $scanner): Dispatcher
    {
        $plugin = new Spamtroll(null, ['params' => '{}']);
        $plugin->setScanner($scanner);

        $dispatcher = new Dispatcher();
        // Exactly what CMSPlugin::registerListeners() does for a
        // SubscriberInterface plugin (CMSPlugin.php:226-233).
        $dispatcher->addSubscriber($plugin);

        return $dispatcher;
    }

    private function app(): RecordingApplication
    {
        $app = Factory::$application;
        self::assertInstanceOf(RecordingApplication::class, $app);

        return $app;
    }

    private function spamScanner(): Scanner
    {
        return $this->scannerFor(
            new StubHttpClient([$this->scanResponse('blocked', 30.0)]),
            $this->blockingConfig()
        );
    }

    private function safeScanner(): Scanner
    {
        return $this->scannerFor(
            new StubHttpClient([$this->scanResponse('safe', 1.0)]),
            $this->blockingConfig()
        );
    }

    private function scanResponse(string $status, float $rawScore): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'success' => true,
            'data' => [
                'status' => $status,
                'spam_score' => $rawScore,
                'symbols' => [],
            ],
        ]) ?: '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private function blockingConfig(): array
    {
        return [
            'api_key' => 'k_demo',
            'spam_threshold' => 0.70,
            'suspicious_threshold' => 0.40,
            'action_blocked' => 'block',
            'enable_user_check' => 1,
            'enable_content_check' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function scannerFor(StubHttpClient $http, array $config): Scanner
    {
        return new Scanner(new ClientFactory($http), new Logger(new InMemoryDatabase()), $config);
    }
}
