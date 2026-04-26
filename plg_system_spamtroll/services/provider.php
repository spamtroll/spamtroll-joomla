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

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $config = (array) PluginHelper::getPlugin('system', 'spamtroll');
                $params = isset($config['params']) ? $config['params'] : '';

                $paramsArray = self::decodeParams($params);

                $http = new JoomlaHttpClient();
                $factory = new ClientFactory($http);

                /** @var DatabaseInterface $db */
                $db = $container->get(DatabaseInterface::class);
                $logger = new Logger($db);

                $scanner = new Scanner($factory, $logger, $paramsArray);

                /** @var DispatcherInterface $dispatcher */
                $dispatcher = $container->get(DispatcherInterface::class);

                $plugin = new Spamtroll(
                    $dispatcher,
                    (array) $config
                );
                $plugin->setApplication(Factory::getApplication());
                $plugin->setScanner($scanner);

                return $plugin;
            }
        );
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
