<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Spamtroll\Extension\Spamtroll;
use Joomla\Plugin\System\Spamtroll\Service\ClientFactory;
use Joomla\Plugin\System\Spamtroll\Service\JoomlaHttpClient;
use Joomla\Plugin\System\Spamtroll\Service\Logger;
use Joomla\Plugin\System\Spamtroll\Service\Scanner;

// The Spamtroll PHP SDK ships inside the installed package (see
// build/build-package.sh). Joomla's autoloader only knows the plugin's own
// namespace, so the SDK has to be registered here — before anything touches
// JoomlaHttpClient, which `implements Spamtroll\Sdk\Http\HttpClientInterface`
// and would otherwise fatal with "Interface not found" at class-load time.
// This provider runs during application bootstrap for every request, front and
// back end, so an uncaught error here takes the whole site down.
if (!class_exists(\Spamtroll\Sdk\Client::class)) {
    $spamtrollAutoload = __DIR__ . '/../vendor/autoload.php';

    if (is_file($spamtrollAutoload)) {
        require_once $spamtrollAutoload;
    }
}

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $config = (array) PluginHelper::getPlugin('system', 'spamtroll');

                /** @var DispatcherInterface $dispatcher */
                $dispatcher = $container->get(DispatcherInterface::class);

                $plugin = new Spamtroll($dispatcher, $config);
                $plugin->setApplication(Factory::getApplication());
                $plugin->setScanner(self::createScanner($container, $config));

                return $plugin;
            }
        );
    }

    /**
     * Returns null when the SDK is unavailable — a package built without
     * `vendor/`, or a half-finished upgrade. The plugin then registers its
     * listeners as usual and they no-op, which is the fail-open outcome the
     * integration policy demands. Blowing up here would break every request.
     *
     * @param array<string, mixed> $config
     */
    private static function createScanner(Container $container, array $config): ?Scanner
    {
        if (!interface_exists(\Spamtroll\Sdk\Http\HttpClientInterface::class)) {
            self::logMissingSdk();

            return null;
        }

        try {
            $paramsArray = self::decodeParams($config['params'] ?? '');

            $http = new JoomlaHttpClient();
            $factory = new ClientFactory($http);

            /** @var DatabaseInterface $db */
            $db = $container->get(DatabaseInterface::class);
            $logger = new Logger($db);

            return new Scanner($factory, $logger, $paramsArray);
        } catch (\Throwable $e) {
            self::logFailure($e);

            return null;
        }
    }

    private static function logMissingSdk(): void
    {
        self::log(
            'Spamtroll PHP SDK not found — expected vendor/autoload.php in the plugin directory. '
            . 'Scanning is disabled; all content is allowed through.'
        );
    }

    private static function logFailure(\Throwable $e): void
    {
        self::log('Spamtroll scanner could not be built: ' . $e->getMessage());
    }

    private static function log(string $message): void
    {
        try {
            \Joomla\CMS\Log\Log::add($message, \Joomla\CMS\Log\Log::WARNING, 'spamtroll');
        } catch (\Throwable $e) {
            error_log('Spamtroll: ' . $message);
        }
    }

    /**
     * @param mixed $params
     *
     * @return array<string, mixed>
     */
    private static function decodeParams($params): array
    {
        if (is_array($params)) {
            return $params;
        }
        if (is_string($params) && $params !== '') {
            $decoded = json_decode($params, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_object($params)) {
            return json_decode(json_encode($params) ?: '{}', true) ?? [];
        }
        return [];
    }
};
