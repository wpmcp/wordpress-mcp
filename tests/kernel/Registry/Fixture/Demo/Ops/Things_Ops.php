<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Registry\Fixture\Demo\Ops;

use WPMCP\Api\V1\Attribute\Operations;
use WPMCP\Api\V1\Attribute\Opt_In_Gate;
use WPMCP\Api\V1\Contract\Operation_Provider;
use WPMCP\Api\V1\Value\Requires;

#[Operations(
    dispatcher: 'plugin-data',
    host: 'Demo Plugin',
    summary: 'Demo things',
    order: 1200,
    requires: new Requires(plugin: ['demo/demo.php']),
    not_objects: ['demo-get:row_id' => 'a row in its own table'],
)]
#[Operations(dispatcher: 'plugin-data', host: 'Demo Extra', summary: null, order: 1201, method: 'extra_operations', hosts_method: 'hosts')]
#[Opt_In_Gate(filter: 'wpmcp_enable_demo_write', destructive: true, labels: 'opt_in_labels')]
final class Things_Ops implements Operation_Provider
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return [
            'demo-get' => ['mode' => 'read', 'handler' => static fn (): array => [], 'objects' => ['post_id' => 'post'], 'input_schema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'row_id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]],
            'demo-delete' => ['mode' => 'destructive', 'handler' => static fn (): array => [], 'opt_in_filter' => 'wpmcp_enable_demo_write', 'enabled_by_default' => false, 'capability' => 'manage_options'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function extra_operations(): array
    {
        return ['demo-extra' => ['mode' => 'write', 'tier' => 'pro', 'handler' => static fn (): array => []]];
    }

    /** @return array<string, string> */
    public static function hosts(): array
    {
        return ['demo-extra' => 'Demo Extra Plugin'];
    }

    /** @return array<string, string> */
    public static function opt_in_labels(): array
    {
        return ['wpmcp_enable_demo_write' => __('Demo: writes', 'wpmcp')];
    }
}
