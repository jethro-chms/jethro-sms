<?php

/**
 * Unit tests for SmsSetting / SmsSettings / ConstantSettings.
 */

namespace Test\Sms\Values;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_throws};
use \Sms\ConstantSettings;

require_once __DIR__ . '/../../src/load.php';

if (!defined('SMSSETTINGS_TEST_FALSE')) define('SMSSETTINGS_TEST_FALSE', false);
if (!defined('SMSSETTINGS_TEST_EMPTY')) define('SMSSETTINGS_TEST_EMPTY', '');
if (!defined('SMSSETTINGS_TEST_VALUE')) define('SMSSETTINGS_TEST_VALUE', 'abc');

test('ConstantSettings: undefined name returns val() default', function () {
    $s = new ConstantSettings();
    assert_eq($s['SMSSETTINGS_TEST_UNDEFINED']->val('dflt'), 'dflt');
    assert_eq($s['SMSSETTINGS_TEST_UNDEFINED']->val(), null);
    assert_eq($s['SMSSETTINGS_TEST_VALUE']->val('dflt'), 'abc');
});

test('ConstantSettings: defined false/empty string returned as-is, not the default', function () {
    $s = new ConstantSettings();
    assert_eq($s['SMSSETTINGS_TEST_FALSE']->val('dflt'), false);
    assert_eq($s['SMSSETTINGS_TEST_EMPTY']->val('dflt'), '');
});

test('ConstantSettings: getName() returns requested name even when unset', function () {
    $s = new ConstantSettings();
    assert_eq($s['SMSSETTINGS_TEST_UNDEFINED']->getName(), 'SMSSETTINGS_TEST_UNDEFINED');
    assert_eq($s['SMSSETTINGS_TEST_VALUE']->getName(), 'SMSSETTINGS_TEST_VALUE');
});

test('ConstantSettings: isset() reflects definedness', function () {
    $s = new ConstantSettings();
    assert_true(isset($s['SMSSETTINGS_TEST_VALUE']));
    assert_true(isset($s['SMSSETTINGS_TEST_FALSE']));
    assert_false(isset($s['SMSSETTINGS_TEST_UNDEFINED']));
});

test('SmsSettings: offsetSet/offsetUnset throw LogicException', function () {
    $s = new ConstantSettings();
    assert_throws(\LogicException::class, function () use ($s) { $s['X'] = 1; });
    assert_throws(\LogicException::class, function () use ($s) { unset($s['X']); });
});
