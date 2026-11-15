<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';

$postActions = array(
    'auth.register', 'auth.login', 'auth.logout',
    'token.redeem', 'token.quiz_answer', 'token.spend_create', 'token.spend_cancel', 'token.transfer',
    'story.redeem', 'news.mark_read',
    'market.buy', 'market.sell', 'market.loan', 'market.repay', 'market.create_company', 'market.rename_company',
    'admin.company_rename',
    'admin.issue_create', 'admin.issue_revoke', 'admin.collect',
    'admin.user_update', 'admin.user_create', 'admin.user_bulk_update',
    'admin.question_save', 'admin.question_delete',
    'admin.story_save', 'admin.story_delete',
    'admin.upload',
    'admin.market_refresh', 'admin.market_future_save', 'admin.market_ai_future',
    'admin.news_save', 'admin.news_delete', 'admin.news_draft', 'admin.news_publish', 'admin.notice_publish',
    'admin.news_published_delete', 'admin.news_published_clear',
    'admin.settings_update',
    'admin.cleanup_orphans', 'admin.cleanup_test_accounts', 'admin.event_reset',
);

$map = array(
    'me' => 'thp_act_me',
    'auth.register' => 'thp_act_register',
    'auth.login' => 'thp_act_login',
    'auth.logout' => 'thp_act_logout',
    'code.resolve' => 'thp_act_code_resolve',
    'token.redeem' => 'thp_act_token_redeem',
    'token.quiz_answer' => 'thp_act_token_quiz_answer',
    'token.spend_create' => 'thp_act_token_spend_create',
    'token.spend_status' => 'thp_act_token_spend_status',
    'token.spend_cancel' => 'thp_act_token_spend_cancel',
    'token.transfer' => 'thp_act_token_transfer',
    'token.history' => 'thp_act_token_history',
    'market.state' => 'thp_act_market_state',
    'market.company_detail' => 'thp_act_market_company_detail',
    'market.buy' => 'thp_act_market_buy',
    'market.sell' => 'thp_act_market_sell',
    'market.loan' => 'thp_act_market_loan',
    'market.repay' => 'thp_act_market_repay',
    'market.create_company' => 'thp_act_market_create_company',
    'market.rename_company' => 'thp_act_market_rename_company',
    'news.inbox' => 'thp_act_news_inbox',
    'news.detail' => 'thp_act_news_detail',
    'news.mark_read' => 'thp_act_news_mark_read',
    'story.list' => 'thp_act_story_list',
    'story.get' => 'thp_act_story_get',
    'story.redeem' => 'thp_act_story_redeem',
    'admin.stats' => 'thp_act_admin_stats',
    'admin.users' => 'thp_act_admin_users',
    'admin.risk' => 'thp_act_admin_risk',
    'admin.user_update' => 'thp_act_admin_user_update',
    'admin.user_create' => 'thp_act_admin_user_create',
    'admin.user_bulk_update' => 'thp_act_admin_user_bulk_update',
    'admin.issue_create' => 'thp_act_admin_issue_create',
    'admin.issue_list' => 'thp_act_admin_issue_list',
    'admin.issue_revoke' => 'thp_act_admin_issue_revoke',
    'admin.collect' => 'thp_act_admin_collect',
    'admin.question_list' => 'thp_act_admin_question_list',
    'admin.question_save' => 'thp_act_admin_question_save',
    'admin.question_delete' => 'thp_act_admin_question_delete',
    'admin.story_list' => 'thp_act_admin_story_list',
    'admin.story_save' => 'thp_act_admin_story_save',
    'admin.story_delete' => 'thp_act_admin_story_delete',
    'admin.upload' => 'thp_act_admin_upload',
    'admin.market_info' => 'thp_act_admin_market_info',
    'admin.market_export' => 'thp_act_admin_market_export',
    'admin.market_refresh' => 'thp_act_admin_market_refresh',
    'admin.market_future' => 'thp_act_admin_market_future',
    'admin.market_future_save' => 'thp_act_admin_market_future_save',
    'admin.market_ai_future' => 'thp_act_admin_market_ai_future',
    'admin.news_list' => 'thp_act_admin_news_list',
    'admin.news_save' => 'thp_act_admin_news_save',
    'admin.news_delete' => 'thp_act_admin_news_delete',
    'admin.news_published_delete' => 'thp_act_admin_news_published_delete',
    'admin.news_published_clear' => 'thp_act_admin_news_published_clear',
    'admin.news_draft' => 'thp_act_admin_news_draft',
    'admin.news_publish' => 'thp_act_admin_news_publish',
    'admin.notice_publish' => 'thp_act_admin_notice_publish',
    'admin.company_rename' => 'thp_act_admin_company_rename',
    'admin.settings_get' => 'thp_act_admin_settings_get',
    'admin.settings_update' => 'thp_act_admin_settings_update',
    'admin.logs' => 'thp_act_admin_logs',
    'admin.maintenance_info' => 'thp_act_admin_maintenance_info',
    'admin.cleanup_orphans' => 'thp_act_admin_cleanup_orphans',
    'admin.cleanup_test_accounts' => 'thp_act_admin_cleanup_test_accounts',
    'admin.event_reset' => 'thp_act_admin_event_reset',
    'admin.health' => 'thp_act_admin_health',
);

$idempotencyExcluded = array('auth.register', 'auth.login', 'auth.logout');
$idempotencyAction = '';
$idempotencyKey = '';
$idempotencyUser = 0;

try {
    if ($action === 'captcha') {
        thp_captcha_output();
    }
    if ($action === '') {
        thp_json_out(array('ok' => false, 'error' => '缺少 action 参数'));
    }
    if (in_array($action, $postActions, true)) {
        thp_require_post();
    }
    if (!isset($map[$action])) {
        thp_json_out(array('ok' => false, 'error' => '未知的操作'), 404);
    }
    // 所有已登录用户的写操作统一去重：双击、超时重试和断网重发都复用同一结果。
    if (in_array($action, $postActions, true) && !in_array($action, $idempotencyExcluded, true)) {
        $candidateKey = thp_idempotency_key_value();
        if ($candidateKey !== '') {
            $currentUser = thp_current_user();
            if ($currentUser) {
                $idempotencyAction = $action;
                $idempotencyKey = $candidateKey;
                $idempotencyUser = (int)$currentUser['id'];
                $idem = thp_idempotency_begin($idempotencyAction, $idempotencyKey, $idempotencyUser);
                if (!empty($idem['cached'])) {
                    thp_json_out(array('ok' => true, 'data' => $idem['data'], 'replayed' => true));
                }
            }
        }
    }
    $handler = $map[$action];
    $result = $handler();
    if ($idempotencyKey !== '') {
        thp_idempotency_finish($idempotencyAction, $idempotencyKey, $idempotencyUser, $result);
    }
    thp_json_out(array('ok' => true, 'data' => $result));
} catch (ThpError $e) {
    if ($idempotencyKey !== '') {
        thp_idempotency_abort($idempotencyAction, $idempotencyKey, $idempotencyUser);
    }
    $code = $e->getCode();
    if ($code < 400 || $code > 599) { $code = 400; }
    if ($code === 429) {
        try {
            $riskUser = thp_current_user();
            $riskUid = $riskUser ? (int)$riskUser['id'] : 0;
            $riskWho = $riskUid > 0 ? ('u:' . $riskUid) : ('ip:' . thp_client_ip());
            // 同一个用户/接口每分钟只记一条，避免脚本刷 429 时把风险表写爆。
            if (thp_throttle('risklog:throttle:' . $riskWho . ':' . $action, 1, 60)) {
                thp_risk_event('throttle_blocked', '接口 ' . $action . '：' . $e->getMessage(), 2, $riskUid > 0 ? $riskUid : null);
            }
        } catch (Throwable $ignored) {}
    }
    thp_json_out(array_merge(array('ok' => false, 'error' => $e->getMessage()), $e->extra), $code);
} catch (Throwable $e) {
    if ($idempotencyKey !== '') {
        thp_idempotency_abort($idempotencyAction, $idempotencyKey, $idempotencyUser);
    }
    error_log('[THP] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    thp_json_out(array('ok' => false, 'error' => '服务器开小差了，请稍后再试'), 500);
}
