<?php

require_once __DIR__ . '/../app/utils/FuiouPay.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assertTrueValue($actual, $message)
{
    assertSameValue(true, $actual, $message);
}

$pay = new FuiouPay([
    'mchnt_cd' => '0002900F5829371',
    'mchnt_key' => 'f00dac5077ea11e754e14c9541bc0170',
    'term_id' => 'ZWEI0001',
    'notify_url' => 'https://example.com/api/payment/fuiou_notify.php',
    'sandbox' => true,
]);

$expectedPreCreateSign = md5(
    '0002900F5829371|WECHAT|1|10662026050612345612345678|20260506123456|命盘整体解读|ZWEI0001|127.0.0.1|https://example.com/api/payment/fuiou_notify.php|abcdef1234567890abcdef1234567890|1.0|f00dac5077ea11e754e14c9541bc0170'
);

$payload = $pay->buildPreCreatePayloadForTest(
    '10662026050612345612345678',
    0.01,
    '命盘整体解读',
    'WECHAT',
    '127.0.0.1',
    '20260506123456',
    'abcdef1234567890abcdef1234567890'
);

assertSameValue('1.0', $payload['version'], 'preCreate payload should include version');
assertSameValue('0002900F5829371', $payload['mchnt_cd'], 'preCreate payload should include merchant id');
assertSameValue('WECHAT', $payload['order_type'], 'preCreate payload should include order type');
assertSameValue('1', $payload['order_amt'], 'preCreate payload should convert yuan to cents');
assertSameValue('10662026050612345612345678', $payload['mchnt_order_no'], 'preCreate payload should include order number');
assertSameValue('命盘整体解读', $payload['goods_des'], 'preCreate payload should include goods description');
assertSameValue($expectedPreCreateSign, $payload['sign'], 'preCreate signature should match Fuiou field order');

$expectedWxPreCreateSign = md5(
    '0002900F5829371|JSAPI|1|10662026050612345612345679|20260506123456|命盘整体解读|ZWEI0001|127.0.0.1|https://example.com/api/payment/fuiou_notify.php|abcdef1234567890abcdef1234567891|1.0|f00dac5077ea11e754e14c9541bc0170'
);

$wxPayload = $pay->buildWxPreCreatePayloadForTest(
    '10662026050612345612345679',
    0.01,
    '命盘整体解读',
    'JSAPI',
    'wx-appid',
    'wx-openid',
    '127.0.0.1',
    '20260506123456',
    'abcdef1234567890abcdef1234567891'
);

assertSameValue('JSAPI', $wxPayload['trade_type'], 'wxPreCreate payload should include JSAPI trade type');
assertSameValue('wx-appid', $wxPayload['sub_appid'], 'wxPreCreate payload should include sub appid');
assertSameValue('wx-openid', $wxPayload['sub_openid'], 'wxPreCreate payload should include sub openid');
assertSameValue($expectedWxPreCreateSign, $wxPayload['sign'], 'wxPreCreate signature should match Fuiou field order');

$expectedQuerySign = md5(
    '0002900F5829371|WECHAT|10662026050612345612345678|ZWEI0001|abcdef1234567890abcdef1234567892|1.0|f00dac5077ea11e754e14c9541bc0170'
);

$queryPayload = $pay->buildCommonQueryPayloadForTest(
    '10662026050612345612345678',
    'WECHAT',
    'abcdef1234567890abcdef1234567892'
);

assertSameValue('commonQuery', $queryPayload['__test_endpoint'], 'commonQuery test payload should name the endpoint');
unset($queryPayload['__test_endpoint']);
assertSameValue($expectedQuerySign, $queryPayload['sign'], 'commonQuery signature should match Fuiou field order');

$queryResponse = [
    'result_code' => '000000',
    'result_msg' => 'SUCCESS',
    'mchnt_cd' => '0002900F5829371',
    'order_amt' => '1',
    'transaction_id' => '4200000000000001',
    'mchnt_order_no' => '10662026050612345612345678',
    'reserved_fy_settle_dt' => '20260506',
    'trans_stat' => 'SUCCESS',
    'random_str' => 'abcdef1234567890abcdef1234567893',
];
$queryResponse['sign'] = md5(
    '000000|SUCCESS|0002900F5829371|1|4200000000000001|10662026050612345612345678|20260506|SUCCESS|abcdef1234567890abcdef1234567893|f00dac5077ea11e754e14c9541bc0170'
);

assertTrueValue($pay->verifyQueryResponse($queryResponse), 'commonQuery response signature should verify');

$notify = [
    'result_code' => '000000',
    'result_msg' => '交易成功',
    'mchnt_cd' => '0002900F5829371',
    'mchnt_order_no' => '10662026050612345612345678',
    'settle_order_amt' => '1',
    'order_amt' => '1',
    'txn_fin_ts' => '20260506123510',
    'reserved_fy_settle_dt' => '20260506',
    'random_str' => 'abcdef1234567890abcdef1234567890',
];
$notify['sign'] = md5(
    '0002900F5829371|10662026050612345612345678|1|1|20260506123510|20260506|abcdef1234567890abcdef1234567890|f00dac5077ea11e754e14c9541bc0170'
);
$notify['full_sign'] = md5(
    '000000|交易成功|0002900F5829371|10662026050612345612345678|1|1|20260506123510|20260506|abcdef1234567890abcdef1234567890|f00dac5077ea11e754e14c9541bc0170'
);

assertTrueValue($pay->verifyNotify($notify), 'notify signature should verify');

$notify['order_amt'] = '2';
assertSameValue(false, $pay->verifyNotify($notify), 'notify signature should fail after tampering');

echo "FuiouPay tests passed\n";
