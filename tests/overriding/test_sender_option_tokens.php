<?php

/**
 * Unit tests for \Sms\senderOptionTokens() — the SMS_SENDER_OPTIONS token list
 * the sender dropdown is built from (and send() is checked against).
 */

namespace Test\Sms\Overriding;

use function \Test\{test, assert_eq};
use function \Sms\senderOptionTokens;

test('senderOptionTokens: blank or whitespace setting gives the default, mobile first', function () {
	assert_eq(senderOptionTokens(''), ['_USER_MOBILE_', '_SENDER_IDS_']);
	assert_eq(senderOptionTokens('  '), ['_USER_MOBILE_', '_SENDER_IDS_']);
});

test('senderOptionTokens: keeps configured order, trims, drops empty segments', function () {
	assert_eq(senderOptionTokens(' StBarnabas ,,_SENDER_IDS_, _USER_MOBILE_,'), ['StBarnabas', '_SENDER_IDS_', '_USER_MOBILE_']);
});

test('senderOptionTokens: only commas configured means no options, not the default', function () {
	assert_eq(senderOptionTokens(',,'), []);
});
