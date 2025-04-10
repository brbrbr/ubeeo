<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_scheduler
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Brambring\Plugin\System\Ubeeo\Field;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\NoteField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Field to override the text field layout to add a copy-text button, used in the com_scheduler
 * configuration form.
 * This field class is only needed because the layout file is in a non-global directory, so this should
 * be made redundant and removed if/once the layout is shifted to `JPATH_SITE/layout/`
 *
 * @since 4.1.0
 */
class WebcronLinkField extends NoteField
{
    /**
     * We use a custom layout that allows for the link to be copied.
     *
     * @var  string
     * @since  4.1.0
     */


    private function getHashKey()
    {

        return  ComponentHelper::getParams('com_scheduler')->get('webcron.key');
    }

    protected function getLabel()
    {


        $html  = [];
        $class = [];

        if (!empty($this->class)) {
            $class[] = $this->class;
        }

        if ($close = (string) $this->element['close']) {
            HTMLHelper::_('bootstrap.alert');
            $close   = $close === 'true' ? 'alert' : $close;
            $html[]  = '<button type="button" class="btn-close" data-bs-dismiss="' . $close . '"></button>';
            $class[] = 'alert-dismissible show';
        }
        $rehashedKey = join('-', str_split(md5(Factory::getApplication()->get('secret') . $this->getHashKey()), 8));
        $relative    = 'index.php?option=com_ajax&plugin=Ubeeo&group=system&format=json&hash=' . $this->getHashKey();
        $link        = Route::link('site', $relative, false, Route::TLS_FORCE, true);


        $class       = $class ? ' class="' . implode(' ', $class) . '"' : '';
        $title       = $this->element['label'] ? (string) $this->element['label'] : ($this->element['title'] ? (string) $this->element['title'] : '');
        $heading     = $this->element['heading'] ? (string) $this->element['heading'] : 'h4';

        $html[]      = !empty($title) ? '<' . $heading . '>' . Text::_($title) . '</' . $heading . '>' : '';
        $html[]      = "<a target=\"_blank\" href=\"$link\">$link</a>";
        $html[]      = "<p>x-api-key:  $rehashedKey</p>";

        return '</div><div ' . $class . '>' . implode('', $html);
    }
}
