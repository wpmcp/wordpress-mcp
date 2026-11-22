<?php

declare(strict_types=1);

namespace WPMCP\Bench;

use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;

require_once __DIR__ . '/Data.php';

/**
 * tools/list as the client receives it, measured in-process (issue #1130).
 *
 * ToolsListBudgetTest serializes each ability by hand (name, description,
 * inputSchema, annotations) and so leaves out outputSchema and _meta, which
 * the wire carries. Here the list comes from the plugin's own MCP
 * serialization: Stdio_Transport answers tools/list with the same tool
 * entries the HTTP 2026-07-28 route sends (Wire_Schema, Output_Schemas,
 * Tool_Annotations, then the Tool_Exposure filter), so the bytes are the
 * JSON-RPC response a client gets. If that path cannot be reached, the
 * hand-built list is measured instead and the result says 'approximate'.
 *
 * Loaded inside WordPress (the in-process bench), never in a release.
 */
final class Wire
{
    /**
     * @return array{bytes: int, tools: int, source: string, payload: string}
     */
    public static function tools_list(string $mode): array
    {
        update_option(Tool_Exposure::OPTION, $mode);

        try {
            $transport = new Stdio_Transport();
            $transport->handle_request([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities'    => new \stdClass(),
                    'clientInfo'      => ['name' => 'wpmcp-bench', 'version' => '1'],
                ],
            ]);
            $response = $transport->handle_request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
            $tools    = Data::arr($response, 'result', 'tools');
            if ([] !== $tools) {
                $payload = Data::json($response);
                return ['bytes' => strlen($payload), 'tools' => count($tools), 'source' => 'wire (Stdio_Transport tools/list, 2025-11-25)', 'payload' => $payload];
            }
        } catch (\Throwable $e) {
            // Fall through to the approximation below; the source says so.
        }

        $tools = [];
        foreach (Plugin::instance()->registrar()->all() as $a) {
            $tools[] = [
                'name'        => Tool_Exposure::tool_name($a->name),
                'description' => $a->description,
                'inputSchema' => [] === $a->input_schema ? new \stdClass() : $a->input_schema,
                'annotations' => [
                    'readOnlyHint'    => $a->read_only_hint,
                    'destructiveHint' => $a->destructive_hint,
                    'idempotentHint'  => $a->idempotent_hint,
                ],
            ];
        }
        $tools   = array_values((array) (new Tool_Exposure())->filter_tools_list($tools));
        $payload = Data::json(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => $tools]]);

        return ['bytes' => strlen($payload), 'tools' => count($tools), 'source' => 'approximate (no outputSchema or _meta)', 'payload' => $payload];
    }

    /**
     * Bytes per token: the calibrated factor from token-factor.json when
     * calibrate-tokens.php has written one, otherwise 4.
     *
     * @return array{bytes_per_token: float, method: string}
     */
    public static function token_factor(string $dir): array
    {
        $file = $dir . '/token-factor.json';
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $per  = Data::float($data, 'bytes_per_token');
        if ($per > 0) {
            return [
                'bytes_per_token' => $per,
                'method'          => sprintf('bytes / %.2f, calibrated with count_tokens on %s (%s)', $per, self::or_unknown(Data::str($data, 'measured_at')), self::or_unknown(Data::str($data, 'model'))),
            ];
        }
        return ['bytes_per_token' => 4.0, 'method' => 'bytes / 4, not calibrated (tools/bench/calibrate-tokens.php has not been run)'];
    }

    private static function or_unknown(string $value): string
    {
        return '' === $value ? '?' : $value;
    }
}
