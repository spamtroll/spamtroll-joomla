<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Spamtroll\Service\Decision;
use Joomla\Plugin\System\Spamtroll\Service\Scanner;
use RuntimeException;
use Throwable;

/**
 * System plugin entry point.
 *
 * Subscribes to user and content lifecycle events, asks the {@see Scanner}
 * for a verdict and either lets the save proceed, queues it for moderation
 * or cancels it by raising an exception (Joomla treats a thrown exception
 * inside a `*BeforeSave` listener as a veto).
 *
 * Fail-open: any unexpected error during the listener itself — outside of
 * the scanner — is caught and the save is allowed through.
 */
final class Spamtroll extends CMSPlugin implements SubscriberInterface
{
    /** @var bool */
    protected $autoloadLanguage = true;

    private Scanner $scanner;

    public function setScanner(Scanner $scanner): void
    {
        $this->scanner = $scanner;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onUserBeforeSave' => 'onUserBeforeSave',
            'onUserBeforeDataValidation' => 'onUserBeforeDataValidation',
            'onContentBeforeSave' => 'onContentBeforeSave',
        ];
    }

    /**
     * Hooked into both user creation and edit. We only scan brand-new
     * registrations (`$isNew === true`); profile edits flow through.
     *
     * @param array<string, mixed>|object $user
     * @param array<string, mixed>        $newData
     */
    public function onUserBeforeSave($user, bool $isNew, array $newData): void
    {
        if (!$isNew) {
            return;
        }

        try {
            $username = $this->extractStringField($newData, ['username', 'name'], '');
            $email = $this->extractStringField($newData, ['email', 'email1'], '');
            $ip = $this->getClientIp();

            $decision = $this->scanner->checkUserRegistration($username, $email, $ip);
            $this->applyDecision($decision, 'registration');
        } catch (RuntimeException $e) {
            // Re-raise the veto exception so Joomla cancels the save.
            throw $e;
        } catch (Throwable $e) {
            $this->logFailOpen('onUserBeforeSave', $e);
        }
    }

    /**
     * Hooked from the front-end registration form before validation. Scanning
     * here lets us reject obvious spam without ever touching the user table.
     *
     * @param array<string, mixed>|object $data
     * @param mixed                       $form
     */
    public function onUserBeforeDataValidation($data, $form = null): void
    {
        unset($form);

        try {
            $array = $this->coerceToArray($data);
            $username = $this->extractStringField($array, ['username', 'name'], '');
            $email = $this->extractStringField($array, ['email1', 'email', 'email2'], '');
            $ip = $this->getClientIp();

            $decision = $this->scanner->checkUserRegistration($username, $email, $ip);
            $this->applyDecision($decision, 'registration');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logFailOpen('onUserBeforeDataValidation', $e);
        }
    }

    /**
     * Hooked into article / contact / generic content saves.
     *
     * @param string                      $context
     * @param array<string, mixed>|object $article
     * @param bool                        $isNew
     * @param array<string, mixed>        $data
     */
    public function onContentBeforeSave($context, $article, bool $isNew, array $data = []): bool
    {
        unset($isNew);

        try {
            $array = $this->coerceToArray($article);
            $merged = array_merge($array, $data);
            $content = $this->extractContentBody($merged);

            if ($content === '') {
                return true;
            }

            $ip = $this->getClientIp();
            $decision = $this->scanner->checkContent($content, (string) $context, $ip);
            $this->applyDecision($decision, 'content');
        } catch (RuntimeException $e) {
            // Translate the veto into a return value Joomla understands AND
            // surface the error message to the user via setError on $article.
            if (is_object($article) && method_exists($article, 'setError')) {
                $article->setError($e->getMessage());
            }
            return false;
        } catch (Throwable $e) {
            $this->logFailOpen('onContentBeforeSave', $e);
        }

        return true;
    }

    /**
     * @throws RuntimeException When the decision blocks the save.
     */
    private function applyDecision(Decision $decision, string $kind): void
    {
        unset($kind);

        $app = $this->getApplicationSafe();

        if ($decision->isBlocked()) {
            $message = Text::_('PLG_SYSTEM_SPAMTROLL_MSG_BLOCKED');
            if ($app !== null) {
                $app->enqueueMessage($message, 'error');
            }
            throw new RuntimeException($message);
        }

        if ($decision->isModerated() && $app !== null) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_SPAMTROLL_MSG_QUEUED'), 'warning');
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
     * @param array<string, mixed>|object $data
     *
     * @return array<string, mixed>
     */
    private function coerceToArray($data): array
    {
        if (is_array($data)) {
            return $data;
        }
        if (is_object($data)) {
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
