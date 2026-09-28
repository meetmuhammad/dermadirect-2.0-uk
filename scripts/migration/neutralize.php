<?php
/**
 * Neutralise cloned outbound integrations BEFORE cron or any integration is enabled
 * (plain SQL; WordPress is not bootstrapped).
 *   php neutralize.php plan  --dest=<db>
 *   php neutralize.php apply --dest=<db>        [MIG_HOSTED=1 --confirm-dest=<db> on Kinsta]
 * - Pauses every WooCommerce webhook (a paused webhook fires nothing new).
 * - Cancels every pending Action Scheduler action. Queued webhook deliveries still send after
 *   the webhook is paused, and cloned jobs (Super Payments ingestion, CRM pushes, marketing and
 *   tracking polls) must not run. Plugins re-create their own recurring actions when cron runs.
 * - Clears WooCommerce's cached active-webhook list.
 * Every change is recorded in dd_mig_neutralized (previous status) for review and targeted
 * re-enablement at launch. REST API keys are reported, not changed: they are revoked/re-issued as
 * an explicit launch step when each integration is re-pointed.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';

$a = args($argv);
$mode = $a['_'][0] ?? fail('mode required: plan|apply');
$dest = $a['dest'] ?? fail('--dest required');
guard_dbs($dest, null, $a);
$db = connect($dest);
$D = dest_ref($dest);
if (one($db, "SELECT option_value FROM {$D['options']} WHERE option_name='stylesheet'") !== 'derma-direct') {
    fail("$dest is not the new site — refusing");
}
$report = [
    'destination' => $dest, 'at' => gmdate('c'),
    'webhooks' => rows($db, "SELECT webhook_id, status, topic, SUBSTRING_INDEX(SUBSTRING_INDEX(delivery_url,'/',3),'/',-1) host FROM {$D['wc_webhooks']} ORDER BY webhook_id"),
    'pending_actions_by_hook' => rows($db, "SELECT hook, COUNT(*) n FROM {$D['actionscheduler_actions']} WHERE status='pending' GROUP BY hook ORDER BY n DESC"),
    'rest_api_keys' => rows($db, "SELECT key_id, description, permissions, last_access FROM {$D['woocommerce_api_keys']} ORDER BY key_id"),
];
if ($mode === 'apply') {
    q($db, "CREATE TABLE IF NOT EXISTS `$dest`.dd_mig_neutralized (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL,
        kind VARCHAR(16) NOT NULL, object_id BIGINT UNSIGNED NOT NULL, detail VARCHAR(255) NOT NULL, previous VARCHAR(40) NOT NULL) ENGINE=InnoDB");
    q($db, 'START TRANSACTION');
    q($db, "INSERT INTO `$dest`.dd_mig_neutralized (at, kind, object_id, detail, previous) SELECT UTC_TIMESTAMP(), 'webhook', webhook_id, LEFT(CONCAT(topic,' ',delivery_url),255), status FROM {$D['wc_webhooks']} WHERE status<>'paused'");
    q($db, "UPDATE {$D['wc_webhooks']} SET status='paused' WHERE status<>'paused'");
    $w = $db->affected_rows;
    q($db, "INSERT INTO `$dest`.dd_mig_neutralized (at, kind, object_id, detail, previous) SELECT UTC_TIMESTAMP(), 'action', action_id, LEFT(hook,255), status FROM {$D['actionscheduler_actions']} WHERE status='pending'");
    q($db, "UPDATE {$D['actionscheduler_actions']} SET status='canceled' WHERE status='pending'");
    $c = $db->affected_rows;
    q($db, "DELETE FROM {$D['options']} WHERE option_name IN ('_transient_woocommerce_webhook_ids_status_active','_transient_timeout_woocommerce_webhook_ids_status_active')");
    q($db, 'COMMIT');
    $report['applied'] = ['webhooks_paused' => $w, 'actions_canceled' => $c];
}
$report['after'] = [
    'active_webhooks' => (int) one($db, "SELECT COUNT(*) FROM {$D['wc_webhooks']} WHERE status='active'"),
    'pending_actions' => (int) one($db, "SELECT COUNT(*) FROM {$D['actionscheduler_actions']} WHERE status='pending'"),
];
file_put_contents(report_dir() . "/neutralize-$mode-" . gmdate('Ymd\THis') . '.json', json_encode($report, JSON_PRETTY_PRINT));
out(json_encode(['webhooks' => count($report['webhooks']), 'pending_actions' => array_sum(array_column($report['pending_actions_by_hook'], 'n')), 'applied' => $report['applied'] ?? null, 'after' => $report['after']]));
