<?php
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'smarty.php';


use nntmux\Regexes;

$page = new AdminPage();
$regexes = new Regexes(['Settings' => $page->settings, 'Table_Name' => 'collection_regexes']);

$page->title = 'Collections Regex List';

$group = (isset($_REQUEST['group']) && !empty($_REQUEST['group']) ? $_REQUEST['group'] : '');
$offset = $_REQUEST['offset'] ?? 0;
$regex  = $regexes->getRegex($group, ITEMS_PER_PAGE, $offset);
$page->smarty->assign([
		'group'             => $group,
		'regex'             => $regex,
		'pagertotalitems'   => $regexes->getCount($group),
		'pageroffset'       => $offset,
		'pageritemsperpage' => ITEMS_PER_PAGE,
		'pagerquerybase'    => WWW_TOP . '/collection_regexes-list.php?' . $group . 'offset=',
		'pagerquerysuffix'  => ''
	]
);

$page->smarty->assign('pager', $page->smarty->fetch('pager.tpl'));

$page->content = $page->smarty->fetch('collection_regexes-list.tpl');
$page->render();
