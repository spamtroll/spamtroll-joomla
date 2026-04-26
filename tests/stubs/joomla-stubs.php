<?php

declare(strict_types=1);

/*
 * Lightweight stubs for the subset of Joomla 4 / 5 classes that the plugin
 * touches. Joomla isn't installable from Composer in the way Drupal /
 * WordPress are, so these stubs let PHPStan analyse the code and let the
 * unit suite instantiate the relevant classes without dragging the whole
 * framework into the project.
 *
 * They intentionally omit behaviour: they only declare the surface area
 * the plugin uses.
 */

namespace Joomla\CMS\Log {
    if (!class_exists(Log::class, false)) {
        class Log
        {
            public const ALL = 30719;
            public const EMERGENCY = 1;
            public const ALERT = 2;
            public const CRITICAL = 4;
            public const ERROR = 8;
            public const WARNING = 16;
            public const NOTICE = 32;
            public const INFO = 64;
            public const DEBUG = 128;

            /** @var array<int, array{0: string, 1: int, 2: string}> */
            public static array $entries = [];

            public static function add(string $message, int $priority = self::INFO, string $category = ''): void
            {
                self::$entries[] = [$message, $priority, $category];
            }
        }
    }
}

namespace Joomla\CMS\Language {
    if (!class_exists(Text::class, false)) {
        class Text
        {
            public static function _(string $key): string
            {
                return $key;
            }

            public static function sprintf(string $key, mixed ...$args): string
            {
                return $key;
            }
        }
    }
}

namespace Joomla\CMS\Extension {
    if (!interface_exists(PluginInterface::class, false)) {
        interface PluginInterface
        {
        }
    }
}

namespace Joomla\CMS\Plugin {
    use Joomla\CMS\Extension\PluginInterface;

    if (!class_exists(CMSPlugin::class, false)) {
        abstract class CMSPlugin implements PluginInterface
        {
            /** @var bool */
            protected $autoloadLanguage = false;

            /** @var array<string, mixed> */
            protected $params = [];

            /** @var mixed */
            protected $app;

            /**
             * @param mixed                $subject
             * @param array<string, mixed> $config
             */
            public function __construct($subject = null, array $config = [])
            {
                if (isset($config['params'])) {
                    $params = $config['params'];
                    if (is_string($params)) {
                        $decoded = json_decode($params, true);
                        $params = is_array($decoded) ? $decoded : [];
                    }
                    $this->params = is_array($params) ? $params : [];
                }
            }

            /**
             * @param mixed $app
             */
            public function setApplication($app): void
            {
                $this->app = $app;
            }
        }
    }

    if (!class_exists(PluginHelper::class, false)) {
        class PluginHelper
        {
            /**
             * @return array<string, mixed>|object
             */
            public static function getPlugin(string $type, string $plugin): array|object
            {
                unset($type, $plugin);
                return [
                    'name' => 'spamtroll',
                    'type' => 'system',
                    'params' => '{}',
                ];
            }
        }
    }
}

namespace Joomla\CMS\Application {
    if (!interface_exists(CMSApplicationInterface::class, false)) {
        interface CMSApplicationInterface
        {
            public function enqueueMessage(string $msg, string $type = 'info'): void;
        }
    }
}

namespace Joomla\CMS {
    if (!class_exists(Factory::class, false)) {
        class Factory
        {
            /** @var mixed */
            public static $application;

            /**
             * @return mixed
             */
            public static function getApplication()
            {
                return self::$application;
            }
        }
    }
}

namespace Joomla\CMS\Http {
    if (!class_exists(Http::class, false)) {
        class Http
        {
            /**
             * @param array<string, string> $headers
             *
             * @return object{code: int, body: string, headers: array<string, string>}
             */
            public function get(string $url, array $headers = [], int $timeout = 5): object
            {
                unset($url, $headers, $timeout);
                return (object) ['code' => 200, 'body' => '{}', 'headers' => []];
            }

            /**
             * @param array<string, string> $headers
             *
             * @return object{code: int, body: string, headers: array<string, string>}
             */
            public function post(string $url, string $body, array $headers = [], int $timeout = 5): object
            {
                unset($url, $body, $headers, $timeout);
                return (object) ['code' => 200, 'body' => '{}', 'headers' => []];
            }

            /**
             * @param array<string, string> $headers
             *
             * @return object{code: int, body: string, headers: array<string, string>}
             */
            public function put(string $url, string $body, array $headers = [], int $timeout = 5): object
            {
                unset($url, $body, $headers, $timeout);
                return (object) ['code' => 200, 'body' => '{}', 'headers' => []];
            }

            /**
             * @param array<string, string> $headers
             *
             * @return object{code: int, body: string, headers: array<string, string>}
             */
            public function delete(string $url, array $headers = [], int $timeout = 5): object
            {
                unset($url, $headers, $timeout);
                return (object) ['code' => 200, 'body' => '{}', 'headers' => []];
            }
        }
    }

    if (!class_exists(HttpFactory::class, false)) {
        class HttpFactory
        {
            public static ?Http $instance = null;

            public static function getHttp(): Http
            {
                if (self::$instance === null) {
                    self::$instance = new Http();
                }
                return self::$instance;
            }
        }
    }
}

namespace Joomla\Event {
    if (!interface_exists(SubscriberInterface::class, false)) {
        interface SubscriberInterface
        {
            /**
             * @return array<string, string|array{0: string, 1: int}>
             */
            public static function getSubscribedEvents(): array;
        }
    }

    if (!interface_exists(DispatcherInterface::class, false)) {
        interface DispatcherInterface
        {
        }
    }
}

namespace Joomla\DI {
    if (!interface_exists(ServiceProviderInterface::class, false)) {
        interface ServiceProviderInterface
        {
            public function register(Container $container): void;
        }
    }

    if (!class_exists(Container::class, false)) {
        class Container
        {
            /** @var array<string, mixed> */
            private array $bindings = [];

            public function set(string $key, mixed $value): void
            {
                $this->bindings[$key] = $value;
            }

            public function get(string $key): mixed
            {
                if (!isset($this->bindings[$key])) {
                    throw new \RuntimeException('Unknown binding: ' . $key);
                }
                $value = $this->bindings[$key];
                return is_callable($value) ? $value($this) : $value;
            }
        }
    }
}

namespace Joomla\Database {
    if (!interface_exists(DatabaseInterface::class, false)) {
        interface DatabaseInterface
        {
            public function getQuery(bool $new = false): mixed;

            public function quote(string $value): string;

            public function quoteName(string $name): string;

            public function setQuery(mixed $query): mixed;

            public function execute(): bool;

            public function getAffectedRows(): int;
        }
    }
}
