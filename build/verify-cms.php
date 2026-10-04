<?php

declare(strict_types=1);

// Run only against the disposable site created for this publication check.
$site = $argv[1] ?? '';
if (!is_file($site . '/configuration.php') ||
    !str_contains(file_get_contents($site . '/configuration.php'), 'Spamtroll disposable publication verification')) {
    throw new RuntimeException('Expected the isolated publication fixture site.');
}

define('_JEXEC', 1);
define('JPATH_BASE', $site);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $site . '/includes/defines.php';
require $site . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap();
$db = $container->get(\Joomla\Database\DatabaseInterface::class);
$row = $db->setQuery("SELECT * FROM #__extensions WHERE type='plugin' AND folder='system' AND element='spamtroll'")->loadObject();
if (!$row || (json_decode($row->params, true)['api_key'] ?? '') !== '') {
    throw new RuntimeException('Expected an installed plugin without any live API key.');
}
$db->setQuery('UPDATE #__extensions SET enabled=1 WHERE extension_id=' . (int) $row->extension_id)->execute();
$plugin = $app->bootPlugin('spamtroll', 'system');
if (!$plugin instanceof \Joomla\Plugin\System\Spamtroll\Extension\Spamtroll) {
    throw new RuntimeException('The native CMS did not load the plugin class.');
}
$scanner = (new ReflectionProperty($plugin, 'scanner'))->getValue($plugin);
if (!$scanner instanceof \Joomla\Plugin\System\Spamtroll\Service\Scanner ||
    !class_exists(\Spamtroll\Sdk\Client::class)) {
    throw new RuntimeException('Installed SDK/provider did not initialize the scanner.');
}
$dispatcher = $app->getDispatcher();
$plugin->registerListeners();
$eventClass = class_exists(\Joomla\CMS\Event\User\BeforeSaveEvent::class)
    ? \Joomla\CMS\Event\User\BeforeSaveEvent::class : \Joomla\Event\Event::class;
$event = new $eventClass('onUserBeforeSave', [
    'subject' => [], 'isNew' => true,
    'data' => ['username' => 'fixture', 'email' => 'fixture@example.invalid'],
]);
$dispatcher->dispatch('onUserBeforeSave', $event);
if (in_array(false, $event->getArgument('result', []), true)) {
    throw new RuntimeException('Missing-key registration must remain allowed.');
}
if (!is_file($site . '/plugins/system/spamtroll/LICENSE') ||
    !is_file($site . '/plugins/system/spamtroll/vendor/autoload.php')) {
    throw new RuntimeException('Installed license or SDK missing.');
}
$server = $db->setQuery("SELECT location FROM #__update_sites WHERE name='Spamtroll for Joomla'")->loadResult();
if ($server !== 'https://github.com/spamtroll/spamtroll-joomla/releases/latest/download/updates.xml') {
    throw new RuntimeException('Joomla did not register the update server.');
}
$db->setQuery('SELECT COUNT(*) FROM #__spamtroll_log')->loadResult();
if (($argv[2] ?? '') === '--updates') {
    $previous = json_decode($row->manifest_cache, true, 512, JSON_THROW_ON_ERROR);
    $previous['version'] = '0.1.0';
    try {
        $db->setQuery('UPDATE #__extensions SET manifest_cache=' . $db->quote(json_encode($previous)) .
            ' WHERE extension_id=' . (int) $row->extension_id)->execute();
        \Joomla\CMS\Updater\Updater::getInstance()->findUpdates((int) $row->extension_id, 0);
        $available = $db->setQuery('SELECT version FROM #__updates WHERE extension_id=' . (int) $row->extension_id)->loadColumn();
        if ($available !== ['0.1.1']) {
            throw new RuntimeException('The real CMS updater did not discover the public 0.1.1 feed.');
        }
    } finally {
        $db->setQuery('UPDATE #__extensions SET manifest_cache=' . $db->quote($row->manifest_cache) .
            ' WHERE extension_id=' . (int) $row->extension_id)->execute();
        $db->setQuery('DELETE FROM #__updates WHERE extension_id=' . (int) $row->extension_id)->execute();
    }
}
$db->setQuery('UPDATE #__extensions SET enabled=0 WHERE extension_id=' . (int) $row->extension_id)->execute();
echo 'Real Joomla ', JVERSION, ': installed identity, provider, SDK, missing-key registration event, license, log table, update server and enable/disable verified; extension ID ', $row->extension_id, PHP_EOL;
