<?php

use App\Models\Video;

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

$page->title = 'TV Shows List';

$tvshowname = (request()->has('showname') && ! empty(request()->input('showname')) ? request()->input('showname') : '');
$offset = request()->input('offset') ?? 0;

$page->smarty->assign(
    [
        'showname'          => $tvshowname,
        'tvshowlist'        => Video::getRange($offset, config('nntmux.items_per_page'), $tvshowname),
        'pagertotalitems'   => Video::getCount($tvshowname),
        'pageroffset'       => $offset,
        'pageritemsperpage' => config('nntmux.items_per_page'),
        'pagerquerysuffix'  => '',
        'pagerquerybase'    => WWW_TOP.'/show-list.php?'.
    ($tvshowname !== '' ? 'showname='.$tvshowname.'&amp;' : '').'&offset=',
    ]
);
$page->smarty->assign('pager', $page->smarty->fetch('pager.tpl'));

$page->content = $page->smarty->fetch('show-list.tpl');
$page->render();
