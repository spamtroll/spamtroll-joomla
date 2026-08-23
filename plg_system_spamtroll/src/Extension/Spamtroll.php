<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Spamtroll\Service\Scanner;
use Throwable;

/**
 * System plugin entry point.
 *
 * Subscribes to the user and content save events, asks the {@see Scanner} for
 * a verdict and either lets the save proceed or vetoes it.
 *
 * ## Listener signatures
 *
 * This class implements {@see SubscriberInterface}, which means Joomla skips
 * the legacy argument-unwrapping layer entirely
 * (`libraries/src/Plugin/CMSPlugin.php:226-233` in Joomla 5.3.0 and
 * `:204-212` in Joomla 4.4.13) and registers the methods straight on the
 * dispatcher. The dispatcher then calls every listener with exactly one
 * argument and discards the return value
 * (`joomla/event` 3.0.2 `src/Dispatcher.php:454`: `$listener($event);`).
 *
 * Consequently every listener here takes a single `EventInterface` and reads
 * its payload from the event object. A multi-argument signature would raise
 * `ArgumentCountError` *before* the method body runs, which no `try`/`catch`
 * inside the method can intercept — and since `ArgumentCountError` extends
 * `Error`, not `Exception`, Joomla's `catch (\Exception)` in `User::save()`
 * and `AdminModel::save()` would not catch it either. That is a hard HTTP 500
 * on every registration and every article save, the exact opposite of the
 * fail-open policy.
 *
 * ## Veto mechanism
 *
 * Returning `false` is meaningless on the subscriber path. Joomla reads the
 * verdict from the event's `result` argument:
 *
 * - `libraries/src/User/User.php:788-794` (Joomla 5.3.0)
 * - `libraries/src/MVC/Model/AdminModel.php:1293-1299` (Joomla 5.3.0)
 * - `libraries/src/Application/EventAware.php:111-114` (Joomla 4.4.13,
 *   reached through `triggerEvent()`)
 *
 * See {@see self::vetoEvent()} for how the two event shapes are handled.
 *
 * ## Fail-open
 *
 * Every listener body is wrapped in `try { … } catch (Throwable)`. A veto is
 * signalled in-band through the event, never by throwing, so no exception
 * from the scanner or the SDK can ever be mistaken for "block this".
 */
final class Spamtroll extends CMSPlugin implements SubscriberInterface
{
    /** @var bool */
    protected $autoloadLanguage = true;

    /**
     * Null when the SDK could not be autoloaded; the listeners then no-op.
     * See `services/provider.php`.
     */
    private ?Scanner $scanner = null;

    public function setScanner(?Scanner $scanner): void
    {
        $this->scanner = $scanner;
    }

    /**
     * `onUserBeforeDataValidation` is deliberately absent. It is deprecated in
     * Joomla 5 (`libraries/src/MVC/Model/FormModel.php:200-211`, removal in
     * Joomla 6), its event class `Model\BeforeValidateDataEvent` extends
     * `AbstractImmutableEvent` and is *not* `ResultAware`, so a listener has no
     * way to cancel the registration there. Scanning it as well would just
     * double the API spend for the same submission — `onUserBeforeSave` covers
     * every registration path (`RegistrationModel::register()` and
     * `UserModel::save()` both end in `User::save()`).
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onUserBeforeSave' => 'onUserBeforeSave',
            'onContentBeforeSave' => 'onContentBeforeSave',
        ];
    }

    /**
     * Fires on user creation and on profile edits. Only brand-new
     * registrations (`isNew === true`) are scanned.
     *
     * Payload — Joomla 5 (`User.php:783-787`): named arguments
     * `subject` (old user properties), `isNew`, `data` (new properties).
     * Joomla 4 (`User.php:751`): the same three, positional.
     */
    public function onUserBeforeSave(EventInterface $event): void
    {
        try {
            if ($this->scanner === null) {
                return;
            }

            if (!(bool) $this->eventArgument($event, 'isNew', 1, false)) {
                return;
            }

            $newData = $this->coerceToArray($this->eventArgument($event, 'data', 2, []));
            $username = $this->extractStringField($newData, ['username', 'name'], '');
            $email = $this->extractStringField($newData, ['email', 'email1'], '');

            $decision = $this->scanner->checkUserRegistration($username, $email, $this->getClientIp());

            if ($decision->isBlocked()) {
                // User::save() returns false and com_users wraps our enqueued
                // message; the User object carries no error of its own.
                $this->enqueue(Text::_('PLG_SYSTEM_SPAMTROLL_MSG_BLOCKED'), 'error');
                $this->vetoEvent($event);

                return;
            }

            if ($decision->isModerated()) {
                $this->enqueue(Text::_('PLG_SYSTEM_SPAMTROLL_MSG_QUEUED'), 'warning');
            }
        } catch (Throwable $e) {
            $this->logFailOpen('onUserBeforeSave', $e);
        }
    }

    /**
     * Fires from `AdminModel::save()` for articles, categories, contacts and
     * anything else built on it.
     *
     * Payload — Joomla 5 (`AdminModel.php:1287-1292`): named arguments
     * `context`, `subject` (the `Table` being stored), `isNew`, `data`.
     * Joomla 4 (`AdminModel.php:1258`): the same four, positional.
     */
    public function onContentBeforeSave(EventInterface $event): void
    {
        try {
            if ($this->scanner === null) {
                return;
            }

            $context = $this->eventArgument($event, 'context', 0, '');
            $subject = $this->eventArgument($event, 'subject', 1, null);
            $data = $this->coerceToArray($this->eventArgument($event, 'data', 3, []));

            $merged = array_merge($this->coerceToArray($subject), $data);
            $content = $this->extractContentBody($merged);

            if ($content === '') {
                return;
            }

            $decision = $this->scanner->checkContent($content, (string) $context, $this->getClientIp());

            if ($decision->isBlocked()) {
                $message = Text::_('PLG_SYSTEM_SPAMTROLL_MSG_BLOCKED');

                // AdminModel::save() surfaces the veto as
                // `$this->setError($table->getError())`, so the message has to
                // live on the subject for the user to ever see it.
                if (is_object($subject) && method_exists($subject, 'setError')) {
                    $subject->setError($message);
                }

                $this->enqueue($message, 'error');
                $this->vetoEvent($event);

                return;
            }

            if ($decision->isModerated()) {
                $this->enqueue(Text::_('PLG_SYSTEM_SPAMTROLL_MSG_QUEUED'), 'warning');
            }
        } catch (Throwable $e) {
            $this->logFailOpen('onContentBeforeSave', $e);
        }
    }

    /**
     * Cancels the save by appending `false` to the event's `result` array.
     *
     * Two shapes exist in the wild:
     *
     * - Joomla 5 dispatches concrete, *immutable* event classes
     *   (`Model\BeforeSaveEvent`, `User\BeforeSaveEvent`) that implement
     *   `ResultAwareInterface`. `setArgument('result', …)` throws on those;
     *   `addResult()` writes `$this->arguments` directly for exactly that
     *   reason (`libraries/src/Event/Result/ResultAware.php:65-67`).
     * - Joomla 4 has no concrete class for either event name
     *   (`CoreEventAware::$eventNameToConcreteClass` lists neither), so
     *   `getEventClassByEventName()` falls back to the plain, mutable
     *   `Joomla\Event\Event` and the `result` argument has to be set by hand.
     */
    private function vetoEvent(EventInterface $event): void
    {
        if ($event instanceof ResultAwareInterface) {
            $event->addResult(false);

            return;
        }

        if (!$event instanceof Event) {
            return;
        }

        $result = $event->getArgument('result', []);
        $result = is_array($result) ? $result : [];
        $result[] = false;

        $event->setArgument('result', $result);
    }

    /**
     * Reads a named event argument, falling back to the positional index used
     * by Joomla 4's `triggerEvent()` payloads.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    private function eventArgument(EventInterface $event, string $name, int $index, $default)
    {
        $value = $event->getArgument($name);

        if ($value === null) {
            // Cast for the string-typed docblock on EventInterface::getArgument();
            // PHP resolves the numeric-string key back to the integer one.
            $value = $event->getArgument((string) $index);
        }

        return $value ?? $default;
    }

    private function enqueue(string $message, string $type): void
    {
        $app = $this->getApplicationSafe();

        if ($app !== null) {
            $app->enqueueMessage($message, $type);
        }
    }

    private function getApplicationSafe(): ?CMSApplicationInterface
    {
        try {
            $app = Factory::getApplication();
            return $app instanceof CMSApplicationInterface ? $app : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getClientIp(): ?string
    {
        try {
            $app = Factory::getApplication();
            $input = method_exists($app, 'getInput') ? $app->getInput() : ($app->input ?? null);
            if ($input !== null && isset($input->server) && method_exists($input->server, 'getString')) {
                $ip = $input->server->getString('REMOTE_ADDR', '');
                return $ip !== '' ? $ip : null;
            }
        } catch (Throwable $e) {
            // ignored
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return $remote !== '' ? $remote : null;
    }

    /**
     * `Table` and `CMSObject` expose their columns through `getProperties()`;
     * `get_object_vars()` from out here would only see the public ones.
     *
     * @param mixed $data
     *
     * @return array<string, mixed>
     */
    private function coerceToArray($data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_object($data)) {
            if (method_exists($data, 'getProperties')) {
                $properties = $data->getProperties();

                if (is_array($properties)) {
                    return $properties;
                }
            }

            return get_object_vars($data);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $candidates
     */
    private function extractStringField(array $data, array $candidates, string $default): string
    {
        foreach ($candidates as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractContentBody(array $data): string
    {
        $parts = [];
        foreach (['title', 'subject', 'name'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $parts[] = $data[$key];
                break;
            }
        }
        foreach (['introtext', 'fulltext', 'description', 'message', 'body', 'text'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $parts[] = $data[$key];
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function logFailOpen(string $event, Throwable $e): void
    {
        try {
            \Joomla\CMS\Log\Log::add(
                $event . ' fail-open: ' . $e->getMessage(),
                \Joomla\CMS\Log\Log::WARNING,
                'spamtroll'
            );
        } catch (Throwable $logException) {
            error_log('Spamtroll: failed to log fail-open: ' . $logException->getMessage());
        }
    }
}
