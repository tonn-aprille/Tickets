<?php
/** @var array $scriptProperties */
if (empty($thread)) {$scriptProperties['thread'] = $modx->getOption('thread', $scriptProperties, 'resource-'.$modx->resource->id, true);}
$scriptProperties['resource'] = $modx->resource->id;
$scriptProperties['snippetPrepareComment'] = $modx->getOption('tickets.snippet_prepare_comment');
$scriptProperties['commentEditTime'] = $modx->getOption('tickets.comment_edit_time', null, 180);

if (!isset($depth)) {$depth = 0;}
if (empty($tplComments)) {$tplComments = 'tpl.Tickets.comment.wrapper';}
if (empty($tplCommentForm)) {$tplCommentForm = 'tpl.Tickets.comment.form';}
if (empty($tplCommentFormGuest)) {$tplCommentFormGuest = 'tpl.Tickets.comment.form.guest';}
if (empty($tplCommentAuth)) {$tplCommentAuth = 'tpl.Tickets.comment.one.auth';}
if (empty($tplCommentGuest)) {$tplCommentGuest = 'tpl.Tickets.comment.one.guest';}
if (empty($tplLoginToComment)) {$tplLoginToComment = 'tpl.Tickets.comment.login';}
if (empty($outputSeparator)) {$outputSeparator = "\n";}

/** @var Tickets $Tickets */
$Tickets = $modx->getService('tickets','Tickets',$modx->getOption('tickets.core_path',null,$modx->getOption('core_path').'components/tickets/').'model/tickets/',$scriptProperties);
$Tickets->initialize($modx->context->key, $scriptProperties);

/** @var pdoFetch $pdoFetch */
$fqn = $modx->getOption('pdoFetch.class', null, 'pdotools.pdofetch', true);
if (!$pdoClass = $modx->loadClass($fqn, '', false, true)) {return false;}
$pdoFetch = new $pdoClass($modx, $scriptProperties);
$pdoFetch->addTime('pdoTools loaded');

// Prepare Ticket Thread
/** @var TicketThread $thread */
if (!$thread = $modx->getObject('TicketThread', array('name' => $scriptProperties['thread']))) {
	$thread = $modx->newObject('TicketThread');
	$thread->fromArray(array(
		'name' => $scriptProperties['thread'],
		'resource' => $modx->resource->id,
		'createdby' => $modx->user->id,
		'createdon' => date('Y-m-d H:i:s'),
		'subscribers' => array($modx->resource->get('createdby')),
	));
}
elseif ($thread->get('deleted')) {
	return $modx->lexicon('ticket_thread_err_deleted');
}
// Prepare session for guests
if (!empty($allowGuest) && !isset($_SESSION['TicketComments'])) {
	$_SESSION['TicketComments'] = array('name' => '', 'email' => '', 'ids' => array());
}

// Migrate authors to subscription system
if (!is_array($thread->get('subscribers'))) {
	$thread->set('subscribers', array($modx->resource->get('createdby')));
}
$thread->set('properties', $scriptProperties);
$thread->save();

// Prepare query to db
$class = 'TicketComment';
$where = array();
if (empty($showUnpublished)) {$where['published'] = 1;}

// Joining tables
$innerJoin = array(
	'Thread' => array(
		'class' => 'TicketThread',
		'on' => '`Thread`.`id` = `TicketComment`.`thread` AND `Thread`.`name` = "'.$thread->get('name').'"'
	)
);
$leftJoin = array(
	'User' => array('class' => 'modUser', 'on' => '`User`.`id` = `TicketComment`.`createdby`'),
	'Profile' => array('class' => 'modUserProfile', 'on' => '`Profile`.`internalKey` = `TicketComment`.`createdby`'),
);
if ($modx->user->isAuthenticated($modx->context->key)) {
	$leftJoin['Vote'] = array(
		'class' => 'TicketVote',
		'on' => '`Vote`.`id` = `TicketComment`.`id` AND `Vote`.`class` = "TicketComment" AND `Vote`.`createdby` = '.$modx->user->id
	);
	$leftJoin['Star'] = array(
		'class' => 'TicketStar',
		'on' => '`Star`.`id` = `TicketComment`.`id` AND `Star`.`class` = "TicketComment" AND `Star`.`createdby` = '.$modx->user->id
	);
}
// Fields to select
$select = array(
	'TicketComment' => $modx->getSelectColumns('TicketComment', 'TicketComment', '', array('raw'), true) . ', `parent` as `new_parent`, `rating` as `rating_total`',
	'Thread' => '`Thread`.`resource`',
	'User' => '`User`.`username`',
	'Profile' => $modx->getSelectColumns('modUserProfile', 'Profile', '', array('id','email'), true) . ',`Profile`.`email` as `user_email`',
);
if ($modx->user->isAuthenticated($modx->context->key)) {
	$select['Vote'] = '`Vote`.`value` as `vote`';
	$select['Star'] = 'COUNT(`Star`.`id`) as `star`';
}

// Add custom parameters
foreach (array('where','select','leftJoin','innerJoin') as $v) {
	if (!empty($scriptProperties[$v])) {
		$tmp = $modx->fromJSON($scriptProperties[$v]);
		if (is_array($tmp)) {
			$$v = array_merge($$v, $tmp);
		}
	}
	unset($scriptProperties[$v]);
}
$pdoFetch->addTime('Conditions prepared');

$default = array(
	'class' => $class,
	'where' => $modx->toJSON($where),
	'innerJoin' => $modx->toJSON($innerJoin),
	'leftJoin' => $modx->toJSON($leftJoin),
	'select' => $modx->toJSON($select),
	'sortby' => $class.'.id',
	'sortdir' => 'ASC',
	'groupby' => $class.'.id',
	'limit' => 0,
	'fastMode' => true,
	'return' => 'data',
	'nestedChunkPrefix' => 'tickets_',
);

// Merge all properties and run!
$pdoFetch->setConfig(array_merge($default, $scriptProperties), false);
$pdoFetch->addTime('Query parameters prepared.');
$rows = $pdoFetch->run();

// Processing rows
$output = $commentsThread = null;
if (!empty($rows) && is_array($rows)) {
	$tmp = array();
	$i = 1;
	foreach ($rows as $row)  {
		$row['idx'] = $i ++;
		$tmp[$row['id']] = $row;
	}
	$rows = $thread->buildTree($tmp, $depth);
	unset($tmp, $i);

	if (!empty($formBefore)) {
		$rows = array_reverse($rows);
	}

	$tpl = !$thread->get('closed') && ($modx->user->isAuthenticated($modx->context->key) || !empty($allowGuest))
		? $tplCommentAuth
		: $tplCommentGuest;
	foreach ($rows as $row) {
		$output[] = $Tickets->templateNode($row, $tpl);
	}

	$pdoFetch->addTime('Returning processed chunks');
	$output = implode($outputSeparator, $output);
}

$commentsThread = $pdoFetch->getChunk($tplComments, array(
	'total' => $modx->getPlaceholder($pdoFetch->config['totalVar']),
	'comments' => $output,
	'subscribed' => $thread->isSubscribed(),
));

$pls = array('thread' => $scriptProperties['thread']);
if (!$modx->user->isAuthenticated($modx->context->key) && empty($allowGuest)) {
	$form = $pdoFetch->getChunk($tplLoginToComment);
}
elseif (!$modx->user->isAuthenticated($modx->context->key)) {
	$pls['name'] = $_SESSION['TicketComments']['name'];
	$pls['email'] = $_SESSION['TicketComments']['email'];
	if (!empty($enableCaptcha)) {
		$tmp = $Tickets->getCaptcha();
		$pls['captcha'] = $modx->lexicon('ticket_comment_captcha', $tmp);
	}
	$form = $pdoFetch->getChunk($tplCommentFormGuest, $pls);
}
else {
	$form = $pdoFetch->getChunk($tplCommentForm, $pls);
}

$commentForm = $thread->get('closed')
	? $modx->lexicon('ticket_thread_err_closed')
	: $form;
$output = !empty($formBefore)
	? $commentForm . $commentsThread
	: $commentsThread . $commentForm;

if ($modx->user->hasSessionContext('mgr') && !empty($showLog)) {
	$output .= '<pre class="CommentsLog">' . print_r($pdoFetch->getTime(), 1) . '</pre>';
}

$modx->regClientStartupScript('<script type="text/javascript">TicketsConfig.formBefore = '. (integer) !empty($formBefore).';TicketsConfig.thread_depth = '. (integer) $depth.';</script>', true);

// Return output
if (!empty($toPlaceholder)) {
	$modx->setPlaceholder($toPlaceholder, $output);
}
else {
	return $output;
}