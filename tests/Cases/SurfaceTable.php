<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

/**
 * The 163 rows of the shared SDK surface, as data.
 *
 * Split out of {@see SurfaceCases} for one reason: that file holds the
 * EXECUTION and the assertions, this one holds the table they run over, and
 * together they were 807 lines against the 650 CONTRACT.md §9 allows. "Split
 * by responsibility" is what the rule asks for, and a table of routes with no
 * assertions in it is a different responsibility from the pass that executes
 * them.
 *
 * There are no `Assert` calls in this file on purpose. If one appears here it
 * is in the wrong place.
 */
final class SurfaceTable
{
    /**
     * `[namespace, manifest name, PHP method, verb, manifest path, arguments]`.
     *
     * Arguments use {@see SurfaceCases::SENTINELS} for path parameters, in
     * path order, and
     * ordinary throwaway values for everything else (a body field, a title, a
     * tag that travels in the body rather than the path).
     *
     * @return array<int,array{0:string,1:string,2:string,3:string,4:string,5:array<int,mixed>}>
     */
    public static function surface(): array
    {
        return [
            ['root', 'whoami', 'whoami', 'GET', '/v1/api-keys/whoami', []],
            ['root', 'me', 'me', 'GET', '/v1/me', []],

            ['contacts', 'list', 'list', 'GET', '/v1/contacts', []],
            ['contacts', 'get', 'get', 'GET', '/v1/contacts/{id}', ['__p0__']],
            ['contacts', 'upsert', 'upsert', 'POST', '/v1/contacts', [['email' => 'a@b.test']]],
            ['contacts', 'bulk_upsert', 'bulkUpsert', 'POST', '/v1/contacts/bulk', [[['email' => 'a@b.test']]]],
            ['contacts', 'merge', 'merge', 'POST', '/v1/contacts/{id}/merge', ['__p0__', 'c_absorbed']],
            ['contacts', 'add_tags', 'addTags', 'POST', '/v1/contacts/{id}/tags', ['__p0__', 'vip']],
            ['contacts', 'remove_tag', 'removeTag', 'DELETE', '/v1/contacts/{id}/tags/{tag}', ['__p0__', '__p1__']],
            ['contacts', 'unsubscribe', 'unsubscribe', 'POST', '/v1/contacts/{id}/unsubscribe', ['__p0__']],
            ['contacts', 'journey', 'journey', 'GET', '/v1/contacts/{id}/journey', ['__p0__']],

            ['conversations', 'list', 'list', 'GET', '/v1/conversations', []],
            ['conversations', 'get', 'get', 'GET', '/v1/conversations/{id}', ['__p0__']],
            ['conversations', 'messages', 'messages', 'GET', '/v1/conversations/{id}/messages', ['__p0__']],
            ['conversations', 'send', 'send', 'POST', '/v1/conversations/{id}/messages', ['__p0__', 'hello']],
            ['conversations', 'unattended', 'unattended', 'GET', '/v1/conversations/unattended', []],
            ['conversations', 'agent_handling', 'agentHandling', 'GET', '/v1/conversations/{id}/agent-handling', ['__p0__']],
            ['conversations', 'set_agent_handling', 'setAgentHandling', 'PUT', '/v1/conversations/{id}/agent-handling', ['__p0__', 'handled']],
            ['conversations', 'generated_replies', 'generatedReplies', 'GET', '/v1/conversations/{id}/generated-replies', ['__p0__']],
            ['conversations', 'generate_reply', 'generateReply', 'POST', '/v1/conversations/{id}/generated-replies', ['__p0__', 'a draft']],
            ['conversations', 'scheduled_messages', 'scheduledMessages', 'GET', '/v1/conversations/{id}/scheduled-messages', ['__p0__']],
            ['conversations', 'schedule_message', 'scheduleMessage', 'POST', '/v1/conversations/{id}/scheduled-messages', ['__p0__', 'later', '2026-01-01T00:00:00Z']],
            ['conversations', 'cancel_scheduled', 'cancelScheduled', 'DELETE', '/v1/conversations/scheduled-messages/{scheduled_id}', ['__p0__']],

            ['tickets', 'list', 'list', 'GET', '/v1/tickets', []],
            ['tickets', 'comments', 'comments', 'GET', '/v1/tickets/{id}/comments', ['__p0__']],
            ['tickets', 'for_conversation', 'forConversation', 'GET', '/v1/tickets/for-conversation/{conversation_id}', ['__p0__']],
            ['tickets', 'queues', 'queues', 'GET', '/v1/tickets/queues', []],
            ['tickets', 'merge', 'merge', 'POST', '/v1/tickets/{id}/merge', ['__p0__', 't_absorbed']],
            ['tickets', 'split', 'split', 'POST', '/v1/tickets/{id}/split', ['__p0__', 'The second problem']],
            ['tickets', 'bulk', 'bulk', 'POST', '/v1/tickets/bulk', [['t_1'], 'status', ['status' => 'closed']]],

            ['macros', 'list', 'list', 'GET', '/v1/macros', []],
            ['macros', 'create', 'create', 'POST', '/v1/macros', [['name' => 'Refund', 'scope' => 'workspace', 'body' => 'done']]],
            ['macros', 'update', 'update', 'PATCH', '/v1/macros/{id}', ['__p0__', ['name' => 'Refunded']]],
            ['macros', 'delete', 'delete', 'DELETE', '/v1/macros/{id}', ['__p0__']],

            ['kb', 'articles', 'articles', 'GET', '/v1/kb/articles', []],
            ['kb', 'article', 'article', 'GET', '/v1/kb/articles/{slug}', ['__p0__']],
            ['kb', 'collections', 'collections', 'GET', '/v1/kb/collections', []],

            ['search', 'query', 'query', 'GET', '/v1/search', ['refund']],

            ['events', 'track', 'track', 'POST', '/v1/cdp/events', ['c_1', 'order_placed']],
            ['events', 'list', 'list', 'GET', '/v1/cdp/events', []],

            ['webhooks', 'deliveries', 'deliveries', 'GET', '/v1/webhooks/deliveries', []],
            ['webhooks', 'delivery', 'delivery', 'GET', '/v1/webhooks/deliveries/{id}', ['__p0__']],
            ['webhooks', 'redeliver', 'redeliver', 'POST', '/v1/webhooks/deliveries/{id}/redeliver', ['__p0__']],

            ['reports', 'overview', 'overview', 'GET', '/v1/reports/overview', []],
            ['reports', 'agents', 'agents', 'GET', '/v1/reports/agents', []],
            ['reports', 'conversations_by_day', 'conversationsByDay', 'GET', '/v1/reports/conversations-by-day', []],
            ['reports', 'sla', 'sla', 'GET', '/v1/reports/sla', []],

            ['channels', 'list', 'list', 'GET', '/v1/channels', []],
            ['channels', 'get', 'get', 'GET', '/v1/channels/{id}', ['__p0__']],

            ['billing', 'plans', 'plans', 'GET', '/v1/billing/plans', []],
            ['billing', 'usage', 'usage', 'GET', '/v1/billing/usage', ['ai.tokens']],

            ['workspace', 'settings', 'settings', 'GET', '/v1/workspace/settings', []],
            ['workspace', 'labels', 'labels', 'GET', '/v1/labels', []],
            ['workspace', 'inboxes', 'inboxes', 'GET', '/v1/inboxes', []],
            ['workspace', 'team_members', 'teamMembers', 'GET', '/v1/teams/{id}/members', ['__p0__']],
            ['workspace', 'agent_skills', 'agentSkills', 'GET', '/v1/agents/skills', []],
            ['workspace', 'key_scopes', 'keyScopes', 'GET', '/v1/api-keys/scopes', []],
            ['workspace', 'secrets', 'secrets', 'GET', '/v1/workspace/secrets', []],
            ['workspace', 'mine', 'mine', 'GET', '/v1/workspaces/me', []],

            ['contacts', 'mood', 'mood', 'GET', '/v1/contacts/{id}/mood', ['__p0__']],
            ['contacts', 'cards', 'cards', 'POST', '/v1/contacts/{contactId}/cards', ['__p0__']],

            ['conversations', 'unread_counts', 'unreadCounts', 'GET', '/v1/conversations/unread-counts', []],
            ['conversations', 'linked', 'linked', 'GET', '/v1/conversations/{id}/linked', ['__p0__']],
            ['conversations', 'outcomes', 'outcomes', 'GET', '/v1/conversations/{id}/outcomes', ['__p0__']],
            ['conversations', 'record_outcome', 'recordOutcome', 'POST', '/v1/conversations/{id}/outcomes', ['__p0__', 'resolved']],
            ['conversations', 'return_to_bot', 'returnToBot', 'POST', '/v1/conversations/{id}/return-to-bot', ['__p0__', 'bot_1']],
            ['conversations', 'update_blocks', 'updateBlocks', 'PATCH', '/v1/conversations/{id}/messages/{messageId}/blocks', ['__p0__', '__p1__', [['id' => 'b1', 'set' => ['done' => true]]]]],

            ['tickets', 'conversations', 'conversations', 'GET', '/v1/tickets/{id}/conversations', ['__p0__']],
            ['tickets', 'open_count', 'openCount', 'GET', '/v1/tickets/open-count/{conversationId}', ['__p0__']],
            ['tickets', 'time', 'time', 'GET', '/v1/tickets/{id}/time', ['__p0__']],
            ['tickets', 'status_history', 'statusHistory', 'GET', '/v1/tickets/{id}/status-history', ['__p0__']],
            ['tickets', 'lineage', 'lineage', 'GET', '/v1/tickets/{id}/lineage', ['__p0__']],

            ['macros', 'get', 'get', 'GET', '/v1/macros/{id}', ['__p0__']],
            ['macros', 'actions', 'actions', 'GET', '/v1/macros/actions', []],
            ['macros', 'templates', 'templates', 'GET', '/v1/macros/templates', []],
            ['macros', 'template', 'template', 'GET', '/v1/macros/templates/{slug}', ['__p0__']],

            ['webhooks', 'list', 'list', 'GET', '/v1/webhooks', []],
            ['webhooks', 'create', 'create', 'POST', '/v1/webhooks', ['https://hooks.test/x', ['conversation.created']]],
            ['webhooks', 'events', 'events', 'GET', '/v1/webhooks/events', []],
            ['webhooks', 'schema_versions', 'schemaVersions', 'GET', '/v1/webhooks/schema-versions', []],
            ['webhooks', 'set_schema_version', 'setSchemaVersion', 'PATCH', '/v1/webhooks/{id}/schema-version', ['__p0__', '2026-01-01']],
            ['webhooks', 'delete', 'delete', 'DELETE', '/v1/webhooks/{id}', ['__p0__']],

            ['reports', 'sources', 'sources', 'GET', '/v1/reports/sources', []],
            ['reports', 'attribution', 'attribution', 'GET', '/v1/reports/attribution', []],
            ['reports', 'cohorts', 'cohorts', 'GET', '/v1/reports/cohorts', []],
            ['reports', 'response_times', 'responseTimes', 'GET', '/v1/reports/response-times', []],
            ['reports', 'teams', 'teams', 'GET', '/v1/reports/teams', []],
            ['reports', 'labels', 'labels', 'GET', '/v1/reports/labels', []],
            ['reports', 'voice', 'voice', 'GET', '/v1/reports/voice', []],
            ['reports', 'themes', 'themes', 'GET', '/v1/reports/themes', []],
            ['reports', 'theme_conversations', 'themeConversations', 'GET', '/v1/reports/themes/{id}/conversations', ['__p0__']],

            ['channels', 'adapters', 'adapters', 'GET', '/v1/channels/adapters', []],
            ['channels', 'create', 'create', 'POST', '/v1/channels', [['type' => 'web', 'name' => 'Site']]],
            ['channels', 'update', 'update', 'PATCH', '/v1/channels/{id}', ['__p0__', ['name' => 'Site']]],
            ['channels', 'rotate_secret', 'rotateSecret', 'POST', '/v1/channels/{id}/secret/rotate', ['__p0__']],

            ['billing', 'summary', 'summary', 'GET', '/v1/billing', []],
            ['billing', 'invoices', 'invoices', 'GET', '/v1/billing/invoices', []],
            ['billing', 'usage_monthly', 'usageMonthly', 'GET', '/v1/billing/usage/monthly', []],
            ['billing', 'usage_daily', 'usageDaily', 'GET', '/v1/billing/usage/daily', []],

            ['ai', 'providers', 'providers', 'GET', '/v1/ai-providers', []],
            ['ai', 'add_provider', 'addProvider', 'POST', '/v1/ai-providers', ['openai', 'sk-test']],
            ['ai', 'test_provider', 'testProvider', 'POST', '/v1/ai-providers/{provider}/test', ['__p0__']],
            ['ai', 'quota', 'quota', 'GET', '/v1/ai/quota', []],
            ['ai', 'tone', 'tone', 'GET', '/v1/ai/tone', []],
            ['ai', 'set_tone', 'setTone', 'PUT', '/v1/ai/tone', [[['category' => 'voice', 'rule' => 'Be brief']]]],
            ['ai', 'tone_preview', 'tonePreview', 'GET', '/v1/ai/tone/preview', []],
            ['ai', 'generate_tone', 'generateTone', 'POST', '/v1/ai/tone/generate', ['We write plainly and we never shout at the customer.']],
            ['ai', 'tools', 'tools', 'GET', '/v1/ai/tools', []],
            ['ai', 'create_tool', 'createTool', 'POST', '/v1/ai/tools', [['name' => 'order_lookup', 'description' => 'Find an order', 'urlTemplate' => 'https://acme.test/o']]],
            ['ai', 'update_tool', 'updateTool', 'PATCH', '/v1/ai/tools/{id}', ['__p0__', ['description' => 'Find an order by id']]],
            ['ai', 'delete_tool', 'deleteTool', 'DELETE', '/v1/ai/tools/{id}', ['__p0__']],

            ['bots', 'list', 'list', 'GET', '/v1/bots', []],
            ['bots', 'get', 'get', 'GET', '/v1/bots/{id}', ['__p0__']],
            ['bots', 'create', 'create', 'POST', '/v1/bots', [['name' => 'Support', 'systemPrompt' => 'You are a helpful support assistant.']]],
            ['bots', 'update', 'update', 'PATCH', '/v1/bots/{id}', ['__p0__', ['name' => 'Support EU']]],
            ['bots', 'update_external', 'updateExternal', 'PATCH', '/v1/bots/{id}/external', ['__p0__', ['timeoutSeconds' => 20]]],
            ['bots', 'rotate_external_secret', 'rotateExternalSecret', 'POST', '/v1/bots/{id}/external/rotate-secret', ['__p0__']],
            ['bots', 'deliveries', 'deliveries', 'GET', '/v1/bots/{id}/deliveries', ['__p0__']],
            ['bots', 'delivery', 'delivery', 'GET', '/v1/bots/{id}/deliveries/{deliveryId}', ['__p0__', '__p1__']],
            ['bots', 'redeliver', 'redeliver', 'POST', '/v1/bots/{id}/deliveries/{deliveryId}/redeliver', ['__p0__', '__p1__']],
            ['bots', 'test_delivery', 'testDelivery', 'POST', '/v1/bots/{id}/test-delivery', ['__p0__']],
            ['bots', 'analytics', 'analytics', 'GET', '/v1/bots/{id}/analytics', ['__p0__']],

            ['bot_routes', 'list', 'list', 'GET', '/v1/bot-routes', []],
            ['bot_routes', 'fields', 'fields', 'GET', '/v1/bot-routes/fields', []],
            ['bot_routes', 'create', 'create', 'POST', '/v1/bot-routes', [['botId' => 'bot_1', 'name' => 'Pricing']]],
            ['bot_routes', 'update', 'update', 'PATCH', '/v1/bot-routes/{id}', ['__p0__', ['priority' => 10]]],
            ['bot_routes', 'delete', 'delete', 'DELETE', '/v1/bot-routes/{id}', ['__p0__']],
            ['bot_routes', 'resolve', 'resolve', 'POST', '/v1/bot-routes/resolve', [['page' => ['path' => '/pricing']]]],

            ['brands', 'list', 'list', 'GET', '/v1/brands', []],
            ['brands', 'create', 'create', 'POST', '/v1/brands', [['name' => 'Acme', 'slug' => 'acme']]],
            ['brands', 'update', 'update', 'PATCH', '/v1/brands/{id}', ['__p0__', ['name' => 'Acme EU']]],
            ['brands', 'delete', 'delete', 'DELETE', '/v1/brands/{id}', ['__p0__']],

            ['triggers', 'list', 'list', 'GET', '/v1/triggers', []],
            ['triggers', 'create', 'create', 'POST', '/v1/triggers', [['name' => 'Greet', 'eventType' => 'conversation.created', 'enabled' => true, 'conditions' => [], 'actions' => []]]],
            ['triggers', 'delete', 'delete', 'DELETE', '/v1/triggers/{id}', ['__p0__']],

            ['canvas_steps', 'get', 'get', 'GET', '/v1/canvas-steps/{id}', ['__p0__']],
            ['canvas_steps', 'resolve', 'resolve', 'POST', '/v1/canvas-steps/{id}/resolve', ['__p0__', ['blocks' => []]]],

            ['customer_cards', 'list', 'list', 'GET', '/v1/customer-cards', []],
            ['customer_cards', 'create', 'create', 'POST', '/v1/customer-cards', [['key' => 'orders', 'title' => 'Orders', 'url' => 'https://acme.test/c', 'ttlSeconds' => 60]]],
            ['customer_cards', 'update', 'update', 'PATCH', '/v1/customer-cards/{id}', ['__p0__', ['title' => 'Recent orders']]],
            ['customer_cards', 'rotate_secret', 'rotateSecret', 'POST', '/v1/customer-cards/{id}/rotate-secret', ['__p0__']],
            ['customer_cards', 'delete', 'delete', 'DELETE', '/v1/customer-cards/{id}', ['__p0__']],

            ['customer_card_endpoints', 'list', 'list', 'GET', '/v1/customer-card-endpoints', []],
            ['customer_card_endpoints', 'create', 'create', 'POST', '/v1/customer-card-endpoints', ['https://acme.test/c']],
            ['customer_card_endpoints', 'rotate_secret', 'rotateSecret', 'POST', '/v1/customer-card-endpoints/{id}/rotate-secret', ['__p0__']],
            ['customer_card_endpoints', 'activate_secret', 'activateSecret', 'POST', '/v1/customer-card-endpoints/{id}/activate-secret', ['__p0__']],
            ['customer_card_endpoints', 'delete', 'delete', 'DELETE', '/v1/customer-card-endpoints/{id}', ['__p0__']],

            ['docs_sources', 'providers', 'providers', 'GET', '/v1/docs-sources/providers', []],
            ['docs_sources', 'list', 'list', 'GET', '/v1/docs-sources', []],
            ['docs_sources', 'discover', 'discover', 'POST', '/v1/docs-sources/discover', [['provider' => 'gitbook', 'token' => 'tok']]],
            ['docs_sources', 'create', 'create', 'POST', '/v1/docs-sources', [['provider' => 'gitbook', 'token' => 'tok', 'organizationId' => 'o1', 'spaceId' => 's1']]],
            ['docs_sources', 'update', 'update', 'PATCH', '/v1/docs-sources/{id}', ['__p0__', ['label' => 'Docs']]],
            ['docs_sources', 'sync', 'sync', 'POST', '/v1/docs-sources/{id}/sync', ['__p0__']],
            ['docs_sources', 'webhook', 'webhook', 'POST', '/v1/docs-sources/{id}/webhook', ['__p0__']],
            ['docs_sources', 'delete', 'delete', 'DELETE', '/v1/docs-sources/{id}', ['__p0__']],

            ['email_templates', 'list', 'list', 'GET', '/v1/email-templates', []],
            ['email_templates', 'preview', 'preview', 'POST', '/v1/email-templates/preview', ['workspace_invite', 'Join us', 'Hello {{name}}']],
            ['email_templates', 'update', 'update', 'PUT', '/v1/email-templates/{kind}', ['__p0__', 'Join us', 'Hello {{name}}']],
            ['email_templates', 'delete', 'delete', 'DELETE', '/v1/email-templates/{kind}', ['__p0__']],

            ['knowledge_gaps', 'list', 'list', 'GET', '/v1/knowledge-gaps', []],
            ['knowledge_gaps', 'update', 'update', 'PATCH', '/v1/knowledge-gaps/{id}', ['__p0__', 'resolved']],
            ['knowledge_gaps', 'draft', 'draft', 'POST', '/v1/knowledge-gaps/{id}/draft', ['__p0__']],

            // The 163rd route, key-accessible since 2026-10-14. No body and no
            // query: the path parameter is the whole input, so the argument
            // list is one sentinel and nothing else.
            ['visitors', 'start_conversation', 'startConversation', 'POST', '/v1/visitors/{contactId}/start-conversation', ['__p0__']],
        ];
    }

    /**
     * The PHP property for a manifest namespace.
     *
     * ─── ONE MAPPING, IN ONE PLACE, AND ONLY FOR THE NAME ─────────────────
     *
     * `surface.json` spells a namespace `bot_routes`; this SDK spells the
     * property `botRoutes`, per CONTRACT.md §9 — "the language's own idiom
     * wins over cross-language symmetry", `camelCase` in PHP. A
     * `$chatly->customer_card_endpoints` would be the manifest's idiom
     * imported into a language that does not use it.
     *
     * The LOCK still records the manifest's `ns` verbatim, so the parity gate
     * compares the same strings across eight languages. This function
     * converts only for the property lookup, and it is derived rather than
     * tabulated so a new namespace needs no entry anywhere.
     */
    public static function property(string $namespace): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $namespace))));
    }
}
