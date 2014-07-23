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

        if (!file_exists(JPATH_SITE.'/components/com_rseventspro/helpers/rseventspro.php'))
            return array();

        require_once JPATH_SITE.'/components/com_rseventspro/helpers/rseventspro.php';
        require_once JPATH_SITE.'/components/com_rseventspro/helpers/route.php';
        require_once JPATH_SITE.'/administrator/components/com_search/helpers/search.php';

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

        $query->select('e.id, e.name AS title, e.start AS created');
        $query->select('e.description AS text');
        $query->select('e.URL AS section, \'2\' AS browsernav');

        $query->from('#__rseventspro_events AS e');
        $query->leftJoin('#__rseventspro_locations AS l ON e.location = l.id');
        $query->where('('. $where .')' . ' AND e.published = 1 AND e.completed = 1 ');
        $query->group('e.id, e.name');
        $query->order($order);

        $db->setQuery($query, 0, $limit);
        $list = $db->loadObjectList();

        $user   = JFactory::getUser();
        $groups = implode(',', $user->getAuthorisedViewLevels());

        if (isset($list)) {
            foreach($list as $key => $item) {
                if (!rseventsproHelper::canview($item->id))
                    unset($list[$key]);

                $list[$key]->href = rseventsproHelper::route('index.php?option=com_rseventspro&layout=show&id='.
                                                             rseventsproHelper::sef($item->id,$item->title),
                                                             true,
                                                             RseventsproHelperRoute::getEventsItemid());
                $list[$key]->text = strip_tags($item->text);
            }
        }
        $rows[] = $list;

        $results = array();
        if (count($rows)) {
            foreach($rows as $row) {
                $new_row = array();
                foreach($row as $key => $article) {
                    if (searchHelper::checkNoHTML($article, $searchText, array('text', 'title'))) {
                        $new_row[] = $article;
                    }
                }
                $results = array_merge($results, (array) $new_row);
            }
        }

        return $results;
    }
}
