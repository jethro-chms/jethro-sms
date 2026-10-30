<?php
/**
 * Minimal config for jethro-sms/bin/jethro-sms CLI, targeting the local
 * 5CentSMS mock proxy (proxy at 127.0.0.1:12346).
 *
 * Usage:
 *   jethro-sms/bin/jethro-sms --config=jethro-sms/bin/config.php <command>
 *
 * The mock proxy's default sender IDs are StBarnabas, 61491570158, 61402000002.
 * Sender numbers must be registered via the proxy's /senderid endpoint before use.
 */

define('SMS_5CENTSMS_URL', 'http://127.0.0.1:12346/5centsms/demo');
define('SMS_5CENTSMS_APIKEY_ID', 'fake');
define('SMS_5CENTSMS_APIKEY', 'fake');
