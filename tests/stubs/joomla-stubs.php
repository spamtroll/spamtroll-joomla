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

            /** @var mixed */
            public static $container;

            /**
             * @return mixed
             */
            public static function getApplication()
            {
                return self::$application;
            }

            /**
             * @return mixed
             */
            public static function getContainer()
            {
                if (self::$container === null) {
                    throw new \RuntimeException('No container available in the test stubs.');
                }

                return self::$container;
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

namespace Joomla\CMS\Form {
    if (!class_exists(FormField::class, false)) {
        abstract class FormField
        {
            /** @var string */
            protected $type = '';

            /**
             * @return string
             */
            abstract protected function getInput();
        }
    }
}

namespace Joomla\CMS\Form\Field {
    use Joomla\CMS\Form\FormField;

    if (!class_exists(NoteField::class, false)) {
        class NoteField extends FormField
        {
            /** @var string */
            protected $type = 'Note';

            /**
             * @return string
             */
            protected function getInput()
            {
                return '';
            }

            /**
             * @return string
             */
            protected function getLabel()
            {
                return '';
            }

            /**
             * @return string
             */
            protected function getTitle()
            {
                return '';
            }
        }
    }
}

/*
 * Faithful minimal reproductions of the Joomla CMS event hierarchy the plugin
 * has to interoperate with. Verified against joomla-cms 5.3.0:
 *
 *   libraries/src/Event/AbstractEvent.php:39       extends Joomla\Event\Event
 *   libraries/src/Event/AbstractImmutableEvent.php setArgument()/offsetSet() throw
 *   libraries/src/Event/Result/ResultAware.php:65  writes $this->arguments directly,
 *                                                  "to allow this to work on immutable events"
 *   libraries/src/Event/Model/ModelEvent.php:24    extends AbstractImmutableEvent
 *   libraries/src/Event/Model/BeforeSaveEvent.php:27  implements ResultAwareInterface
 *   libraries/src/Event/User/BeforeSaveEvent.php:27   implements ResultAwareInterface
 *
 * The immutability matters: a listener that vetoes through
 * setArgument('result', …) works on Joomla 4 and throws on Joomla 5.
 */

namespace Joomla\CMS\Event\Result {
    if (!interface_exists(ResultAwareInterface::class, false)) {
        interface ResultAwareInterface
        {
            /**
             * @param mixed $data
             */
            public function addResult($data): void;
        }
    }

    if (!trait_exists(ResultAware::class, false)) {
        trait ResultAware
        {
            /**
             * @param mixed $data
             */
            public function addResult($data): void
            {
                $this->arguments['result'] ??= [];
                $this->arguments['result'][] = $data;
            }
        }
    }
}

namespace Joomla\CMS\Event {
    use Joomla\Event\Event as BaseEvent;

    if (!class_exists(AbstractEvent::class, false)) {
        abstract class AbstractEvent extends BaseEvent
        {
        }
    }

    if (!class_exists(AbstractImmutableEvent::class, false)) {
        abstract class AbstractImmutableEvent extends AbstractEvent
        {
            /**
             * @param string $name
             * @param mixed  $value
             *
             * @return $this
             */
            public function setArgument($name, $value)
            {
                throw new \BadMethodCallException(sprintf('Cannot modify an immutable event (%s).', static::class));
            }

            /**
             * @param string $name
             * @param mixed  $value
             *
             * @return void
             */
            public function offsetSet($name, $value): void
            {
                throw new \BadMethodCallException(sprintf('Cannot modify an immutable event (%s).', static::class));
            }
        }
    }
}

namespace Joomla\CMS\Event\Model {
    use Joomla\CMS\Event\AbstractImmutableEvent;
    use Joomla\CMS\Event\Result\ResultAware;
    use Joomla\CMS\Event\Result\ResultAwareInterface;

    if (!class_exists(BeforeSaveEvent::class, false)) {
        /**
         * Mirrors `onContentBeforeSave` on Joomla 5: named arguments
         * `context`, `subject`, `isNew`, `data`.
         */
        class BeforeSaveEvent extends AbstractImmutableEvent implements ResultAwareInterface
        {
            use ResultAware;
        }
    }
}

namespace Joomla\CMS\Event\User {
    use Joomla\CMS\Event\AbstractImmutableEvent;
    use Joomla\CMS\Event\Result\ResultAware;
    use Joomla\CMS\Event\Result\ResultAwareInterface;

    if (!class_exists(BeforeSaveEvent::class, false)) {
        /**
         * Mirrors `onUserBeforeSave` on Joomla 5: named arguments
         * `subject`, `isNew`, `data`.
         */
        class BeforeSaveEvent extends AbstractImmutableEvent implements ResultAwareInterface
        {
            use ResultAware;
        }
    }
}
