<?php

/**
 * Unit tests for SmsDelivery::withoutPerSegmentCost().
 *
 * with() treats null as "keep", so it can't drop a provider-reported price;
 * withoutPerSegmentCost() must clear exactly that field.
 */

namespace Test\Sms\Values;

use function \Test\{test, assert_eq, assert_true};
use \Sms\PhoneNumber;
use \Sms\SmsDelivery;
use \Sms\SmsStatus;

require_once __DIR__ . '/../../src/load.php';

test('withoutPerSegmentCost(): clears a reported cost, keeps every other field', function () {
    $d = new SmsDelivery(
        recipient: new PhoneNumber('0401234567'),
        status: SmsStatus::DELIVERED,
        remoteId: 'r1',
        deliveryTimestamp: 200,
        sendTimestamp: 100,
        rawResponse: 'raw',
        message: 'hi',
        statusDetail: 'detail',
        segmentsUsed: 2,
        perSegmentCost: 0.042,
        costBearer: false,
    );
    $c = $d->withoutPerSegmentCost();
    assert_eq($c->perSegmentCost(), null);
    assert_eq($c->recipient()->value, '0401234567');
    assert_true($c->status() === SmsStatus::DELIVERED);
    assert_eq($c->remoteId(), 'r1');
    assert_eq($c->deliveryTimestamp(), 200);
    assert_eq($c->sendTimestamp(), 100);
    assert_eq($c->rawResponse(), 'raw');
    assert_eq($c->message(), 'hi');
    assert_eq($c->statusDetail(), 'detail');
    assert_eq($c->segmentsUsed(), 2);
    assert_eq($c->costBearer(), false);
});
