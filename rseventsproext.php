<?php
/**
* @version 1.0.0
* @package RSEvents!Pro 1.0.0
* @copyright (C) 2011 www.rsjoomla.com
*                2014 Samuel Mehrbrodt
* @license GPL, http://www.gnu.org/copyleft/gpl.html
*/

// no direct access
defined('_JEXEC') or die;

if (!file_exists(JPATH_SITE.'/components/com_rseventspro/helpers/rseventspro.php'))
    die('RSEventsPro has to be installed');

require_once JPATH_SITE.'/components/com_rseventspro/helpers/rseventspro.php';
require_once JPATH_SITE.'/components/com_rseventspro/helpers/route.php';
require_once JPATH_SITE.'/administrator/components/com_search/helpers/search.php';

/**
 * REvents!Pro Extended Search plugin
 *
 * @package     Joomla.Plugin
 * @subpackage  RSEvents!Pro.events
 * @since       1.6
 */
class PlgSearchRseventsproExt extends JPlugin
{
    public function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage();
    }

    /**
     * @return array An array of search areas
     */
    public function onContentSearchAreas() {
        return array('rseventsproext' => 'PLG_SEARCH_RSEVENTSPROEXT');
    }
    
    private function format_date($start, $end) {
    if (rseventsproHelper::date($start, 'd.m.Y', true) ==
        rseventsproHelper::date($end, 'd.m.Y', true)) {
        // Start and end are the same day
        $date = rseventsproHelper::date($start, 'D. d.m.Y', true);
        // Change "Mon" to "Mo (Monday shorthand)
        return substr_replace($date, '', 2, 1);
    } else {
        $start = rseventsproHelper::date($start, 'D. d.m.', true);
        // Change "Mon" to "Mo (Monday shorthand)
        $start = substr_replace($start, '', 2, 1);
        $end = rseventsproHelper::date($end, 'D. d.m.Y', true);
        $end = substr_replace($end, '', 2, 1);
        return "$start-$end";
    }
}


    /**
     * RSEvents!Pro Search method
     * The sql must return the following fields that are used in a common display
     * routine: href, title, section, created, text, browsernav
     * @param string Target search string
     * @param string mathcing option, exact|any|all
     * @param string ordering option, newest|oldest|popular|alpha|category
     * @param mixed An array if the search it to be restricted to areas, null if search all
     */
    public function onContentSearch($text, $phrase='', $ordering='', $areas=null) {
        $db = JFactory::getDbo();

        $searchText = $text;
        if (is_array($areas)) {
            if (!array_intersect($areas, array_keys($this->onContentSearchAreas()))) {
                return array();
            }
        }

        $limit = $this->params->def('search_limit', 50);
        $search_description = (bool) $this->params->def('enable_event_description_search', true);
        $search_location = (bool) $this->params->def('enable_event_location_search', true);
        $search_contact = (bool) $this->params->def('enable_event_contact_search', true);

        $text = trim($text);
        if ($text == '') {
            return array();
        }

        $wheres = array();
        switch ($phrase) {
            case 'exact':
                $text       = $db->Quote('%'.$db->escape($text, true).'%', false);
                $wheres2    = array();
                $wheres2[]  = 'e.name LIKE '.$text;
                if ($search_description) {
                    $wheres2[]  = 'e.description LIKE '.$text;
                }
                if ($search_location) {
                    $wheres2[] = "l.name LIKE $text";
                    $wheres2[] = "l.address LIKE $text";
                }
                if ($search_contact) {
                        $wheres2[]  = 'e.email LIKE '.$text;
                        $wheres2[]  = 'e.phone LIKE '.$text;
                        $wheres2[]  = 'e.URL LIKE '.$text;
                    }
                $where = '(' . implode(') OR (', $wheres2) . ')';
                break;

            case 'all':
            case 'any':
            default:
                $words = explode(' ', $text);
                $wheres = array();
                foreach ($words as $word) {
                    $word       = $db->Quote('%'.$db->escape($word, true).'%', false);
                    $wheres2    = array();
                    $wheres2[]  = 'e.name LIKE '.$word;
                    if ($search_description) {
                        $wheres2[]  = 'e.description LIKE '.$word;
                    }
                    if ($search_location) {
                        $wheres2[] = "l.name LIKE $word";
                        $wheres2[] = "l.address LIKE $word";
                    }
                    if ($search_contact) {
                        $wheres2[]  = 'e.email LIKE '.$word;
                        $wheres2[]  = 'e.phone LIKE '.$word;
                        $wheres2[]  = 'e.URL LIKE '.$word;
                    }
                    $wheres[] = implode(' OR ', $wheres2);
                }
                $where = '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
                break;
        }

        $morder = '';
        switch ($ordering) {
            case 'oldest':
                $order = 'e.start ASC';
                break;

            case 'alpha':
                $order = 'e.name ASC';
                break;

            case 'newest':
            default:
                $order = 'e.start DESC';
                break;
        }

        $rows = array();
        $query  = $db->getQuery(true);

        // search query
        $query->clear();

        $query->select('e.id,
                       e.name AS title,
                       e.start AS created,
                       e.start,
                       e.end,
                       e.icon,
                       e.phone,
                       e.description,
                       e.URL,
                       l.name,
                       l.address,
                       2 AS browsernav');

        $query->from('#__rseventspro_events AS e');
        $query->leftJoin('#__rseventspro_locations AS l ON e.location = l.id');
        $query->where('('. $where .')' . ' AND e.published = 1 AND e.completed = 1 ');
        $query->group('e.id, e.name');
        $query->order($order);

        $db->setQuery($query, 0, $limit);
        $list = $db->loadObjectList();

        foreach($list as $key => $item) {
            if (!rseventsproHelper::canview($item->id))
                unset($list[$key]);

            $list[$key]->href = rseventsproHelper::route('index.php?option=com_rseventspro&layout=show&id='.
                                                         rseventsproHelper::sef($item->id,$item->title),
                                                         true,
                                                         RseventsproHelperRoute::getEventsItemid());
            $list[$key]->text = '';
            $list[$key]->date = $this->format_date($item->start, $item->end);
            if (!empty($item->name))
                $list[$key]->location = "{$item->name} {$item->address}";

            if (!empty($item->url))
                $list[$key]->section = $item->url;
            else
                $list[$key]->section = $item->phone;
            //Icon
            if ($list[$key]->icon) {
                $list[$key]->image = JURI::root().
                                    'components/com_rseventspro/assets/images/events/thumbs/s_'.
                                    $list[$key]->icon;
            }
        }

        return $list;
    }
}
