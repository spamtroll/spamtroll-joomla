<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Field;

use Joomla\CMS\Form\Field\NoteField;
use Joomla\CMS\Language\Text;
use Joomla\Plugin\System\Spamtroll\Service\Scanner;

\defined('_JEXEC') || die;

/**
 * Renders a warning panel summarising quota-skipped scans from the
 * trailing 7 days plus an "Upgrade your plan" CTA. Shown only when at
 * least one event has been recorded in the window so a healthy
 * account doesn't see the panel at all.
 *
 * Subclasses NoteField (rather than a fully custom widget) because
 * Joomla renders NoteField inline without a separate input column —
 * exactly the layout we want for a banner-style notice.
 *
 * Field type: `quotaskipped`. Registered via
 * `addfieldprefix="Joomla\Plugin\System\Spamtroll\Field"` on the
 * `<fields>` element of `spamtroll.xml`.
 */
class QuotaskippedField extends NoteField
{
    /**
     * @var string
     */
    protected $type = 'Quotaskipped';

    /**
     * @return string
     */
    protected function getInput()
    {
        $stats = Scanner::getQuotaSkippedStats(7);
        if ($stats['total'] === 0) {
            // Render an empty placeholder so the field doesn't generate
            // an empty grey row in the form.
            return '';
        }

        $usage = $stats['last_usage'];
        $current = isset($usage['current']) && is_numeric($usage['current']) ? (int) $usage['current'] : 0;
        $limit = isset($usage['limit']) && is_numeric($usage['limit']) ? (int) $usage['limit'] : 0;
        $plan = isset($usage['plan']) && is_string($usage['plan']) ? $usage['plan'] : 'free';

        $heading = Text::sprintf('PLG_SYSTEM_SPAMTROLL_QUOTA_SKIPPED_HEADING', (int) $stats['total']);
        $detail = ($limit > 0)
            ? Text::sprintf('PLG_SYSTEM_SPAMTROLL_QUOTA_SKIPPED_DETAIL', $current, $limit, $plan)
            : Text::_('PLG_SYSTEM_SPAMTROLL_QUOTA_SKIPPED_DETAIL_NO_USAGE');
        $cta = Text::_('PLG_SYSTEM_SPAMTROLL_QUOTA_SKIPPED_UPGRADE');

        $html = '<div class="alert alert-warning" style="margin-bottom: 12px;">'
            . '<strong>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</strong><br />'
            . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8')
            . ' <a href="https://spamtroll.io/dashboard/billing" target="_blank" rel="noopener" class="btn btn-sm btn-primary" style="margin-left: 8px;">'
            . htmlspecialchars($cta, ENT_QUOTES, 'UTF-8')
            . ' →</a>'
            . '</div>';

        return $html;
    }

    /**
     * Hide the standard label column — the panel renders end-to-end.
     *
     * @return string
     */
    protected function getLabel()
    {
        return '';
    }

    /**
     * Force the field to span the full row width even though the
     * underlying NoteField defaults to a labelled layout.
     *
     * @return string
     */
    protected function getTitle()
    {
        return '';
    }
}
